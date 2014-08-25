<?php
/**
 * @category Database
 * @package Log
 * @author Mike Rodarte
 * @version 1.0
 */

/**
 * Start the session, just in case.
 */
@session_start();

/**
 * Helper file for various functions and configuration
 */
require_once 'helpers.php';

/**
 * Write messages to the file system
 * @author Mike Rodarte
 *
 */
class Log {
    /**
     * Full path to log file
     * @var string
     */
    private $_logFile = '';

    /**
     * @var bool
     */
    private $_debug = false;

    /**
     * Current PHP file who called this object
     * @var string
     */
    private $_file = '';
    /**
     * Current PHP function
     * @var string
     */
    private $_function = '';
    /**
     * Acceptable log level (0 = All, 1 = Debug, 2 = Warning, 3 = Error)
     * @var integer
     */
    private $_level = 3;
    /**
     * Array of logged messages
     * @var array
     */
    private $_messages = array();
    /**
     * Last set log message
     * @var string
     */
    private $_msg = '';
    /**
     * Array of session messages
     */
    private $_sessionMessages = array();


    /**
     * @param string $log_file Log file path and name
     * @param int $log_level Logging level
     * @param string $file The file currently being used
     */
    public function __construct() {
        $args = func_get_args();
        if (is_array_ne($args)) {
            if (isset($args[0]) && is_string_ne($args[0])) {
                $this->logFile($args[0]);
                if (isset($args[1]) && is_this_int($args[1])) {
                    $this->level($args[1]);
                    if (isset($args[2]) && is_string_ne($args[2])) {
                        $this->file($args[2]);
                    }
                }
            }
        }
    }


    public function __destruct() {
        unset($this->_logFile);
        unset($this->_function);
        unset($this->_level);
        unset($this->_messages);
        unset($this->_msg);
    }


    /**
     * Write the provided message to the log file if the log level matches
     * @param string $msg
     * @param integer $log_level
     * @param array $params
     * @return boolean|number
     */
    public function write($msg, $log_level = 0, $params = array()) {
        // check the file
        $valid_file = $this->_checkFile();
        if (!$valid_file) {
            trigger_error('File is not a valid file', E_USER_WARNING);
            return false;
        }
        // make sure this log message is at the appropriate level for logging
        if ($log_level < $this->_level) {
            return false;
        }

        // get date and time
        $log_msg = date(DATE_FORMAT).SEP;

        $log_msg .= $this->_getIdentifiers();

        // add message
        $log_msg .= $msg.SEP;

        // add parameters
        $log_msg .= $this->_paramsToString($params);

        $this->_messages[] = $log_msg;
        $this->_msg = $log_msg;
        
        if ($this->_debug && function_exists('get_functions')) {
            $stack = get_functions();
            $log_msg .= NL.implode(NL, $stack);
        }

        $bytes = file_put_contents($this->_logFile, $log_msg.NL, FILE_APPEND);

        return $bytes;
    }


    /**
     * Read messages from the log file for this session and return them as a string.
     * @return string
     */
    public function read() {
        // get the current session id
        $session_id = session_id();
        // only continue if there is a valid string session id
        if (!is_string_ne($session_id)) {
            return '';
        }
        // read the log file
        $lines = file($this->_logFile);
        // only continue if there are lines in the file
        if (!is_array_ne($lines)) {
            return '';
        }
        // reset sessionMessages
        $this->_sessionMessages = array();
        // get the identifier that goes in each line
        $identifier = $this->_getIdentifiers();
        // loop through each line
        foreach($lines as $line) {
            // use the line that contains this session id
            if (strstr($line, SEP.$session_id.SEP)) {
                // add the line, without the identifier, to the array for return
                $this->_sessionMessages[] = str_replace($identifier, '', $line);
            }
        }

        // send the log messages back as a string
        return implode(NL, $this->_sessionMessages);
    }


    /**
     * Clear all values except for file name
     */
    public function reset() {
        $this->_file = '';
        $this->_function = '';
        $this->_level = 3;
        $this->_messages = array();
        $this->_msg = '';
        $this->_sessionMessages = array();
    }


    // getters and setters
    /**
     * Getter or setter for debug
     * @return bool
     */
    public function debug() {
        $args = func_get_args();
        if (is_array_ne($args) && isset($args[0]) && is_bool($args[0])) {
            $this->_debug = !!$args[0];
        }
        return $this->_debug;
    }


    /**
     * Getter or setter for current file
     * @return string
     */
    public function file() {
        $args = func_get_args();
        if (is_array_ne($args) && isset($args[0]) && is_string_ne($args[0])) {
            $this->_file = $args[0];
        }
        return $this->_file;
    }


    /**
     * Getter or setter for function
     * @return string
     */
    public function func() {
        $args = func_get_args();
        if (is_array_ne($args) && isset($args[0]) && is_string_ne($args[0])) {
            $this->_function = $args[0];
        }
        return $this->_function;
    }


    /**
     * Getter or setter for log level
     * @return integer
     */
    public function level() {
        $args = func_get_args();
        if (is_array_ne($args) && isset($args[0]) && is_this_int($args[0])) {
            $this->_level = $args[0];
        }
        return $this->_level;
    }


    /**
     * Getter or setter for log file
     * @return string
     */
    public function logFile() {
        $args = func_get_args();
        if (is_array_ne($args) && isset($args[0]) && is_string_ne($args[0])) {
            $this->_logFile = $args[0];
        }
        return $this->_logFile;
    }


    /**
     * Getter for messages logged in ascending order
     * @return array
     */
    public function messages() {
        return $this->_messages;
    }


    /**
     * Getter for last log message
     * @return string
     */
    public function message() {
        return $this->_msg;
    }


    /**
     * Alias for message
     * @return string
     * @uses Log::message()
     */
    public function msg() {
        return $this->message();
    }


    /**
     * Check for valid log file
     * @return boolean
     */
    private function _checkFile() {
        // make sure _fileName is a string
        if (!is_string_ne($this->_logFile)) {
            return false;
        }
        // make sure the file exists
        touch($this->_logFile);
        // check for valid file
        if (!is_file($this->_logFile) || !file_exists($this->_logFile) || !is_writable($this->_logFile)) {
            return false;
        }

        return true;
    }


    private function _getIdentifiers() {
        // get page
        $log_msg = $_SERVER['REQUEST_URI'].SEP;

        $log_msg .= getmypid().SEP;

        // get session ID
        $session_id = @session_id();
        if (is_string_ne($session_id)) {
            $log_msg .= $session_id.SEP;
        }

        // get IP address
        $log_msg .= get_remote_ip().SEP;

        if (is_string_ne($this->_file)) {
            $log_msg .= $this->_file.': ';
        }

        if (is_string_ne($this->_function)) {
            $log_msg .= $this->_function.': ';
        }

        return $log_msg;
    }


    /**
     * Convert associative array into a string, using recursion when necessary
     * @param array $params
     * @return string
     */
    private function _paramsToString($params) {
        if (!is_array_ne($params)) {
            return '';
        }
        $str = '';
        foreach($params as $k => $v) {
            if (is_string_ne($v)) {
                $str .= $k . ': '.$v.SEP;
            } else if (is_array_ne($v)) {
                $str .= 'Array ('.$this->_paramsToString($v).')'.SEP;
            }
        }
        return $str;
    }


}
?>
