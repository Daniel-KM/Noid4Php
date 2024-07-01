<?php
/**
 * Backward-compatible wrapper for Noid 1.1.2 API.
 *
 * This class provides the same interface as Noid 1.1.2 for tools that depend
 * on the old API. It internally delegates to the new namespaced classes in
 * version 1.2.x.
 *
 * @deprecated Use the new namespaced classes directly for new code.
 * @see \Noid\Noid
 * @see \Noid\Lib\Db
 * @see \Noid\Lib\Helper
 * @see \Noid\Lib\Log
 * @see \Noid\Lib\Generator
 *
 * @author Daniel Berthereau
 * @license CeCILL-B v1.0 http://www.cecill.info/licences/Licence_CeCILL-B_V1-en.txt
 * @package Noid
 */

use Noid\Noid as NoidNew;
use Noid\Lib\Db;
use Noid\Lib\Generator;
use Noid\Lib\Globals;
use Noid\Lib\Helper;
use Noid\Lib\Log;
use Noid\Storage\DatabaseInterface;

/**
 * Backward-compatible Noid class (v1.1.2 API).
 *
 * This class wraps the new namespaced API to provide backward compatibility
 * for tools that depend on the old Noid 1.1.2 interface.
 *
 * Usage (same as v1.1.2, just add autoload):
 *   require_once 'vendor/autoload.php';
 *   require_once 'lib/Noid.php';
 *   // Now you can use the old API unchanged:
 *   $noid = Noid::dbcreate('.', 'admin', 'f5.reedeedk', 'long', '12345', 'example.org', 'test');
 */
class Noid
{
    const VERSION = '1.1.2-compat-1.2.1';

    const NOLIMIT = -1;
    const SEQNUM_MIN = 1;
    const SEQNUM_MAX = 1000000;

    const DB_CREATE = 'c';
    const DB_RDONLY = 'r';
    const DB_WRITE = 'w';

    const DB_RANGE_PARTIAL = 'partial';
    const DB_RANGE_REGEX = 'regex';

    // For compatibility with the perl script.
    const BDB_CREATE = 1;
    const BDB_RDONLY = 1024;
    const BDB_RDWR = 0;
    const BDB_INIT_LOCK = 256;
    const BDB_INIT_TXN = 8192;
    const BDB_INIT_MPOOL = 1024;
    const BDB_INIT_CDB = 128;
    const BDB_SET_RANGE = 27;

    /**
     * For compatibility with the perl script.
     *
     * @var string
     */
    public static $random_generator = 'Perl_Random';

    /**
     * List of possible repertoires of characters.
     *
     * @var array
     */
    public static $alphabets = array(
        'd' => '0123456789',
        'e' => '0123456789bcdfghjkmnpqrstvwxz',
        'i' => '0123456789x',
        'x' => '0123456789abcdef_',
        'v' => '0123456789abcdefghijklmnopqrstuvwxyz_',
        'E' => '123456789bcdfghjkmnpqrstvwxzBCDFGHJKMNPQRSTVWXZ',
        'w' => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ#*+@_',
        'c' => '!"#$&\'()*+,0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`abcdefghijklmnopqrstuvwxyz{|}~',
        'l' => '0123456789abcdefghijkmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
    );

    /**
     * Allows to test the locking mechanism.
     *
     * @var int
     */
    public static $locktest = 0;

    /**
     * Store the current database type for the wrapper.
     *
     * @var string
     */
    protected static $_dbType = 'bdb';

    /**
     * Map of noid paths to settings for dbopen/dbclose.
     *
     * @var array
     */
    protected static $_noidSettings = array();

    public function __construct()
    {
        self::init();
    }

    /**
     * Initialize the library.
     *
     * @return void
     * @throws Exception
     */
    public static function init()
    {
        NoidNew::init();
    }

    /**
     * Set the database type for the wrapper.
     *
     * @param string $dbType One of 'bdb', 'mysql', 'sqlite', 'xml'.
     */
    public static function setDbType($dbType)
    {
        self::$_dbType = $dbType;
    }

    /**
     * Adds an error message for a database pointer/object.
     *
     * @param string $noid
     * @param string $message
     * @return int 1
     * @throws Exception
     */
    public static function addmsg($noid, $message)
    {
        return Log::addmsg($noid, $message);
    }

    /**
     * Returns accumulated messages for a database pointer/object.
     *
     * @param string $noid
     * @param int $reset
     * @return string
     * @throws Exception
     */
    public static function errmsg($noid = null, $reset = 0)
    {
        return Log::errmsg($noid, $reset);
    }

