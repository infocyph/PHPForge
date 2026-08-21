#!/usr/bin/env bash
set -euo pipefail

database="${PHPFORGE_SERVICE_DATABASE:-phpforge}"
password="${MSSQL_SA_PASSWORD:?MSSQL_SA_PASSWORD is required}"
sqlcmd=(/opt/mssql-tools18/bin/sqlcmd -b -C -U sa -P "$password")

if [[ ! "$database" =~ ^[A-Za-z][A-Za-z0-9_]*$ ]]; then
  echo "Invalid SQL Server database name: $database" >&2
  exit 1
fi

"${sqlcmd[@]}" -S mssql-primary -Q "
CREATE MASTER KEY ENCRYPTION BY PASSWORD = 'PHPForge_Ag_123!';
CREATE CERTIFICATE phpforge_primary_cert WITH SUBJECT = 'PHPForge primary AG certificate';
BACKUP CERTIFICATE phpforge_primary_cert TO FILE = '/var/opt/mssql/shared/primary.cer';
CREATE ENDPOINT phpforge_hadr STATE = STARTED AS TCP (LISTENER_PORT = 5022, LISTENER_IP = ALL)
FOR DATABASE_MIRRORING (AUTHENTICATION = CERTIFICATE phpforge_primary_cert, ROLE = ALL);
"

"${sqlcmd[@]}" -S mssql-replica -Q "
CREATE MASTER KEY ENCRYPTION BY PASSWORD = 'PHPForge_Ag_123!';
CREATE CERTIFICATE phpforge_replica_cert WITH SUBJECT = 'PHPForge replica AG certificate';
BACKUP CERTIFICATE phpforge_replica_cert TO FILE = '/var/opt/mssql/shared/replica.cer';
CREATE ENDPOINT phpforge_hadr STATE = STARTED AS TCP (LISTENER_PORT = 5022, LISTENER_IP = ALL)
FOR DATABASE_MIRRORING (AUTHENTICATION = CERTIFICATE phpforge_replica_cert, ROLE = ALL);
"

"${sqlcmd[@]}" -S mssql-primary -Q "
CREATE LOGIN phpforge_ag_login WITH PASSWORD = 'R3plica!Link#2026';
CREATE USER phpforge_ag_user FOR LOGIN phpforge_ag_login;
CREATE CERTIFICATE phpforge_replica_cert AUTHORIZATION phpforge_ag_user FROM FILE = '/var/opt/mssql/shared/replica.cer';
GRANT CONNECT ON ENDPOINT::phpforge_hadr TO phpforge_ag_login;
"

"${sqlcmd[@]}" -S mssql-replica -Q "
CREATE LOGIN phpforge_ag_login WITH PASSWORD = 'R3plica!Link#2026';
CREATE USER phpforge_ag_user FOR LOGIN phpforge_ag_login;
CREATE CERTIFICATE phpforge_primary_cert AUTHORIZATION phpforge_ag_user FROM FILE = '/var/opt/mssql/shared/primary.cer';
GRANT CONNECT ON ENDPOINT::phpforge_hadr TO phpforge_ag_login;
"

"${sqlcmd[@]}" -S mssql-primary -Q "
CREATE DATABASE [$database];
ALTER DATABASE [$database] SET RECOVERY FULL;
BACKUP DATABASE [$database] TO DISK = N'/var/opt/mssql/shared/phpforge.bak' WITH INIT;
BACKUP LOG [$database] TO DISK = N'/var/opt/mssql/shared/phpforge.trn' WITH INIT;
CREATE AVAILABILITY GROUP phpforge_ag
WITH (CLUSTER_TYPE = NONE)
FOR DATABASE [$database]
REPLICA ON
  N'mssql-primary' WITH (
    ENDPOINT_URL = N'TCP://mssql-primary:5022',
    AVAILABILITY_MODE = SYNCHRONOUS_COMMIT,
    FAILOVER_MODE = MANUAL,
    SEEDING_MODE = AUTOMATIC,
    SECONDARY_ROLE (ALLOW_CONNECTIONS = ALL)
  ),
  N'mssql-replica' WITH (
    ENDPOINT_URL = N'TCP://mssql-replica:5022',
    AVAILABILITY_MODE = SYNCHRONOUS_COMMIT,
    FAILOVER_MODE = MANUAL,
    SEEDING_MODE = AUTOMATIC,
    SECONDARY_ROLE (ALLOW_CONNECTIONS = ALL)
  );
"

"${sqlcmd[@]}" -S mssql-replica -Q "
ALTER AVAILABILITY GROUP phpforge_ag JOIN WITH (CLUSTER_TYPE = NONE);
ALTER AVAILABILITY GROUP phpforge_ag GRANT CREATE ANY DATABASE;
"
