#!/usr/bin/env bash
set -eo pipefail

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<'SQL'
CREATE USER IF NOT EXISTS 'phpforge_repl'@'%' IDENTIFIED WITH caching_sha2_password BY 'phpforge_repl';
GRANT REPLICATION SLAVE ON *.* TO 'phpforge_repl'@'%';
FLUSH PRIVILEGES;
SQL
