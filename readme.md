Noid for php
============

[Noid for Php] is a tool to create and manage temporary or permanent nice
opaque identifiers ([noid]) for physical or digital objects or anything else
like "12025/654xz321". Then, any noid minted can be associated to any content
(binding) and saved in a storage.

This is the php version of a [perl tool] of 2002-2006, still largely used in
libraries, museums and any institution that manage collections and archives,
for example the University of California, the Internet Archive, or the
Bibliothèque nationale de France.

The main goal of this version is for web services, that can create and manage
standard noids without any dependancy. All commands and functions can be used
as in the Perl version, via the command line or via the web.

It is used in [Omeka] and [Omeka S], an open source CMS designed to expose
digitalized documents, via the addons [Ark & Noid for Omeka] and
[Ark for Omeka S]. Other versions exist in [Java] and [Ruby].


Table of Contents
-----------------

- [Quick Start](#quick-start)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Format of noids](#format-of-noids)
  - [Random Generator Selection](#random-generator-selection)
  - [Storage](#storage)
- [Usage](#usage)
  - [Binding Elements to Identifiers](#binding-elements-to-identifiers)
  - [Fetching Elements from Identifiers](#fetching-elements-from-identifiers)
- [Migration from BerkeleyDB](#migration-from-berkeleydb)
  - [BerkeleyDB/db4 deprecation](#berkeleydbdb4-deprecation)
  - [Available alternatives](#available-alternatives)
  - [Method 1: Using noid dbimport](#method-1-using-noid-dbimport-db4-handler-still-available)
  - [Method 2: Using migration scripts](#method-2-using-migration-scripts-db4-handler-not-available)
- [Performance](#performance)
  - [Batch Minting](#performance-batch-minting)
  - [Persistent Connections](#performance-persistent-connections)
  - [Pre-generation Pool](#performance-pre-generation-pool)
- [About the port](#about-the-port)
- [To do](#to-do)
- [Warning](#warning)
- [Troubleshooting](#troubleshooting)
- [License](#license)
- [Copyright](#copyright)


Quick Start
-----------

**Install:**
```bash
composer require daniel-km/noid4php
```

**CLI usage:**
```bash
# Create a minter
noid dbcreate .zd long 12345 example.org test

# Mint identifiers
noid mint 1
noid mint 10

# Bind metadata
noid bind set 12345/ab3x title "My Document"

# Fetch metadata
noid fetch 12345/ab3x
```

**PHP usage:**
```php
use Noid\Lib\Db;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;

$settings = [
    'db_type' => 'lmdb',
    'storage' => ['lmdb' => ['data_dir' => '/data']],
];

// Create database (once)
Db::dbcreate(
    $settings,
    'contact@example.org',
    '.zd',
    'long',
    '12345',
    'example.org'
);

// Mint identifiers
$noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);
$id = Noid::mint($noid, 'contact@example.org');
$ids = Noid::mintMultiple($noid, 'contact@example.org', 100);  // Batch mint
Db::dbclose($noid);
```

**Storage backends:** lmdb (default), pdo, sqlite, xml, mysql (deprecated),
bdb (deprecated).

See detailed documentation below for advanced features (batch operations,
persistent connections, pre-generation pool, migration guides).


Installation
------------

Since version 1.2.0, the tool is a library managed by composer.

Else, simply include the file "lib/Noid.php" in your project and the class
"Noid" will be available.

```bash
composer require daniel-km/noid4php
```

The CLI tool is available via `noid.php` or the symlink `noid`.

### Automatic test

[PhpUnit] (version 6 or higher) can be used to check the installation via the
command `phpunit` at the root of the tool. Note that the full series of tests
are long, because the process compares the outputs of php methods and perl ones
for many seeds.

The version 1.1.2 is compatible from php 5.6.
The version 1.2.0 is compatible from php 7.1 to php 7.4 (unchecked above).
The version 1.4.0 is compatible from php 7.1 to last php version.


Configuration
-------------

When creating a database, two main choices should be done, because they cannot
be changed later: the format of the identifiers (see below [random noids](#random-noids)),
and, if the the noids are random and not simply sequential, the
[random generator](#random-generator-selection). The choice of storage is less
important, because it can be changed, but it should be explained too.

### Format of noids

The choice of the format of noids is the most important decision when creating
a database. It is recommended to use prefixes so you will be able to build
multiple series, in particular when you think to use short series.

#### Noid Overview

The noid utility creates minters (identifier generators) and accepts commands
that operate them. Once created, a minter can be used to produce persistent,
globally unique names for documents, databases, images, vocabulary terms, etc.
Properly managed, these identifiers can be used as long term durable
information object references within naming schemes such as ARK, PURL, URN,
DOI, and LSID. At the same time, alternative minters can be set up to produce
short-lived names for transaction identifiers, compact web server session keys,
and other ephemera.

In general, a noid minter efficiently generates, tracks, and binds unique
identifiers, which are produced without replacement in random or sequential
order, and with or without a check character that can be used for detecting
transcription errors. A minter can bind identifiers to arbitrary element names
and element values that are either stored or produced upon retrieval from
rule-based transformations of requested identifiers; the latter has application
in identifier resolution. Noid minters are very fast, scalable, easy to create
and tear down, and have a relatively small footprint. They use BerkeleyDB as
the underlying database, but any storage can be used.

Identifiers generated by a noid minter are also known as "noids" (nice opaque
identifiers -- rhymes with void). While a minter can record and bind any
identifiers that you bring to its attention, often it is used to generate,
bringing to your attention, identifier strings that carry no widely
recognizable meaning. This semantic opaqueness reduces their vulnerability to
era- and language-specific change, and helps persistence by making for
identifiers that can age and travel well.

See the full description, tutorial and usage on [metacpan], and the list
of available [commands].

#### Enhancements in alphabets

[Noid for Php] enhances some points present in the todo list of the Perl
script.

- Added the command get_note() to get a user note.
- Added support for alphabets until 89 characters. The character repertoires
  are:
    - Standard:
        - `d`: `{ 0-9 x }` cardinality 10
        - `e`: `{ 1-9 b-z }` - `{l, vowels}` cardinality 29
    - Proposed:
        - `i`: `{ 0-9 x }` cardinality 11
        - `x`: `{ 0-9 a-f _ }` cardinality 17
        - `v`: `{ 0-9 a-z _ }` cardinality 37
        - `E`: `{ 1-9 b-z B-Z }` - `{l, vowels}` cardinality 47
        - `w`: `{ 0-9 a-z A-Z # * + @ _ }` cardinality 67
    - Proposed, but not accepted for Ark:
        - `c`: Visible ASCII - `{ % - . / \ }` cardinality 89
    - Not proposed in the Perl script, but compatible with Ark and useful
      because the longest with only alphanumeric characters:
        - `l`: `{ 0-9 a-z A-Z }` - `{ l }` cardinality 61


### Random Generator Selection

When creating a new database, you can select a format based on a random number.
If this is the case, you have the choice between two random number generators
for ID generation. The generator cannot be change later. This affects
performance and Perl compatibility:


| Name      | Generator                     | Notes                                        |
|-----------|-------------------------------|----------------------------------------------|
| drand48   | Linear Congruential Generator | Default, compatible with Perl Noid.          |
| mt_rand   | Mersenne Twister              | More random, better distribution, 7x faster. |

**CLI usage:**
```bash
# Create a new database (uses drand48 by default, Perl-compatible)
noid -t lmdb dbcreate .zd long 12345 example.org test

# Create a database with faster mt_rand (not Perl-compatible)
noid -t lmdb -g mt_rand dbcreate .zd long 12345 example.org test
```

**Programmatic usage:**
```php
use Noid\Lib\Db;

// New databases use drand48 by default (Perl-compatible)
$settings = [
    'db_type' => 'lmdb',
    'storage' => [
        'lmdb' => ['data_dir' => '/path/to/data'],
    ],
];

// For faster generation (not Perl-compatible), specify mt_rand
$settings['generator'] = 'mt_rand';

Db::dbcreate(
    $settings,
    'contact@example.org',
    '.zd',
    'long',
    '12345',
    'example.org',
    'test'
);
```

**Note:** The generator is set when the database is created and cannot be
changed later. The ID sequence must remain consistent for the database to
work correctly.

#### Perl and php

The output of perl (release 5.20 and greater) and php (before and since release
7.1, where the output of `rand()` and `srand()` were [fixed] in the php
algorithm), are the same for integers at least until 32 bits (perl limit,
namely more than 4 000 000 000 identifiers).

**For float numbers, the output is different after the 8192nd value**, so keep
the same language or use marsenne.

Anyway, it is recommended to use php 7.1 or higher, since previous versions of
php are no more supported.

Anyway, **in the frameset of this library, the minting process uses only
integer keys, so series are always the same** with drand48 between perl and
php.

### Storage

#### Backends

| Backend  | Extension | Notes                                      |
|----------|-----------|--------------------------------------------|
| **lmdb** | dba+lmdb  | Default. Fast, reliable. Debian 10+, RHEL. |
| xml      | xml       | Human-readable, good for backup/export.    |
| pdo      | pdo       | For existing SQL (any) infrastructure.     |
| mysql    | mysqli    | For existing MySQL/MariaDB DB (deprecated).|
| sqlite   | sqlite3   | Portable, single file.                     |
| bdb      | dba+db4   | Legacy. Deprecated on modern Linux.        |

See [Performance](#performance) for benchmarks and optimization tips.

#### Storage Backend Performance

Benchmark results for 1,000 identifiers (PHP 8.2, Debian 12):

**Minting Performance:**

| Backend | Time   |    Rate |
|---------|-------:|--------:|
| xml     |  0.99s | 1,010/s |
| lmdb    |  6.04s |   165/s |
| sqlite  | 18.01s |    55/s |

**Binding Performance:**

| Backend | Time   |   Rate |
|---------|-------:|-------:|
| xml     |  2.07s |  484/s |
| lmdb    |  5.55s |  180/s |
| sqlite  | 18.72s |   53/s |

**Notes:**
- XML is fastest for small datasets but doesn't scale well and lacks ACID
  guarantees
- LMDB provides the best balance of performance and reliability for production
  use
- SQLite is slowest but offers portability and SQL compatibility

Run the benchmark yourself: `php scripts/benchmark.php`

The config can be set when instantiating a class or passing it via the option
`-f` of noid with the path to the config file (default is config/settings.php).


Usage
-----

### Binding Elements to Identifiers

Use `bind()` to attach metadata elements to identifiers, and `bindMultiple()`
for efficient batch binding with a single lock cycle.

**Single binding:**

```php
use Noid\Noid;

// Bind a single element to an identifier
$result = Noid::bind(
    $noid,
    'contact@example.org',
    '-',
    'set',
    $id,
    'title',
    'My Document'
);
```

**Multiple binding (more efficient for bulk operations):**

```php
use Noid\Noid;

// Bind multiple elements in one call
$bindings = [
    ['how' => 'set', 'id' => $id, 'elem' => 'title', 'value' => 'My Document'],
    ['how' => 'set', 'id' => $id, 'elem' => 'author', 'value' => 'John Doe'],
    ['how' => 'set', 'id' => $id, 'elem' => 'date', 'value' => '2024-01-01'],
];

$results = Noid::bindMultiple($noid, 'contact@example.org', '-', $bindings);

foreach ($results as $i => $result) {
    if ($result === null) {
        echo "Binding $i failed\n";
    }
}
```

**API Methods:**

| Method                                                               | Returns        |
|----------------------------------------------------------------------|----------------|
| `Noid::bind($noid, $contact, $validate, $how, $id, $elem, $value)`   | `string\|null` |
| `Noid::bindMultiple($noid, $contact, $validate, $bindings)`          | `array`        |

**Bind operations (`$how` parameter):**

| Operation | Description                                    |
|-----------|------------------------------------------------|
| `set`     | Set element value (creates or replaces)        |
| `new`     | Create new element (fails if already exists)   |
| `replace` | Replace existing element (fails if not exists) |
| `append`  | Append value to end of existing element        |
| `prepend` | Prepend value to beginning of existing element |
| `add`     | Alias for `append`                             |
| `insert`  | Alias for `prepend`                            |
| `delete`  | Delete element (value must be empty)           |
| `purge`   | Alias for `delete`                             |
| `mint`    | Mint new ID and bind element (id must be 'new')|

### Fetching Elements from Identifiers

Use `fetch()` to retrieve metadata elements from identifiers, and
`fetchMultiple()` for efficient batch fetching with a single lock cycle.

**Single fetch:**

```php
use Noid\Noid;

// Fetch a single element
$value = Noid::fetch($noid, 0, $id, 'title');

// Fetch with verbose output (includes id, circulation status)
$value = Noid::fetch($noid, 1, $id, 'title');
```

**Multiple fetch (more efficient for bulk operations):**

```php
use Noid\Noid;

// Fetch multiple elements in one call
$requests = [
    ['id' => $id1, 'elems' => ['title', 'author']],
    ['id' => $id2, 'elems' => ['title']],
    ['id' => $id3, 'elems' => []],  // empty elems = fetch all
];

$results = Noid::fetchMultiple($noid, 0, $requests);

foreach ($results as $i => $result) {
    if ($result === null) {
        echo "Fetch $i failed\n";
    } else {
        echo $result;
    }
}
```

**API Methods:**

| Method                                            | Returns        |
|---------------------------------------------------|----------------|
| `Noid::fetch($noid, $verbose, $id, ...$elems)`    | `string\|null` |
| `Noid::fetchMultiple($noid, $verbose, $requests)` | `array`        |


Migration from BerkeleyDB
-------------------------

If you have existing Noid databases in BerkeleyDB format, there are two
approaches to migrate to LMDB depending on your situation.

### BerkeleyDB/db4 deprecation

The original Noid tool uses BerkeleyDB as its native database, accessed through
PHP's DBA extension with the "db4" handler. However, **the db4 handler is being
phased out on recent Linux distributions**.

**Why db4 is being removed:**

In 2013, Oracle changed the BerkeleyDB license from the Sleepycat License (a
permissive open-source license) to the GNU Affero General Public License
(AGPL). This license change had significant implications for Linux
distributions and software that embedded BerkeleyDB, leading to its gradual
removal.

**Debian/Ubuntu status:**

The db4 handler was removed starting with **PHP 8.x packages** in Debian
Bookworm. If you are running Debian 11 Bullseye with PHP 7.4, the db4 handler
is available from the official Debian repositories. Next versions from
Debian 10 does not support db4, because php is compiled without argument
`--with-db4`.

**Note:** The [Sury repository](https://deb.sury.org) (deb.sury.org), which
provides newer PHP versions for Debian, compiles PHP 8.x **without db4
support**. Installing php8.x-dba from Sury does not provide the db4 handler.

**LMDB on Debian:** The LMDB handler is compiled by default (`--with-lmdb`) in
php-dba since Debian 10 Buster (PHP 7.3). All Debian versions (10, 11, 12)
include `liblmdb0` as a dependency of php-dba, so LMDB is directly usable
without any additional configuration.

You can check available handlers with:
`php -r "print_r(dba_handlers());"`

**Red Hat/CentOS:** The libdb package is deprecated in RHEL 9 and removed in
RHEL 10. More importantly, the official RHEL php-dba package is compiled
**without** the `--with-db4` flag, so the db4 handler is not available even on
systems where libdb is present. However, LMDB (`--with-lmdb`) is enabled by
default in RHEL's php-dba. Fedora includes both db4 and lmdb handlers.

Verify available handlers on your system with:
`php -r "print_r(dba_handlers());"`

### Available alternatives

1. **LMDB**: Recommended replacement for BerkeleyDB. Fast key-value store with
   similar performance characteristics. Available by default in php-dba on
   Debian (since 10), RHEL, and Fedora - no additional packages required.
   Set `'db_type' => 'lmdb'` in your settings.

2. **XML**: Human-readable format, suitable for long-term storage, easy backup,
   and data export/import between systems.
   Set `'db_type' => 'xml'` in your settings.

3. **SQLite**: Portable, no external dependencies, works on all platforms.
   Set `'db_type' => 'sqlite'` in your settings.

4. **PDO (MySQL, PostgreSQL, SQLite)**: Unified SQL backend using PDO
   extension. More portable than mysqli, supports multiple database engines.
   Set `'db_type' => 'pdo'` and configure the `driver` option in your settings:
   ```php
   'pdo' => [
       'driver' => 'mysql',  // or 'pgsql', 'sqlite'
       'data_dir' => '/path/to/data',
       'host' => 'localhost',
       'user' => 'noid',
       'password' => 'secret',
       'db_name' => 'noid',
   ],
   ```

5. **MySQL/MariaDB (deprecated)**: Legacy backend using mysqli extension.
   Use PDO instead (`'db_type' => 'pdo'` with `'driver' => 'mysql'`).

6. **Compile PHP with db4**: If you specifically need BerkeleyDB support, you
   must compile PHP from source with the `--with-db4` flag and install
   libdb5.3-dev package. Or use a distribution that uses this argument by
   default.

### Method 1: Using `noid dbimport` (db4 handler still available)

If your system still has the db4 handler available (e.g., Debian 11 before
upgrading), use the built-in migration command:

```bash
# 1. Create the destination LMDB database
./noid -t lmdb dbcreate .zd

# 2. Import data from BerkeleyDB to LMDB
./noid -t lmdb dbimport bdb

# 3. Verify the migration
./noid -t lmdb dbinfo

# 4. Update your settings.php to use 'db_type' => 'lmdb'
```

**Note:** The `dbimport` command requires:
- The destination database to exist (created with `dbcreate`)
- The PHP db4 handler to read the source database

### Method 2: Using migration scripts (db4 handler NOT available)

If your system no longer has the db4 handler (Debian 12+, RHEL 10+), use the
migration scripts provided in the `scripts/` directory. These scripts read
BerkeleyDB files using system tools (not PHP) and restore them to LMDB.

**Scripts provided:**

| Script                  | Language | Description                               |
|-------------------------|----------|-------------------------------------------|
| `import_from_dump.php`  | PHP      | Restores LMDB from db_dump output or JSON |
| `export_bdb_to_json.py` | Python   | Exports BerkeleyDB to JSON                |
| `export_bdb_to_json.pl` | Perl     | Exports BerkeleyDB to JSON                |

**Option A: Using db_dump (recommended)**

The `db_dump` utility from the `db-util` package can read BerkeleyDB files
without PHP:

```bash
# 1. Install db-util
sudo apt install db-util          # Debian/Ubuntu
sudo dnf install libdb-utils      # RHEL/Fedora

# 2. Create a text dump of your database
db_dump -p /path/to/datafiles/NOID/noid.bdb > noid_dump.txt

# 3. Check system requirements
php scripts/import_from_dump.php --check

# 4. Restore to LMDB (creates noid.lmdb automatically)
php scripts/import_from_dump.php noid_dump.txt /path/to/datafiles/NOID

# 5. Update your settings.php to use 'db_type' => 'lmdb'
```

**Option B: Using Python**

If `db_dump` doesn't work (version mismatch), use Python with bsddb3:

```bash
# 1. Install Python bsddb3
sudo apt install python3-bsddb3   # Debian/Ubuntu
pip3 install bsddb3               # Or via pip

# 2. Export to JSON
python3 scripts/export_bdb_to_json.py /path/to/noid.bdb noid_data.json

# 3. Restore to LMDB
php scripts/import_from_dump.php --json noid_data.json /path/to/datafiles/NOID
```

**Option C: Using Perl**

```bash
# 1. Install Perl modules
sudo apt install libberkeleydb-perl libjson-perl   # Debian/Ubuntu

# 2. Export to JSON
perl scripts/export_bdb_to_json.pl /path/to/noid.bdb noid_data.json

# 3. Restore to LMDB
php scripts/import_from_dump.php --json noid_data.json /path/to/datafiles/NOID
```

**Option D: Using Docker**

If none of the above work, use a Docker container with an older Debian:

```bash
# Run a Debian 11 container with your data mounted
docker run -it --rm -v /path/to/datafiles:/data debian:bullseye bash

# Inside the container
apt update && apt install -y php-cli php-dba
cd /data/NOID

# Use Method 1 (dbimport) inside the container
```

### Key differences between methods

| Aspect                 | `noid dbimport`          | `import_from_dump.php`          |
|------------------------|--------------------------|---------------------------------|
| Creates destination DB | No (requires `dbcreate`) | **Yes** (creates automatically) |
| Requires db4 handler   | **Yes**                  | No                              |
| Input source           | PHP db4 handler          | Text file (dump/JSON)           |
| Works on Debian 12+    | No                       | **Yes**                         |

### Migration script options

```bash
# Check system requirements
php scripts/import_from_dump.php --check

# Show help for creating dump files
php scripts/import_from_dump.php --dump-help

# Dry run (parse file without importing)
php scripts/import_from_dump.php -n noid_dump.txt /path/to/NOID

# Verbose mode
php scripts/import_from_dump.php -v noid_dump.txt /path/to/NOID

# Import from JSON file
php scripts/import_from_dump.php --json noid_data.json /path/to/NOID
```


Performance
-----------

### Performance: Batch Minting

For applications that need to generate many identifiers at once (e.g., bulk
imports), use the `mint()` method with a `$count` parameter. Batch minting is
significantly faster because it:

- Performs setup (template parsing, cache loading) only once per batch
- Keeps the database connection open for all IDs
- Optimizes queue processing

**Usage:**

```php
use Noid\Lib\Db;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;

// Open database
$noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);

// Single mint (returns string)
$id = Noid::mint($noid, 'contact@example.org');

// Mint multiple identifiers (returns array, max 10,000 per call)
$ids = Noid::mintMultiple($noid, 'contact@example.org', 100);

foreach ($ids as $id) {
    echo $id . "\n";
}

Db::dbclose($noid);
```

- `mint()` always returns a single identifier string (or null on error).
- `mintMultiple()` always returns an array of identifiers (may be shorter than
  requested if the minter becomes exhausted, or empty on error).

### Performance: Persistent Connections

For applications that perform many consecutive mint operations (e.g., in a loop
or request handler), enable **persistent connection mode** to avoid repeated
database open/close overhead.

When persistent mode is enabled:
- `Db::dbclose()` will not actually close the connection
- `Db::dbopen()` will reuse an existing connection if available

**Usage:**

```php
use Noid\Lib\Db;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;

// Enable persistent mode
Db::dbpersist(true);

// Multiple operations reuse the same connection
for ($i = 0; $i < 1000; $i++) {
    $noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);
    $id = Noid::mint($noid, 'contact@example.org');
    Db::dbclose($noid);  // Does not actually close in persistent mode
}

// When done, disable persistent mode and close the connection
Db::dbunpersist();
```

**API Methods:**

| Method                       | Description                                      |
|------------------------------|--------------------------------------------------|
| `Db::dbpersist($enable)`     | Enable (true) or disable (false) persistent mode |
| `Db::dbunpersist($noid)`     | Disable persistent mode and close the connection |
| `Db::isPersistent()`         | Check if persistent mode is enabled              |
| `Db::isConnected()`          | Check if a database connection is currently open |
| `Db::getCurrentNoid()`       | Get the currently open noid path, or null        |
| `Db::dbclose($noid, $force)` | Force close even in persistent mode if `$force`  |

**Notes:**
- `Db::dbunpersist()` is a convenience method that disables persistent mode
  and closes the connection in one call
- Database creation (`DB_CREATE`) always opens a fresh connection, bypassing
  persistent mode
- Persistent mode is best suited for single-database operations; if you need
  to access multiple different databases, disable persistent mode or use
  `dbclose($noid, true)` to force close

### Performance: Pre-generation Pool

For latency-sensitive applications requiring instant identifier retrieval, use
the **pre-generation pool** to generate IDs in advance.

**Usage:**

```php
use Noid\Lib\Db;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;

$noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);

// Pre-generate 100 IDs into the pool
$count = Noid::pregenerate($noid, 'contact@example.org', 100);
echo "Pre-generated $count IDs\n";

// Later, mint() returns instantly from the pool
$id = Noid::mint($noid, 'contact@example.org');

// Check how many IDs remain in the pool
$remaining = Noid::getPregenCount($noid);

Db::dbclose($noid);
```

**API Methods:**

| Method                                       | Description                             |
|----------------------------------------------|-----------------------------------------|
| `Noid::pregenerate($noid, $contact, $count)` | Pre-generate IDs into pool (max 10,000) |
| `Noid::getPregenCount($noid)`                | Get count of IDs in pool                |

**How it works:**
1. `pregenerate()` generates IDs and stores them in a FIFO pool
2. `mint()` checks the pool first before generating new IDs
3. Pool IDs are marked with circulation status 'p' (pre-generated)
4. When minted, status changes to 'i' (issued)

**Use cases:**
- Web applications requiring sub-millisecond response times
- Background cron job to refill pool during low-traffic periods
- Pre-populating IDs before a batch import operation


About the port
--------------

### Main purpose

The main purpose of this first conversion is the fidelity to the structure of
the original Perl script: each method has the equivalent code in perl and php
scripts. Furthermore, no dependency is added: the script can be run directly.
Of course, the performance is lower, mainly because the access to the database
is slower: only the generic functions are used, not the ones specific to
Berkeley.

### Random noids

When the template is a random one, the order of generated noids is different
between the perl and the php script, because the pseudo-random generated by
these languages are different and in some cases dependent of the platform too.
In Perl, before 5.20.0 (May 2014), rand() calls drand48(), random() or rand()
from the C library stdlib.h in that order. Since 5.20.0, drand48() is
implemented. In Php, rand() uses the underlying C library's rand
(see [rosettacode.org]).

So, the process uses an emulator of the rand() function based on the ground of
the C code (PerlRandom). The use of the Perl rand() is possible via the class
Noid. Anyway, the result will be the same.

### drand48 generator

The `drand48` generator (PerlRandom class) emulates Perl's drand48() Linear
Congruential Generator (LCG) algorithm to produce identical random sequences.
Since version 1.4, it uses native 64-bit arithmetic for much faster
performance.

Nevertheless, bcmath may be required for `int_rand()` with length > 32,767 and
for float operations.

Anyway, this issue is just for the PerlRandom generator: the minting uses a
maximum length of 293 (subcounter count), so it uses always native computation.

**Known limitations:**

For float numbers (`rand()`, `string_rand()`), results may differ from Perl
after the 8192nd value due to floating-point precision differences between
languages (15 vs 14 significant digits). This does not affect minting, which
only uses `int_rand()`.

### Database

- Only generic features of database are managed: no environment, no alarm for
  locking. Furthermore, the locking mechanism is different between perl and
  php, so don't use them at the same time.
- Php can read and write databases created with the perl script, but the perl
  script cannot access php ones. There is a workaround: copy the three files
  "__db.001", "__db.002" and "__db.003" in the directory "NOID". They come from
  a freshly created minter and are provided with the module in the directory
  "bdbfiles". No advanced check had been done for cross-writing databases.
- If php and perl are used, the template must not be a random one, because
  seeds are not the same and generated ids are ordered differently.
- In the php version, the elements can't be duplicated: dba_replace() is
  systematically used instead of dba_insert(). Anyway, this feature is not
  available in the perl script.
- In Perl script, $noid is a pointer to data managed from $dbname. To avoid
  this direct access to memory, harder to maintain in a high level language,
  $noid is the $dbname, that points internally to the db handle.
- Some more alphabets are implemented (see enhancements of noids above).


To do
-----

- [x] Optimize structure and process, but keep inputs, calls, and outputs.
- [ ] Seperate creation of noids and management of bindings.
- [x] Use other standard or flat db engines (mysql and simple file).
- [ ] See other todo in the perl or php scripts.
- [ ] Normalize classes (no false, but null, args, return types, etc.)
- [ ] Make bin noid as composer
- [ ] Better xml format and sql table (no reserved root, separate type, date,
      owner and value, etc.)


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

For long term noids, all events are saved in a log too.


Troubleshooting
---------------

See online issues on the [issues] page.


License
-------

* The original tool has been published by the University of California under the
BSD licence.

Permission to use, copy, modify, distribute, and sell this software and its
documentation for any purpose is hereby granted without fee, provided that (i)
the above copyright notices and this permission notice appear in all copies of
the software and related documentation, and (ii) the names of the UC Regents and
the University of California are not used in any advertising or publicity
relating to the software without the specific, prior written permission of the
University of California.

THE SOFTWARE IS PROVIDED "AS-IS" AND WITHOUT WARRANTY OF ANY KIND, EXPRESS,
IMPLIED OR OTHERWISE, INCLUDING WITHOUT LIMITATION, ANY WARRANTY OF
MERCHANTABILITY OR FITNESS FOR A PARTICULAR PURPOSE.

IN NO EVENT SHALL THE UNIVERSITY OF CALIFORNIA BE LIABLE FOR ANY SPECIAL,
INCIDENTAL, INDIRECT OR CONSEQUENTIAL DAMAGES OF ANY KIND, OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER OR NOT ADVISED
OF THE POSSIBILITY OF DAMAGE, AND ON ANY THEORY OF LIABILITY, ARISING OUT OF OR
IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.

* The php version is published under the [CeCILL-B v1.0], compatible with the
BSD one.

The exercising of this freedom is conditional upon a strong obligation of giving
credits for everybody that distributes a software incorporating a software ruled
by the current license so as all contributions to be properly identified and
acknowledged.

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.

* Contains PerlRandom, published under the [CeCILL-C v1.0].


Copyright
---------

* Author:  John A. Kunze, jak@ucop.edu, California Digital Library
* Originally created Nov. 2002 at UCSF Center for Knowledge Management
* Ported to php by Daniel Berthereau for Mines ParisTech, then improved for
  various universities.

- Copyright (c) 2002-2006 UC Regents
- Copyright (c) 2016-2024 Daniel Berthereau (see [Daniel-KM] on GitLab)


[Noid for Php]: https://gitlab.com/Daniel-KM/Noid4Php
[noid]: https://wiki.ucop.edu/display/Curation/NOID
[perl tool]: http://search.cpan.org/~jak/Noid-0.424/
[Omeka]: https://www.omeka.org
[Omeka S]: https://www.omeka.org/s
[Ark & Noid for Omeka]: https://gitlab.com/Daniel-KM/Omeka-plugin-ArkAndNoid
[Ark for Omeka S]: https://gitlab.com/Daniel-KM/Omeka-S-module-Ark
[Java]: https://confluence.ucop.edu/download/attachments/16744482/noid-java.tar.gz
[Ruby]: https://github.com/microservices/noid
[metacpan]: https://metacpan.org/pod/distribution/Noid/noid
[commands]: https://metacpan.org/pod/Noid
[fixed]: https://secure.php.net/manual/en/migration71.incompatible.php#migration71.incompatible.fixes-to-mt_rand-algorithm
[PhpUnit]: https://phpunit.de
[rosettacode.org]: https://rosettacode.org/wiki/Random_number_generator_%28included%29#Perl
[issues]: https://gitlab.com/Daniel-KM/Noid4Php/issues
[CeCILL-B v1.0]: https://www.cecill.info/licences/Licence_CeCILL-B_V1-en.txt
[CeCILL-C v1.0]: https://www.cecill.info/licences/Licence_CeCILL-C_V1-en.txt
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
