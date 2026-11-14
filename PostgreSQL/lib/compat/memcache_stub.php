<?php
/**
 * Stand-in for the PHP "Memcache" class. Only loaded when the real memcache
 * extension is NOT installed, so phppgsql.php (unmodified) can still run.
 * It never caches anything (get() always misses), so data is always fresh.
 */
class Memcache
{
    public function connect($host, $port = 11211) { return true; }
    public function get($key)                     { return false; }
    public function add($key, $v, $flag = 0, $t = 0) { return true; }
    public function set($key, $v, $flag = 0, $t = 0) { return true; }
    public function delete($key)                  { return true; }
}
