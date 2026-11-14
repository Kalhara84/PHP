<?php
/**
 * Userland replacement for the few pg_* functions that phppgsql.php uses.
 * Only loaded when the PHP "pgsql" extension is NOT enabled. Uses PgClient
 * (pure-PHP PostgreSQL protocol), so no extension is needed.
 */
require_once dirname(__FILE__) . '/PgClient.php';

class PgPolyConn   { public $client; public $preps = array(); public $error = ''; }
class PgPolyResult { public $rows = array(); public $affected = 0; }

function _pgpoly_bind($sql, $params, $client)
{
    $out = ''; $n = strlen($sql); $i = 0;
    while ($i < $n) {
        $c = $sql[$i];
        if ($c === "'") {                       // copy string literals untouched
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
        if ($c === '$' && preg_match('/\G\$(\d+)/', $sql, $m, 0, $i)) {
            $idx = (int)$m[1] - 1;
            $out .= $client->quote(isset($params[$idx]) ? $params[$idx] : null);
            $i += strlen($m[0]); continue;
        }
        $out .= $c; $i++;
    }
    return $out;
}

if (!function_exists('pg_connect')) {
    function pg_connect($connstr)
    {
        $o = array('host' => 'localhost', 'port' => '5432', 'dbname' => '', 'user' => '', 'password' => '');
        preg_match_all("/(\\w+)\\s*=\\s*('(?:[^'\\\\\\\\]|\\\\\\\\.)*'|\\S*)/", $connstr, $m, PREG_SET_ORDER);
        foreach ($m as $kv) {
            $v = $kv[2];
            if ($v !== '' && $v[0] === "'") $v = preg_replace('/\\\\(.)/', '$1', substr($v, 1, -1));
            $o[$kv[1]] = $v;
        }
        try {
            $c = new PgPolyConn();
            $c->client = new PgClient($o['host'], $o['port'], $o['dbname'], $o['user'], $o['password']);
            return $c;
        } catch (Exception $e) {
            $GLOBALS['__pgpoly_err'] = 'ERROR:  ' . $e->getMessage();
            return false;
        }
    }
}
if (!function_exists('pg_pconnect'))          { function pg_pconnect($s) { return pg_connect($s); } }
if (!function_exists('pg_close'))             { function pg_close($c = null) { return true; } }
if (!function_exists('pg_set_client_encoding')) { function pg_set_client_encoding($c, $e = null) { return 0; } }
if (!function_exists('pg_last_error')) {
    function pg_last_error($c = null)
    {
        if ($c instanceof PgPolyConn) return $c->error;
        return isset($GLOBALS['__pgpoly_err']) ? $GLOBALS['__pgpoly_err'] : '';
    }
}
if (!function_exists('pg_prepare'))  { function pg_prepare($c, $name, $sql) { $c->preps[$name] = $sql; return true; } }
if (!function_exists('pg_execute')) {
    function pg_execute($c, $name, $params = array())
    {
        if (!isset($c->preps[$name])) { $c->error = 'ERROR:  prepared statement "' . $name . '" does not exist'; return false; }
        return pg_query($c, _pgpoly_bind($c->preps[$name], $params, $c->client));
    }
}
if (!function_exists('pg_query')) {
    function pg_query($c, $sql = null)
    {
        try {
            $r = new PgPolyResult();
            $r->rows = $c->client->query($sql);
            $r->affected = $c->client->affected();
            return $r;
        } catch (Exception $e) {
            $c->error = 'ERROR:  ' . $e->getMessage();
            return false;
        }
    }
}
if (!function_exists('pg_num_rows'))      { function pg_num_rows($r) { return count($r->rows); } }
if (!function_exists('pg_affected_rows')) { function pg_affected_rows($r) { return $r->affected; } }
if (!function_exists('pg_free_result'))   { function pg_free_result($r) { return true; } }
if (!function_exists('pg_fetch_all'))     { function pg_fetch_all($r) { return $r->rows; } }
if (!function_exists('pg_fetch_row')) {
    function pg_fetch_row($r)
    {
        if (!$r->rows) return false;
        $row = $r->rows[0];
        return array_values($row);
    }
}
