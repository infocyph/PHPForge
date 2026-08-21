<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\InitCommand;
use Infocyph\PHPForge\Support\ServiceCatalog;
use Infocyph\PHPForge\Support\WorkflowWrapper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('parses service selection as a unique JSON string list', function (): void {
    $method = new ReflectionMethod(InitCommand::class, 'jsonStringList');
    $output = new BufferedOutput();

    $services = $method->invoke(new InitCommand(), '["mysql","redis","mysql"]', 'services', $output);

    expect($services)->toBe(['mysql', 'redis'])
        ->and($output->fetch())->toBe('');
});

it('rejects malformed service and topology JSON', function (): void {
    $command = new InitCommand();
    $listMethod = new ReflectionMethod(InitCommand::class, 'jsonStringList');
    $mapMethod = new ReflectionMethod(InitCommand::class, 'jsonStringMap');
    $output = new BufferedOutput();

    expect($listMethod->invoke($command, '{"mysql":true}', 'services', $output))->toBeNull()
        ->and($mapMethod->invoke($command, '["replica"]', 'service-topologies', $output))->toBeNull()
        ->and($output->fetch())->toContain('must be a JSON');
});

it('validates topology selection against the canonical service catalog', function (): void {
    expect(ServiceCatalog::validate(['mysql'], ['mysql' => 'replica']))->toBe([])
        ->and(ServiceCatalog::validate(['memcached'], ['memcached' => 'replica']))->toContain('Unsupported topology for memcached: replica')
        ->and(ServiceCatalog::validate(['unknown'], ['unknown' => 'standalone']))->toContain('Unknown integration service: unknown')
        ->and(ServiceCatalog::validate(['redis'], ['mysql' => 'replica']))->toContain('Topology configured for unselected service: mysql');
});

it('escapes yaml values safely', function (): void {
    expect(WorkflowWrapper::yamlDoubleQuoted("a\"b\nc\\d"))->toBe('"a\\"b\\nc\\\\d"')
        ->and(WorkflowWrapper::yamlSingleQuoted("a'b"))->toBe("'a''b'");
});

it('renders the compact service workflow contract', function (): void {
    $template = <<<'YAML'
name: "Security & Standards"

jobs:
  phpforge:
    uses: infocyph/phpforge/.github/workflows/security-standards.yml@old-ref
    with:
      integration_services: '[]'
      service_topologies: '{}'
YAML;

    $updated = WorkflowWrapper::update($template, 'main', [
        'integration_services' => WorkflowWrapper::yamlSingleQuoted('["mysql","mongodb"]'),
        'service_topologies' => WorkflowWrapper::yamlSingleQuoted('{"mysql":"replica","mongodb":"replica-set"}'),
    ]);

    expect($updated)->toContain('uses: infocyph/phpforge/.github/workflows/security-standards.yml@main')
        ->and($updated)->toContain('integration_services: \'["mysql","mongodb"]\'')
        ->and($updated)->toContain('service_topologies: \'{"mysql":"replica","mongodb":"replica-set"}\'')
        ->and($updated)->not->toContain('enable_mysql_service');
});

it('publishes only the canonical init command name', function (): void {
    expect((new InitCommand())->getName())->toBe('ic:init');
});

it('accepts an empty interactive service selection', function (): void {
    $command = new InitCommand();
    $command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));
    $input = new ArrayInput([]);
    $input->setInteractive(true);
    $stream = fopen('php://memory', 'r+');

    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to create the interactive input stream.');
    }

    fwrite($stream, "\n\n\n");
    rewind($stream);
    $input->setStream($stream);
    $output = new BufferedOutput();
    $method = new ReflectionMethod(InitCommand::class, 'interactiveSelection');
    $selection = $method->invoke($command, $input, $output, [
        'workflow' => false,
        'captainhook' => false,
        'gitlab_ci' => false,
        'bitbucket_ci' => false,
        'forgejo_workflow' => false,
        'community_templates' => false,
    ], 'main');

    fclose($stream);

    expect($selection)->toBeArray()
        ->and($selection['services'])->toBe([])
        ->and($selection['topologies'])->toBe([])
        ->and($output->fetch())->not->toContain('is invalid');
});

it('does not persist service selections during init', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-init-' . uniqid('', true);
    $configuration = $projectRoot . DIRECTORY_SEPARATOR . '.phpforge-services.json';

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    chdir($projectRoot);

    try {
        $command = new InitCommand();
        $input = new ArrayInput([
            '--services' => '["mysql"]',
            '--service-topologies' => '{}',
        ], $command->getDefinition());
        $input->setInteractive(false);
        $method = new ReflectionMethod(InitCommand::class, 'execute');
        $status = $method->invoke($command, $input, new BufferedOutput());

        expect($status)->toBe(0)
            ->and(is_file($configuration))->toBeFalse();
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        if (is_file($configuration)) {
            unlink($configuration);
        }

        unlink($projectRoot . DIRECTORY_SEPARATOR . 'composer.json');
        rmdir($projectRoot);
    }
});
