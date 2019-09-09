<?php
/**
 * Database Wrapper/Connector class, wrapping xml.
 * Noid class's db-related functions(open/close/read/write/...) will
 * be replaced with the functions of this class.
 *
 * XML database is faster than Berkeley DB. Because all the operations are executed in RAM.
 * Only when the database is closed, all the data, which loaded when the database is opened,
 * is stored into the XML file.
 *
 * However, Mysql DB is faster db engine, specially when database is BIGGER.
 */

namespace Noid\Storage;

use Exception;
use SimpleXMLElement;

class XmlDB implements DatabaseInterface
{
    /**
     * XML-formatted storage of stored items:
     *
     * ```xml
     * <noid>
     *    <i k="key">value</i>
     * </noid>
     * ```
     */

    /**
     * Database file extension.
     *
     * @const string FILE_EXT
     */
    const FILE_EXT = 'xml';

    /**
     * @var string $file_path
     */
    private $file_path;

    /**
     * @var SimpleXMLElement $handle
     */
    private $handle;

    /**
     * @var array
     */
    private $settings;

    /**
     * BerkeleyDB constructor.
     * @throws Exception
     */
    public function __construct()
    {
        // Check if dba is installed.
        if (!extension_loaded('xml')) {
            throw new Exception('NOID requires the extension "XML" (php-xml).');
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

        $storage = $settings['storage']['xml'];

        if (empty($storage['data_dir'])) {
            throw new Exception('A directory where to store BerkeleyDB is required.');
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
        $this->file_path = $file_path;

        // create mode
        if (strpos(strtolower($mode), DatabaseInterface::DB_CREATE) !== false) {
            // don't create any file yet, but will be created when the db closed.
            $this->handle = new SimpleXMLElement('<?xml version="1.0"?><noid></noid>');
            return $this->handle;
        }

        // open mode
        if (file_exists($this->file_path)) {
            // just return the object from the file.
            $this->handle = simplexml_load_file($this->file_path);
            return $this->handle;
        }

        return false;
    }

    /**
     * @throws Exception
     */
    public function close()
    {
        // well, it's time for saving the database into the disk.
        // Save indented xml, but keep space.
        // $this->handle->asXML($this->file_path);
        $dom = dom_import_simplexml($this->handle)->ownerDocument;
        // $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->save($this->file_path);
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
        // xml xpath searching...
        $item = $this->handle->xpath('//noid/i[@k="' . $key . '"]');

        // found it.
        if (count($item) > 0) {
            return (string) $item[0][0];
        }

        return false; // oh, no.
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
        $item = $this->handle->xpath('//noid/i[@k="' . $key . '"]');

        // if it exists, remove it... for unique keying.
        if (count($item) > 0) {
            unset($item[0][0]);
        }

        // insert new item
        /**
         * @var SimpleXMLElement $item_node
         */
        $item_node = $this->handle->addChild('i', $value);
        $item_node->addAttribute('k', $key);

        return true;
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function delete($key)
    {
        // refer to: set()
        $item = $this->handle->xpath('//noid/i[@k="' . $key . '"]');

        // found it.
        if (count($item) > 0) {
            // delete it.
            unset($item[0][0]);

            return true;
        }

        return false;
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws Exception
     */
    public function exists($key)
    {
        // find

        $item = $this->handle->xpath('//noid/i[@k="' . $key . '"]');

        // found it.
        if (count($item) > 0) {
            return true;
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
        if (is_null($pattern) || !is_object($this->handle)) {
            return null;
        }

        // a variable to be returned.
        $results = array();

        // find all the records contained the specific pattern.
        $items = $this->handle->xpath("//noid/i[contains(@k, '" . $pattern . "')]");

        // keep 'em all.
        foreach ($items as $item) {
            if (isset($item['k'])) {
                $key = (string) $item['k'];
                $value = (string) $item;
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
     * @warning when do this, the original data is erased.
     *
     * @param DatabaseInterface $src_db
     *
     * @return bool
     * @throws Exception
     */
    public function import($src_db)
    {
        if (is_null($src_db) || is_null($this->handle) || !($this->handle instanceof SimpleXMLElement)) {
            return false;
        }

        // 1. erase all data. this step depends on database implementation.
        $this->handle = new SimpleXMLElement('<?xml version="1.0"?><noid></noid>');

        // 2. get data from source database.
        $imported_data = $src_db->get_range('');
        if (count($imported_data) == 0) {
            print "(no data) ";
            return false;
        }

        // 3. write 'em all into this database.
        // The database is empty and the input is an associative array, so no
        // need to check via $this->set().
        foreach ($imported_data as $key => $value) {
            $item_node = $this->handle->addChild('i', $value);
            $item_node->addAttribute('k', $key);
        }

        return true;
    }
}
