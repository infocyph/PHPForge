#!/usr/bin/env php
<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\TaskCatalog;
use Infocyph\PHPForge\Support\ParallelRunner;
use Infocyph\PHPForge\Support\TaskDisplay;
use Infocyph\PHPForge\Support\TaskSkipPolicy;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$task = $argv[1] ?? '';

if ($task === 'tests:parallel') {
    $exitCode = (new ParallelRunner(new ConsoleOutput()))->run(
        TaskCatalog::syntax(),
        TaskCatalog::testParallel(),
        ParallelRunner::concurrencyFrom($argv[2] ?? null),
    );

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf('Parallel tests failed with exit code %d.', $exitCode));
    }

    return;
}

$tasks = taskCommands($task);
$isFirstTask = true;

foreach ($tasks as $command) {
    if (!$isFirstTask) {
        fwrite(STDOUT, PHP_EOL);
    }

    $isFirstTask = false;
    fwrite(STDOUT, TaskDisplay::heading($command) . PHP_EOL);

    $process = runProcess($command);

    if ($process->isSuccessful()) {
        continue;
    }

    if (TaskSkipPolicy::shouldSkipUnavailablePerPreset($command, $process->getOutput(), $process->getErrorOutput())) {
        fwrite(STDERR, "Pint preset 'per' is unavailable; skipping this Pint task." . PHP_EOL);

        continue;
    }

    throw new RuntimeException(sprintf('Task "%s" failed with exit code %d.', implode(' ', $command), $process->getExitCode() ?? 1));
}

/**
 * @param list<string> $command
 */
function runProcess(array $command): Process
{
    $process = new Process($command, getcwd() ?: null);
    $process->setTimeout(null);
    $process->run(function (string $type, string $buffer): void {
        if ($type === Process::ERR) {
            fwrite(STDERR, $buffer);

            return;
        }

        fwrite(STDOUT, $buffer);
    });

    return $process;
}

/**
 * @return list<list<string>>
 */
function taskCommands(string $task): array
{
    return match ($task) {
        'process:all' => TaskCatalog::processAll(),
        'process:lint' => TaskCatalog::lintFix(),
        'process:refactor' => TaskCatalog::refactorFix(),
        'process:sniff' => TaskCatalog::sniffFix(),
        'test:code' => TaskCatalog::testCode(),
        'test:duplicates' => TaskCatalog::duplicates(),
        'test:lint' => TaskCatalog::lintCheck(),
        'test:refactor' => TaskCatalog::refactorCheck(),
        'test:security' => TaskCatalog::security(),
        'test:sniff' => TaskCatalog::sniff(),
        'test:static' => TaskCatalog::staticAnalysis(),
        'test:syntax' => TaskCatalog::syntax(),
        'tests' => TaskCatalog::testAll(),
        default => throw new InvalidArgumentException(sprintf('Unknown task "%s".', $task)),
    };
}
