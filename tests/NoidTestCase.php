<?php
/**
 * @author Daniel Berthereau
 * @package Noid
 */

namespace NoidTest;

use PHPUnit\Framework\TestCase;

use Exception;
use Noid\Lib\Globals;
use Noid\Lib\Db;
use Noid\Lib\Log;
use Noid\Storage\DatabaseInterface;

/**
 * Common methods to test Noid
 */
class NoidTestCase extends TestCase
{
    const NOID_BIN = 'blib/script/noid';

    /**
     * Command for noid.
     *
     * @var string
     */
    public $cmd;

    /**
     * Command to remove test files.
     *
     * @var string
     */
    public $rm_cmd;

    /**
     * Dir for the datafiles (default "datafiles_test").
     *
     * @var string
     */
    public $data_dir;

    /**
     * Dir for the specific database inside the main dir (default "datafiles_test/NOID").
     *
     * @var string
     */
    public $noid_dir;

    /**
     * path to the setting files.
     *
     * @var string
     */
    protected $settings_file = 'settings_test.php';

    /**
     * Checked and filled full settings.
     *
     * @var array
     */
    protected $settings = 'settings_test.php';

    public function setUp(): void
    {
        $this->settings_file = __DIR__ . DIRECTORY_SEPARATOR . $this->settings_file;
        $this->settings = include $this->settings_file;

        // Use db_type from settings, default to 'bdb' if empty.
        if (empty($this->settings['db_type'])) {
            $this->settings['db_type'] = 'bdb';
        }
        $db_type = $this->settings['db_type'];

        $this->data_dir = $this->settings['storage'][$db_type]['data_dir'];
        if (!$this->data_dir) {
            throw new Exception('A directory where to store databases is required.');
        }
        if (substr($this->data_dir, 0, 1) !== '/') {
            $this->data_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $this->data_dir;
        }
        if (file_exists($this->data_dir) && (!is_dir($this->data_dir) || !is_writeable($this->data_dir))) {
            throw new Exception(sprintf(
                '%s is not a writeable directory.',
                $this->data_dir
            ));
        }
        if (!file_exists($this->data_dir) && !is_writeable(dirname($this->data_dir))) {
            throw new Exception(sprintf(
                '%s is not a writeable directory. You may create the test dir manually or config it.',
                dirname($this->data_dir)
            ));
        } elseif (!file_exists($this->data_dir)) {
            mkdir($this->data_dir);
        }

        if (empty($this->settings['storage'][$db_type]['db_name'])) {
            throw new Exception(sprintf(
                'The database name should be set in test settings.'
            ));
        }

        $db_name = $this->settings['storage'][$db_type]['db_name'];

        $this->noid_dir = $this->data_dir . DIRECTORY_SEPARATOR . $db_name . DIRECTORY_SEPARATOR;

        // TODO Move to tear down.
        $this->rm_cmd = sprintf('rm -rf %s > /dev/null 2>&1', escapeshellarg($this->noid_dir));

        if (is_executable(self::NOID_BIN)) {
            $this->cmd = self::NOID_BIN;
        } else {
            $this->cmd = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'noid';
            if (!is_executable($this->cmd)) {
                $this->cmd = 'php ' . escapeshellarg($this->cmd);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function tearDown(): void
    {
    }

    public function testReady()
    {
        // TODO Add a test for readiness.
        $this->assertTrue(true);
    }

    /**
     * @param $cmd
     * @param $status
     * @param $output
     * @param $errors
     *
     * @throws Exception
     */
    protected function _executeCommand($cmd, &$status, &$output, &$errors)
    {
        // Using proc_open() instead of exec() avoids an issue: current working
        // directory cannot be set properly via exec().  Note that exec() works
        // fine when executing in the web environment but fails in CLI.
        $descriptorSpec = array(
            0 => array('pipe', 'r'), //STDIN
            1 => array('pipe', 'w'), //STDOUT
            2 => array('pipe', 'w'), //STDERR
        );
        if ($proc = proc_open($cmd, $descriptorSpec, $pipes, getcwd())) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($proc);
        } else {
            throw new Exception("Failed to execute command: $cmd.");
        }
    }

    /**
     * Subroutine to get the policy out of the README file.
     *
     * @param string $file_name
     *
     * @return string
     */
    protected function _get_policy($file_name)
    {
        $fh = fopen($file_name, 'r');
        $error = error_get_last();
        $this->assertTrue(is_resource($fh),
            sprintf('open of "%s" failed, %s', $file_name, isset($error) ? $error['message'] : '[no message]'));
        if ($fh === false) {
            return null;
        }

        $regex = '/^Policy:\s+\(:((G|-)(R|-)(A|-)(N|-)(I|-)(T|-)(E|-))\)\s*$/';
        while ($line = fgets($fh)) {
            $result = preg_match($regex, $line, $matches);
            if ($result) {
                fclose($fh);
                return $matches[1];
            }
        }
        fclose($fh);

        return null;
    }

    /**
     * Subroutine to generate a random string of (sort of) random length.
     *
     * @return string
     */
    protected function _random_string()
    {
        $to_choose_from =
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ' .
            'abcdefghijklmnopqrstuvwxyz' .
            '0123456789';
        $building_string = '';

        # Calculate the string length.  First, get a fractional number that's
        # between 0 and 1 but never 1.
        $string_length = (float) mt_rand() / (float) (mt_getrandmax() - 1);
        # Multiply it by 48, so that it's between 0 and 48, but never 48.
        $string_length *= 48;
        # Throw away the fractional part, leaving an integer between 0 and 47.
        $string_length = intval($string_length);
        # Add 3 to give us a number between 3 and 50.
        $string_length += 3;

        for ($i = 0; $i < $string_length; $i++) {
            # Calculate an integer between 0 and ((length of
            # $to_choose_from) - 1).
            # First, get a fractional number that's between 0 and 1,
            # but never 1.
            $to_choose_index = (float) mt_rand() / (float) (mt_getrandmax() - 1);
            # Multiply it by the length of $to_choose_from, to get
            # a number that's between 0 and (length of $to_choose_from),
            # but never (length of $choose_from);
            $to_choose_index *= strlen($to_choose_from);
            # Throw away the fractional part to get an integer that's
            # between 0 and ((length of $to_choose_from) - 1).
            $to_choose_index = intval($to_choose_index);

            # Fetch the character at that index into $to_choose_from,
            # and append it to the end of the string we're building.
            $building_string .= substr($to_choose_from, $to_choose_index, 1);
        }

        # Return our construction.
        return $building_string;
    }

    /**
     * @param        $template
     * @param string $return
     *
     * @return bool|string
     * @throws Exception
     */
    protected function _short($template, $return = 'erc')
    {
        $report = Db::dbcreate($this->settings, 'jak', $template, 'short');
        $errmsg = Log::errmsg(null, 1);
        if ($return == 'stdout' || $return == 'stderr') {
            $this->assertEmpty($report, sprintf('should output an error: %s', $errmsg));
            return $errmsg;
        }
        $this->assertNotEmpty($report, $errmsg);

        // Return the erc.
        $isReadable = is_readable($this->noid_dir . 'README');
        $error = error_get_last();
        $this->assertTrue($isReadable, sprintf('can’t open README: %s', isset($error) ? $error['message'] : '[no message]'));

        $erc = file_get_contents($this->noid_dir . 'README');
        return $erc;
        #return `./noid dbcreate $template short 2>&1`;
    }
}
