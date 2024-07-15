<?php
/**
 * Benchmark script for comparing storage backend performance.
 *
 * Usage: php scripts/benchmark.php [db_type] [count] [operation]
 *        php scripts/benchmark.php                    # runs all backends, 1000 items, mint+bind
 *        php scripts/benchmark.php lmdb 5000          # runs lmdb with 5000 items
 *        php scripts/benchmark.php lmdb 1000 mint     # runs only mint test
 *        php scripts/benchmark.php lmdb 1000 bind     # runs only bind test
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Noid\Lib\Db;
use Noid\Lib\Log;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;

$dbType = $argv[1] ?? null;
$count = isset($argv[2]) ? (int)$argv[2] : 1000;
$operation = $argv[3] ?? 'all';

$dbTypes = ['lmdb', 'sqlite', 'xml'];

// Check if bdb is available
if (function_exists('dba_handlers') && in_array('db4', dba_handlers())) {
    $dbTypes[] = 'bdb';
}

// If specific db type requested, run only that one
if ($dbType) {
    if (!in_array($dbType, $dbTypes)) {
        echo "Unknown db type: $dbType\n";
        echo "Available: " . implode(', ', $dbTypes) . "\n";
        exit(1);
    }
    runBenchmark($dbType, $count, $operation);
    exit(0);
}

// Run all backends
echo "Noid Storage Backend Benchmark\n";
echo "==============================\n";
echo "Items per test: $count\n";
echo "Available backends: " . implode(', ', $dbTypes) . "\n\n";

$results = [];
foreach ($dbTypes as $type) {
    // Mint test
    $output = shell_exec("php " . escapeshellarg(__FILE__) . " " . escapeshellarg($type) . " " . escapeshellarg($count) . " mint 2>&1");
    if (preg_match('/MINT_TIME:([\d.]+)/', $output, $m)) {
        $results[$type]['mint'] = (float)$m[1];
    }

    // Bind test
    $output = shell_exec("php " . escapeshellarg(__FILE__) . " " . escapeshellarg($type) . " " . escapeshellarg($count) . " bind 2>&1");
    if (preg_match('/BIND_TIME:([\d.]+)/', $output, $m)) {
        $results[$type]['bind'] = (float)$m[1];
    }

    if (isset($results[$type]['mint'])) {
        $mintRate = $count / $results[$type]['mint'];
        echo sprintf("  %s mint: %.3fs (%.0f/s)\n", $type, $results[$type]['mint'], $mintRate);
    } else {
        echo "  $type mint: FAILED\n";
    }

    if (isset($results[$type]['bind'])) {
        $bindRate = $count / $results[$type]['bind'];
        echo sprintf("  %s bind: %.3fs (%.0f/s)\n", $type, $results[$type]['bind'], $bindRate);
    } else {
        echo "  $type bind: FAILED\n";
    }
    echo "\n";
}

// Print summary tables
echo "\n### Minting Performance\n\n";
echo "| Backend | Time | Rate |\n";
echo "|---------|-----:|-----:|\n";
foreach ($results as $type => $data) {
    if (isset($data['mint'])) {
        $rate = $count / $data['mint'];
        echo sprintf("| %s | %.2fs | %s/s |\n", $type, $data['mint'], number_format((int)$rate));
    }
}

echo "\n### Binding Performance\n\n";
echo "| Backend | Time | Rate |\n";
echo "|---------|-----:|-----:|\n";
foreach ($results as $type => $data) {
    if (isset($data['bind'])) {
        $rate = $count / $data['bind'];
        echo sprintf("| %s | %.2fs | %s/s |\n", $type, $data['bind'], number_format((int)$rate));
    }
}

function runBenchmark($dbType, $count, $operation) {
    $dataDir = dirname(__DIR__) . '/tests/datafiles_benchmark_' . $dbType;

    // Clean up
    if (is_dir($dataDir)) {
        removeDir($dataDir);
    }
    mkdir($dataDir, 0755, true);

    $settings = [
        'db_type' => $dbType,
        'storage' => [
            $dbType => [
                'data_dir' => $dataDir,
                'db_name' => null,
            ],
        ],
    ];

    // Create database
    $report = Db::dbcreate($settings, 'benchmark', '.zd', 'short');
    if (!$report) {
        echo "ERROR creating database: " . Log::errmsg(null, 1) . "\n";
        exit(1);
    }

    // Open for writing
    $noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);
    if (!$noid) {
        echo "ERROR opening database\n";
        exit(1);
    }

    // Mint test
    if ($operation === 'mint' || $operation === 'all') {
        $start = microtime(true);
        $minted = 0;
        $remaining = $count;
        while ($remaining > 0) {
            $batch = min($remaining, 10000);
            $ids = Noid::mintMultiple($noid, 'benchmark', $batch);
            $minted += count($ids);
            $remaining -= $batch;
            if (count($ids) < $batch) {
                break;
            }
        }
        $mintTime = microtime(true) - $start;

        if ($minted === $count) {
            echo "MINT_TIME:$mintTime\n";
        } else {
            echo "MINT_ERROR: only minted $minted of $count\n";
        }
    }

    // Bind test - mint IDs first if needed, then bind elements
    if ($operation === 'bind' || $operation === 'all') {
        // First mint IDs for binding
        $ids = [];
        $remaining = $count;
        while ($remaining > 0) {
            $batch = min($remaining, 10000);
            $newIds = Noid::mintMultiple($noid, 'benchmark', $batch);
            $ids = array_merge($ids, $newIds);
            $remaining -= $batch;
            if (count($newIds) < $batch) {
                break;
            }
        }

        if (count($ids) < $count) {
            echo "BIND_ERROR: could not mint enough IDs for bind test\n";
        } else {
            // Build bindings array
            $bindings = [];
            foreach ($ids as $id) {
                $bindings[] = ['how' => 'set', 'id' => $id, 'elem' => 'title', 'value' => 'Test Title ' . $id];
            }

            // Benchmark binding
            $start = microtime(true);
            $bound = 0;
            $remaining = count($bindings);
            $offset = 0;
            while ($remaining > 0) {
                $batch = min($remaining, 10000);
                $batchBindings = array_slice($bindings, $offset, $batch);
                $results = Noid::bindMultiple($noid, 'benchmark', '-', $batchBindings);
                $bound += count(array_filter($results, function($r) { return $r !== null; }));
                $remaining -= $batch;
                $offset += $batch;
            }
            $bindTime = microtime(true) - $start;

            if ($bound === $count) {
                echo "BIND_TIME:$bindTime\n";
            } else {
                echo "BIND_ERROR: only bound $bound of $count\n";
            }
        }
    }

    Db::dbclose($noid);

    // Clean up
    removeDir($dataDir);
}

function removeDir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? removeDir($path) : unlink($path);
    }
    rmdir($dir);
}
