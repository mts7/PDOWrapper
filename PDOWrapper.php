<?php
/**
 * @category Database
 * @package PDOWrapper
 * @author Mike Rodarte
 * @todo Finish documenting with PHPDoc
 * @version 3.0
 *
 * USAGE:
 * // Execute select statement for default MySQL database
 * $myquery = new PDOWrapper('mysql');
 * $myquery->logLevel(2); // set log level to show warnings and errors
 * $query = 'SELECT * FROM table_name WHERE id = ?';
 * $rows = $myquery->select($query, 15);
 * // do something with array $rows
 *
 * // Execute select statement with different database
 * $myquery = new PDOWrapper();
 * $myquery->set_db->('database_name');
 * $myquery->begin();
 * $rows = $myquery->select('SELECT * FROM this_table WHERE id IN (?, ?)', array(15, 17));
 *
 * // Execute insert with different data source
 * $pgquery = new PDOWrapper('pgsql', 'localhost', 'pg_user', 'pg_pass', 'pg_db');
 * $pgquery->logLevel(0); // write all log messages to log file
 * $insert_id = $pgquery->insert('pg_table', array('field' => $value));
 * if (is_bool($insert_id) && !$insert_id) { echo $pgquery->lastError(); }
 */

require_once 'helpers.php';
require_once 'Log.php';


/**
 * @package PDOWrapper
 * @version 0.20110209
 * @since 0.20101122
 */
class PDOWrapper {
    // connection members
    /**
     * @var PDO
     */
    private $_dbh = '';
    private $_server = 'mysql_host';
    private $_db = 'mysql_database';
    private $_user = 'mysql_user';
    private $_pass = 'mysql_password';
    private $_dsn = 'mysql';
    private $_driver = '';
    private $_errorMode = PDO::ERRMODE_SILENT;

    // query members
    private $_q = '';
    /**
     * @var PDOStatement
     */
    private $_stmt = '';
    private $_fieldDelim = '`';
    private $_numFields = 0;
    private $_affected = 0;
    private $_fetchMode = PDO::FETCH_ASSOC;
    private $_bindArray = array();
    private $_where = '';

    // log members
    private $_debug = false;
    private $_errors = array();
    private $_logFile = 'queries.log';
    /**
     * @var Log
     */
    private $_logger = false;
    private $_logLevel = 3;

    // static arrays
    private $_data_sources = array(
        'MySQL' => 'mysql',
        'PostgreSQL' => 'pgsql',
        'SQLite' => 'sqlite',
        'IBM' => 'ibm',
        'ODBC' => 'odbc',
        'FreeTDS' => 'sybase',
        'Microsoft_SQL_Server' => 'mssql',
        'DB-lib' => 'dblib'
    );

    private $_bad = array(
        'INDEX',
        'CREATE TEMPORARY TABLES',
        /*'CREATE',*/
        'ALTER',
        /*'DROP',*/
        'LOCK TABLES',
        'REFERENCES',
        'CREATE ROUTINE' );

    private $_logLevels = array(
        0 => 'All',
        1 => 'Debug',
        2 => 'Warning',
        3 => 'Fatal'
    );

    /**
     * Create Query object with specified optional parameters
     * @param mixed $dsn String with database type or Array with key value pairs
     * @param string $server Server address or name
     * @param string $user Database user
     * @param string $pass Database password
     * @param string $db Database
     * @param string $fetch_mode assoc|numeric|both
     */
    public function __construct($dsn = '', $server = '', $user = '', $pass = '', $db = '', $fetch_mode = '') {
        $this->_setLogger();
        $this->_log('begin '.__FUNCTION__, 0);
        if (is_string_ne($dsn)) {
            $this->_setDataSource($dsn);
        } else if (is_array_ne($dsn)) {
            $this->_log(__FUNCTION__.': array of values provided', 1);
            extract($dsn);
        }
        if (is_string_ne($server)) {
            $this->_server =$server;
        }
        if (is_string_ne($user)) {
            $this->_user = $user;
        }
        if (is_string_ne($pass)) {
            $this->_pass = $pass;
        }
        if (is_string_ne($db)) {
            $this->database($db);
        }
        if (is_string_ne($fetch_mode)) {
            $this->_fetchMode = $fetch_mode;
        }
        if (isset($log_level) && is_this_int($log_level)) {
            $this->logLevel($log_level);
        }
        $this->begin();
        $this->_log('end '.__FUNCTION__, 0);
    }


