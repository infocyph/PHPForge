<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\InitCommand;
use Infocyph\PHPForge\Support\WorkflowWrapper;
use Symfony\Component\Console\Output\BufferedOutput;

it('normalizes json string list settings for workflow template rendering', function (): void {
    $command = new InitCommand();
    $method = new ReflectionMethod(InitCommand::class, 'normalizedJsonStringList');
    $output = new BufferedOutput();

    $normalized = $method->invoke($command, '["8.4","8.5"]', 'php_versions', $output);

    expect($normalized)->toBe('["8.4","8.5"]');
});

it('rejects invalid workflow ref values', function (): void {
    $command = new InitCommand();
    $method = new ReflectionMethod(InitCommand::class, 'validatedWorkflowRef');
    $output = new BufferedOutput();

    $result = $method->invoke($command, 'main bad', $output);

    expect($result)->toBeNull()
        ->and($output->fetch())->toContain('Invalid workflow_ref');
});

it('rejects multiline scalar workflow settings', function (): void {
    $command = new InitCommand();
    $method = new ReflectionMethod(InitCommand::class, 'singleLineValue');
    $output = new BufferedOutput();

    $result = $method->invoke($command, "line1\nline2", 'composer_flags', $output);

    expect($result)->toBeNull()
        ->and($output->fetch())->toContain('newlines are not allowed');
});

it('escapes yaml double-quoted values safely', function (): void {
    $escaped = WorkflowWrapper::yamlDoubleQuoted("a\"b\nc\\d");

    expect($escaped)->toBe('"a\\"b\\nc\\\\d"');
});

it('updates workflow wrappers without relying on template default literals', function (): void {
    $template = <<<'YAML'
name: "Security & Standards"

jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@old-ref
    with:
      php_versions: '["8.1"]'
      dependency_versions: '["prefer-stable"]'
YAML;

    $updated = WorkflowWrapper::update($template, 'main', [
        'php_versions' => WorkflowWrapper::yamlSingleQuoted('["8.4","8.5"]'),
        'dependency_versions' => WorkflowWrapper::yamlSingleQuoted('["prefer-lowest","prefer-stable"]'),
        'php_extensions' => WorkflowWrapper::yamlDoubleQuoted('mbstring, intl'),
        'composer_flags' => WorkflowWrapper::yamlDoubleQuoted('--with-all-dependencies'),
        'phpstan_memory_limit' => WorkflowWrapper::yamlDoubleQuoted('2G'),
        'psalm_threads' => WorkflowWrapper::yamlDoubleQuoted('2'),
        'run_analysis' => 'false',
        'run_svg_report' => 'true',
        'enable_redis_service' => 'true',
        'enable_valkey_service' => 'false',
        'enable_memcached_service' => 'true',
        'enable_postgres_service' => 'true',
        'enable_mysql_service' => 'false',
        'enable_scylladb_service' => 'true',
        'enable_elasticsearch_service' => 'false',
        'enable_mongodb_service' => 'true',
        'service_db_name' => WorkflowWrapper::yamlDoubleQuoted('phpforge'),
        'service_db_user' => WorkflowWrapper::yamlDoubleQuoted('phpforge'),
        'service_db_password' => WorkflowWrapper::yamlDoubleQuoted('phpforge'),
    ]);

    expect($updated)->toContain('uses: infocyph/phpforge/.github/workflows/security-standards.yml@main')
        ->and($updated)->toContain('php_versions: \'["8.4","8.5"]\'')
        ->and($updated)->toContain('php_extensions: "mbstring, intl"')
        ->and($updated)->toContain('run_analysis: false')
        ->and($updated)->toContain('enable_redis_service: true')
        ->and($updated)->toContain('enable_valkey_service: false')
        ->and($updated)->toContain('enable_scylladb_service: true')
        ->and($updated)->toContain('service_db_user: "phpforge"');
});
