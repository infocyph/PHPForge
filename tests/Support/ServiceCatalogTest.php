<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\ServiceCommand;
use Infocyph\PHPForge\Support\ServiceCatalog;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('preserves workflow service input validation and error ordering', function (string $services, string $topologies, string $message): void {
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2) . '/.github/scripts/resolve-services.php',
    ], null, [
        'INTEGRATION_SERVICES_INPUT' => $services,
        'SERVICE_TOPOLOGIES_INPUT' => $topologies,
        'PHP_EXTENSIONS_INPUT' => '',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toBe($message . "\n")
        ->and($process->getOutput())->toBe('');
})->with([
    'numeric service' => ['[1]', '{}', 'integration_services must be a JSON string array.'],
    'numeric topology' => ['["redis"]', '{"redis":1}', 'service_topologies must be a JSON object of service-to-topology strings.'],
    'invalid services precede invalid topologies' => ['[1]', '[]', 'integration_services must be a JSON string array.'],
]);

it('rejects numeric service keys and non-string topology values', function (): void {
    expect(ServiceCatalog::topologiesFromJson('{"0":"standalone"}'))->toBeNull()
        ->and(ServiceCatalog::topologiesFromJson('{"redis":0}'))->toBeNull()
        ->and(ServiceCatalog::topologiesFromJson('{"redis":"replica"}'))->toBe(['redis' => 'replica']);
});

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
    $compose = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/services/compose.yml');
    $profiles = [];

    foreach (fixtureArray(fixtureValue($compose, ['services'], true) ?? []) as $service) {
        foreach (fixtureArray(fixtureValue($service, ['profiles'], true) ?? []) as $profile) {
            $profiles[fixtureString($profile)] = true;
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
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $workflowEnvironment = fixtureValue($workflow, ['jobs', 'run', 'env'], true) ?? [];
    $command = new ServiceCommand('up');
    $method = new ReflectionMethod(ServiceCommand::class, 'serviceEnvironment');
    $localEnvironment = $method->invoke($command, ServiceCatalog::names(), []);

    expect($workflowEnvironment)->toBeArray()
        ->and($localEnvironment)->toBeArray()
        ->and($localEnvironment)->not->toHaveKey('PHPFORGE_MSSQL_PASSWORD')
        ->and(fixtureValue($localEnvironment, ['IC_MSSQL_PASSWORD'], true) ?? null)->toBe(fixtureValue($localEnvironment, ['IC_SERVICE_PASSWORD'], true) ?? null);

    foreach (ServiceCatalog::all() as $definition) {
        foreach ($definition['environment'] as $variable) {
            expect($workflowEnvironment)->toHaveKey($variable)
                ->and($localEnvironment)->toHaveKey($variable);
        }
    }
});

it('keeps advanced workflow credential overrides connected to service environments', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $inputs = fixtureValue($workflow, ['on', 'workflow_call', 'inputs'], true) ?? [];
    $environment = fixtureValue($workflow, ['jobs', 'run', 'env'], true) ?? [];

    expect(fixtureValue($inputs, ['service_db_name', 'default'], true) ?? null)->toBe('phpforge')
        ->and(fixtureValue($inputs, ['service_db_user', 'default'], true) ?? null)->toBe('phpforge')
        ->and(fixtureValue($inputs, ['service_password', 'default'], true) ?? null)->toBe('Phpforge_123!')
        ->and($inputs)->not->toHaveKey('service_db_password')
        ->and($inputs)->not->toHaveKey('mssql_password')
        ->and(fixtureValue($environment, ['PHPFORGE_SERVICE_DATABASE'], true) ?? null)->toBe('${{ inputs.service_db_name }}')
        ->and(fixtureValue($environment, ['PHPFORGE_SERVICE_USERNAME'], true) ?? null)->toBe('${{ inputs.service_db_user }}')
        ->and(fixtureValue($environment, ['PHPFORGE_SERVICE_PASSWORD'], true) ?? null)->toBe('${{ inputs.service_password }}')
        ->and($environment)->not->toHaveKey('PHPFORGE_MSSQL_PASSWORD')
        ->and(fixtureValue($environment, ['IC_MSSQL_PASSWORD'], true) ?? null)->toBe('${{ inputs.service_password }}')
        ->and(fixtureValue($environment, ['IC_REDIS_PASSWORD'], true) ?? null)->toBe('${{ inputs.service_password }}')
        ->and(fixtureValue($environment, ['IC_VALKEY_PASSWORD'], true) ?? null)->toBe('${{ inputs.service_password }}');
});

