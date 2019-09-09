<?php
/**
 * Database Wrapper/Connector class, wrapping Mysql.
 * Noid class's db-related functions(open/close/read/write/...) will
 * be replaced with the functions of this class.
 *
 * @Attention: we use the <string>base64-encoding</strong> here, because the keys and values may contain the special chars which is not allowed in SQL queries.
 */

namespace Noid\Storage;

use Exception;
use mysqli;

// use mysqli_result;

require_once 'DatabaseInterface.php';
require_once 'MysqlConf.php';

class MysqlDB implements DatabaseInterface
{
    /**
     * @var mysqli $handle
     */
    private $handle;

    /**
     * MysqlDB constructor.
     * @throws Exception
     */
    public function __construct()
    {
        // Check if mysql/mysqli is installed.
        // The extension is mysql for php 7.1 and mysqli for php 7.4, so use
        // class.
        if (!class_exists('mysqli')) {
            throw new Exception('NOID requires the extension mysql/mysqli.');
        }

        $this->handle = null;
    }

    /**
     * @throws Exception
     */
    private function connect()
    {
        // My lovely Maria (DB) lives my home (local). :)
        $this->handle = @new mysqli(
            isset(MysqlConf::$mysql_host) ? trim(MysqlConf::$mysql_host) : ini_get('mysqli.default_host'),
            isset(MysqlConf::$mysql_user) ? trim(MysqlConf::$mysql_user) : ini_get('mysqli.default_user'),
            isset(MysqlConf::$mysql_passwd) ? MysqlConf::$mysql_passwd : ini_get('mysqli.default_pw'),
            '', // default database is none. maybe selected later.
            isset(MysqlConf::$mysql_port) ? MysqlConf::$mysql_port : ini_get('mysqli.default_port'),
            isset(MysqlConf::$mysql_socket) ? trim(MysqlConf::$mysql_socket) : ini_get('mysqli.default_socket'));

        // Oops! I can't see her (Maria).
        if ($this->handle->connect_errno) {
            throw new Exception('Mysql connection error: ' . $this->handle->connect_errno);
        }
    }

    /**
     * @param string $name
     * @param string $mode
     *
     * @return mysqli|FALSE
     * @throws Exception
     */
    public function open($name, $mode)
    {
        if (is_null($this->handle)) {
            $this->connect();
        }

        if (!is_null($this->handle)) {
            // determine the database name.
            $database = !empty(MysqlConf::$mysql_dbname)
                ? trim(MysqlConf::$mysql_dbname)
                : DatabaseInterface::DATABASE_NAME;

            // It's time for checking the db existence. If not exists, will create it.
            $this->handle->query("CREATE DATABASE IF NOT EXISTS `" . $database . "`");

            // select the database `noid`.
            $this->handle->select_db($database);

            // If the table is not exist, create it.
            $this->handle->query("CREATE TABLE IF NOT EXISTS `" . DatabaseInterface::TABLE_NAME . "` (  `_key` VARCHAR(512) NOT NULL, `_value` VARCHAR(4096) DEFAULT NULL, PRIMARY KEY (`_key`))");

            // when create db
            if (strpos(strtolower($mode), DatabaseInterface::DB_CREATE) !== false) {
                // if create mode, truncate the table records.
                $this->handle->query("TRUNCATE TABLE `" . DatabaseInterface::TABLE_NAME . "`");
            }

            // Optimize the table for better performance.
            $this->handle->query("OPTIMIZE TABLE `" . DatabaseInterface::TABLE_NAME . "`");

            return $this->handle;
        }

        return false;
    }

    /**
     * @throws Exception
     */
    public function close()
    {
        if (!($this->handle instanceof mysqli)) {
            return;
        }

        $this->handle->close();
        $this->handle = null;
    }