    public function __destruct() {
        $this->_dbh = null;
    }


    /**
     * begin makes the initial PDO database connection and returns true on success or false on failure
     * @return boolean did the connection get established
     **/
    public function begin() {
        $this->_log('begin '.__FUNCTION__, 0);

        $connection_string = $this->_dsn.':';
        if (is_string_ne($this->_driver) && ($this->_dsn == 'ibm' || $this->_dsn == 'odbc')) {
            $connection_string .= 'driver='.$this->_driver.';';
        }
        $connection_string .= 'host='.$this->_server.';dbname='.$this->_db;
        $this->_log(__FUNCTION__.': '.$connection_string, 1);

        try {
            $this->_dbh = new PDO($connection_string, $this->_user, $this->_pass);
            $this->_dbh->setAttribute(PDO::ATTR_ERRMODE, $this->_errorMode);
            $this->_dbh->setAttribute(PDO::ATTR_PERSISTENT, false);
            $this->_log(__FUNCTION__.': set dbh', 1);
        } catch (PDOException $e) {
            $this->_error($e, 3);
            exit($e->getMessage());
        }

        $this->_log('end '.__FUNCTION__, 0);
        return true;
    }


    /**
     * Run a SELECT query with the provided parameters.
     * @param string $q Query
     * @param array $params Parameters
     * @return array|bool
     */
    public function select($q = '', $params = array()) {
        set_error_handler('query_error_handler', E_ALL);

        $this->_log('begin '.__FUNCTION__, 0);
        $rows = false;
        $this->_clearQueryResults();

        try {
            // use the provided query if one has not already been set
            if (!is_string_ne($this->_q) && is_string_ne($q)) {
                $this->query($q);
                // use the provided parameters if they exist
                if (!empty($params)) {
                    $this->_params($params);
                }
            }
            $this->_log(__FUNCTION__, 1, array('query' => $this->_q, 'params' => $this->_bindArray));
            $this->_stmt = $this->_dbh->prepare($this->_q);
            if ($this->_stmt) {
                // execute the query with the bound parameters
                if ($this->_stmt->execute($this->_bindArray)) {
                    $rows = $this->_stmt->fetchAll($this->_fetchMode);
                    $num_rows = is_array($rows) ? count($rows) : -1;
                    $this->_log(__FUNCTION__.': successfully selected '.$num_rows.' rows', 1);
                } else {
                    $errors = $this->_stmt->errorInfo();
                    $this->_error($errors);
                }
            } else {
                $errors = $this->_dbh->errorInfo();
                $this->_error($errors);
            }
        } catch (PDOException $e) {
            $this->_error($e, 3);
        }
        $this->_clearQueryParams();

        $this->_log('end '.__FUNCTION__, 0);
        restore_error_handler();
        return $rows;
    }


