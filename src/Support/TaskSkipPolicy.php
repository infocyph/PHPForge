<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class TaskSkipPolicy
{
    /**
     * @param list<string> $command
     */
    public static function shouldSkipUnavailablePerPreset(array $command, string $stdout, string $stderr): bool
    {
        if (!self::isPintCommand($command)) {
            return false;
        }

        if (!str_contains($stderr . PHP_EOL . $stdout, 'Preset not found')) {
            return false;
        }

        $config = self::optionValue($command, '--config');

        if (!is_string($config) || str_contains($config, "\0")) {
            return false;
        }

        try {
            $configPath = realpath(Paths::config('pint.json'));
        } catch (\RuntimeException) {
            return false;
        }

        if ($configPath === false || realpath($config) !== $configPath || !is_file($configPath)) {
            return false;
        }

        $contents = file_get_contents($configPath);

        if (!is_string($contents) || $contents === '') {
            return false;
        }

        return (ArrayShape::stringKeyed(json_decode($contents, true))['preset'] ?? null) === 'per';
    }

    /**
     * @param list<string> $command
     */
    private static function isPintCommand(array $command): bool
    {
        $executable = ($command[0] ?? '') === Paths::php() ? ($command[1] ?? '') : ($command[0] ?? '');

        return $executable === 'pint' || str_ends_with($executable, DIRECTORY_SEPARATOR . 'pint');
    }

    /**
     * @param list<string> $command
     */
    private static function optionValue(array $command, string $option): ?string
    {
        foreach ($command as $index => $argument) {
            if ($argument === $option) {
                return $command[$index + 1] ?? null;
            }

            if (str_starts_with($argument, $option . '=')) {
                return substr($argument, strlen($option) + 1);
            }
        }

        return null;
    }
}
