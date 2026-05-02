#!/usr/bin/env php
<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\TaskCatalog;
use Infocyph\PHPForge\Support\ParallelRunner;
use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$task = $argv[1] ?? '';
$output = new ConsoleOutput();

if ($task === 'tests:parallel') {
    $exitCode = (new ParallelRunner($output))->run(
        TaskCatalog::syntax(),
        TaskCatalog::testParallel(),
        ParallelRunner::concurrencyFrom($argv[2] ?? null),
    );

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf('Parallel tests failed with exit code %d.', $exitCode));
    }

    return;
}

$exitCode = (new Runner($output))->run(taskCommands($task));

if ($exitCode !== 0) {
    throw new RuntimeException(sprintf('Task "%s" failed with exit code %d.', $task, $exitCode));
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
        'test:architecture' => TaskCatalog::architecture(),
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
