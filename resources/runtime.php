<?php

declare(strict_types=1);

return [
    'php_versions' => ['8.4', '8.5', '8.6'],
    'service_client_versions' => [
        'mssql_odbc' => '18',
    ],
    'service_images' => [
        'mysql' => 'mysql:26.10',
        'mariadb' => 'mariadb:13.0',
        'postgres' => 'postgres:19-alpine',
        'mssql' => 'mcr.microsoft.com/mssql/server:2025-CU11-ubuntu-22.04',
        'mongodb' => 'mongo:9.0.8',
        'redis' => 'redis:8.12-alpine',
        'valkey' => 'valkey/valkey:9.2-alpine',
        'memcached' => 'memcached:1.6.45-alpine',
        'rabbitmq' => 'rabbitmq:4.4-management-alpine',
        'nats' => 'nats:2.15-alpine',
        'mailpit' => 'axllent/mailpit:v1.34',
        'elasticsearch' => 'docker.elastic.co/elasticsearch/elasticsearch:9.5.7',
        'scylladb' => 'scylladb/scylla:2026.3',
    ],
    'service_support_images' => [
        'mssql-shared-init' => 'alpine:3.24',
    ],
];
