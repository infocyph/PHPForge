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
    $process = runProcess($command);

    if ($process->isSuccessful()) {
        continue;
    }

    $fallbackCommand = perPresetFallbackCommand($command, $process);

    if ($fallbackCommand !== null) {
        fwrite(STDERR, "Pint preset 'per' is unavailable; retrying with fallback preset 'psr12'." . PHP_EOL);

        $fallbackProcess = runProcess($fallbackCommand);
        $originalConfigPath = configuredPintPath($command);
        $fallbackConfigPath = configuredPintPath($fallbackCommand);

        if (is_string($fallbackConfigPath) && $fallbackConfigPath !== $originalConfigPath && is_file($fallbackConfigPath)) {
            unlink($fallbackConfigPath);
        }

        if ($fallbackProcess->isSuccessful()) {
            continue;
        }

        throw new RuntimeException(sprintf('Task "%s" failed with exit code %d.', implode(' ', $fallbackCommand), $fallbackProcess->getExitCode() ?? 1));
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
 * @param list<string> $command
 * @return list<string>|null
 */
function perPresetFallbackCommand(array $command, Process $process): ?array
{
    if (!isPintCommand($command)) {
        return null;
    }

    $message = $process->getErrorOutput() . PHP_EOL . $process->getOutput();

    if (!str_contains($message, 'Preset not found')) {
        return null;
    }

    $configIndex = array_search('--config', $command, true);

    if ($configIndex === false || !isset($command[$configIndex + 1])) {
        return null;
    }

    $configPath = $command[$configIndex + 1];

    if (!is_file($configPath)) {
        return null;
    }

    $contents = file_get_contents($configPath);

    if (!is_string($contents) || $contents === '') {
        return null;
    }

    $config = json_decode($contents, true);

    if (!is_array($config) || ($config['preset'] ?? null) !== 'per') {
        return null;
    }

    $config['preset'] = 'psr12';
    $fallbackPath = tempnam(sys_get_temp_dir(), 'phpforge-pint-');

    if (!is_string($fallbackPath) || $fallbackPath === '') {
        return null;
    }

    $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded)) {
        return null;
    }

    if (file_put_contents($fallbackPath, $encoded . PHP_EOL) === false) {
        return null;
    }

    $fallback = $command;
    $fallback[$configIndex + 1] = $fallbackPath;

    return $fallback;
}

/**
 * @param list<string> $command
 */
function isPintCommand(array $command): bool
{
    foreach ($command as $part) {
        if (str_ends_with($part, DIRECTORY_SEPARATOR . 'pint') || $part === 'pint') {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $command
 */
function configuredPintPath(array $command): ?string
{
    $configIndex = array_search('--config', $command, true);

    if ($configIndex === false || !isset($command[$configIndex + 1])) {
        return null;
    }

    return $command[$configIndex + 1];
}
