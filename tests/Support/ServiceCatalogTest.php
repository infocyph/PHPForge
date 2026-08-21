<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\ServiceCommand;
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

it('resolves every supported non-standalone topology', function (): void {
    $services = ServiceCatalog::names();
    $topologies = [
        'mysql' => 'replica',
        'mariadb' => 'replica',
        'postgres' => 'replica',
        'mssql' => 'availability-group',
        'mongodb' => 'replica-set',
        'redis' => 'replica',
        'valkey' => 'replica',
        'rabbitmq' => 'cluster',
        'nats' => 'cluster',
        'elasticsearch' => 'cluster',
        'scylladb' => 'cluster',
    ];

    expect(ServiceCatalog::validate($services, $topologies))->toBe([])
        ->and(ServiceCatalog::profiles($services, $topologies))->toBe([
            'mysql-replica',
            'mariadb-replica',
            'postgres-replica',
            'mssql-availability-group',
            'mongodb-replica',
            'redis-replica',
            'valkey-replica',
            'memcached',
            'rabbitmq-cluster',
            'nats-cluster',
            'mailpit',
            'elasticsearch-cluster',
            'scylladb-cluster',
        ]);
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

it('exports every catalog environment variable in local and workflow orchestration', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $workflowEnvironment = $workflow['jobs']['run']['env'] ?? [];
    $command = new ServiceCommand('up');
    $method = new ReflectionMethod(ServiceCommand::class, 'serviceEnvironment');
    $localEnvironment = $method->invoke($command, ServiceCatalog::names(), []);

    expect($workflowEnvironment)->toBeArray()
        ->and($localEnvironment)->toBeArray()
        ->and($localEnvironment)->not->toHaveKey('PHPFORGE_MSSQL_PASSWORD')
        ->and($localEnvironment['IC_MSSQL_PASSWORD'] ?? null)->toBe($localEnvironment['IC_SERVICE_PASSWORD'] ?? null);

    foreach (ServiceCatalog::all() as $definition) {
        foreach ($definition['environment'] as $variable) {
            expect($workflowEnvironment)->toHaveKey($variable)
                ->and($localEnvironment)->toHaveKey($variable);
        }
    }
});

it('keeps advanced workflow credential overrides connected to service environments', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $inputs = $workflow['on']['workflow_call']['inputs'] ?? [];
    $environment = $workflow['jobs']['run']['env'] ?? [];

    expect($inputs['service_db_name']['default'] ?? null)->toBe('phpforge')
        ->and($inputs['service_db_user']['default'] ?? null)->toBe('phpforge')
        ->and($inputs['service_password']['default'] ?? null)->toBe('Phpforge_123!')
        ->and($inputs)->not->toHaveKey('service_db_password')
        ->and($inputs)->not->toHaveKey('mssql_password')
        ->and($environment['PHPFORGE_SERVICE_DATABASE'] ?? null)->toBe('${{ inputs.service_db_name }}')
        ->and($environment['PHPFORGE_SERVICE_USERNAME'] ?? null)->toBe('${{ inputs.service_db_user }}')
        ->and($environment['PHPFORGE_SERVICE_PASSWORD'] ?? null)->toBe('${{ inputs.service_password }}')
        ->and($environment)->not->toHaveKey('PHPFORGE_MSSQL_PASSWORD')
        ->and($environment['IC_MSSQL_PASSWORD'] ?? null)->toBe('${{ inputs.service_password }}')
        ->and($environment['IC_REDIS_PASSWORD'] ?? null)->toBe('${{ inputs.service_password }}')
        ->and($environment['IC_VALKEY_PASSWORD'] ?? null)->toBe('${{ inputs.service_password }}');
});

