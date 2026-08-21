#!/usr/bin/env sh
set -eu

if [ ! -s "$PGDATA/PG_VERSION" ]; then
  mkdir -p "$PGDATA"
  chown -R postgres:postgres "$(dirname "$(dirname "$PGDATA")")"
  chmod 0700 "$PGDATA"

  export PGPASSWORD="$POSTGRES_REPLICATION_PASSWORD"

  until gosu postgres pg_isready --host postgres-primary --port 5432 --username "$POSTGRES_REPLICATION_USER" --dbname postgres; do
    sleep 1
  done

  gosu postgres pg_basebackup \
    --host postgres-primary \
    --port 5432 \
    --username "$POSTGRES_REPLICATION_USER" \
    --pgdata "$PGDATA" \
    --wal-method stream \
    --write-recovery-conf
fi

exec docker-entrypoint.sh postgres