    /**
     * Insert into the database, using the parameters provided or query and parameters already set.
     * @param string|array $table|$query|$params
     * @param array $key_values|$params
     * @return boolean
     * @uses PDOWrapper::_insertQuery()
     */
    public function insert() {
        set_error_handler('query_error_handler', E_ALL);

        $this->_log('begin '.__FUNCTION__, 0);
        $insert_id = false;
        $this->_clearQueryResults();

        $args = func_get_args();
        $this->_log(__FUNCTION__.' called with '.func_num_args().' arguments', 1);

        if (is_string_ne($args[0]) && is_array_ne($args[1])) {
            $params = $args[1];
            if (is_array_assoc($params)) {
                $this->_log(__FUNCTION__.': params is associative, so this is $table, $pairs', 1);
                $set = $this->_insertQuery($args[0], $params);
                if (!$set) {
                    $this->_log('could not create insert query and insert', 2);
                    return false;
                }
            } else {
                // these are just parameters, so we have a query and its parameters
                $this->_log(__FUNCTION__.': this is $query, $params', 1);
                $this->query($args[0]);
                $this->_params($params);
            }
        } else if (is_array_ne($args[0])) {
            $this->_log(__FUNCTION__.': setting parameters', 1);
            // this is actually an array of parameters to set
            $this->_params($args[0]);
        }

        try {
            $this->_stmt = $this->_dbh->prepare($this->_q);
            if ($this->_stmt) {
                $executed = $this->_stmt->execute($this->_bindArray);
                if ($executed) {
                    $insert_id = $this->_dbh->lastInsertId();
                    $this->_affected = $this->_stmt->rowCount();
                    // PostgreSQL needs a sequence for lastInsertId, so grab affected
                    if ($this->_dsn == 'pgsql') {
                        $insert_id = $this->_affected;
                    }
                    $this->_log(__FUNCTION__.': successfully inserted rows', 1,
                        array('affected' => $this->_affected, 'last_insert_id' => $insert_id));
                } else {
                    $this->_log(__FUNCTION__.': could not execute insert', 2,
                        array('query' => $this->_q, 'bindArray' => $this->_bindArray));
                    $errors = $this->_stmt->errorInfo();
                    if ($errors[0] == '00000' && $errors[1] == NULL && $errors[2] == NULL) {
                        $this->_log(__FUNCTION__.': unsure about stmt->errorInfo', 2);
                        $errors = $this->_dbh->errorInfo();
                    }
                    $this->_error($errors);
                }
            } else {
                $this->_log(__FUNCTION__.': could not prepare query', 2, array('query' => $this->_q));
                $errors = $this->_dbh->errorInfo();
                $this->_error($errors);
            }
        } catch (PDOException $e) {
            $this->_log(__FUNCTION__.': PDO Exception', 3);
            $this->_error($e, 3);
        }
        $this->_clearQueryParams();

        $this->_log('end '.__FUNCTION__, 0);
        restore_error_handler();
        return $insert_id;
    }