    /**
     * Logs a message.
     *
     * @param string $noid
     * @param string $message
     * @return int 1
     * @throws Exception
     */
    public static function logmsg($noid, $message)
    {
        return Log::logmsg($noid, $message);
    }

    /**
     * Bind data to an identifier.
     *
     * @param string $noid
     * @param string $contact
     * @param int $validate
     * @param string $how
     * @param string $id
     * @param string $elem
     * @param string $value
     * @return int
     * @throws Exception
     */
    public static function bind($noid, $contact, $validate, $how, $id, $elem, $value)
    {
        return NoidNew::bind($noid, $contact, $validate, $how, $id, $elem, $value);
    }

    /**
     * Compute check character for given identifier.
     *
     * @param string $id
     * @param string $alphabet
     * @return string|null
     * @throws Exception
     */
    public static function checkchar($id, $alphabet = 'e')
    {
        return Helper::checkChar($id, $alphabet);
    }

    /**
     * Create a new minter database.
     *
     * @param string $dbdir Directory where to create the database.
     * @param string $contact Who is responsible for the minter.
     * @param string $template The template for generating identifiers.
     * @param string $term The term ('long', 'medium', 'short', or '-').
     * @param string $naan The NAAN (Name Assigning Authority Number).
     * @param string $naa The NAA (Name Assigning Authority).
     * @param string $subnaa The SubNAA.
     * @return string|null The noid (database path) on success, null on failure.
     * @throws Exception
     */
    public static function dbcreate($dbdir, $contact, $template = null, $term = '-', $naan = '', $naa = '', $subnaa = '')
    {
        // Convert old-style parameters to new settings array.
        $settings = self::_buildSettings($dbdir, self::$_dbType);

        // Store for later use with dbclose.
        $noid = Db::dbcreate($settings, $contact, $template, $term, $naan, $naa, $subnaa);
        if ($noid) {
            self::$_noidSettings[$noid] = $settings;
        }

        return $noid;
    }

    /**
     * Return info about a minter database.
     *
     * @param string $noid
     * @param string $level
     * @return string|array
     * @throws Exception
     */
    public static function dbinfo($noid, $level = 'brief')
    {
        return Db::dbinfo($noid, $level);
    }

    /**
     * Open a database in the specified mode.
     *
     * @param string $dbname The database path.
     * @param string $flags DB_RDONLY, DB_CREATE, or DB_WRITE.
     * @return string|null The noid on success, null on failure.
     * @throws Exception
     */
    public static function dbopen($dbname, $flags = self::DB_WRITE)
    {
        // Convert flags for compatibility.
        switch ($flags) {
            case self::DB_WRITE:
            case self::BDB_RDWR:
                $mode = DatabaseInterface::DB_WRITE;
                break;
            case self::DB_RDONLY:
            case self::BDB_RDONLY:
                $mode = DatabaseInterface::DB_RDONLY;
                break;
            case self::DB_CREATE:
            case self::BDB_CREATE:
                $mode = DatabaseInterface::DB_CREATE;
                break;
            default:
                self::addmsg(null, sprintf('"%s" is not a regular flag', $flags));
                return null;
        }

        // Extract directory from dbname (e.g., '/path/to/NOID/noid.bdb' -> '/path/to').
        $dbdir = preg_replace('|/NOID/[^/]+$|', '', $dbname);
        if ($dbdir === $dbname) {
            // Try alternate pattern.
            $dbdir = dirname(dirname($dbname));
        }

        $settings = self::_buildSettings($dbdir, self::$_dbType);
        $noid = Db::dbopen($settings, $mode);

        if ($noid) {
            self::$_noidSettings[$noid] = $settings;
        }

        return $noid;
    }

    /**
     * Test lock mechanism.
     *
     * @param int $sleepvalue
     * @return int
     */
    public static function locktest($sleepvalue)
    {
        return Db::locktest($sleepvalue);
    }

    /**
     * Close the database.
     *
     * @param string $noid
     * @return int
     * @throws Exception
     */
    public static function dbclose($noid)
    {
        $result = Db::dbclose($noid);
        unset(self::$_noidSettings[$noid]);
        return $result;
    }

    /**
     * Fetch bound data for an identifier.
     *
     * @param string $noid
     * @param int $verbose
     * @param string $id
     * @param array|string $elems
     * @return array
     * @throws Exception
     */
    public static function fetch($noid, $verbose, $id, $elems)
    {
        return NoidNew::fetch($noid, $verbose, $id, $elems);
    }

    /**
     * Get the alphabet for check character computation.
     *
     * @param string $template
     * @return string|bool
     */
    public static function get_alphabet($template)
    {
        return Helper::getAlphabet($template);
    }