it('resolves canonical workflow service outputs including empty objects', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/.github/scripts/resolve-services.php']);
    $process->setEnv([
        'INTEGRATION_SERVICES_INPUT' => '["mysql","mongodb","sqlite"]',
        'SERVICE_TOPOLOGIES_INPUT' => '{"mysql":"replica","mongodb":"replica-set"}',
        'PHP_EXTENSIONS_INPUT' => 'ext-json, curl',
        'GITHUB_OUTPUT' => false,
    ]);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    expect(fixtureValue($result, ['compose_profiles']))->toBe('["mongodb-replica","mysql-replica"]')
        ->and(fixtureValue($result, ['php_extensions']))->toBe('curl, json, mongodb, pdo_mysql, pdo_sqlite')
        ->and(fixtureValue($result, ['has_external_services']))->toBe('true');

    $empty = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/.github/scripts/resolve-services.php']);
    $empty->setEnv([
        'INTEGRATION_SERVICES_INPUT' => '[]',
        'SERVICE_TOPOLOGIES_INPUT' => '{}',
        'GITHUB_OUTPUT' => false,
    ]);
    $empty->mustRun();

    expect(fixtureValue(json_decode($empty->getOutput(), true, 512, JSON_THROW_ON_ERROR), ['service_topologies']))->toBe('{}');
});

it('keeps the pre-setup workflow resolver free of PHP 8.4-only array helpers', function (): void {
    $script = fixtureFile(dirname(__DIR__, 2) . '/.github/scripts/resolve-services.php');

    expect($script)
        ->not->toContain('array_any(')
        ->not->toContain('array_all(')
        ->not->toContain('array_find(')
        ->not->toContain('array_find_key(');
});

it('maps every catalog probe to protocol-level readiness logic', function (): void {
    $script = fixtureFile(dirname(__DIR__, 2) . '/resources/services/verify-and-wait.sh');

    foreach (ServiceCatalog::all() as $definition) {
        expect(preg_match('/^\s+[^\n]*\b' . preg_quote($definition['probe'], '/') . '(?:\||\))/m', $script))->toBe(1);
    }

    expect($script)->toContain('phpforge_replication_probe')
        ->toContain('replication_token=')
        ->toContain('wait_for mssql-availability-group')
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

    $availabilityGroupReady = strpos($script, 'wait_for mssql-availability-group');
    $availabilityGroupWrite = strpos($script, '$primary = new PDO((string) getenv("IC_MSSQL_PRIMARY_DSN")');

    PHPUnit\Framework\Assert::assertIsInt($availabilityGroupReady);
    PHPUnit\Framework\Assert::assertIsInt($availabilityGroupWrite);
    expect($availabilityGroupWrite)->toBeGreaterThan($availabilityGroupReady);
});

it('runs packaged and workflow readiness from outside the package directory', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $environment = [
        'INTEGRATION_SERVICES' => '["sqlite"]',
        'SERVICE_TOPOLOGIES' => '{}',
        'PHPFORGE_SERVICE_CATALOG' => false,
        'IC_SQLITE_MEMORY_DSN' => 'sqlite::memory:',
        'IC_SQLITE_FILE_DSN' => 'sqlite::memory:',
    ];

    foreach (['resources/services/verify-and-wait.sh', '.github/scripts/verify-and-wait-services.sh'] as $path) {
        $process = new Process(['bash', $projectRoot . '/' . $path], sys_get_temp_dir(), $environment);
        $process->mustRun();

        expect($process->getOutput())->toContain('sqlite ready');
    }
});

it('uses the versioned runtime manifest as the service image source of truth', function (): void {
    $runtime = require dirname(__DIR__, 2) . '/resources/runtime.php';
    $compose = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/services/compose.yml');
    $profileImages = [];

    foreach (ServiceCatalog::all() as $name => $definition) {
        foreach ($definition['topologies'] as $profile) {
            if (is_string($profile)) {
                $profileImages[$profile] = fixtureValue($runtime, ['service_images', $name], true) ?? null;
            }
        }
    }

    foreach (fixtureArray(fixtureValue($compose, ['services'], true) ?? []) as $name => $service) {
        $expected = fixtureValue($runtime, ['service_support_images', $name], true) ?? null;

        if (!is_string($expected)) {
            $profile = fixtureValue($service, ['profiles', 0], true) ?? null;
            $expected = is_string($profile) ? ($profileImages[$profile] ?? null) : null;
        }

        expect($expected)->toBeString()
            ->and(fixtureValue($service, ['image'], true) ?? null)->toBe($expected);
    }

    expect(fixtureValue($runtime, ['php_versions'], true) ?? null)->toBe(['8.4', '8.5'])
        ->and(fixtureValue($runtime, ['service_client_versions', 'mssql_odbc'], true) ?? null)->toBe('18')
        ->and(fixtureValue($runtime, ['service_images', 'mssql'], true) ?? null)
        ->toBe('mcr.microsoft.com/mssql/server:2025-CU10-ubuntu-22.04')
        ->and(array_keys(fixtureArray(fixtureValue($runtime, ['service_images'], true) ?? [])))->toBe(array_values(array_filter(
            ServiceCatalog::names(),
            static fn(string $service): bool => $service !== 'sqlite',
        )))
        ->and(implode("\n", fixtureStringList(array_values(fixtureArray(fixtureValue($runtime, ['service_images'], true) ?? [])))))->not->toContain('bitnami/');
});

