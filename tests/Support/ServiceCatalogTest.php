<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ServiceCatalog;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('defines the complete supported integration service set once', function (): void {
    expect(ServiceCatalog::names())->toBe([
        'mysql',
        'mariadb',
        'postgres',
        'mssql',
        'sqlite',
        'mongodb',
        'redis',
        'valkey',
        'memcached',
        'rabbitmq',
        'nats',
        'mailpit',
        'elasticsearch',
        'scylladb',
    ])->and(ServiceCatalog::validateDefinitions())->toBe([]);
});

it('resolves unique extensions and topology profiles from the catalog', function (): void {
    expect(ServiceCatalog::extensions(['mysql', 'mariadb', 'mongodb', 'sqlite']))
        ->toBe(['mongodb', 'pdo_mysql', 'pdo_sqlite'])
        ->and(ServiceCatalog::profiles(
            ['mysql', 'mongodb', 'sqlite'],
            ['mysql' => 'replica', 'mongodb' => 'replica-set'],
        ))->toBe(['mysql-replica', 'mongodb-replica']);
});

it('keeps every catalog Compose profile represented in compose configuration', function (): void {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/resources/services/compose.yml');
    $profiles = [];

    foreach ($compose['services'] ?? [] as $service) {
        foreach ($service['profiles'] ?? [] as $profile) {
            $profiles[$profile] = true;
        }
    }

    foreach (ServiceCatalog::all() as $definition) {
        foreach ($definition['topologies'] as $profile) {
            if (is_string($profile)) {
                expect($profiles)->toHaveKey($profile);
            }
        }
    }
});

it('resolves canonical workflow service outputs including empty objects', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/resolve-services.php']);
    $process->setEnv([
        'INTEGRATION_SERVICES_INPUT' => '["mysql","mongodb","sqlite"]',
        'SERVICE_TOPOLOGIES_INPUT' => '{"mysql":"replica","mongodb":"replica-set"}',
        'PHP_EXTENSIONS_INPUT' => 'ext-json, curl',
    ]);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    expect($result['compose_profiles'])->toBe('["mongodb-replica","mysql-replica"]')
        ->and($result['php_extensions'])->toBe('curl, json, mongodb, pdo_mysql, pdo_sqlite')
        ->and($result['has_external_services'])->toBe('true');

    $empty = new Process([PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/resolve-services.php']);
    $empty->setEnv(['INTEGRATION_SERVICES_INPUT' => '[]', 'SERVICE_TOPOLOGIES_INPUT' => '{}']);
    $empty->mustRun();

    expect(json_decode($empty->getOutput(), true, 512, JSON_THROW_ON_ERROR)['service_topologies'])->toBe('{}');
});

it('maps every catalog probe to protocol-level readiness logic', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/.github/scripts/verify-and-wait-services.sh');

    foreach (ServiceCatalog::all() as $definition) {
        expect(preg_match('/^\s+[^\n]*\b'.preg_quote($definition['probe'], '/').'(?:\||\))/m', $script))->toBe(1);
    }

    expect($script)->toContain('phpforge_replication_probe')
        ->toContain('replication_token=')
        ->not->toContain('for ($attempt = 0; $attempt < 30; $attempt++)')
        ->toContain('replSetGetStatus')
        ->toContain('/api/health/checks/alarms')
        ->toContain('/jsz')
        ->toContain('/v1/info');
});

it('uses the versioned runtime manifest as a valid unique matrix', function (): void {
    $runtime = require dirname(__DIR__, 2).'/resources/runtime.php';

    expect($runtime['php_versions'] ?? null)->toBe(['8.4', '8.5']);
});
