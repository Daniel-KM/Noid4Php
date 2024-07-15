<?php
/**
 * Database Wrapper/Connector class, wrapping LMDB (Lightning Memory-Mapped Database).
 *
 * LMDB is the recommended replacement for BerkeleyDB on modern Linux distributions.
 * It is accessed through PHP's DBA extension with the 'lmdb' handler.
 *
 * @see https://www.symas.com/lmdb
 */

namespace Noid\Storage;

use Exception;

class LmdbDB implements DatabaseInterface
{
    /**
     * Database file extension.
     *
     * @const string FILE_EXT
     */
    const FILE_EXT = 'lmdb';

    /**
     * @var resource $handle
     */
    private $handle;

    /**
     * @var array
     */
    private $settings;

    /**
     * LmdbDB constructor.
     * @throws Exception
     */
    public function __construct()
    {
        // Check if dba is installed.
        if (!extension_loaded('dba')) {
            throw new Exception('NOID requires the extension "Database (dbm-style) Abstraction Layer" (dba).');
        }

        // Check if LMDB is installed.
        if (!in_array('lmdb', dba_handlers())) {
            throw new Exception('LMDB handler is not available in the DBA extension. Install php-dba with LMDB support.');
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

        $storage = $settings['storage']['lmdb'];

        if (empty($storage['data_dir'])) {
            throw new Exception('A directory where to store LMDB database is required.');
        }

        $data_dir = $storage['data_dir'];
        if (substr($data_dir, 0, 1) !== '/' && substr($data_dir, 0, 1) !== '\\') {
            $data_dir = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . $data_dir;
        }

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

        // In create mode, if file existed, remove it.
        if (strpos(strtolower($mode), DatabaseInterface::DB_CREATE) !== false) {
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            // Also remove LMDB lock file if it exists.
            $lock_file = $file_path . '-lock';
            if (file_exists($lock_file)) {
                unlink($lock_file);
            }

            // Create a log file from scratch and make it writable.
            $logfile = $path . DIRECTORY_SEPARATOR . 'loglmdb';
            if (file_put_contents($logfile, '') === false || !chmod($logfile, 0666)) {
                return false;
            }
        }

        $this->handle = dba_open($file_path, $mode, 'lmdb');

        return $this->handle;
    }

    /**
     * @throws Exception
     */
    public function close()
    {
        if (!is_resource($this->handle)) {
            return;
        }

        // Suppress warning if database was opened read-only.
        @dba_sync($this->handle);
        dba_close($this->handle);
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
        if (!is_resource($this->handle)) {
            return false;
        }

        return dba_fetch($key, $this->handle);
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
        if (!is_resource($this->handle)) {
            return false;
        }

        return dba_replace($key, (string) $value, $this->handle);
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function delete($key)
    {
        if (!is_resource($this->handle)) {
            return false;
        }

        // LMDB throws a warning if the key doesn't exist, so check first.
        if (!dba_exists($key, $this->handle)) {
            return false;
        }

        return dba_delete($key, $this->handle);
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function exists($key)
    {
        if (!is_resource($this->handle)) {
            return false;
        }

        return dba_exists($key, $this->handle);
    }

    /**
     * Workaround to get an array of all keys matching a simple pattern.
     *
     * @internal The default extension "dba" doesn't allow to get range of keys.
     * This workaround may be slow on big bases and may need a lot of memory.
     *
     * @param string $pattern The pattern of the keys to retrieve (no regex).
     * @param int    $limit   Maximum number of results (0 = unlimited).
     *
     * @return array Ordered associative array of matching keys and values.
     * @throws Exception
     */
    public function get_range($pattern, $limit = 0)
    {
        if (is_null($pattern) || !is_resource($this->handle)) {
            return null;
        }
        $results = array();
        $key = dba_firstkey($this->handle);
        $count = 0;

        // Normalize and manage empty pattern.
        $pattern = (string) $pattern;
        if (strlen($pattern) == 0) {
            while ($key !== false) {
                $results[$key] = dba_fetch($key, $this->handle);
                $count++;
                if ($limit > 0 && $count >= $limit) {
                    break;
                }
                $key = dba_nextkey($this->handle);
            }
        }
        // Manage prefix pattern matching.
        else {
            while ($key !== false) {
                if (strpos($key, $pattern) === 0) {
                    $results[$key] = dba_fetch($key, $this->handle);
                    $count++;
                    if ($limit > 0 && $count >= $limit) {
                        break;
                    }
                }
                $key = dba_nextkey($this->handle);
            }
        }
        // Sort by keys for consistent ordering.
        ksort($results);
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
        if (is_null($src_db) || is_null($this->handle) || !is_resource($this->handle)) {
            return false;
        }

        // 1. erase all data. this step depends on database implementation.
        $data_to_del = $this->get_range('');
        foreach ($data_to_del as $k => $v) {
            $this->delete($k);
        }

        // 2. get data from source database.
        $imported_data = $src_db->get_range('');
        if (count($imported_data) == 0) {
            return false;
        }

        // 3. write 'em all into this database.
        // The database is empty and the input is an associative array, so no
        // need to check via $this->set().
        foreach ($imported_data as $key => $value) {
            dba_replace($key, (string) $value, $this->handle);
        }

        return true;
    }
}
