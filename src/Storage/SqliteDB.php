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
use SQLite3;

// use \SQLite3Result;

class SqliteDB implements DatabaseInterface
{
    /**
     * Database file extension.
     *
     * @const string FILE_EXT
     */
    const FILE_EXT = 'sqlite';

    /**
     * @var SQLite3 $handle
     */
    private $handle;

    /**
     * @var array
     */
    private $settings;

    /**
     * SqliteDB: constructor.
     * @throws Exception
     */
    public function __construct()
    {
        // Check if sqlite3 is enabled.
        if (!extension_loaded('sqlite3') || !class_exists('SQLite3')) {
            throw new Exception('NOID requires the extension "SQLite3".');
        }

        $this->handle = null;
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
        $this->settings = $settings;

        $storage = $this->settings['storage']['sqlite'];

        if (empty($storage['data_dir'])) {
            throw new Exception('A directory where to store the sqlite database is required.');
        }

        $data_dir = $storage['data_dir'];
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

        $file_path = $path . DIRECTORY_SEPARATOR . DatabaseInterface::TABLE_NAME . '.' . self::FILE_EXT;

        if (!is_null($this->handle) && $this->handle instanceof SQLite3) {
            $this->handle->close();
        }

        $this->handle = new SQLite3($file_path);
        // create mode
        if (strpos(strtolower($mode), DatabaseInterface::DB_CREATE) !== false) {
            // If the table does not exist, create it.
            $this->handle->exec(sprintf('
                CREATE TABLE IF NOT EXISTS `%s` (
                    `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                    `k` VARCHAR(512) NOT NULL UNIQUE,
                    `v` LONGTEXT DEFAULT NULL
                )', DatabaseInterface::TABLE_NAME));

            // if create mode, truncate the table records.
            $this->handle->exec(sprintf('DELETE FROM `%s`', DatabaseInterface::TABLE_NAME));
        }

        return $this->handle;
    }

    /**
     * @throws Exception
     */
    public function close()
    {
        if (is_null($this->handle) || !($this->handle instanceof SQLite3)) {
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
        if (is_null($this->handle) || !($this->handle instanceof SQLite3)) {
            return false;
        }

        $stmt = $this->handle->prepare(sprintf('SELECT `v` FROM `%s` WHERE `k` = :k', DatabaseInterface::TABLE_NAME));
        $stmt->bindParam(':k', $key, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_NUM);
        $result->finalize();
        $stmt->reset();
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
        if (is_null($this->handle) || !($this->handle instanceof SQLite3)) {
            return false;
        }

        $stmt = $this->handle->prepare(sprintf('REPLACE INTO `%s` (`k`, `v`) VALUES (:k, :v)', DatabaseInterface::TABLE_NAME));
        $stmt->bindParam(':k', $key, SQLITE3_TEXT);
        $stmt->bindParam(':v', $value, SQLITE3_TEXT);
        $result = (bool) $stmt->execute();
        $stmt->reset();
        return $result;
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function delete($key)
    {
        if (is_null($this->handle) || !($this->handle instanceof SQLite3)) {
            return false;
        }

        $stmt = $this->handle->prepare(sprintf('DELETE FROM `%s` WHERE `k` = :k', DatabaseInterface::TABLE_NAME));
        $stmt->bindParam(':k', $key, SQLITE3_TEXT);
        $result = (bool) $stmt->execute();
        $stmt->reset();
        return $result;
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function exists($key)
    {
        if (is_null($this->handle) || !($this->handle instanceof SQLite3)) {
            return false;
        }

        $stmt = $this->handle->prepare(sprintf('SELECT `k` FROM `%s` WHERE `k` = :k', DatabaseInterface::TABLE_NAME));
        $stmt->bindParam(':k', $key, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_NUM);
        $result->finalize();
        $stmt->reset();
        return (bool) $row;
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
        if (is_null($pattern) || is_null($this->handle) || !($this->handle instanceof SQLite3)) {
            return null;
        }

        $patternLike = "%$pattern%";

        // @internal Ordered by default with Berkeley database.
        $stmt = $this->handle->prepare(sprintf('SELECT `k`, `v` FROM `%s` WHERE `k` LIKE :pattern ORDER BY `id`', DatabaseInterface::TABLE_NAME));
        $stmt->bindParam(':pattern', $patternLike, SQLITE3_TEXT);
        $result = $stmt->execute();

        $results = array();
        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            $results[$row[0]] = $row[1];
        }
        $result->finalize();
        $stmt->reset();

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
        if (is_null($src_db) || is_null($this->handle) || !($this->handle instanceof SQLite3)) {
            return false;
        }

        // 1. erase all data. this step depends on database implementation.
        $this->handle->exec(sprintf('DELETE FROM `%s`', DatabaseInterface::TABLE_NAME));

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
