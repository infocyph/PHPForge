<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\CleanCommand;
use Infocyph\PHPForge\Composer\DoctorCommand;
use Infocyph\PHPForge\Composer\ActiveConfigCommand;
use Infocyph\PHPForge\Composer\ListConfigCommand;
use Infocyph\PHPForge\Composer\PublishCommunityTemplatesCommand;
use Infocyph\PHPForge\Composer\VersionCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument as Argument;
use Symfony\Component\Console\Input\InputOption as Option;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

function removeUtilityCommandsTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}

/**
 * @param array<string, mixed> $input
 * @param list<Option> $options
 * @return array{exit_code: int, output: string}
 */
function runComposerCommand(Command $command, array $input = [], array $options = []): array
{
    $execute = new ReflectionMethod($command, 'execute');
    $definition = new InputDefinition($options);
    $arrayInput = new ArrayInput($input, $definition);
    $output = new BufferedOutput();

    return [
        'exit_code' => $execute->invoke($command, $arrayInput, $output),
        'output' => $output->fetch(),
    ];
}

it('prints version command output', function (): void {
    $result = runComposerCommand(new VersionCommand());

    expect($result['exit_code'])->toBe(0)
        ->and($result['output'])->toContain('PHPForge:')
        ->and($result['output'])->toContain('Vendor dir:');
});

it('exposes root diagnostic Composer scripts', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

    expect(is_array($composer))->toBeTrue()
        ->and($composer['scripts']['ic:doctor'] ?? null)->toBe('@php bin/phpforge doctor')
        ->and($composer['scripts']['ic:list-config'] ?? null)->toBe('@php bin/phpforge list-config');
});

it('lists config inventory as json', function (): void {
    $result = runComposerCommand(
        new ListConfigCommand(),
        ['--json' => true],
        [new Option('json', null, Option::VALUE_NONE)],
    );
    $decoded = json_decode($result['output'], true);

    expect($result['exit_code'])->toBe(0)
        ->and(is_array($decoded))->toBeTrue()
        ->and(array_values(array_filter($decoded, static fn (mixed $row): bool => is_array($row) && (($row['file'] ?? null) === 'phpprobe.json'))) !== [])->toBeTrue();
});

it('reports active configs across supported tools as json', function (): void {
    $result = runComposerCommand(
        new ActiveConfigCommand(),
        ['--json' => true],
        [
            new Argument('files', Argument::IS_ARRAY),
            new Option('json', null, Option::VALUE_NONE),
            new Option('all', null, Option::VALUE_NONE),
            new Option('parameter', null, Option::VALUE_REQUIRED),
        ],
    );
    $decoded = json_decode($result['output'], true);
    $phpstan = is_array($decoded)
        ? array_values(array_filter($decoded, static fn (mixed $row): bool => is_array($row) && (($row['tool'] ?? null) === 'phpstan')))
        : [];

    expect($result['exit_code'])->toBe(0)
        ->and(is_array($decoded))->toBeTrue()
        ->and($phpstan)->not->toBe([])
        ->and($phpstan[0]['config_file'] ?? null)->toBe('phpstan.neon.dist')
        ->and($phpstan[0]['effective']['memory_limit'] ?? null)->toBe('1G');
});

it('filters active config output by selected file and parameter', function (): void {
    $result = runComposerCommand(
        new ActiveConfigCommand(),
        ['files' => ['phpstan.neon.dist'], '--json' => true, '--parameter' => 'cognitive_complexity'],
        [
            new Argument('files', Argument::IS_ARRAY),
            new Option('json', null, Option::VALUE_NONE),
            new Option('all', null, Option::VALUE_NONE),
            new Option('parameter', null, Option::VALUE_REQUIRED),
        ],
    );
    $decoded = json_decode($result['output'], true);

    expect($result['exit_code'])->toBe(0)
        ->and(is_array($decoded))->toBeTrue()
        ->and(count($decoded))->toBe(1)
        ->and($decoded[0]['tool'] ?? null)->toBe('phpstan')
        ->and($decoded[0]['parameter'] ?? null)->toBe('cognitive_complexity')
        ->and($decoded[0]['value']['function'] ?? null)->toBe(12);
});

it('accepts double-dash-prefixed active config file selections', function (): void {
    $result = runComposerCommand(
        new ActiveConfigCommand(),
        ['files' => ['--phpstan.neon.dist'], '--json' => true],
        [
            new Argument('files', Argument::IS_ARRAY),
            new Option('json', null, Option::VALUE_NONE),
            new Option('all', null, Option::VALUE_NONE),
            new Option('parameter', null, Option::VALUE_REQUIRED),
        ],
    );
    $decoded = json_decode($result['output'], true);

    expect($result['exit_code'])->toBe(0)
        ->and(is_array($decoded))->toBeTrue()
        ->and(count($decoded))->toBe(1)
        ->and($decoded[0]['tool'] ?? null)->toBe('phpstan');
});

it('removes known tool outputs via clean command', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-utility-clean-'.uniqid('', true);
    $cacheDir = $projectRoot.DIRECTORY_SEPARATOR.'.phpunit.cache';
    $sarifFile = $projectRoot.DIRECTORY_SEPARATOR.'phpstan-results.sarif';
    $psalmSarifFile = $projectRoot.DIRECTORY_SEPARATOR.'psalm-results.sarif';

    mkdir($cacheDir, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($cacheDir.DIRECTORY_SEPARATOR.'cache.txt', 'cache');
    file_put_contents($sarifFile, '{}');
    file_put_contents($psalmSarifFile, '{}');

    chdir($projectRoot);

    try {
        $result = runComposerCommand(new CleanCommand());

        expect($result['exit_code'])->toBe(0)
            ->and(is_dir($cacheDir))->toBeFalse()
            ->and(is_file($sarifFile))->toBeFalse()
            ->and(is_file($psalmSarifFile))->toBeFalse()
            ->and($result['output'])->toContain('Clean complete');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeUtilityCommandsTree($projectRoot);
    }
});

