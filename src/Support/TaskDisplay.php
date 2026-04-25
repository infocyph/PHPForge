<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class TaskDisplay
{
    /**
     * @param list<string> $task
     */
    public static function heading(array $task): string
    {
        $title = self::title($task);
        $source = self::configSource($task);

        if (!is_string($source)) {
            return $title;
        }

        return sprintf('%s (%s)', $title, $source);
    }

    private static function commandBasename(string $command): string
    {
        if ($command === '') {
            return 'task';
        }

        $base = strtolower(basename(str_replace('\\', '/', $command)));

        if (str_ends_with($base, '.bat')) {
            return substr($base, 0, -4);
        }

        if (str_ends_with($base, '.exe')) {
            $base = substr($base, 0, -4);
        }

        if (preg_match('/^php(\d+(\.\d+)*)?$/', $base) === 1) {
            return 'php';
        }

        return $base;
    }

    /**
     * @param list<string> $task
     */
    private static function configPath(array $task): ?string
    {
        $optionValue = self::optionValue($task, '--configuration')
            ?? self::optionValue($task, '--config')
            ?? self::optionValue($task, '--standard');

        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
    }

    /**
     * @param list<string> $task
     */
    private static function configSource(array $task): ?string
    {
        $path = self::configPath($task);

        if (!is_string($path)) {
            return null;
        }

        if (self::isBundledVendorPath($path)) {
            return 'Stock';
        }

        $resolvedPath = realpath($path);
        $candidate = is_string($resolvedPath) ? $resolvedPath : $path;

        $projectRoot = Paths::projectRootPath();
        $packageRoot = dirname(__DIR__, 2);
        $projectRealPath = realpath($projectRoot);
        $packageRealPath = realpath($packageRoot);

        if (is_string($packageRealPath) && is_string($projectRealPath) && $projectRealPath !== $packageRealPath && self::isPathWithin($candidate, $packageRealPath)) {
            return 'Stock';
        }

        if (is_string($projectRealPath) && self::isPathWithin($candidate, $projectRealPath)) {
            return 'Project';
        }

        return null;
    }

    private static function isBundledVendorPath(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', strtolower($path));

        return str_contains($normalizedPath, '/vendor/infocyph/phpforge/');
    }

    private static function isPathWithin(string $path, string $base): bool
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedBase = rtrim(str_replace('\\', '/', $base), '/');

        return $normalizedPath === $normalizedBase || str_starts_with($normalizedPath, $normalizedBase . '/');
    }

    /**
     * @param list<string> $task
     */
    private static function optionValue(array $task, string $option): ?string
    {
        $taskCount = count($task);

        for ($index = 0; $index < $taskCount; $index++) {
            $argument = $task[$index];

            if ($argument === $option) {
                return $task[$index + 1] ?? null;
            }

            if (str_starts_with($argument, $option . '=')) {
                return substr($argument, strlen($option) + 1);
            }
        }

        return null;
    }

    /**
     * @param list<string> $task
     *
     * @return array{tool: string, subcommand: string}
     */
    private static function parseTool(array $task): array
    {
        $tool = self::commandBasename($task[0] ?? '');
        $subcommand = '';

        if ($tool === 'php' && isset($task[1])) {
            $toolIndex = self::phpScriptIndex($task);
            $tool = self::commandBasename($task[$toolIndex] ?? '');

            for ($index = $toolIndex + 1; $index < count($task); $index++) {
                if ($task[$index] !== '' && !str_starts_with($task[$index], '-')) {
                    $subcommand = $task[$index];

                    break;
                }
            }

            return [
                'tool' => $tool,
                'subcommand' => $subcommand,
            ];
        }

        for ($index = 1; $index < count($task); $index++) {
            if ($task[$index] !== '' && !str_starts_with($task[$index], '-')) {
                $subcommand = $task[$index];

                break;
            }
        }

        return [
            'tool' => $tool,
            'subcommand' => $subcommand,
        ];
    }

    /**
     * @param list<string> $task
     */
    private static function phpScriptIndex(array $task): int
    {
        $taskCount = count($task);

        for ($index = 1; $index < $taskCount; $index++) {
            $argument = $task[$index];

            if ($argument === '-d' || $argument === '-c') {
                $index++;

                continue;
            }

            if (str_starts_with($argument, '-d') || str_starts_with($argument, '-c')) {
                continue;
            }

            if ($argument !== '' && !str_starts_with($argument, '-')) {
                return $index;
            }
        }

        return 1;
    }

    /**
     * @param list<string> $task
     */
    private static function title(array $task): string
    {
        $parsed = self::parseTool($task);
        $tool = $parsed['tool'];
        $subcommand = $parsed['subcommand'];

        return match (true) {
            $tool === 'phpforge' && $subcommand === 'syntax' => 'Checking Syntax',
            $tool === 'phpforge' && $subcommand === 'audit' => 'Composer Audit',
            $tool === 'composer' && $subcommand === 'validate' => 'Composer Validate',
            $tool === 'composer' && $subcommand === 'normalize' => 'Composer Normalize',
            $tool === 'pest' => 'Pest',
            $tool === 'pint' => 'Pint',
            $tool === 'phpcs' => 'PHPCS',
            $tool === 'phpcbf' => 'PHPCBF',
            $tool === 'phpstan' => 'PHPStan',
            $tool === 'psalm' => 'Psalm',
            $tool === 'rector' => 'Rector',
            $tool === 'phpbench' => 'PHPBench',
            $tool === 'captainhook' => 'CaptainHook',
            default => ucfirst($tool),
        };
    }
}