it('binds the published ScyllaDB admin API on every node', function (): void {
    $compose = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/services/compose.yml');

    foreach (['scylladb', 'scylladb-primary', 'scylladb-replica-1', 'scylladb-replica-2'] as $name) {
        $command = fixtureValue($compose, ['services', $name, 'command']);
        $index = array_search('--api-address', fixtureArray($command), true);

        PHPUnit\Framework\Assert::assertIsInt($index);
        expect(fixtureValue($command, [$index + 1], true) ?? null)->toBe('0.0.0.0');
    }
});

it('bounds and sequences SQL Server availability-group startup', function (): void {
    $compose = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/services/compose.yml');
    $services = fixtureValue($compose, ['services'], true) ?? [];
    $standalone = fixtureValue($services, ['mssql'], true) ?? [];
    $primary = fixtureValue($services, ['mssql-primary'], true) ?? [];
    $replica = fixtureValue($services, ['mssql-replica'], true) ?? [];

    expect(fixtureValue($standalone, ['environment', 'MSSQL_MEMORY_LIMIT_MB'], true) ?? null)
        ->toBe('${PHPFORGE_MSSQL_MEMORY_LIMIT_MB:-3072}')
        ->and(fixtureValue($standalone, ['mem_limit'], true) ?? null)
        ->toBe('${PHPFORGE_MSSQL_CONTAINER_MEMORY_LIMIT:-3840m}')
        ->and(fixtureValue($primary, ['environment', 'MSSQL_MEMORY_LIMIT_MB'], true) ?? null)
        ->toBe('${PHPFORGE_MSSQL_MEMORY_LIMIT_MB:-3072}')
        ->and(fixtureValue($replica, ['environment', 'MSSQL_MEMORY_LIMIT_MB'], true) ?? null)
        ->toBe('${PHPFORGE_MSSQL_MEMORY_LIMIT_MB:-3072}')
        ->and(fixtureValue($primary, ['mem_limit'], true) ?? null)
        ->toBe('${PHPFORGE_MSSQL_CONTAINER_MEMORY_LIMIT:-3840m}')
        ->and(fixtureValue($replica, ['mem_limit'], true) ?? null)
        ->toBe('${PHPFORGE_MSSQL_CONTAINER_MEMORY_LIMIT:-3840m}')
        ->and(fixtureValue($primary, ['restart'], true) ?? null)->toBe('on-failure:3')
        ->and(fixtureValue($replica, ['restart'], true) ?? null)->toBe('on-failure:3')
        ->and(fixtureValue($replica, ['depends_on', 'mssql-primary', 'condition'], true) ?? null)->toBe('service_healthy');
});

it('installs the versioned official MSSQL ODBC client only for MSSQL workflows', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root . '/.github/workflows/security-standards.yml');
    $steps = fixtureValue($workflow, ['jobs', 'run', 'steps'], true) ?? [];
    $stepsByName = array_column(fixtureArray($steps), null, 'name');
    $installerStep = $stepsByName['Install Microsoft ODBC driver for SQL Server'] ?? [];
    $installer = fixtureFile($root . '/.github/scripts/install-mssql-odbc-driver.sh');

    expect(fixtureValue($installerStep, ['if'], true) ?? null)
        ->toBe("contains(fromJson(needs.prepare.outputs.integration_services), 'mssql')")
        ->and(fixtureValue($installerStep, ['run'], true) ?? null)
        ->toBe('bash .phpforge-workflow/.github/scripts/install-mssql-odbc-driver.sh')
        ->and($installer)->toContain('service_client_versions"]["mssql_odbc')
        ->toContain('https://packages.microsoft.com/config/')
        ->toContain('ACCEPT_EULA=Y')
        ->toContain('odbcinst -q -d -n "$driver_name"');
});
