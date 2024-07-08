#!/usr/bin/env perl
#
# Export BerkeleyDB database to JSON format.
#
# This script reads a BerkeleyDB file and exports all key-value pairs to a JSON file.
# Useful for migrating Noid databases from BerkeleyDB to LMDB when the PHP db4 handler
# is no longer available (e.g., on Debian 12+).
#
# Prerequisites:
#     - Perl 5.10+
#     - BerkeleyDB module: cpan BerkeleyDB
#       Or on Debian/Ubuntu: apt install libberkeleydb-perl
#     - JSON module: cpan JSON (usually included)
#       Or on Debian/Ubuntu: apt install libjson-perl
#
# Usage:
#     perl export_bdb_to_json.pl /path/to/noid.bdb output.json
#
# Then import into LMDB using:
#     php import_from_dump.php --json output.json /path/to/datafiles/NOID
#
# Author: Claude (migration script for Noid4Php)
# License: BSD
#

use strict;
use warnings;
use utf8;
use Getopt::Long;
use Pod::Usage;
use File::Basename;

# Check for required modules
my $has_berkeleydb = eval { require BerkeleyDB; 1 };
my $has_json = eval { require JSON; 1 };

sub check_requirements {
    my $ok = 1;

    unless ($has_berkeleydb) {
        print STDERR "Error: BerkeleyDB module is not installed.\n\n";
        print STDERR "Install it using one of these methods:\n\n";
        print STDERR "  Debian/Ubuntu:\n";
        print STDERR "    sudo apt install libberkeleydb-perl\n\n";
        print STDERR "  Using CPAN:\n";
        print STDERR "    cpan BerkeleyDB\n\n";
        print STDERR "  If CPAN install fails, install libdb development files first:\n";
        print STDERR "    Debian/Ubuntu: sudo apt install libdb5.3-dev\n";
        print STDERR "    RHEL/CentOS:   sudo dnf install libdb-devel\n\n";
        $ok = 0;
    }

    unless ($has_json) {
        print STDERR "Error: JSON module is not installed.\n\n";
        print STDERR "Install it using one of these methods:\n\n";
        print STDERR "  Debian/Ubuntu:\n";
        print STDERR "    sudo apt install libjson-perl\n\n";
        print STDERR "  Using CPAN:\n";
        print STDERR "    cpan JSON\n\n";
        $ok = 0;
    }

    return $ok;
}

sub export_bdb_to_json {
    my ($bdb_path, $json_path, $verbose) = @_;

    require BerkeleyDB;
    require JSON;

    unless (-e $bdb_path) {
        die "BerkeleyDB file not found: $bdb_path\n";
    }

    print "Opening database: $bdb_path\n" if $verbose;

    # Try to open as BTREE first
    my $db;
    my %data;

    # Try BTREE
    $db = BerkeleyDB::Btree->new(
        -Filename => $bdb_path,
        -Flags    => BerkeleyDB::DB_RDONLY()
    );

    # Try HASH if BTREE fails
    unless ($db) {
        $db = BerkeleyDB::Hash->new(
            -Filename => $bdb_path,
            -Flags    => BerkeleyDB::DB_RDONLY()
        );
    }

    unless ($db) {
        die "Cannot open database: $bdb_path\n" .
            "Error: $BerkeleyDB::Error\n";
    }

    print "Database opened successfully\n" if $verbose;

    # Read all records
    my $cursor = $db->db_cursor();
    my ($key, $value) = ('', '');
    my $count = 0;
    my $errors = 0;

    while ($cursor->c_get($key, $value, BerkeleyDB::DB_NEXT()) == 0) {
        # Handle encoding - try UTF-8 first
        my $decoded_key = $key;
        my $decoded_value = $value;

        eval {
            utf8::decode($decoded_key);
            utf8::decode($decoded_value);
        };

        if ($@) {
            $errors++;
            print STDERR "Warning: Could not decode record $count: $@\n" if $verbose;
            next;
        }

        $data{$decoded_key} = $decoded_value;
        $count++;

        if ($verbose && $count % 1000 == 0) {
            print "  Read $count records...\n";
        }
    }

    $cursor->c_close();
    $db->db_close();

    print "Total records read: $count\n" if $verbose;
    print "Records with errors: $errors\n" if $verbose && $errors > 0;

    # Write to JSON
    print "Writing to JSON: $json_path\n" if $verbose;

    my $json = JSON->new->utf8->pretty->canonical;
    my $json_text = $json->encode(\%data);

    open(my $fh, '>:encoding(UTF-8)', $json_path)
        or die "Cannot write to $json_path: $!\n";
    print $fh $json_text;
    close($fh);

    if ($verbose) {
        my $file_size = -s $json_path;
        printf "JSON file size: %s bytes\n", commify($file_size);
    }

    return $count;
}