    /**
     * This updates the database with the provided parameters. The method accepts 8 different parameter lists.
     * 0 - use query and params already set
     * 1 - bool $limit
     * 1 - string $query
     * 2 - string $query, bool $limit
     * 2 - string $query, array $params
     * 3 - string $query, array $params, bool $limit
     * 3 - string $table, array $pairs, string|array $where
     * 4 - string $table, array $pairs, string|array $where, bool $limit
     * 4 - string $table, array $pairs, string|array $where, array $params
     * 5 - string $table, array $pairs, string|array $where, array $params, bool $limit
     * @return bool
     */
    public function update() {
        set_error_handler('query_error_handler', E_ALL);

        $this->_log('begin '.__FUNCTION__, 0);
        $updated = false;
        $this->_clearQueryResults();

        $limit = true;

        // this has 8 different method signatures
        $args = func_get_args();
        $num_args = func_num_args();
        switch($num_args) {
            case 1:
                if (is_bool($args[0])) {
                    $limit = $args[0];
                } else if (is_string_ne($args[0])) {
                    $this->query($args[0]);
                }
                break;
            case 2:
                if (is_string_ne($args[0]) && is_array_ne($args[1])) {
                    $this->query($args[0]);
                    $this->_params($args[1]);
                } else if (is_string_ne($args[0]) && is_bool($args[1])) {
                    $this->query($args[0]);
                    $limit = $args[1];
                }
                break;
            case 3:
                if (is_string_ne($args[0]) && is_array_ne($args[1])) {
                    if (is_bool($args[2])) {
                        $this->query($args[0]);
                        $this->_params($args[1]);
                        $limit = $args[2];
                    } else if (is_string_ne($args[2]) || is_array_ne($args[2])) {
                        $this->_updateQuery($args[0], $args[1], $args[2]);
                    }
                }
                break;
            case 4:
                if (is_string_ne($args[0]) && is_array_ne($args[1]) &&
                    (is_string_ne($args[2]) || is_array_ne($args[2]))) {
                    $this->_updateQuery($args[0], $args[1], $args[2]);
                    if (is_bool($args[3])) {
                        $limit = $args[3];
                    } else if (is_array_ne($args[3])) {
                        $this->_params($args[3]);
                    }
                }
                break;
            case 5:
                if (is_string_ne($args[0]) && is_array_ne($args[1]) &&
                    (is_string_ne($args[2]) || is_array_ne($args[2])) && is_array_ne($args[3]) && is_bool($args[4])) {
                    $this->_updateQuery($args[0], $args[1], $args[2]);
                    $this->_params($args[3]);
                    $limit = $args[4];
                }
                break;
        }

        if (!is_string_ne($this->_q)) {
            $this->_log('query is empty', 2);
            return false;
        }

        if ($this->_dsn == 'mysql' && $limit) {
            // check for presence of LIMIT 1 at the end of the query
            $this->_checkLimit();
            $this->_log(__FUNCTION__, 1, array('query' => $this->_q));
        }

        $this->_log(__FUNCTION__, 1, array('bind_array' => $this->_bindArray));

        try {
            $this->_stmt = $this->_dbh->prepare($this->_q);
            if ($this->_stmt) {
                if ($this->_stmt->execute($this->_bindArray)) {
                    $updated = true;
                    $this->_affected = $this->_stmt->rowCount();
                    $this->_log(__FUNCTION__.': successfully updated rows', 1, array('affected' => $this->_affected));
                } else {
                    $errors = $this->_stmt->errorInfo();
                    $this->_error($errors);
                }
            } else {
                $errors = $this->_dbh->errorInfo();
                $this->_error($errors);
            }
        } catch (PDOException $e) {
            $this->_error($e, 3);
        }
        $this->_clearQueryParams();

        $this->_log('end '.__FUNCTION__, 0);
        restore_error_handler();
        return $updated;
    }


    /**
     * Delete from the specified table
     * @param string $table database table
     * @param array $params Where (=) conditions
     * @param bool $limit check for limit
     * @return int Number of records deleted
     */
    public function delete($table = '', $params = array(), $limit = false) {
        set_error_handler('query_error_handler', E_ALL);

        $this->_log('begin '.__FUNCTION__, 0);
        $deleted = 0;
        $this->_clearQueryResults();

        // prepare query based on parameters
        if (!is_string_ne($this->_q) && is_string_ne($table)) {
            $q = 'DELETE FROM '.$table;
            if (is_array_ne($params)) {
                $q .= ' WHERE ';
                foreach($params as $field => $value) {
                    $q .= $field.' ';
                    if (is_array_ne($value)) {
                        $q .= 'IN (';
                        $q .= implode(', ', array_fill(0, count($value), '?'));
                        $q .= ')';
                        $this->_params(array_values($value));
                    } else {
                        $q .= '= ?';
                        $this->_params($value);
                    }
                    $q .= ' AND ';
                }
                $q = substr($q, 0, -5);
            }
            $set = $this->query($q);
            $this->_log('set delete query for '.$table.': '.$this->_q, 1);
        }

        if ($this->_dsn == 'mysql' && !!$limit) {
            // check for presence of LIMIT 1 at the end of the query
            $this->_checkLimit();
            $this->_log(__FUNCTION__, 1, array('query' => $this->_q));
        }

        $this->_log(__FUNCTION__, 1, array('bind_array' => $this->_bindArray));

        try {
            $this->_stmt = $this->_dbh->prepare($this->_q);
            if ($this->_stmt) {
                if ($this->_stmt->execute($this->_bindArray)) {
                    $this->_affected = $this->_stmt->rowCount();
                    $deleted = true;
                    $this->_log(__FUNCTION__.': successfully deleted rows', 1, array('affected' => $this->_affected));
                } else {
                    $errors = $this->_stmt->errorInfo();
                    $this->_error($errors);
                }
            } else {
                $errors = $this->_dbh->errorInfo();
                $this->_error($errors);
            }
        } catch (PDOException $e) {
            $this->_error($e, 3);
        }
        $this->_clearQueryParams();

        $this->_log('end '.__FUNCTION__, 0);
        restore_error_handler();
        return $deleted;
    }