    /**
     * Get the value of a named internal variable.
     *
     * @param string $noid
     * @param string $varname
     * @return string
     * @throws Exception
     */
    public static function getnoid($noid, $varname)
    {
        return Log::getnoid($noid, $varname);
    }

    /**
     * Get a user note.
     *
     * @param string $noid
     * @param string $key
     * @return string
     * @throws Exception
     */
    public static function get_note($noid, $key)
    {
        return Log::get_note($noid, $key);
    }

    /**
     * Set or release a hold on identifiers.
     *
     * @param string $noid
     * @param string $contact
     * @param string $on_off
     * @param array|string $ids
     * @return int
     * @throws Exception
     */
    public static function hold($noid, $contact, $on_off, $ids)
    {
        return NoidNew::hold($noid, $contact, $on_off, $ids);
    }

    /**
     * Set a hold on an identifier.
     *
     * @param string $noid
     * @param string $id
     * @return int
     * @throws Exception
     */
    public static function hold_set($noid, $id)
    {
        return NoidNew::hold_set($noid, $id);
    }

    /**
     * Release a hold on an identifier.
     *
     * @param string $noid
     * @param string $id
     * @return int
     * @throws Exception
     */
    public static function hold_release($noid, $id)
    {
        return NoidNew::hold_release($noid, $id);
    }

    /**
     * Mint identifiers.
     *
     * @param string $noid
     * @param string $contact
     * @param int $pepper
     * @return string|array|null
     * @throws Exception
     */
    public static function mint($noid, $contact, $pepper = 0)
    {
        return NoidNew::mint($noid, $contact, $pepper);
    }

    /**
     * Record user values in admin area.
     *
     * @param string $noid
     * @param string $contact
     * @param string $key
     * @param string $value
     * @return int
     * @throws Exception
     */
    public static function note($noid, $contact, $key, $value)
    {
        return Log::note($noid, $contact, $key, $value);
    }

    /**
     * Convert a number to an extended digit.
     *
     * @param int $num
     * @param string $mask
     * @return string
     * @throws Exception
     */
    public static function n2xdig($num, $mask)
    {
        return Generator::n2xdig($num, $mask);
    }

    /**
     * Parse a template and return the total number of identifiers.
     *
     * @param string $template
     * @param string $prefix Output parameter.
     * @param string $mask Output parameter.
     * @param string $gen_type Output parameter.
     * @param string $message Output parameter.
     * @return int
     * @throws Exception
     */
    public static function parse_template($template, &$prefix, &$mask, &$gen_type, &$message)
    {
        return Helper::parseTemplate($template, $prefix, $mask, $gen_type, $message);
    }

    /**
     * Queue identifiers for later minting.
     *
     * @param string $noid
     * @param string $contact
     * @param string $when
     * @param array|string $ids
     * @return int
     * @throws Exception
     */
    public static function queue($noid, $contact, $when, $ids)
    {
        return NoidNew::queue($noid, $contact, $when, $ids);
    }

    /**
     * Generate a sample identifier.
     *
     * @param string $noid
     * @param int $num
     * @return string
     * @throws Exception
     */
    public static function sample($noid, $num = null)
    {
        return NoidNew::sample($noid, $num);
    }

    /**
     * Describe the identifier space.
     *
     * @param string $noid
     * @return string
     * @throws Exception
     */
    public static function scope($noid)
    {
        return NoidNew::scope($noid);
    }

    /**
     * Validate identifiers.
     *
     * @param string $noid
     * @param string $template
     * @param array|string $ids
     * @return array
     * @throws Exception
     */
    public static function validate($noid, $template, $ids)
    {
        return NoidNew::validate($noid, $template, $ids);
    }

    /**
     * Build settings array from directory path.
     *
     * @param string $dbdir
     * @param string $dbType
     * @return array
     */
    protected static function _buildSettings($dbdir, $dbType = 'bdb')
    {
        $dbdir = ($dbdir === '.') ? getcwd() : $dbdir;

        return array(
            'db_type' => $dbType,
            'storage' => array(
                'bdb' => array(
                    'data_dir' => $dbdir,
                    'db_name' => 'NOID',
                ),
                'mysql' => array(
                    'data_dir' => $dbdir,
                    'host' => 'localhost',
                    'user' => null,
                    'password' => null,
                    'db_name' => 'NOID',
                    'port' => 3306,
                    'socket' => null,
                ),
                'sqlite' => array(
                    'data_dir' => $dbdir,
                    'db_name' => 'NOID',
                ),
                'xml' => array(
                    'data_dir' => $dbdir,
                    'db_name' => 'NOID',
                ),
            ),
        );
    }
}
