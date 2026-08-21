<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\StageCommand;
use Infocyph\PHPForge\Composer\CommandProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument as Argument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

function removeStageCommandTree(string $path): void
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

/** @return array{exit_code: int, output: string} */
function runStageCommand(array $paths): array
{
    $command = new StageCommand();
    $execute = new ReflectionMethod($command, 'execute');
    $input = new ArrayInput(
        ['paths' => $paths],
        new InputDefinition([new Argument('paths', Argument::IS_ARRAY | Argument::REQUIRED)]),
    );
    $output = new BufferedOutput();

    return [
        'exit_code' => $execute->invoke($command, $input, $output),
        'output' => $output->fetch(),
    ];
}

/** @param list<string> $command */
function runStageGit(array $command, string $root): string
{
    $process = new Process($command, $root);
    $process->mustRun();

    return $process->getOutput();
}

function createStageCommandFixture(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-stage-' . uniqid('', true);
    $bin = $root . DIRECTORY_SEPARATOR . 'test-bin';
    $source = $root . DIRECTORY_SEPARATOR . 'src';

    mkdir($bin, 0755, true);
    mkdir($source, 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', "{\"require\":{},\"name\":\"example/project\"}\n");
    file_put_contents($source . DIRECTORY_SEPARATOR . 'Example.php', "<?php\n\ndeclare(strict_types=1);\n");
    file_put_contents($bin . DIRECTORY_SEPARATOR . 'composer', <<<'PHP'
#!/usr/bin/env php
<?php

$file = getcwd() . DIRECTORY_SEPARATOR . 'composer.json';
$composer = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
ksort($composer);
file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
fwrite(STDOUT, "composer.json normalized\n");
PHP
    );
    chmod($bin . DIRECTORY_SEPARATOR . 'composer', 0755);

    runStageGit(['git', 'init', '--quiet'], $root);
    runStageGit(['git', 'config', 'user.email', 'phpforge@example.test'], $root);
    runStageGit(['git', 'config', 'user.name', 'PHPForge Tests'], $root);
    runStageGit(['git', 'add', '--', '.'], $root);
    runStageGit(['git', 'commit', '--quiet', '-m', 'Initial fixture'], $root);

    return $root;
}

it('publishes the canonical stage command through the Composer plugin', function (): void {
    $commandNames = array_map(
        static fn(Symfony\Component\Console\Command\Command $command): ?string => $command->getName(),
        (new CommandProvider())->getCommands(),
    );

    expect($commandNames)->toContain('ic:stage');
});

it('normalizes Composer files, validates PHP syntax, and stages all successful changes', function (): void {
    $originalCwd = getcwd();
    $originalPath = getenv('PATH');
    $root = createStageCommandFixture();

    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Example.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Example {}\n");
    chdir($root);
    putenv('PATH=' . $root . DIRECTORY_SEPARATOR . 'test-bin' . PATH_SEPARATOR . ($originalPath === false ? '' : $originalPath));

    try {
        $result = runStageCommand(['src/Example.php']);
        $staged = array_values(array_filter(explode("\n", runStageGit(['git', 'diff', '--cached', '--name-only'], $root))));

        expect($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('Syntax OK: 1 PHP file(s).')
            ->and($staged)->toBe(['composer.json', 'src/Example.php']);
    } finally {
        putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);

        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeStageCommandTree($root);
    }
});

it('prevents every selected and generated file from being staged when PHP syntax fails', function (): void {
    $originalCwd = getcwd();
    $originalPath = getenv('PATH');
    $root = createStageCommandFixture();

    file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Example.php', "<?php\n\nfunction broken(: void {}\n");
    chdir($root);
    putenv('PATH=' . $root . DIRECTORY_SEPARATOR . 'test-bin' . PATH_SEPARATOR . ($originalPath === false ? '' : $originalPath));

    try {
        $result = runStageCommand(['src/Example.php']);
        $staged = trim(runStageGit(['git', 'diff', '--cached', '--name-only'], $root));

        expect($result['exit_code'])->toBe(1)
            ->and($result['output'])->toContain('Syntax check failed: src/Example.php')
            ->and($result['output'])->toContain('Nothing was staged.')
            ->and($staged)->toBe('');
    } finally {
        putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);

        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeStageCommandTree($root);
    }
});