    /**
     * Getter for affected rows.
     * @return int rows affected
     */
    public function affected() {
        return $this->_affected;
    }


    /**
     * Getter and setter for database name.
     * @param string $db database name
     * @return string database name
     */
    public function database() {
        $args = func_get_args();
        if (is_array_ne($args)) {
            $value = $args[0];
            if (is_string_ne($value)) {
                $this->_db = $value;
                return true;
            } else {
                return false;
            }
        } else {
            return $this->_db;
        }
    }


    /**
     * Get last error message to caller.
     * @return string Error message
     */
    public function lastError() {
        if (isset($this->_errors[0])) {
            return $this->_errors[0];
        } else {
            return '';
        }
    }


    /**
     * Getter and setter for log level.
     * @param int $level Log level to display
     * @return int Log level
     */
    public function logLevel() {
        $args = func_get_args();
        if (is_array_ne($args)) {
            $value = $args[0];
            if (!is_this_int($value)) {
                return false;
            }
            $this->_logLevel = $value;
            $this->_setLogger();
        } else {
            return $this->_logLevel;
        }
    }


    /**
     * Getter and setter for query.
     * @return boolean|string
     */
    public function query() {
        $args = func_get_args();
        if (is_array_ne($args) && isset($args[0])) {
            $value = $args[0];
            $check = isset($args[1]) ? !!$args[1] : true;
            $query = $this->_checkQueryWords($value, $check) == true ? $value : '';
            if (is_string_ne($query)) {
                $this->_q = $query;
                return true;
            } else {
                return false;
            }
        } else {
            return $this->_q;
        }
    }


    /**
     * Check for LIMIT in the query, and add LIMIT 1 if it's not there.
     */
    private function _checkLimit() {
        $this->_log('begin '.__FUNCTION__, 0);
        // check for LIMIT in $this->q
        if (is_string_ne($this->_q) && !strstr(strtoupper($this->_q), 'LIMIT')) {
            $pos_semi = strrpos($this->_q, ';');
            if ($pos_semi) {
                $this->_q = substr($this->_q, 0, $pos_semi - 1).' LIMIT 1;';
            }
            else {
                $this->_q .= ' LIMIT 1;';
            }
        }
        $this->_log('end '.__FUNCTION__, 0);
    }


    /**
     * Checks given input [query] for matching words in bad array
     * @return boolean
     * @uses Query::query
     * @uses Query::bad
     */
    private function _checkQueryWords() {
        $this->_log('begin '.__FUNCTION__, 0);

        $args = func_get_args();
        $input = isset($args[0]) ? $args[0] : '';
        $check = isset($args[1]) ? $args[1] : false;

        $result = 0;

        if ($check == true) {
            foreach($this->_bad as $word) {
                if (stristr(strtoupper($input), $word)) {
                    $this->_log('found bad word', 1, array('word' => $word));
                    $result--;
                }
            }

            if ($result < 0) {
                $this->_log('input: '.$input, 1);
            } else {
                $result = true;
            }
        } else if ($check == false) {
            $result = true;
        }

        $this->_log('end '.__FUNCTION__, 0);
        return $result;
    } // end check_query_words


    private function _clearQueryParams() {
        $this->_q = '';
        $this->_bindArray = array();
        $this->_where = '';
    }


    private function _clearQueryResults() {
        $this->_stmt = '';
        $this->_numFields = 0;
        $this->_affected = 0;
        $this->_errors = array();
    }


