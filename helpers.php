<?php
/**
 * @const string
 */
define('DATE_FORMAT', 'Y-m-d H:i:s');

/**
 * @const string
 */
define('NL', "\n");

/**
 * @const string
 */
define('SL', "<br />\n");

/**
 * @const string
 */
define('SEP', ' | ');


/**
 * Get the trace of the call, then return the functions called.
 * @return array
 */
function get_functions() {
    $stack = debug_backtrace();
    $calls = array();
    foreach($stack as $array) {
        $file = isset($array['file']) ? $array['file'].':' : '';
        $line = isset($array['line']) ? $array['line'] : 0;
        $func = isset($array['function']) ? $array['function'] : '';
        $msg = is_string_ne($file) ? $file.$line.' => '.$func : is_string_ne($func) ? $func : 'unknown stack item';
        $calls[] = $msg;
    }
    return $calls;
}


/**
 * Get remote IP address from user, whether forwarded or not.
 * @return string
 */
function get_remote_ip() {
    $forwarded_for = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
    $remote_addr = $_SERVER['REMOTE_ADDR'];

    $ip = (!empty($forwarded_for)) ? $forwarded_for : $remote_addr;
    return $ip;
}


/**
 * Verify an array is associative or not by making sure at least one key is a string
 * @param array $array
 * @return boolean
 */
function is_array_assoc($array) {
    $assoc = false;
    if (is_array_ne($array)) {
        $keys = array_keys($array);
        if (is_array($keys) && !empty($keys)) {
            foreach($keys as $key) {
                if (is_string($key)) {
                    $assoc = true;
                    break;
                }
            }
        }
    }
    return $assoc;
}


/**
 * Checks for valid non-empty array
 * @param array $array Array of values
 * @return bool The validity of the input
 */
function is_array_ne($array) {
    return !empty($array) && is_array($array);
}


/**
 * Determine if the variable contains a non-empty string
 * @param string $str
 * @return boolean
 */
function is_string_ne($str) {
    return ((is_string($str) && !empty($str)) || $str === '0');
}


/**
 * Determine if the contents of the given variable is an integer
 * @param mixed $int
 * @return boolean
 */
function is_this_int($int) {
    return is_int($int) || (is_numeric($int) && $int + 0 == (int)$int);
}


