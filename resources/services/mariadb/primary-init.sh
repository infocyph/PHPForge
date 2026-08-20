#!/usr/bin/env bash
set -eo pipefail

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<'SQL'
CREATE USER IF NOT EXISTS 'phpforge_repl'@'%' IDENTIFIED BY 'phpforge_repl';
GRANT REPLICATION SLAVE ON *.* TO 'phpforge_repl'@'%';
FLUSH PRIVILEGES;
SQL
