<?php
/**
 * PDO Database Storage class.
 *
 * Unified storage backend using PDO for MySQL, MariaDB, PostgreSQL, and SQLite.
 * This is the recommended SQL storage backend as PDO is more commonly available
 * and provides a consistent interface across database engines.
 *
 * Supported drivers: mysql, pgsql, sqlite
 *
 * @note For SQLite, you can also use the dedicated SqliteDB class which uses
 *       the SQLite3 extension directly.
 */

namespace Noid\Storage;

use Exception;
use PDO;
use PDOException;

class PdoDb implements DatabaseInterface
{
    /**
     * Database file extension for SQLite.
     */
    const SQLITE_FILE_EXT = 'sqlite';

    /**
     * @var PDO|null
     */
    private $handle;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var string PDO driver name (mysql, pgsql, sqlite)
     */
    private $driver;

    /**
     * PdoDb constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        if (!extension_loaded('pdo')) {
            throw new Exception('NOID requires the PDO extension.');
        }

        $this->handle = null;
        $this->driver = null;
    }

    /**
     * Build DSN string for PDO connection.
     *
     * @param array $storage Storage configuration
     * @return string DSN string
     * @throws Exception
     */
    private function buildDsn(array $storage)
    {
        $driver = $storage['driver'] ?? 'mysql';
        $this->driver = $driver;

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $this->driver = 'mysql';
                if (!in_array('mysql', PDO::getAvailableDrivers())) {
                    throw new Exception('PDO MySQL driver is not available.');
                }
                $host = $storage['host'] ?? 'localhost';
                $port = $storage['port'] ?? 3306;
                $charset = $storage['charset'] ?? 'utf8mb4';
                return "mysql:host={$host};port={$port};charset={$charset}";

            case 'pgsql':
            case 'postgresql':
                $this->driver = 'pgsql';
                if (!in_array('pgsql', PDO::getAvailableDrivers())) {
                    throw new Exception('PDO PostgreSQL driver is not available.');
                }
                $host = $storage['host'] ?? 'localhost';
                $port = $storage['port'] ?? 5432;
                return "pgsql:host={$host};port={$port}";

            case 'sqlite':
                $this->driver = 'sqlite';
                if (!in_array('sqlite', PDO::getAvailableDrivers())) {
                    throw new Exception('PDO SQLite driver is not available.');
                }
                $dataDir = $storage['data_dir'];
                $dbName = $storage['db_name'] ?? DatabaseInterface::DATABASE_NAME;
                $path = $dataDir . DIRECTORY_SEPARATOR . $dbName;
                $filePath = $path . DIRECTORY_SEPARATOR . DatabaseInterface::TABLE_NAME . '.' . self::SQLITE_FILE_EXT;
                return "sqlite:{$filePath}";

            default:
                throw new Exception("Unsupported PDO driver: {$driver}");
        }
    }

    /**
     * Open database connection.
     *
     * @param array $settings
     * @param string $mode
     * @return PDO|false
     * @throws Exception
     */
    public function open($settings, $mode)
    {
        $this->settings = $settings;
        $storage = $this->settings['storage']['pdo'];

        if (empty($storage['data_dir'])) {
            throw new Exception('A directory where to store logs/data is required.');
        }

        $dataDir = $storage['data_dir'];
        if (substr($dataDir, 0, 1) !== '/' && substr($dataDir, 0, 1) !== '\\') {
            $dataDir = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . $dataDir;
        }
        $storage['data_dir'] = $dataDir;

        $dbName = !empty($storage['db_name']) ? $storage['db_name'] : DatabaseInterface::DATABASE_NAME;

        // Create data directory if needed
        $path = $dataDir . DIRECTORY_SEPARATOR . $dbName;
        if (!file_exists($path)) {
            $result = mkdir($path, 0775, true);
            if (!$result) {
                throw new Exception("Cannot create directory: {$path}");
            }
        }

        try {
            $dsn = $this->buildDsn($storage);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            if ($this->driver === 'sqlite') {
                $this->handle = new PDO($dsn, null, null, $options);
            } else {
                $user = $storage['user'] ?? null;
                $password = $storage['password'] ?? null;
                $this->handle = new PDO($dsn, $user, $password, $options);

                // Create and select database for MySQL/PostgreSQL
                $this->createDatabase($dbName);
            }

            // Create table if needed
            $this->createTable($mode);

        } catch (PDOException $e) {
            throw new Exception('PDO connection error: ' . $e->getMessage());
        }

        return $this->handle;
    }

    /**
     * Create database if it doesn't exist (MySQL/PostgreSQL).
     *
     * @param string $dbName
     */
    private function createDatabase($dbName)
    {
        if ($this->driver === 'mysql') {
            $this->handle->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
            $this->handle->exec("USE `{$dbName}`");
        } elseif ($this->driver === 'pgsql') {
            // PostgreSQL: check if database exists, create if not
            // Note: This requires connecting to a default database first
            try {
                $this->handle->exec("SELECT 1 FROM pg_database WHERE datname = '{$dbName}'");
            } catch (PDOException $e) {
                $this->handle->exec("CREATE DATABASE \"{$dbName}\"");
            }
        }
    }

    /**
     * Create table if it doesn't exist.
     *
     * @param string $mode
     */
    private function createTable($mode)
    {
        $tableName = DatabaseInterface::TABLE_NAME;

        if ($this->driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `k` VARCHAR(512) NOT NULL UNIQUE,
                `v` TEXT DEFAULT NULL
            )";
        } elseif ($this->driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS \"{$tableName}\" (
                \"id\" SERIAL PRIMARY KEY,
                \"k\" VARCHAR(512) NOT NULL UNIQUE,
                \"v\" TEXT DEFAULT NULL
            )";
        } else {
            // MySQL
            $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `k` VARCHAR(512) NOT NULL,
                `v` LONGTEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `k` (`k`)
            )";
        }

        $this->handle->exec($sql);

        // Truncate on create mode
        if (strpos(strtolower($mode), DatabaseInterface::DB_CREATE) !== false) {
            if ($this->driver === 'pgsql') {
                $this->handle->exec("TRUNCATE TABLE \"{$tableName}\" RESTART IDENTITY");
            } else {
                $this->handle->exec("DELETE FROM `{$tableName}`");
            }
        }
    }

    /**
     * Close database connection.
     */
    public function close()
    {
        $this->handle = null;
    }

    /**
     * Check if the database connection is currently open.
     *
     * @return bool TRUE if connection is open, FALSE otherwise.
     */
    public function isOpen()
    {
        return $this->handle instanceof PDO;
    }

    /**
     * Get value by key.
     *
     * @param string $key
     * @return string|false
     */
    public function get($key)
    {
        if (!$this->handle) {
            return false;
        }

        $tableName = DatabaseInterface::TABLE_NAME;
        $stmt = $this->handle->prepare("SELECT `v` FROM `{$tableName}` WHERE `k` = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row[0] : false;
    }

    /**
     * Set key-value pair.
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function set($key, $value)
    {
        if (!$this->handle) {
            return false;
        }

        $tableName = DatabaseInterface::TABLE_NAME;

        if ($this->driver === 'mysql') {
            $sql = "INSERT INTO `{$tableName}` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = ?";
            $stmt = $this->handle->prepare($sql);
            return $stmt->execute([$key, $value, $value]);
        } elseif ($this->driver === 'pgsql') {
            $sql = "INSERT INTO \"{$tableName}\" (\"k\", \"v\") VALUES (?, ?) ON CONFLICT (\"k\") DO UPDATE SET \"v\" = ?";
            $stmt = $this->handle->prepare($sql);
            return $stmt->execute([$key, $value, $value]);
        } else {
            // SQLite
            $sql = "REPLACE INTO `{$tableName}` (`k`, `v`) VALUES (?, ?)";
            $stmt = $this->handle->prepare($sql);
            return $stmt->execute([$key, $value]);
        }
    }

    /**
     * Delete key.
     *
     * @param string $key
     * @return bool
     */
    public function delete($key)
    {
        if (!$this->handle) {
            return false;
        }

        $tableName = DatabaseInterface::TABLE_NAME;
        $stmt = $this->handle->prepare("DELETE FROM `{$tableName}` WHERE `k` = ?");
        return $stmt->execute([$key]);
    }

    /**
     * Check if key exists.
     *
     * @param string $key
     * @return bool
     */
    public function exists($key)
    {
        if (!$this->handle) {
            return false;
        }

        $tableName = DatabaseInterface::TABLE_NAME;
        $stmt = $this->handle->prepare("SELECT 1 FROM `{$tableName}` WHERE `k` = ?");
        $stmt->execute([$key]);
        return (bool) $stmt->fetch();
    }

    /**
     * Get range of keys matching pattern.
     *
     * @param string $pattern Prefix pattern (not regex)
     * @param int    $limit   Maximum number of results (0 = unlimited).
     * @return array|null
     */
    public function get_range($pattern, $limit = 0)
    {
        if (is_null($pattern) || !$this->handle) {
            return null;
        }

        $tableName = DatabaseInterface::TABLE_NAME;

        // Escape special LIKE characters
        $patternEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $pattern);
        $patternLike = "{$patternEscaped}%";

        $sql = "SELECT `k`, `v` FROM `{$tableName}` WHERE `k` LIKE ? ESCAPE '\\' ORDER BY `id`";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = $this->handle->prepare($sql);
        $stmt->execute([$patternLike]);

        $results = [];
        while ($row = $stmt->fetch()) {
            $results[$row[0]] = $row[1];
        }

        return $results;
    }

    /**
     * Import data from another database.
     *
     * @param DatabaseInterface $src_db
     * @return bool
     */
    public function import($src_db)
    {
        if (!$src_db || !$this->handle) {
            return false;
        }

        $tableName = DatabaseInterface::TABLE_NAME;

        // Clear existing data
        $this->handle->exec("DELETE FROM `{$tableName}`");

        // Get source data
        $importedData = $src_db->get_range('');
        if (empty($importedData)) {
            return false;
        }

        // Import using prepared statement
        if ($this->driver === 'sqlite') {
            $sql = "REPLACE INTO `{$tableName}` (`k`, `v`) VALUES (?, ?)";
        } elseif ($this->driver === 'pgsql') {
            $sql = "INSERT INTO \"{$tableName}\" (\"k\", \"v\") VALUES (?, ?) ON CONFLICT (\"k\") DO UPDATE SET \"v\" = EXCLUDED.\"v\"";
        } else {
            $sql = "INSERT INTO `{$tableName}` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)";
        }

        $stmt = $this->handle->prepare($sql);
        foreach ($importedData as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        return true;
    }
}
