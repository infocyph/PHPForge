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

    private static function supportsInstallOption(string $option): bool
    {
        return str_contains(self::installHelp(), $option);
    }
}