it('reports normalize plugin status in doctor json diagnostics', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-utility-doctor-'.uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', json_encode([
        'name' => 'example/project',
        'config' => [
            'allow-plugins' => [
                'infocyph/phpforge' => true,
                'ergebnis/composer-normalize' => true,
                'pestphp/pest-plugin' => true,
            ],
        ],
    ], JSON_PRETTY_PRINT));

    chdir($projectRoot);

    try {
        $result = runComposerCommand(
            new DoctorCommand(),
            ['--json' => true],
            [new Option('json', null, Option::VALUE_NONE)],
        );
        $decoded = json_decode($result['output'], true);

        expect($result['exit_code'])->toBe(0)
            ->and(is_array($decoded))->toBeTrue()
            ->and(($decoded['plugins']['ergebnis/composer-normalize'] ?? null))->toBeTrue();
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeUtilityCommandsTree($projectRoot);
    }
});

it('recognizes a local reusable security workflow', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-utility-doctor-local-workflow-'.uniqid('', true);
    $workflowPath = $projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'workflows'.DIRECTORY_SEPARATOR.'security-standards.yml';

    mkdir(dirname($workflowPath), 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($workflowPath, "on:\n  workflow_call:\n");

    chdir($projectRoot);

    try {
        $result = runComposerCommand(
            new DoctorCommand(),
            ['--json' => true],
            [new Option('json', null, Option::VALUE_NONE)],
        );
        $decoded = json_decode($result['output'], true);

        expect($result['exit_code'])->toBe(0)
            ->and($decoded['workflow']['ref'] ?? null)->toBe('local')
            ->and($decoded['workflow']['warnings'] ?? null)->toBe([]);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeUtilityCommandsTree($projectRoot);
    }
});

it('parses optional service workflow inputs in doctor diagnostics', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-utility-doctor-workflow-'.uniqid('', true);

    mkdir($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'workflows', 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'workflows'.DIRECTORY_SEPARATOR.'security-standards.yml', <<<'YAML'
name: "Security & Standards"

jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@main
    with:
      php_versions: '["8.4","8.5"]'
      dependency_versions: '["prefer-lowest","prefer-stable"]'
      php_extensions: ""
      composer_flags: ""
      phpstan_memory_limit: "1G"
      psalm_threads: "1"
      run_analysis: true
      run_svg_report: true
      fail_on_skipped_tests: false
      enable_redis_service: true
      enable_valkey_service: true
      enable_memcached_service: true
      enable_postgres_service: true
      enable_mysql_service: false
      enable_scylladb_service: true
      enable_elasticsearch_service: false
      enable_mongodb_service: true
      service_db_name: "cachelayer"
      service_db_user: "phpforge"
      service_db_password: "phpforge"
      artifact_retention_days: 61
YAML
    );

    chdir($projectRoot);

    try {
        $result = runComposerCommand(
            new DoctorCommand(),
            ['--json' => true],
            [new Option('json', null, Option::VALUE_NONE)],
        );
        $decoded = json_decode($result['output'], true);

        expect($result['exit_code'])->toBe(0)
            ->and(is_array($decoded))->toBeTrue()
            ->and(($decoded['workflow']['inputs']['enable_redis_service'] ?? null))->toBe('true')
            ->and(($decoded['workflow']['inputs']['enable_valkey_service'] ?? null))->toBe('true')
            ->and(($decoded['workflow']['inputs']['enable_scylladb_service'] ?? null))->toBe('true')
            ->and(($decoded['workflow']['inputs']['service_db_user'] ?? null))->toBe('phpforge')
            ->and(($decoded['workflow']['warnings'] ?? []))->toBe([]);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeUtilityCommandsTree($projectRoot);
    }
});

it('publishes generic community template files to project root', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-community-templates-'.uniqid('', true);

    mkdir($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE', 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');

    chdir($projectRoot);

    try {
        $result = runComposerCommand(
            new PublishCommunityTemplatesCommand(),
            [],
            [new Option('force', 'f', Option::VALUE_NONE)],
        );

        expect($result['exit_code'])->toBe(0)
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'CONTRIBUTING.md'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'CODE_OF_CONDUCT.md'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'SECURITY.md'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE'.DIRECTORY_SEPARATOR.'bug_report.yml'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE'.DIRECTORY_SEPARATOR.'regression_report.yml'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE'.DIRECTORY_SEPARATOR.'ci_failure.yml'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE'.DIRECTORY_SEPARATOR.'feature_request.yml'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE'.DIRECTORY_SEPARATOR.'question.yml'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE'.DIRECTORY_SEPARATOR.'docs_improvement.yml'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'ISSUE_TEMPLATE'.DIRECTORY_SEPARATOR.'config.yml'))->toBeTrue()
            ->and(is_file($projectRoot.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'PULL_REQUEST_TEMPLATE.md'))->toBeTrue()
            ->and($result['output'])->toContain('Published 11 community template file(s).');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeUtilityCommandsTree($projectRoot);
    }
});
