#!/usr/bin/env bash
set -euo pipefail

mysql -h mysql-replica -uroot -p"${MYSQL_ROOT_PASSWORD}" --connect-timeout=5 <<'SQL'
CHANGE REPLICATION SOURCE TO SOURCE_HOST='mysql-primary', SOURCE_PORT=3306, SOURCE_USER='phpforge_repl', SOURCE_PASSWORD='phpforge_repl', SOURCE_AUTO_POSITION=1, GET_SOURCE_PUBLIC_KEY=1;
START REPLICA;
SQL
