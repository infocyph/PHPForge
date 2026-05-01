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
}
