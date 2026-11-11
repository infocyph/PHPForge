<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\CommandProvider;
use Infocyph\PHPForge\Composer\TaskCatalog;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

it('runs Psalm autofix after Rector and before formatting in every processor entry point', function (): void {
    $tasks = TaskCatalog::processAll();
    $manifest = fixtureArray(json_decode(fixtureFile(Paths::packageFile('composer.json')), true, 512, JSON_THROW_ON_ERROR));
    $commands = array_map(static fn(Command $command): ?string => $command->getName(), (new CommandProvider())->getCommands());

    expect(array_column($tasks, 1))->toBe(['normalize', Paths::bin('rector'), Paths::bin('psalter'), Paths::bin('pint'), Paths::bin('phpcbf')])
        ->and(fixtureValue($manifest, ['scripts', 'ic:process']))->toBe(['@composer normalize', '@ic:process:refactor', '@ic:process:psalm', '@ic:process:lint', '@ic:process:sniff'])
        ->and(fixtureValue($manifest, ['scripts', 'ic:process:psalm']))->toBe('@php bin/run-task.php process:psalm')
        ->and($commands)->toContain('ic:process:psalm');
});

it('keeps automatic Psalm changes scoped and leaves analysis tasks read-only', function (): void {
    $command = TaskCatalog::psalmFix()[0];
    $analysis = TaskCatalog::security()[0];

    expect($command)->toContain('--safe-types', '--allow-backwards-incompatible-changes=false', '--threads=1', '--no-cache')
        ->and(implode(' ', $command))->not->toContain('UnusedMethod', 'PossiblyUnusedMethod', 'UnusedConstructor', 'UnusedVariable', 'MissingPropertyType', '--issues=all')
        ->and(implode(' ', $command))->toContain(',MissingParamType,')
        ->and(basename($analysis[1]))->toBe('psalm')
        ->and($analysis)->toContain('--security-analysis')
        ->and(implode(' ', $analysis))->not->toContain('--alter');
});

it('fixes a consuming project while preserving public APIs and remaining analyzer failures', function (string $vendorDirectory, string $configLocation): void {
    $root = sys_get_temp_dir() . '/phpforge-psalter-' . bin2hex(random_bytes(6));
    $originalCwd = fixtureString(getcwd());
    $psalter = Paths::bin('psalter');
    $psalm = Paths::bin('psalm');
    $autoload = Paths::vendorDir() . '/autoload.php';
    mkdir($root . '/' . $vendorDirectory . '/bin', 0755, true);
    mkdir($root . '/src');
    mkdir($root . '/nested');
    $workingDirectory = $configLocation === 'bundled' ? $root . '/nested' : $root;
    file_put_contents($root . '/composer.json', json_encode(['name' => 'example/consumer', 'config' => ['vendor-dir' => $vendorDirectory]], JSON_THROW_ON_ERROR));
    symlink($psalter, $root . '/' . $vendorDirectory . '/bin/psalter');
    symlink($autoload, $root . '/' . $vendorDirectory . '/autoload.php');
    $config = $configLocation === 'project' ? $root . '/psalm.xml.dist' : $root . '/' . $vendorDirectory . '/infocyph/phpforge/resources/psalm.xml';

    if (!is_dir(dirname($config))) {
        mkdir(dirname($config), 0755, true);
    }

    file_put_contents($config, <<<'XML'
<?xml version="1.0"?>
<psalm xmlns="https://getpsalm.org/schema/config" errorLevel="1" findUnusedCode="true" resolveFromConfigFile="false">
  <projectFiles><directory name="src" /></projectFiles>
</psalm>
XML);
    $source = <<<'SOURCE'
<?php
namespace Consumer;
class PublicApi {
    public function __construct() {}
    public function externalOnly(string $input): string { return $input; }
    public function externalConsumerOnly(): string { return 'external'; }
    public function externalType($input) { return $input; }
    public function acceptsInferred($value): int { return $value + 1; }
}
final class Example extends PublicApi {
    public function externalOnly(string $input): string { return (string) $input; }
    public function finalInferred($value): int { return $value + 1; }
}
$closure = static function () { return 42; };
echo (new Example())->externalOnly('value'), $closure();
function exercise(PublicApi $api, Example $example, int $value): void {
    echo $api->acceptsInferred($value), $example->finalInferred($value);
}
exercise(new PublicApi(), new Example(), 1);
function invalid(): int { return 'unresolved'; }
SOURCE;
    file_put_contents($root . '/src/Example.php', $source);

    try {
        chdir($workingDirectory);
        $command = TaskCatalog::psalmFix()[0];
        $preview = new Process([...$command, '--dry-run'], $workingDirectory);
        $preview->setTimeout(60);
        $preview->run();
        expect(fixtureFile($root . '/src/Example.php'))->toBe($source)
            ->and($command[1])->toBe($root . '/' . $vendorDirectory . '/bin/psalter')
            ->and($command)->toContain('--config=' . $config)
            ->toContain('--root=' . $root)
            ->and($preview->getExitCode())->toBe(0, $preview->getOutput() . $preview->getErrorOutput());

        $process = new Process($command, $workingDirectory);
        $process->setTimeout(60);
        $process->run();
        $fixed = fixtureFile($root . '/src/Example.php');
        expect($fixed)->toContain('#[\\Override]', 'public function __construct()', 'public function externalConsumerOnly(): string', 'public function externalType($input)', 'public function externalOnly(string $input): string', "function invalid(): int { return 'unresolved'; }")
            ->toMatch('/function\s*\(\)\s*:\s*int/')
            ->toContain('public function acceptsInferred($value): int', 'public function finalInferred(int $value): int', '@param int $value')
            ->not->toContain('(string) $input')
            ->and($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());

        $analysis = new Process([PHP_BINARY, $psalm, '--config=' . $config, '--no-cache', '--threads=1', '--no-progress'], $root);
        $analysis->setTimeout(60);
        $analysis->run();
        expect($analysis->getExitCode())->toBe(2, $analysis->getOutput() . $analysis->getErrorOutput())
            ->and($analysis->getOutput() . $analysis->getErrorOutput())->toContain('InvalidReturnType');

        file_put_contents($config, '<invalid>');
        $invalidConfig = new Process($command, $workingDirectory);
        $invalidConfig->setTimeout(60);
        $invalidConfig->run();
        expect($invalidConfig->getExitCode())->not->toBe(0);
    } finally {
        chdir($originalCwd);
        unlink($root . '/' . $vendorDirectory . '/bin/psalter');
        unlink($root . '/' . $vendorDirectory . '/autoload.php');
        removeFixtureDirectory($root);
    }
})->with([
    'standard vendor and project config' => ['vendor', 'project'],
    'custom vendor and project config' => ['deps', 'project'],
    'custom vendor and bundled config' => ['deps', 'bundled'],
]);
