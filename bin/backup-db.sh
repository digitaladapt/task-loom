#!/bin/sh
#
# backup-db.sh — WAL-aware snapshot backup of the TaskLoom SQLite database.
# Pattern proven in task-weaver: the .backup API takes a consistent snapshot
# even while WAL writes are in flight.
#
# Usage:
#   bin/backup-db.sh [output-dir]     # default: var/backups

set -e

DB="${TASKLOOM_DB:-$(dirname "$0")/../data/taskloom.db}"
OUT_DIR="${1:-$(dirname "$0")/../var/backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

if [ ! -f "$DB" ]; then
    echo "No database at $DB" >&2
    exit 1
fi

mkdir -p "$OUT_DIR"
OUT="$OUT_DIR/taskloom-$STAMP.db"

# The SQLite CLI's .backup uses the online-backup API: readers keep reading,
# writers keep writing, and the snapshot is transactionally consistent.
sqlite3 "$DB" ".backup '$OUT'"

echo "Backup written: $OUT"