    private function _error($exception, $log_level = 2) {
        $msg = '';
        $trace = debug_backtrace(false);
        $last = $trace[1];
        $function = $last['function'];
        if (is_string_ne($function)) {
            $msg .= $function.'()'.SEP;
        }
        if (is_object($exception)) {
            if (method_exists($exception, 'getMessage')) {
                $msg .= $exception->getMessage();
            }
            if (method_exists($exception, 'getFile')) {
                $msg .= ' in '.$exception->getFile();
            }
            if (method_exists($exception, 'getLine')) {
                $msg .= ' on '.$exception->getLine();
            }
            if (method_exists($exception, 'getCode')) {
                $msg .= ' with code '.$exception->getCode();
            }
        } else if (is_array_ne($exception) && isset($exception[0]) && isset($exception[1]) && isset($exception[2])) {
            $msg .= 'SQLSTATE: '.$exception[0].SEP.'Code: '.$exception[1].SEP.'Message: '.$exception[2];
        }
        if (!is_string_ne($msg)) {
            $this->_log('Could not figure out exception', 2, array('exception' => $exception));
            return false;
        }

        // put this error message at the top of the errors array (to be pulled by lastError)
        array_unshift($this->_errors, $msg);
        $bytes = $this->_log('Error: '.$msg, $log_level);
        return $bytes;
    }


    /**
     * Prepare an INSERT query based on the table and an array of fields and values.
     * @param string $table Table name
     * @param string $params Array of key/value pairs used for inserting.
     * @return boolean
     */
    private function _insertQuery($table = '', $params = '') {
        $this->_log('begin '.__FUNCTION__, 0);
        if (!is_string_ne($table) || !is_array_ne($params)) {
            $this->_log(__FUNCTION__.': table is not valid or params is not valid', 2);
            return false;
        }
        $this->_clearQueryParams();
        $q = '';
        // create query based on keys and values from $params, then add $params to the bound parameters array
        if (is_array_assoc($params)) {
            $q .= "INSERT INTO $table (".implode(', ', array_map('scrub_input', array_keys($params))).') ';
            $vals = 'VALUES ('.implode(', ', array_fill(0, count($params), '?')).')';
            $this->_params($params);
        } else {
            $this->_log(__FUNCTION__.': params is not an associative array', 2);
        }

        // there could be some SQL injection at this point, so run through this basic filter first
        $q_set = $this->query($q.$vals);
        if (!$q_set) {
            $this->_log(__FUNCTION__.': could not set query', 2, array('query' => $q));
        } else {
            $this->_log(__FUNCTION__.': set query: '.$q_set, 1);
        }

        $this->_log('end '.__FUNCTION__, 0);
        return $q_set;
    }


    private function _log($msg, $level = 0, $params = array()) {
        return $this->_logger->write($msg, $level, $params);
    }


    /**
     * Sets the given value to the bind array
     * @param mixed $val
     * @return void
     * @uses Query::_checkQueryWords()
     * @uses Query::$bind_array
     */
    private function _params() {
        $this->_log('begin '.__FUNCTION__, 0);
        $set = true;
        $args = func_get_args();
        $num_args = count($args);
        if ($num_args == 0) {
            return false;
        } else if ($num_args == 1) {
            $val = $args[0];
            if (is_string($val) || is_int($val) || is_bool($val)) {
                if ($this->_checkQueryWords($val) === true) {
                    $this->_bindArray[] = $val;
                } else {
                    $set = false;
                }
            } else if (is_array($val)) {
                foreach($val as $value) {
                    $set = $this->_params($value);
                }
            }
        } else {
            foreach($args as $val) {
                $eset = $this->_params($val);
                if (!$eset) {
                    $set = $eset;
                }
            }
        }

        $this->_log('end '.__FUNCTION__, 0);
        return $set;
    }


