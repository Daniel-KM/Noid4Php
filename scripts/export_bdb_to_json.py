#!/usr/bin/env python3
"""
Export BerkeleyDB database to JSON format.

This script reads a BerkeleyDB file and exports all key-value pairs to a JSON file.
Useful for migrating Noid databases from BerkeleyDB to LMDB when the PHP db4 handler
is no longer available (e.g., on Debian 12+).

Prerequisites:
    - Python 3.6+
    - bsddb3 module: pip install bsddb3
      Or on Debian/Ubuntu: apt install python3-bsddb3

Usage:
    python3 export_bdb_to_json.py /path/to/noid.bdb output.json

Then import into LMDB using:
    php import_from_dump.php --json output.json /path/to/datafiles/NOID

Author: Claude (migration script for Noid4Php)
License: BSD
"""

import sys
import os
import json
import argparse

def check_bsddb3():
    """Check if bsddb3 module is available and provide installation instructions."""
    try:
        import bsddb3
        return True
    except ImportError:
        print("Error: bsddb3 module is not installed.", file=sys.stderr)
        print(file=sys.stderr)
        print("Install it using one of these methods:", file=sys.stderr)
        print(file=sys.stderr)
        print("  Debian/Ubuntu:", file=sys.stderr)
        print("    sudo apt install python3-bsddb3", file=sys.stderr)
        print(file=sys.stderr)
        print("  Using pip (may require libdb-dev):", file=sys.stderr)
        print("    pip3 install bsddb3", file=sys.stderr)
        print(file=sys.stderr)
        print("  If pip install fails, install libdb development files first:", file=sys.stderr)
        print("    Debian/Ubuntu: sudo apt install libdb5.3-dev", file=sys.stderr)
        print("    RHEL/CentOS:   sudo dnf install libdb-devel", file=sys.stderr)
        return False

def export_bdb_to_json(bdb_path, json_path, verbose=False):
    """
    Export BerkeleyDB database to JSON file.

    Args:
        bdb_path: Path to the BerkeleyDB file
        json_path: Path to output JSON file
        verbose: Print progress information

    Returns:
        Number of records exported
    """
    import bsddb3.db as db

    if not os.path.exists(bdb_path):
        raise FileNotFoundError(f"BerkeleyDB file not found: {bdb_path}")

    # Detect database type
    if verbose:
        print(f"Opening database: {bdb_path}")

    bdb = db.DB()

    # Try to open as BTREE first (most common), then HASH
    try:
        bdb.open(bdb_path, None, db.DB_BTREE, db.DB_RDONLY)
        if verbose:
            print("Database type: BTREE")
    except db.DBInvalidArgError:
        try:
            bdb.open(bdb_path, None, db.DB_HASH, db.DB_RDONLY)
            if verbose:
                print("Database type: HASH")
        except db.DBInvalidArgError:
            # Try without specifying type
            bdb.open(bdb_path, None, db.DB_UNKNOWN, db.DB_RDONLY)
            if verbose:
                print("Database type: UNKNOWN (auto-detected)")

    # Read all records
    data = {}
    cursor = bdb.cursor()
    rec = cursor.first()
    count = 0
    errors = 0

    while rec:
        key_bytes, value_bytes = rec
        try:
            # Try UTF-8 decoding first
            key = key_bytes.decode('utf-8')
            value = value_bytes.decode('utf-8')
        except UnicodeDecodeError:
            # Fall back to latin-1 which can decode any byte sequence
            try:
                key = key_bytes.decode('latin-1')
                value = value_bytes.decode('latin-1')
            except Exception as e:
                if verbose:
                    print(f"Warning: Could not decode record {count}: {e}", file=sys.stderr)
                errors += 1
                rec = cursor.next()
                continue

        data[key] = value
        count += 1

        if verbose and count % 1000 == 0:
            print(f"  Read {count} records...")

        rec = cursor.next()

    cursor.close()
    bdb.close()

    if verbose:
        print(f"Total records read: {count}")
        if errors > 0:
            print(f"Records with errors: {errors}")

    # Write to JSON
    if verbose:
        print(f"Writing to JSON: {json_path}")

    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

    if verbose:
        file_size = os.path.getsize(json_path)
        print(f"JSON file size: {file_size:,} bytes")

    return count

def show_sample(bdb_path, num_records=10):
    """Show sample records from the database."""
    import bsddb3.db as db

    bdb = db.DB()
    try:
        bdb.open(bdb_path, None, db.DB_BTREE, db.DB_RDONLY)
    except db.DBInvalidArgError:
        try:
            bdb.open(bdb_path, None, db.DB_HASH, db.DB_RDONLY)
        except db.DBInvalidArgError:
            bdb.open(bdb_path, None, db.DB_UNKNOWN, db.DB_RDONLY)

    print(f"\nSample records (first {num_records}):\n")
    print("-" * 60)

    cursor = bdb.cursor()
    rec = cursor.first()
    count = 0

    while rec and count < num_records:
        key_bytes, value_bytes = rec
        try:
            key = key_bytes.decode('utf-8')
            value = value_bytes.decode('utf-8')
        except UnicodeDecodeError:
            key = key_bytes.decode('latin-1')
            value = value_bytes.decode('latin-1')

        # Truncate long values
        if len(value) > 50:
            value = value[:50] + "..."

        print(f"  {key}")
        print(f"    => {value}")
        print()

        count += 1
        rec = cursor.next()

    cursor.close()
    bdb.close()
    print("-" * 60)

def main():
    parser = argparse.ArgumentParser(
        description='Export BerkeleyDB database to JSON format for Noid4Php migration.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog='''
Examples:
  %(prog)s /path/to/datafiles/NOID/noid.bdb noid_data.json
  %(prog)s -v /path/to/noid.bdb output.json
  %(prog)s --sample /path/to/noid.bdb

After export, import into LMDB using:
  php import_from_dump.php --json noid_data.json /path/to/datafiles/NOID
'''
    )

    parser.add_argument('bdb_file',
                        help='Path to the BerkeleyDB file (e.g., noid.bdb)')
    parser.add_argument('json_file', nargs='?', default=None,
                        help='Output JSON file path (default: <bdb_file>.json)')
    parser.add_argument('-v', '--verbose', action='store_true',
                        help='Show detailed progress')
    parser.add_argument('-s', '--sample', action='store_true',
                        help='Show sample records without exporting')
    parser.add_argument('-n', '--num-sample', type=int, default=10,
                        help='Number of sample records to show (default: 10)')

    args = parser.parse_args()

    # Check bsddb3 availability
    if not check_bsddb3():
        return 1

    # Verify input file exists
    if not os.path.exists(args.bdb_file):
        print(f"Error: File not found: {args.bdb_file}", file=sys.stderr)
        return 1

    # Sample mode
    if args.sample:
        try:
            show_sample(args.bdb_file, args.num_sample)
            return 0
        except Exception as e:
            print(f"Error reading database: {e}", file=sys.stderr)
            return 1

    # Set default output file
    if args.json_file is None:
        base = os.path.splitext(args.bdb_file)[0]
        args.json_file = base + '.json'

    # Export
    try:
        count = export_bdb_to_json(args.bdb_file, args.json_file, args.verbose)
        print(f"\nSuccessfully exported {count} records to {args.json_file}")
        print(f"\nNext step: Import into LMDB using:")
        print(f"  php import_from_dump.php --json {args.json_file} /path/to/datafiles/NOID")
        return 0
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        return 1

if __name__ == '__main__':
    sys.exit(main())
