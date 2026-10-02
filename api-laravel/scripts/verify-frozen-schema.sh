#!/usr/bin/env bash
# M0 exit gate: prove the Laravel migration loads the frozen schema faithfully.
#
# Creates a reference database in the same cluster, loads the frozen SQL
# directly with psql, then diffs the two catalogs (tables, columns, defaults,
# constraints, indexes, enum types). Any difference means the loader, the
# freeze filter, or the frozen file drifted.
#
# Usage: scripts/verify-frozen-schema.sh [host] [port] [db] [user] [password]
set -euo pipefail

HOST="${1:-127.0.0.1}"
PORT="${2:-5433}"
DB="${3:-lago}"
USER="${4:-postgres}"
export PGPASSWORD="${5:-postgres}"

REF_DB="lago_frozen_reference"

PSQL="docker exec -i lago-laravel-pg psql -U ${USER} -v ON_ERROR_STOP=1"

# 1. Fresh reference DB loaded by raw psql (the ground truth for the frozen file).
$PSQL -d postgres -c "DROP DATABASE IF EXISTS ${REF_DB};" -q
$PSQL -d postgres -c "CREATE DATABASE ${REF_DB};" -q
$PSQL -d "${REF_DB}" < database/frozen/structure.sql > /dev/null

# 2. Canonical catalog dump for a database (runs inside the container against
#    a db name given as $1).
catalog() {
  $PSQL -d "$1" -tA <<'SQL'
WITH objects AS (
  -- Columns (with type, default, nullability, identity)
  SELECT format('col|%s.%s|%s|%s|%s|%s',
    c.table_name, c.column_name, c.data_type, c.udt_name,
    coalesce(c.column_default, ''), c.is_nullable)
  FROM information_schema.columns c
  WHERE c.table_schema = 'public' AND c.table_name <> 'migrations'
  UNION ALL
  -- Tables and views
  SELECT format('table|%s|%s', t.table_name, t.table_type)
  FROM information_schema.tables t
  WHERE t.table_schema = 'public' AND t.table_name <> 'migrations'
  UNION ALL
  -- Constraints
  SELECT format('con|%s|%s|%s|%s', con.conname, con.contype,
    pg_get_constraintdef(con.oid, true), cl.relname)
  FROM pg_constraint con
  JOIN pg_class cl ON cl.oid = con.conrelid
  JOIN pg_namespace ns ON ns.oid = cl.relnamespace
  WHERE ns.nspname = 'public' AND cl.relname <> 'migrations'
  UNION ALL
  -- Indexes
  SELECT format('idx|%s|%s', cl.relname, pg_get_indexdef(idx.indexrelid, 0, true))
  FROM pg_index idx
  JOIN pg_class cl ON cl.oid = idx.indrelid
  JOIN pg_namespace ns ON ns.oid = cl.relnamespace
  WHERE ns.nspname = 'public' AND cl.relname <> 'migrations'
  UNION ALL
  -- Enum types and their values
  SELECT format('enum|%s|%s', t.typname,
    (SELECT string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder)
     FROM pg_enum e WHERE e.enumtypid = t.oid))
  FROM pg_type t
  JOIN pg_namespace ns ON ns.oid = t.typnamespace
  WHERE ns.nspname = 'public' AND t.typtype = 'e'
)
SELECT * FROM objects ORDER BY 1;
SQL
}

MIGRATED=$(catalog "${DB}")
REFERENCE=$(catalog "${REF_DB}")

$PSQL -d postgres -c "DROP DATABASE ${REF_DB};" -q

if [ "$MIGRATED" = "$REFERENCE" ]; then
  echo "FROZEN SCHEMA VERIFIED: Laravel-migrated DB matches psql-loaded reference exactly."
else
  echo "FROZEN SCHEMA MISMATCH:" >&2
  diff <(echo "$REFERENCE") <(echo "$MIGRATED") >&2 || true
  exit 1
fi