    // set the PDO data source name for the class
    // be sure to call begin() after setting the data source name
    private function _setDataSource($dsn) {
        $this->_log('begin '.__FUNCTION__, 0);
        $dsn = strtolower($dsn);
        // check array of data source names to see if the given name exists
        if (in_array($dsn, $this->_data_sources)) {
            // set given name as member
            $this->_dsn = $dsn;
        }

        // the user can change these values or create constants in a config file
        switch ($this->_dsn) {
            case 'pgsql':
                $this->_server = 'pgsql_host';
                $this->_db = 'pgsql_database';
                $this->_user = 'pgsql_user';
                $this->_pass = 'pgsql_password';
                $this->_fieldDelim = '"';
                break;
            case 'ibm':
            case 'odbc':
                $this->_server = 'odbc_host';
                $this->_db = 'odbc_library';
                $this->_user = 'odbc_user';
                $this->_pass = 'odbc_passwod';
                $this->_driver = '{IBM DB2 ODBC DRIVER}';
                break;
            case 'mysql':
            default:
                $this->_server = 'mysql_host';
                $this->_db = 'mysql_database';
                $this->_user = 'mysql_user';
                $this->_pass = 'mysql_password';
                $this->_fieldDelim = '`';
                break;
        }
        $this->_log('end '.__FUNCTION__, 0);
    }


    private function _setLogger() {
        $this->_logger = new Log($this->_logFile, $this->_logLevel);
        $this->_logger->debug(true);
    }


    private function _updateQuery($table = '', $params = '', $where = '') {
        $this->_log('begin '.__FUNCTION__, 0);
        $this->_clearQueryParams();
        $q = '';
        if (!is_string_ne($table) || !is_array_ne($params)) {
            $this->_log(__FUNCTION__.': table or params is/are not valid', 2);
            return false;
        }
        if (is_array_assoc($params)) {
            $q .= "UPDATE $table SET ";
            $keys = array_keys($params);
            $set = $this->_fieldDelim.implode($this->_fieldDelim.' = ?, '.$this->_fieldDelim, $keys).
                $this->_fieldDelim.' = ? ';
            $this->_params(array_values($params));
            $q .= $set;
            $this->_log(__FUNCTION__.': set update query', 1);
        } else {
            $this->_log(__FUNCTION__.': could not figure out parameters', 2, array('params' => $params));
        }

        if (is_array_ne($where)) {
            $q .= $this->_where($where);
        } else {
            $q .= $where;
        }

        $q_set = $this->query($q);

        $this->_log('end '.__FUNCTION__, 0);
        return $q_set;
    }


    private function _where($conditions) {
        if (!is_array_ne($conditions)) {
            $this->_log(__FUNCTION__.': conditions is not an array with values', 2);
            return false;
        }

        $where = ' WHERE ';
        $stuff = array();
        foreach($conditions as $field => $value) {
            $pos_colon = strpos($field, ':');
            $op = '=';
            if (!is_bool($pos_colon) && $pos_colon > 0 && $pos_colon < strlen($field) - 1) {
                list($field, $op) = explode(':', $field);
            }
            // change the where value to a bound parameter if there is no ? in the string
            if (!strstr($value, '?')) {
                $this->_params($value);
                $value = '?';
            }
            $stuff[] = $field.' '.$op.' '.$value;
        }
        $where .= implode(' AND ', $stuff);

        $this->_where = $where;
        return $where;
    }


}


function query_error_handler($number, $string, $file, $line) {
    $filename = 'queries.log';
    $msg = date(DATE_FORMAT).': ';
    switch($number) {
        case E_ERROR:
        case E_USER_ERROR:
            $type = 'Fatal error';
            break;
        case E_WARNING:
        case E_USER_WARNING:
            $type = 'Warning';
            break;
        case E_PARSE:
            $type = 'Parse error';
            break;
        case E_NOTICE:
        case E_USER_NOTICE:
            $type = 'Notice';
            break;
        case E_RECOVERABLE_ERROR:
            $type = 'Recoverable error';
            break;
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            $type = 'Deprecated';
            break;
        default:
            $type = 'Unknown';
            break;
    }
    $trace = debug_backtrace();
    $backtrace = implode(NL, $trace);
    $msg .= 'PHP '.$type.': '.$string.' in '.$file.' on line '.$line.', referrer: '.$_SERVER['HTTP_REFERER'].', page: '.$_SERVER['REQUEST_URI'].NL.$backtrace;
    file_put_contents($filename, $msg.NL, FILE_APPEND);
    return false;
}
?>