<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Infocyph\PHPForge\Composer\TaskCatalog;
use Symfony\Component\Console\Output\ConsoleOutput;

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
            'syntax' => $this->probe('syntax', array_slice($argv, 2)),
            'duplicates' => $this->probe('duplicates', array_slice($argv, 2)),
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
        $output = new ConsoleOutput();

        if (!in_array('--prefer-lowest', $args, true)) {
            return (new ParallelRunner($output))->run(TaskCatalog::syntax(), TaskCatalog::testParallel());
        }

        return (new Runner($output))->run(TaskCatalog::ci(true));
    }

    private function help(): int
    {
        fwrite(STDOUT, 'Usage: phpforge ci [--prefer-lowest] | syntax [paths...] | duplicates [options] [paths...] | audit | phpstan-sarif <phpstan-json> [sarif-output]' . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function probe(string $command, array $args): int
    {
        $binary = Paths::bin('phpprobe');

        if (!is_file($binary)) {
            fwrite(STDERR, 'PHPProbe is not installed. Run composer install or require infocyph/phpprobe.' . PHP_EOL);

            return 2;
        }

        $arguments = $this->withDefaultProbeConfig($args);
        $result = (new ProcRunner())->run([PHP_BINARY, $binary, $command, ...$arguments]);

        if (!$result instanceof ProcessResult) {
            fwrite(STDERR, 'Could not start PHPProbe.' . PHP_EOL);

            return 2;
        }

        fwrite(STDOUT, $result->stdout);
        fwrite(STDERR, $result->stderr);

        return $result->exitCode;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function withDefaultProbeConfig(array $args): array
    {
        foreach ($args as $index => $arg) {
            if ($arg === '--config' || str_starts_with($arg, '--config=')) {
                return $args;
            }

            if ($index > 0 && $args[$index - 1] === '--config') {
                return $args;
            }
        }

        return ['--config', Paths::config('phpforge.json'), ...$args];
    }
}
