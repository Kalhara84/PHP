<?php
/**
 * PgClient - tiny pure-PHP PostgreSQL client (no pdo_pgsql / pgsql extension needed).
 * Talks the PostgreSQL wire protocol over a normal socket (fsockopen).
 * Supports password auth: cleartext, md5 and SCRAM-SHA-256 (default on PostgreSQL 14+).
 * Works on PHP 5.4+ . No SSL (use it for localhost / LAN servers).
 */
class PgClient
{
    private $s;
    private $tag = '';

    public function __construct($host, $port, $db, $user, $pass, $timeout = 10)
    {
        $errno = 0; $errstr = '';
        $this->s = @fsockopen($host, (int)$port, $errno, $errstr, $timeout);
        if (!$this->s) {
            throw new Exception("Could not connect to PostgreSQL at $host:$port - $errstr ($errno)");
        }
        stream_set_timeout($this->s, 60);
        $this->startup($db, $user, $pass);
        $this->query("SET standard_conforming_strings = on");
    }

    public function __destruct()
    {
        if ($this->s) {
            @fwrite($this->s, 'X' . pack('N', 4));
            @fclose($this->s);
        }
    }

    /* ------------------------------ low level ------------------------------ */
    private function read($n)
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->s, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new Exception('PostgreSQL connection lost.');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function readMsg()
    {
        $type = $this->read(1);
        $len  = $this->u32($this->read(4));
        $body = $len > 4 ? $this->read($len - 4) : '';
        return array($type, $body);
    }

    private function send($type, $body)
    {
        $msg = $type . pack('N', strlen($body) + 4) . $body;
        if (@fwrite($this->s, $msg) === false) {
            throw new Exception('Could not write to PostgreSQL connection.');
        }
    }

    private function u32($b) { $a = unpack('N', $b); return $a[1]; }
    private function u16($b) { $a = unpack('n', $b); return $a[1]; }

    private function parseError($p)
    {
        $f = array(); $i = 0; $n = strlen($p);
        while ($i < $n && $p[$i] !== "\0") {
            $code = $p[$i]; $end = strpos($p, "\0", $i + 1);
            $f[$code] = substr($p, $i + 1, $end - $i - 1);
            $i = $end + 1;
        }
        $m = isset($f['M']) ? $f['M'] : 'PostgreSQL error';
        if (isset($f['D'])) $m .= ' - ' . $f['D'];
        return $m;
    }

    /* ------------------------------ authentication ------------------------------ */
    private function startup($db, $user, $pass)
    {
        $params = "user\0$user\0database\0$db\0client_encoding\0UTF8\0\0";
        $msg = pack('N', 196608) . $params;
        fwrite($this->s, pack('N', strlen($msg) + 4) . $msg);

        $scram = null;
        while (true) {
            list($t, $p) = $this->readMsg();
            if ($t === 'E') throw new Exception($this->parseError($p));
            if ($t === 'Z') return;
            if ($t !== 'R') continue;
            $code = $this->u32(substr($p, 0, 4));
            if ($code === 0) continue;                       // AuthenticationOk
            if ($code === 3) {                               // cleartext
                $this->send('p', $pass . "\0");
            } elseif ($code === 5) {                         // md5
                $salt = substr($p, 4, 4);
                $this->send('p', 'md5' . md5(md5($pass . $user) . $salt) . "\0");
            } elseif ($code === 10) {                        // SASL: start SCRAM
                if (strpos($p, 'SCRAM-SHA-256') === false) throw new Exception('Server needs an unsupported SASL mechanism.');
                $nonce = base64_encode($this->randomBytes(18));
                $bare  = 'n=,r=' . $nonce;
                $first = 'n,,' . $bare;
                $this->send('p', "SCRAM-SHA-256\0" . pack('N', strlen($first)) . $first);
                $scram = array('nonce' => $nonce, 'bare' => $bare);
            } elseif ($code === 11 && $scram) {              // SASL continue
                $serverFirst = substr($p, 4);
                $kv = array();
                foreach (explode(',', $serverFirst) as $part) $kv[$part[0]] = substr($part, 2);
                if (strpos($kv['r'], $scram['nonce']) !== 0) throw new Exception('SCRAM nonce mismatch.');
                $salted  = $this->pbkdf2($pass, base64_decode($kv['s']), (int)$kv['i']);
                $cKey    = hash_hmac('sha256', 'Client Key', $salted, true);
                $stored  = hash('sha256', $cKey, true);
                $noProof = 'c=biws,r=' . $kv['r'];
                $auth    = $scram['bare'] . ',' . $serverFirst . ',' . $noProof;
                $sig     = hash_hmac('sha256', $auth, $stored, true);
                $proof   = base64_encode($cKey ^ $sig);
                $scram['server'] = base64_encode(hash_hmac('sha256', $auth,
                                   hash_hmac('sha256', 'Server Key', $salted, true), true));
                $this->send('p', $noProof . ',p=' . $proof);
            } elseif ($code === 12 && $scram) {              // SASL final
                if (substr($p, 4) !== 'v=' . $scram['server']) throw new Exception('SCRAM server signature mismatch.');
            } else {
                throw new Exception("Unsupported PostgreSQL authentication method ($code).");
            }
        }
    }

