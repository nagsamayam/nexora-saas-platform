#!/bin/sh
set -eu

: "${POSTGRES_REPLICATION_USER:?POSTGRES_REPLICATION_USER is required}"
: "${POSTGRES_REPLICATION_PASSWORD:?POSTGRES_REPLICATION_PASSWORD is required}"

echo "===================================================="
echo "PROVISIONING SECURE POSTGRESQL REPLICATION"
echo "===================================================="

# Create the dedicated replication role safely using dynamic SQL
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -v replication_user="$POSTGRES_REPLICATION_USER" -v replication_password="$POSTGRES_REPLICATION_PASSWORD" <<'EOSQL'

SELECT format(
'CREATE ROLE %I WITH REPLICATION LOGIN PASSWORD %L',
:'replication_user',
:'replication_password'
)
WHERE NOT EXISTS (
SELECT 1
FROM pg_roles
WHERE rolname = :'replication_user'
)\gexec

-- Create the physical replication slot if it does not already exist.
SELECT pg_create_physical_replication_slot('laravel_replica_slot')
WHERE NOT EXISTS (
SELECT 1
FROM pg_replication_slots
WHERE slot_name = 'laravel_replica_slot'
);

EOSQL

# Allow the dedicated replication user to connect from Docker networks.
HBA_LINE="host replication ${POSTGRES_REPLICATION_USER} 0.0.0.0/0 scram-sha-256"

grep -Fqx "$HBA_LINE" "$PGDATA/pg_hba.conf" || echo "$HBA_LINE" >> "$PGDATA/pg_hba.conf"

echo "===================================================="
echo "MASTER REPLICATION PROVISIONING COMPLETE"
echo "===================================================="
