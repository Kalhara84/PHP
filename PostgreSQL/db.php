<?php

function db_load_library()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    // the library reads these as GLOBAL variables
    global $PHPPGSQL_DB_CONNECTION, $PHPPGSQL_DB_CACHE_TIMEOUT, $PHPPGSQL_MEMCACHED_SERVER,
           $PHPPGSQL_MEMCACHED_PORT, $PHPPGSQL_ENCODING, $phppgsql_db, $phppgsql_mm, $phppgsql_preps;

    // Database settings used by the existing library.
    $PHPPGSQL_DB_CONNECTION = "host=127.0.0.1 port=5432 dbname=php_Test user=postgres password=mypass";
    $PHPPGSQL_DB_CACHE_TIMEOUT = 1;
    $PHPPGSQL_MEMCACHED_SERVER = 'localhost';
    $PHPPGSQL_MEMCACHED_PORT = 11211;
    $PHPPGSQL_ENCODING = 'UTF-8';

    if (!function_exists('pg_pconnect')) {
        require_once __DIR__ . '/lib/compat/pgsql_polyfill.php';
    }
    if (!class_exists('Memcache')) {
        require_once __DIR__ . '/lib/compat/memcache_stub.php';
    }

    // give a readable error instead of a fatal one when the DB is unreachable
    $test = @pg_connect($PHPPGSQL_DB_CONNECTION);
    if (!$test) {
        $err = trim((string)pg_last_error());
        throw new Exception('Could not connect to PostgreSQL' . ($err !== '' ? ': ' . $err : '.'));
    }
    pg_close($test);

    // load the library quietly (it is old code: notices / deprecations on newer PHP)
    $old = error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT);
    try {
        require_once dirname(__FILE__) . '/lib/phppgsql-r1/phppgsql.php';
    } finally {
        error_reporting($old);
    }
    $loaded = true;
}

// SQL uses $1, $2, ... for values. Keep values separate from the SQL.
// Example: db_query('SELECT * FROM cusmast WHERE cuscus = $1', array('100'));
function db_query($sql, $params = array())
{
    db_load_library();

    // The existing library needs this for compatibility with newer PHP versions.
    $old = error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT);
    try {
        // "..." passes each array value as a separate library argument.
        // NO_CACHE makes changes visible immediately after saving.
        $query = new PHPPgSQL(PHPPgSQL::NO_CACHE, trim($sql), ...$params);
    } finally {
        error_reporting($old);
    }
    return $query;
}

/** Run a SELECT, return all rows (array of assoc arrays). */
function db_all($sql, $params = array())
{
    $q = db_query($sql, $params);
    $rows = $q->getAll();
    return is_array($rows) ? $rows : array();
}

/** First column of the first row (or null). */
function db_val($sql, $params = array())
{
    $rows = db_all($sql, $params);
    if (!$rows) {
        return null;
    }
    $row = $rows[0];
    return reset($row);
}

/** Run INSERT/UPDATE/DELETE, return number of affected rows. */
function db_exec($sql, $params = array())
{
    $q = db_query($sql, $params);
    return (int)$q->reccount;
}

/** Same rule as the old format_string(): quotes -> backtick, then trim. */
function clean_text($s)
{
    return trim(str_replace(array("'", '"'), '`', (string)$s));
}

/** Next numeric customer code (old next_code()). */
function next_customer_code()
{
    $sql = "SELECT COALESCE(MAX(cuscus::bigint), 0) + 1
            FROM cusmast WHERE cuscus ~ '^[0-9]{1,15}$'";
    return (string)db_val($sql);
}

/** Dropdown data: $rtp 16 = Category, 410 = Credit Terms. */
function get_sys_options($rtp)
{
    return db_all("SELECT syssno, sysdes FROM sysmast WHERE sysrtp = $1 ORDER BY sysrno",
                  array((int)$rtp));
}

function get_reps()
{
    return db_all("SELECT repsno, repnam FROM repmast ORDER BY repnam");
}

/** Search + pagination for the list. Returns [rows, total]. */
function search_customers($q, $limit, $offset)
{
    // An empty search shows all rows; otherwise match the entered text.
    $where = "WHERE ($1 = '' OR c.cuscus ILIKE $2 OR c.cusnam ILIKE $2
              OR c.custelno ILIKE $2 OR c.cusemail ILIKE $2 OR c.cusad1 ILIKE $2)";
    $params = array($q, '%' . $q . '%');
    $total = (int)db_val("SELECT COUNT(*) FROM cusmast c $where", $params);

    $sql = "SELECT c.*, s.sysdes AS catname, t.sysdes AS termname, r.repnam
            FROM cusmast c
            LEFT JOIN sysmast s ON s.syssno = c.cuscatsno
            LEFT JOIN sysmast t ON t.syssno = c.cuscredittmssno
            LEFT JOIN repmast r ON r.repsno = c.cusrepsno
            $where
            ORDER BY CASE WHEN c.cuscus ~ '^[0-9]+$' THEN 0 ELSE 1 END,
                     CASE WHEN c.cuscus ~ '^[0-9]{1,15}$' THEN c.cuscus::bigint END,
                     c.cuscus
            LIMIT $3 OFFSET $4";
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    return array(db_all($sql, $params), $total);
}

