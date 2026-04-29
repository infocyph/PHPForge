<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class Cli
{
    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        return match ($command) {
            'ci' => $this->ci(array_slice($argv, 2)),
            'syntax' => (new SyntaxChecker())->run(array_slice($argv, 2)),
            'duplicates' => (new DuplicateDetector())->run(array_slice($argv, 2)),
            'phpstan-sarif' => (new PhpstanSarifConverter())->convert((string) ($argv[2] ?? ''), (string) ($argv[3] ?? 'phpstan-results.sarif')),
            'audit' => (new ComposerAuditor())->run(),
            default => $this->help(),
        };
    }

    /**
     * @param list<string> $args
     */
    private function ci(array $args): int
    {
        $commands = [
            'composer ic:test:syntax',
            'composer ic:test:code',
            'composer ic:test:lint',
            'composer ic:test:sniff',
            'composer ic:test:duplicates',
        ];

        if (!in_array('--prefer-lowest', $args, true)) {
            $commands[] = 'composer ic:test:static';
            $commands[] = 'composer ic:test:security';
        }

        $commands[] = 'composer ic:test:refactor';

        foreach ($commands as $command) {
            $exitCode = $this->passthru($command);

            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        return 0;
    }

    private function help(): int
    {
        fwrite(STDOUT, 'Usage: phpforge ci [--prefer-lowest] | syntax [paths...] | duplicates [options] [paths...] | audit | phpstan-sarif <phpstan-json> [sarif-output]' . PHP_EOL);

        return 0;
    }

    private function passthru(string $command): int
    {
        $process = proc_open($command, [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            fwrite(STDERR, sprintf('Failed to start process: %s', $command) . PHP_EOL);

            return 1;
        }

        return proc_close($process);
    }
}