it('resolves canonical workflow service outputs including empty objects', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/resolve-services.php']);
    $process->setEnv([
        'INTEGRATION_SERVICES_INPUT' => '["mysql","mongodb","sqlite"]',
        'SERVICE_TOPOLOGIES_INPUT' => '{"mysql":"replica","mongodb":"replica-set"}',
        'PHP_EXTENSIONS_INPUT' => 'ext-json, curl',
        'GITHUB_OUTPUT' => false,
    ]);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    expect($result['compose_profiles'])->toBe('["mongodb-replica","mysql-replica"]')
        ->and($result['php_extensions'])->toBe('curl, json, mongodb, pdo_mysql, pdo_sqlite')
        ->and($result['has_external_services'])->toBe('true');

    $empty = new Process([PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/resolve-services.php']);
    $empty->setEnv([
        'INTEGRATION_SERVICES_INPUT' => '[]',
        'SERVICE_TOPOLOGIES_INPUT' => '{}',
        'GITHUB_OUTPUT' => false,
    ]);
    $empty->mustRun();

    expect(json_decode($empty->getOutput(), true, 512, JSON_THROW_ON_ERROR)['service_topologies'])->toBe('{}');
});

it('keeps the pre-setup workflow resolver free of PHP 8.4-only array helpers', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/.github/scripts/resolve-services.php');

    expect($script)
        ->not->toContain('array_any(')
        ->not->toContain('array_all(')
        ->not->toContain('array_find(')
        ->not->toContain('array_find_key(');
});

it('maps every catalog probe to protocol-level readiness logic', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/.github/scripts/verify-and-wait-services.sh');

    foreach (ServiceCatalog::all() as $definition) {
        expect(preg_match('/^\s+[^\n]*\b'.preg_quote($definition['probe'], '/').'(?:\||\))/m', $script))->toBe(1);
    }

    expect($script)->toContain('phpforge_replication_probe')
        ->toContain('replication_token=')
        ->toContain('extension_loaded($argv[1])')
        ->toContain('Last ${name} probe error:')
        ->toContain('$error->getMessage()')
        ->not->toContain('php -m | grep')
        ->not->toContain('for ($attempt = 0; $attempt < 30; $attempt++)')
        ->toContain('replSetGetStatus')
        ->toContain('/api/health/checks/alarms')
        ->toContain('/api/nodes')
        ->toContain('/jsz')
        ->toContain('/routez')
        ->toContain('wait_for_nodes=2')
        ->toContain('/gossiper/endpoint/live/')
        ->toContain('/v1/info');
});

it('uses the versioned runtime manifest as the service image source of truth', function (): void {
    $runtime = require dirname(__DIR__, 2).'/resources/runtime.php';
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/resources/services/compose.yml');
    $profileImages = [];

    foreach (ServiceCatalog::all() as $name => $definition) {
        foreach ($definition['topologies'] as $profile) {
            if (is_string($profile)) {
                $profileImages[$profile] = $runtime['service_images'][$name] ?? null;
            }
        }
    }

    foreach ($compose['services'] ?? [] as $name => $service) {
        $expected = $runtime['service_support_images'][$name] ?? null;

        if (!is_string($expected)) {
            $profile = $service['profiles'][0] ?? null;
            $expected = is_string($profile) ? ($profileImages[$profile] ?? null) : null;
        }

        expect($expected)->toBeString()
            ->and($service['image'] ?? null)->toBe($expected);
    }

    expect($runtime['php_versions'] ?? null)->toBe(['8.4', '8.5'])
        ->and($runtime['service_client_versions']['mssql_odbc'] ?? null)->toBe('18')
        ->and(array_keys($runtime['service_images'] ?? []))->toBe(array_values(array_filter(
            ServiceCatalog::names(),
            static fn (string $service): bool => $service !== 'sqlite',
        )))
        ->and(implode("\n", $runtime['service_images'] ?? []))->not->toContain('bitnami/');
});

it('installs the versioned official MSSQL ODBC client only for MSSQL workflows', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/security-standards.yml');
    $steps = $workflow['jobs']['run']['steps'] ?? [];
    $stepsByName = array_column($steps, null, 'name');
    $installerStep = $stepsByName['Install Microsoft ODBC driver for SQL Server'] ?? [];
    $installer = (string) file_get_contents($root.'/.github/scripts/install-mssql-odbc-driver.sh');

    expect($installerStep['if'] ?? null)
        ->toBe("contains(fromJson(needs.prepare.outputs.integration_services), 'mssql')")
        ->and($installerStep['run'] ?? null)
        ->toBe('bash .phpforge-workflow/.github/scripts/install-mssql-odbc-driver.sh')
        ->and($installer)->toContain('service_client_versions"]["mssql_odbc')
        ->toContain('https://packages.microsoft.com/config/')
        ->toContain('ACCEPT_EULA=Y')
        ->toContain('odbcinst -q -d -n "$driver_name"');
});
