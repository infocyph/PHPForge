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

if (in_array($task, ['tests', 'tests:parallel'], true)) {
    $exitCode = (new ParallelRunner($output))->run(
        [],
        TaskCatalog::testAll(),
        ParallelRunner::concurrencyFrom($argv[2] ?? null, count(TaskCatalog::testAll())),
    );

    exit($exitCode);
}

$failFast = $task !== 'tests:details';
$exitCode = (new Runner($output, $failFast))->run(taskCommands($task));

exit($exitCode);

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
        'bench:chart' => TaskCatalog::benchChart(),
        'bench:quick' => TaskCatalog::benchQuick(),
        'release:guard' => TaskCatalog::releaseGuard(),
        'test:code' => TaskCatalog::testCode(),
        'test:architecture' => TaskCatalog::architecture(),
        'test:bench' => TaskCatalog::benchRun(),
        'test:duplicates' => TaskCatalog::duplicates(),
        'test:probe' => TaskCatalog::probeCheck(),
        'test:comments' => TaskCatalog::comments(),
        'test:lint' => TaskCatalog::lintCheck(),
        'test:refactor' => TaskCatalog::refactorCheck(),
        'test:security' => TaskCatalog::security(),
        'test:sniff' => TaskCatalog::sniff(),
        'test:static' => TaskCatalog::staticAnalysis(),
        'test:syntax' => TaskCatalog::syntax(),
        'tests' => TaskCatalog::testAll(),
        'tests:details' => TaskCatalog::testDetails(),
        default => throw new InvalidArgumentException(sprintf('Unknown task "%s".', $task)),
    };
}
