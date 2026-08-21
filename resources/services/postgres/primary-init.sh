#!/usr/bin/env sh
set -eu

psql \
  --username "$POSTGRES_USER" \
  --dbname "$POSTGRES_DB" \
  --set=replication_user="$POSTGRES_REPLICATION_USER" \
  --set=replication_password="$POSTGRES_REPLICATION_PASSWORD" <<'SQL'
CREATE ROLE :"replication_user" WITH REPLICATION LOGIN PASSWORD :'replication_password';
SQL

printf '%s\n' "host replication $POSTGRES_REPLICATION_USER all scram-sha-256" >> "$PGDATA/pg_hba.conf"
