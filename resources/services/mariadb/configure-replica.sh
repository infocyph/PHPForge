#!/usr/bin/env bash
set -euo pipefail

mariadb -h mariadb-replica -uroot -p"${MARIADB_ROOT_PASSWORD}" --connect-timeout=5 <<'SQL'
CHANGE MASTER TO MASTER_HOST='mariadb-primary', MASTER_PORT=3306, MASTER_USER='phpforge_repl', MASTER_PASSWORD='phpforge_repl', MASTER_USE_GTID=slave_pos;
START SLAVE;
SQL