function customer_exists($code)
{
    return db_val("SELECT 1 FROM cusmast WHERE cuscus = $1", array($code)) !== null;
}

// Read one form field. Missing fields become empty strings.
function form_text($form, $name)
{
    if (isset($form[$name]) && is_string($form[$name])) {
        return trim($form[$name]);
    }
    return '';
}

function form_integer($form, $name)
{
    $value = form_text($form, $name);
    if (ctype_digit($value)) {
        return (int)$value;
    }
    return 0;
}

// Read each field explicitly so it is easy to see what the form saves.
function normalise_customer($form)
{
    $customer = array();
    $customer['cusnam'] = form_text($form, 'cusnam');
    $customer['cuscatsno'] = form_integer($form, 'cuscatsno');
    $customer['cuscredittmssno'] = form_integer($form, 'cuscredittmssno');
    $customer['cuscreditlimit'] = form_text($form, 'cuscreditlimit');
    $customer['cusadd'] = form_text($form, 'cusadd');
    $customer['cusad1'] = form_text($form, 'cusad1');
    $customer['cusad2'] = form_text($form, 'cusad2');
    $customer['cusad3'] = form_text($form, 'cusad3');
    $customer['cusad4'] = form_text($form, 'cusad4');
    $customer['cusad5'] = form_text($form, 'cusad5');
    $customer['custelno'] = form_text($form, 'custelno');
    $customer['cusfaxno'] = form_text($form, 'cusfaxno');
    $customer['cusemail'] = form_text($form, 'cusemail');
    $customer['custinno'] = form_text($form, 'custinno');
    $customer['cusvatno'] = form_text($form, 'cusvatno');
    $customer['cussvatno'] = form_text($form, 'cussvatno');
    $customer['cusactyn'] = form_text($form, 'cusactyn');
    $customer['cusrepsno'] = form_integer($form, 'cusrepsno');

    $customer['cusnam'] = clean_text($customer['cusnam']);
    $customer['cusactyn'] = strtoupper(substr($customer['cusactyn'], 0, 1));
    if (!is_numeric($customer['cuscreditlimit'])) {
        $customer['cuscreditlimit'] = 0;
    }
    return $customer;
}

// Values must follow the same order as $1, $2, ... in the SQL.
function insert_customer($code, $customer, $user)
{
    $sql = "INSERT INTO cusmast (
                cuscus, cusnam, cuscatsno, cuscredittmssno, cuscreditlimit,
                cusadd, cusad1, cusad2, cusad3, cusad4, cusad5,
                custelno, cusfaxno, cusemail, custinno, cusvatno, cussvatno, cusactyn, cusrepsno,
                cusucdnew, cusentddt, cusenttime
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8, $9, $10,
                $11, $12, $13, $14, $15, $16, $17, $18, $19, $20,
                CURRENT_DATE, to_char(now(), 'HH24:MI:SS')
            )";
    $values = array(
        $code,
        $customer['cusnam'],
        $customer['cuscatsno'],
        $customer['cuscredittmssno'],
        $customer['cuscreditlimit'],
        $customer['cusadd'],
        $customer['cusad1'],
        $customer['cusad2'],
        $customer['cusad3'],
        $customer['cusad4'],
        $customer['cusad5'],
        $customer['custelno'],
        $customer['cusfaxno'],
        $customer['cusemail'],
        $customer['custinno'],
        $customer['cusvatno'],
        $customer['cussvatno'],
        $customer['cusactyn'],
        $customer['cusrepsno'],
        $user
    );
    db_exec($sql, $values);
}

function update_customer($code, $customer, $user)
{
    $sql = "UPDATE cusmast SET
                cusnam = $1,
                cuscatsno = $2,
                cuscredittmssno = $3,
                cuscreditlimit = $4,
                cusadd = $5,
                cusad1 = $6,
                cusad2 = $7,
                cusad3 = $8,
                cusad4 = $9,
                cusad5 = $10,
                custelno = $11,
                cusfaxno = $12,
                cusemail = $13,
                custinno = $14,
                cusvatno = $15,
                cussvatno = $16,
                cusactyn = $17,
                cusrepsno = $18,
                cusucdame = $19, cusameddt = CURRENT_DATE,
                cusametime = to_char(now(), 'HH24:MI:SS')
            WHERE cuscus = $20";
    $values = array(
        $customer['cusnam'],
        $customer['cuscatsno'],
        $customer['cuscredittmssno'],
        $customer['cuscreditlimit'],
        $customer['cusadd'],
        $customer['cusad1'],
        $customer['cusad2'],
        $customer['cusad3'],
        $customer['cusad4'],
        $customer['cusad5'],
        $customer['custelno'],
        $customer['cusfaxno'],
        $customer['cusemail'],
        $customer['custinno'],
        $customer['cusvatno'],
        $customer['cussvatno'],
        $customer['cusactyn'],
        $customer['cusrepsno'],
        $user,
        $code
    );
    db_exec($sql, $values);
}

function delete_customer($code)
{
    return db_exec('DELETE FROM cusmast WHERE cuscus = $1', array($code));
}