    /**
     * @param string $key
     *
     * @return string|FALSE
     * @throws Exception
     */
    public function get($key)
    {
        if (!($this->handle instanceof mysqli)) {
            return false;
        }

        $key = htmlspecialchars($key, ENT_QUOTES | ENT_HTML401);

        if ($res = $this->handle->query("SELECT `_value` FROM `" . DatabaseInterface::TABLE_NAME . "` WHERE `_key` = '{$key}'")) {
            $row = $res->fetch_array(MYSQLI_NUM);
            if ($row === null) {
                return false;
            }
            $ret_val = $row[0];
            $res->free();

            return htmlspecialchars_decode($ret_val, ENT_QUOTES | ENT_HTML401);
        }

        return false;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return bool
     * @throws Exception
     */
    public function set($key, $value)
    {
        if (!($this->handle instanceof mysqli)) {
            return false;
        }

        $key = htmlspecialchars($key, ENT_QUOTES | ENT_HTML401);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML401);

        $qry = "INSERT INTO `" . DatabaseInterface::TABLE_NAME . "` (`_key`, `_value`) VALUES ('{$key}', '{$value}') ON DUPLICATE KEY UPDATE `_value` = '{$value}'";
        return $this->handle->query($qry);
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function delete($key)
    {
        if (!($this->handle instanceof mysqli)) {
            return false;
        }

        $key = htmlspecialchars($key, ENT_QUOTES | ENT_HTML401);

        return $this->handle->query("DELETE FROM `" . DatabaseInterface::TABLE_NAME . "` WHERE `_key` = '{$key}'");
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function exists($key)
    {
        if (!($this->handle instanceof mysqli)) {
            return false;
        }

        $key = htmlspecialchars($key, ENT_QUOTES | ENT_HTML401);

        /** @var mysqli_result $res */
        if ($res = $this->handle->query("SELECT `_key` FROM `" . DatabaseInterface::TABLE_NAME . "` WHERE `_key` = '{$key}'")) {
            if ($res->num_rows > 0) {
                return true;
            } else {
                return false;
            }
        }
        return false;
    }

    /**
     * Workaround to get an array of all keys matching a simple pattern.
     *
     * @param string $pattern The pattern of the keys to retrieve (no regex).
     *
     * @return array Ordered associative array of matching keys and values.
     * @throws Exception
     */
    public function get_range($pattern)
    {
        if (is_null($pattern) || !($this->handle instanceof mysqli)) {
            return null;
        }
        $results = array();

        /** @var mysqli_result $res */
        $pattern = htmlspecialchars($pattern, ENT_QUOTES | ENT_HTML401);

        if ($res = $this->handle->query("SELECT `_key`, `_value` FROM `" . DatabaseInterface::TABLE_NAME . "` WHERE `_key` LIKE '%{$pattern}%'")) {
            while ($row = $res->fetch_array(MYSQLI_NUM)) {
                $key = htmlspecialchars_decode($row[0], ENT_QUOTES | ENT_HTML401);
                $value = htmlspecialchars_decode($row[1], ENT_QUOTES | ENT_HTML401);
                $results[$key] = $value;
            }
        }

        // @internal Ordered by default with Berkeley database.
        ksort($results);
        return $results;
    }

    /**
     * Import all data from other data source.
     * 1. erase all data here.
     * 2. get data from source db by its get_range() invocation.
     * 3. insert 'em all here.
     *
     * @attention when do this, the original data is erased.
     *
     * @param DatabaseInterface $src_db
     *
     * @return bool
     * @throws Exception
     */
    public function import($src_db)
    {
        if (is_null($src_db) || is_null($this->handle) || !($this->handle instanceof mysqli)) {
            return false;
        }

        // 1. erase all data. this step depends on database implementation.
        $this->handle->query("TRUNCATE TABLE `" . DatabaseInterface::TABLE_NAME . "`");

        // 2. get data from source database.
        $imported_data = $src_db->get_range('');
        if (count($imported_data) == 0) {
            return false;
        }

        // 3. write 'em all into this database.
        foreach ($imported_data as $k => $v) {
            $this->set($k, $v);
        }

        return true;
    }
}
