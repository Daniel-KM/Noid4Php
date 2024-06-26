<?php
/**
 * Database Wrapper/Connector class, wrapping Mysql.
 * Noid class's db-related functions(open/close/read/write/...) will
 * be replaced with the functions of this class.
 *
 * @warning: we use the <string>base64-encoding</strong> here, because the keys and values may contain the special chars which is not allowed in SQL queries.
 */

namespace Noid\Storage;

use Exception;
use mysqli;

// use mysqli_result;

class MysqlDB implements DatabaseInterface
{
    /**
     * @var mysqli $handle
     */
    private $handle;

    /**
     * @var array
     */
    private $settings;

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
        $storage = $this->settings['storage']['mysql'];

        // My lovely Maria (DB) lives my home (local). :)
        $this->handle = @new mysqli(
            isset($storage['host']) ? trim($storage['host']) : ini_get('mysqli.default_host'),
            isset($storage['user']) ? trim($storage['user']) : ini_get('mysqli.default_user'),
            isset($storage['password']) ? $storage['password'] : ini_get('mysqli.default_pw'),
            // Default database is none. May be selected later.
            '',
            isset($storage['port']) ? $storage['port'] : ini_get('mysqli.default_port'),
            isset($storage['socket']) ? trim($storage['socket']) : ini_get('mysqli.default_socket')
        );

        // Oops! I can't see her (Maria).
        if ($this->handle->connect_errno) {
            throw new Exception(sprintf(
                'Mysql connection error: %s',
                $this->handle->connect_errno
            ));
        }
    }

    /**
     * Open database/file/other storage.
     *
     * @param array $settings Set all settings, in particular for import.
     * @param string $mode
     *
     * @return resource|object|FALSE
     * @throws Exception
     */
    public function open($settings, $mode)
    {
        if (is_null($this->handle)) {
            $this->settings = $settings;
            $this->connect();
        }

        // Early return in case of an issue during connection.
        if (is_null($this->handle)) {
            return false;
        }

        $storage = $this->settings['storage']['mysql'];

        if (empty($storage['data_dir'])) {
            throw new Exception('A directory where to store logs is required.');
        }

        $data_dir = $storage['data_dir'];
        if (substr($data_dir, 0, 1) !== '/' && substr($data_dir, 0, 1) !== '\\') {
            $data_dir = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . $data_dir;
        }

        // determine the database name.
        $db_name = !empty($storage['db_name']) ? $storage['db_name'] : DatabaseInterface::DATABASE_NAME;

        $path = $data_dir . DIRECTORY_SEPARATOR . $db_name;
        if (!file_exists($data_dir . DIRECTORY_SEPARATOR . $db_name)) {
            $result = mkdir($path, 0775, true);
            if (!$result) {
                throw new Exception(sprintf(
                    'A directory %s cannot be created.',
                    $path
                ));
            }
        }

        // It's time for checking the db existence. If not exists, will create it.
        $this->handle->query(sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $db_name));

        // select the database `NOID`.
        $this->handle->select_db($db_name);

        // If the table does not exist, create it.
        $this->handle->query(sprintf('
            CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `k` VARCHAR(512) NOT NULL,
                `v` LONGTEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE `k` (`k`)
        )',
            DatabaseInterface::TABLE_NAME));

        // when create db
        if (strpos(strtolower($mode), DatabaseInterface::DB_CREATE) !== false) {
            // if create mode, truncate the table records.
            $this->handle->query(sprintf('TRUNCATE TABLE `%s`', DatabaseInterface::TABLE_NAME));
        }

        // Optimize the table for better performance.
        $this->handle->query(sprintf('OPTIMIZE TABLE `%s`', DatabaseInterface::TABLE_NAME));

        return $this->handle;
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

        $stmt = $this->handle->prepare(sprintf('SELECT `v` FROM `%s` WHERE `k` = ?', DatabaseInterface::TABLE_NAME));
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_array(MYSQLI_NUM);
        $result->free();
        return $row ? $row[0] : false;
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

        $stmt = $this->handle->prepare(sprintf('INSERT INTO `%s` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = ?', DatabaseInterface::TABLE_NAME));
        $stmt->bind_param('sss', $key, $value, $value);
        return $stmt->execute();
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
        $stmt = $this->handle->prepare(sprintf('DELETE FROM `%s` WHERE `k` = ?', DatabaseInterface::TABLE_NAME));
        $stmt->bind_param('s', $key);
        return $stmt->execute();
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

        $stmt = $this->handle->prepare(sprintf('SELECT `k` FROM `%s` WHERE `k` = ?', DatabaseInterface::TABLE_NAME));
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $output = $result->num_rows > 0;
        $result->free();
        return $output;
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

        $patternLike = "%$pattern%";

        // @internal Ordered by default with Berkeley database.
        $stmt = $this->handle->prepare(sprintf('SELECT `k`, `v` FROM `%s` WHERE `k` LIKE ? ORDER BY `id`', DatabaseInterface::TABLE_NAME));
        $stmt->bind_param('s', $patternLike);
        $stmt->execute();
        $result = $stmt->get_result();

        $results = array();
        // Use fetch_all and array_column?
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $results[$row[0]] = $row[1];
        }
        $result->free();

        return $results;
    }

    /**
     * Import all data from other data source.
     * 1. erase all data here.
     * 2. get data from source db by its get_range() invocation.
     * 3. insert 'em all here.
     *
     * @warning when do this, the original data is erased.
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
        $this->handle->query(sprintf('TRUNCATE TABLE `%s`', DatabaseInterface::TABLE_NAME));

        // 2. get data from source database.
        $imported_data = $src_db->get_range('');
        if (count($imported_data) === 0) {
            return false;
        }

        // 3. write 'em all into this database.
        // The database is empty and the input is an associative array, so no
        // need to check via $this->set().
        $key = '';
        $value = '';
        $stmt = $this->handle->prepare(sprintf('INSERT INTO `%s` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = ?', DatabaseInterface::TABLE_NAME));
        $stmt->bind_param('sss', $key, $value, $value);

        foreach ($imported_data as $key => $value) {
            $stmt->execute();
        }

        return true;
    }
}
