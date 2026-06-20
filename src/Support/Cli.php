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
            'api' => $this->probe('api', array_slice($argv, 2)),
            'comments' => $this->probe('comments', array_slice($argv, 2)),
            'check' => $this->probe('check', array_slice($argv, 2)),
            'active-config' => $this->activeConfig(array_slice($argv, 2)),
            'phpstan-sarif' => (new PhpstanSarifConverter())->convert((string) ($argv[2] ?? ''), (string) ($argv[3] ?? 'phpstan-results.sarif')),
            'audit' => (new ComposerAuditor())->run(),
            default => $this->help(),
        };
    }

    /**
     * @param list<string> $args
     */
    private function activeConfig(array $args): int
    {
        $json = in_array('--json', $args, true);
        $all = in_array('--all', $args, true);
        $parameter = $this->activeConfigParameter($args);
        $files = $all ? [] : $this->activeConfigFiles($args);
        $invalidFiles = ConfigFileSelection::invalid($files, ConfigInventory::activeFiles());

        if ($invalidFiles !== []) {
            fwrite(
                STDERR,
                sprintf(
                    'Invalid active config selection: %s. Supported files: %s%s',
                    implode(', ', $invalidFiles),
                    implode(', ', ConfigInventory::activeFiles()),
                    PHP_EOL,
                ),
            );

            return 1;
        }

        $activeConfig = (new ActiveConfigInspector())->inspect($files, $parameter);

        fwrite(
            STDOUT,
            $json
                ? (string) json_encode($activeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : (new ActiveConfigFormatter())->text($activeConfig),
        );
        fwrite(STDOUT, PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function activeConfigFiles(array $args): array
    {
        $files = [];

        foreach ($args as $index => $arg) {
            if ($arg === '--json' || $arg === '--all') {
                continue;
            }

            if (str_starts_with($arg, '--parameter=')) {
                continue;
            }

            if ($arg === '--parameter') {
                continue;
            }

            if ($index > 0 && $args[$index - 1] === '--parameter') {
                continue;
            }

            if (str_starts_with($arg, '--')) {
                $files = [...$files, ...ConfigFileSelection::normalize([$arg], ConfigInventory::activeFiles())];

                continue;
            }

            $files[] = $arg;
        }

        return $files;
    }

    /**
     * @param list<string> $args
     */
    private function activeConfigParameter(array $args): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, '--parameter=')) {
                $value = substr($arg, strlen('--parameter='));

                return $value !== '' ? $value : null;
            }

            if ($arg === '--parameter') {
                $value = $args[$index + 1] ?? null;

                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param list<string> $args
     */
    private function ci(array $args): int
    {
        $output = new ConsoleOutput();

        if (!in_array('--prefer-lowest', $args, true)) {
            return (new ParallelRunner($output))->run(TaskCatalog::syntax(), TaskCatalog::testParallelCi());
        }

        return (new Runner($output, false))->run(TaskCatalog::ci(true));
    }

    private function help(): int
    {
        fwrite(STDOUT, 'Usage: phpforge ci [--prefer-lowest] | syntax [paths...] | duplicates [options] [paths...] | api [options] [paths...] | comments [options] [paths...] | check [options] [paths...] | active-config [files...] [--json] [--all] [--parameter=name] | audit | phpstan-sarif <phpstan-json> [sarif-output]' . PHP_EOL);
        fwrite(STDOUT, 'Active-config file selection examples: phpforge active-config phpcs.xml.dist | composer ic:active-config phpcs.xml.dist | composer ic:active-config -- --phpcs.xml.dist' . PHP_EOL);

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

        return ['--config', Paths::config('phpprobe.json'), ...$args];
    }
}
