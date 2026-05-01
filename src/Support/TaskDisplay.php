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
        $config = self::configLabel($task);

        if (!is_string($config)) {
            return $title;
        }

        return sprintf('%s (%s)', $title, $config);
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
    private static function configLabel(array $task): ?string
    {
        $path = self::configPath($task);

        if (!is_string($path)) {
            return null;
        }

        $resolvedPath = realpath($path);
        $candidate = is_string($resolvedPath) ? $resolvedPath : $path;

        return 'Config: ' . self::displayPath($candidate);
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

    private static function displayPath(string $path): string
    {
        return str_replace('\\', '/', $path);
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
     * @return array{tool: string, subcommand: string}
     */
    private static function parseTool(array $task): array
    {
        $tool = self::commandBasename($task[0] ?? '');
        $subcommand = '';

        if ($tool === 'php' && isset($task[1])) {
            $tool = self::commandBasename($task[1]);

            for ($index = 2; $index < count($task); $index++) {
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
    private static function title(array $task): string
    {
        $parsed = self::parseTool($task);
        $tool = $parsed['tool'];
        $subcommand = $parsed['subcommand'];

        return match (true) {
            $tool === 'phpforge' && $subcommand === 'syntax' => 'Checking Syntax',
            $tool === 'phpforge' && $subcommand === 'duplicates' => 'Duplicate Code',
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
