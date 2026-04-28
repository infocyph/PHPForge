#!/usr/bin/env php
<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\TaskCatalog;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$task = $argv[1] ?? '';

$taskMap = [
    'process:all' => static fn(): array => TaskCatalog::processAll(),
    'process:lint' => static fn(): array => TaskCatalog::lintFix(),
    'process:refactor' => static fn(): array => TaskCatalog::refactorFix(),
    'process:sniff' => static fn(): array => TaskCatalog::sniffFix(),
    'test:code' => static fn(): array => TaskCatalog::testCode(),
    'test:lint' => static fn(): array => TaskCatalog::lintCheck(),
    'test:refactor' => static fn(): array => TaskCatalog::refactorCheck(),
    'test:security' => static fn(): array => TaskCatalog::security(),
    'test:sniff' => static fn(): array => TaskCatalog::sniff(),
    'test:static' => static fn(): array => TaskCatalog::staticAnalysis(),
    'test:syntax' => static fn(): array => TaskCatalog::syntax(),
    'tests' => static fn(): array => TaskCatalog::testAll(),
];

$resolver = $taskMap[$task] ?? null;

if (!is_callable($resolver)) {
    throw new InvalidArgumentException(sprintf('Unknown task "%s".', $task));
}

$tasks = $resolver();

foreach ($tasks as $command) {
    $process = new Process($command, getcwd() ?: null);
    $process->setTimeout(null);
    $process->run(function (string $type, string $buffer): void {
        if ($type === Process::ERR) {
            fwrite(STDERR, $buffer);

            return;
        }

        fwrite(STDOUT, $buffer);
    });

    if (!$process->isSuccessful()) {
        throw new RuntimeException(sprintf('Task "%s" failed with exit code %d.', implode(' ', $command), $process->getExitCode() ?? 1));
    }
}
