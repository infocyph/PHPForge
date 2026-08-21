<?php

declare(strict_types=1);

return [
    'php_versions' => ['8.4', '8.5'],
    'service_client_versions' => [
        'mssql_odbc' => '18',
    ],
    'service_images' => [
        'mysql' => 'mysql:9.7',
        'mariadb' => 'mariadb:12.3',
        'postgres' => 'postgres:18-alpine',
        'mssql' => 'mcr.microsoft.com/mssql/server:2025-latest',
        'mongodb' => 'mongo:8.3',
        'redis' => 'redis:8.10-alpine',
        'valkey' => 'valkey/valkey:9.1-alpine',
        'memcached' => 'memcached:1.6.45-alpine',
        'rabbitmq' => 'rabbitmq:4.3-management-alpine',
        'nats' => 'nats:2.14-alpine',
        'mailpit' => 'axllent/mailpit:v1.30',
        'elasticsearch' => 'docker.elastic.co/elasticsearch/elasticsearch:9.5.0',
        'scylladb' => 'scylladb/scylla:2026.2',
    ],
    'service_support_images' => [
        'mssql-shared-init' => 'alpine:3.24',
    ],
];
