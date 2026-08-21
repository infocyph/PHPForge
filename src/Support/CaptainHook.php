<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Process\Process;

final class CaptainHook
{
    private static ?string $installHelp = null;

    /**
     * @return list<string>
     */
    public static function installCommand(string $configPath): array
    {
        $command = [
            Paths::php(),
            Paths::bin('captainhook'),
            'install',
            '--configuration=' . $configPath,
            '--bootstrap=' . self::bootstrapPath($configPath),
            '--no-interaction',
        ];

        if (self::supportsInstallOption('--skip-existing')) {
            $command[] = '--skip-existing';
        } else {
            // Fallback for older CaptainHook variants to avoid interactive overwrite prompts.
            $command[] = '--force';
        }

        if (self::supportsInstallOption('--only-enabled')) {
            $command[] = '--only-enabled';
        }

        return $command;
    }

    private static function bootstrapPath(string $configPath): string
    {
        $configDirectory = str_replace('\\', '/', dirname($configPath));
        $autoloadPath = str_replace(
            '\\',
            '/',
            Paths::vendorDir() . DIRECTORY_SEPARATOR . 'autoload.php',
        );
        $configParts = explode('/', rtrim($configDirectory, '/'));
        $autoloadParts = explode('/', $autoloadPath);

        while (
            $configParts !== []
            && $autoloadParts !== []
            && self::samePathSegment($configParts[0], $autoloadParts[0])
        ) {
            array_shift($configParts);
            array_shift($autoloadParts);
        }

        return implode('/', [
            ...array_fill(0, count($configParts), '..'),
            ...$autoloadParts,
        ]);
    }

    private static function installHelp(): string
    {
        if (is_string(self::$installHelp)) {
            return self::$installHelp;
        }

        $process = new Process([Paths::php(), Paths::bin('captainhook'), 'install', '--help'], getcwd() ?: null);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            self::$installHelp = '';

            return self::$installHelp;
        }

        self::$installHelp = $process->getOutput() . PHP_EOL . $process->getErrorOutput();

        return self::$installHelp;
    }

    private static function samePathSegment(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private static function supportsInstallOption(string $option): bool
    {
        return str_contains(self::installHelp(), $option);
    }
}