    private function randomBytes($n)
    {
        if (function_exists('random_bytes')) return random_bytes($n);
        if (function_exists('openssl_random_pseudo_bytes')) return openssl_random_pseudo_bytes($n);
        $b = ''; for ($i = 0; $i < $n; $i++) $b .= chr(mt_rand(0, 255));
        return $b;
    }

    private function pbkdf2($pass, $salt, $iter)
    {
        if (function_exists('hash_pbkdf2')) return hash_pbkdf2('sha256', $pass, $salt, $iter, 32, true);
        $u = hash_hmac('sha256', $salt . pack('N', 1), $pass, true);
        $r = $u;
        for ($i = 1; $i < $iter; $i++) { $u = hash_hmac('sha256', $u, $pass, true); $r ^= $u; }
        return $r;
    }

    /* ------------------------------ queries ------------------------------ */
    /** Run ONE sql statement. Returns array of assoc rows (all values are strings / null). */
    public function query($sql)
    {
        $this->send('Q', $sql . "\0");
        $cols = array(); $rows = array(); $err = null; $tag = '';
        while (true) {
            list($t, $p) = $this->readMsg();
            if ($t === 'T') {
                $n = $this->u16(substr($p, 0, 2)); $pos = 2; $cols = array();
                for ($i = 0; $i < $n; $i++) {
                    $end = strpos($p, "\0", $pos);
                    $cols[] = substr($p, $pos, $end - $pos);
                    $pos = $end + 1 + 18;
                }
            } elseif ($t === 'D') {
                $n = $this->u16(substr($p, 0, 2)); $pos = 2; $row = array();
                for ($i = 0; $i < $n; $i++) {
                    $len = $this->u32(substr($p, $pos, 4)); $pos += 4;
                    if ($len < 0 || $len == 4294967295) { $row[$cols[$i]] = null; }
                    else { $row[$cols[$i]] = substr($p, $pos, $len); $pos += $len; }
                }
                $rows[] = $row;
            } elseif ($t === 'C') {
                $tag = rtrim($p, "\0");
            } elseif ($t === 'E') {
                $err = $this->parseError($p);
            } elseif ($t === 'Z') {
                if ($err !== null) throw new Exception($err);
                $this->tag = $tag;
                return $rows;
            }
        }
    }

    /** Rows affected by the last INSERT/UPDATE/DELETE. */
    public function affected()
    {
        return preg_match('/(\d+)$/', $this->tag, $m) ? (int)$m[1] : 0;
    }

    public function quote($v)
    {
        if ($v === null) return 'NULL';
        if (is_bool($v))  return $v ? 'TRUE' : 'FALSE';
        if (is_int($v))   return (string)$v;
        if (is_float($v)) return sprintf('%.14F', $v);
        return "'" . str_replace("'", "''", str_replace("\0", '', (string)$v)) . "'";
    }

    /** Replace :named placeholders (skips 'strings' and ::casts). */
    public function bind($sql, $params)
    {
        $out = ''; $n = strlen($sql); $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            if ($c === "'") {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === "'") {
                        if ($j + 1 < $n && $sql[$j + 1] === "'") { $j += 2; continue; }
                        break;
                    }
                    $j++;
                }
                $out .= substr($sql, $i, $j - $i + 1); $i = $j + 1; continue;
            }
            if ($c === ':') {
                if ($i + 1 < $n && $sql[$i + 1] === ':') { $out .= '::'; $i += 2; continue; }
                if (preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $m, 0, $i)) {
                    $key = ':' . $m[1];
                    if (!array_key_exists($key, $params)) throw new Exception("Missing parameter $key");
                    $out .= $this->quote($params[$key]); $i += strlen($m[0]); continue;
                }
            }
            $out .= $c; $i++;
        }
        return $out;
    }
}