sub show_sample {
    my ($bdb_path, $num_records) = @_;

    require BerkeleyDB;

    # Try BTREE first
    my $db = BerkeleyDB::Btree->new(
        -Filename => $bdb_path,
        -Flags    => BerkeleyDB::DB_RDONLY()
    );

    # Try HASH if BTREE fails
    unless ($db) {
        $db = BerkeleyDB::Hash->new(
            -Filename => $bdb_path,
            -Flags    => BerkeleyDB::DB_RDONLY()
        );
    }

    unless ($db) {
        die "Cannot open database: $bdb_path\n";
    }

    print "\nSample records (first $num_records):\n\n";
    print "-" x 60 . "\n";

    my $cursor = $db->db_cursor();
    my ($key, $value) = ('', '');
    my $count = 0;

    while ($cursor->c_get($key, $value, BerkeleyDB::DB_NEXT()) == 0 && $count < $num_records) {
        my $decoded_key = $key;
        my $decoded_value = $value;

        eval {
            utf8::decode($decoded_key);
            utf8::decode($decoded_value);
        };

        # Truncate long values
        if (length($decoded_value) > 50) {
            $decoded_value = substr($decoded_value, 0, 50) . "...";
        }

        print "  $decoded_key\n";
        print "    => $decoded_value\n\n";

        $count++;
    }

    $cursor->c_close();
    $db->db_close();

    print "-" x 60 . "\n";
}

sub commify {
    my $num = shift;
    $num = reverse $num;
    $num =~ s/(\d{3})(?=\d)(?!\d*\.)/$1,/g;
    return reverse $num;
}

sub main {
    my $verbose = 0;
    my $sample = 0;
    my $num_sample = 10;
    my $help = 0;

    GetOptions(
        'verbose|v' => \$verbose,
        'sample|s'  => \$sample,
        'num-sample|n=i' => \$num_sample,
        'help|h'    => \$help,
    ) or pod2usage(2);

    if ($help) {
        print_usage();
        return 0;
    }

    # Check requirements
    unless (check_requirements()) {
        return 1;
    }

    # Get positional arguments
    my $bdb_file = shift @ARGV;
    my $json_file = shift @ARGV;

    unless ($bdb_file) {
        print_usage();
        return 1;
    }

    unless (-e $bdb_file) {
        print STDERR "Error: File not found: $bdb_file\n";
        return 1;
    }

    # Sample mode
    if ($sample) {
        eval {
            show_sample($bdb_file, $num_sample);
        };
        if ($@) {
            print STDERR "Error reading database: $@\n";
            return 1;
        }
        return 0;
    }

    # Set default output file
    unless ($json_file) {
        my ($name, $path, $suffix) = fileparse($bdb_file, qr/\.[^.]*/);
        $json_file = $path . $name . '.json';
    }

    # Export
    eval {
        my $count = export_bdb_to_json($bdb_file, $json_file, $verbose);
        print "\nSuccessfully exported $count records to $json_file\n";
        print "\nNext step: Import into LMDB using:\n";
        print "  php import_from_dump.php --json $json_file /path/to/datafiles/NOID\n";
    };
    if ($@) {
        print STDERR "Error: $@\n";
        return 1;
    }

    return 0;
}

sub print_usage {
    print <<"USAGE";
Export BerkeleyDB database to JSON format for Noid4Php migration.

Usage:
  $0 [options] <bdb_file> [json_file]

Arguments:
  bdb_file    Path to the BerkeleyDB file (e.g., noid.bdb)
  json_file   Output JSON file path (default: <bdb_file>.json)

Options:
  -v, --verbose       Show detailed progress
  -s, --sample        Show sample records without exporting
  -n, --num-sample N  Number of sample records to show (default: 10)
  -h, --help          Show this help message

Examples:
  $0 /path/to/datafiles/NOID/noid.bdb noid_data.json
  $0 -v /path/to/noid.bdb output.json
  $0 --sample /path/to/noid.bdb

After export, import into LMDB using:
  php import_from_dump.php --json noid_data.json /path/to/datafiles/NOID

Prerequisites:
  Debian/Ubuntu:
    sudo apt install libberkeleydb-perl libjson-perl

  CPAN:
    cpan BerkeleyDB JSON

USAGE
}

exit(main());
