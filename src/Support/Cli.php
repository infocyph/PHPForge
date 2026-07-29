<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Infocyph\PHPForge\Composer\TaskCatalog;
use Symfony\Component\Console\Output\ConsoleOutput;

final class Cli
{
    private const COMMAND_ROWS = <<<'COMMANDS'
ci|Quality|ci [--prefer-lowest]|Run the CI quality suite.
syntax|Quality|syntax [paths...]|Check PHP syntax.
duplicates|Quality|duplicates [options] [paths...]|Find duplicated code.
api|Quality|api [options] [paths...]|Check the public API snapshot.
comments|Quality|comments [options] [paths...]|Check the comment policy.
check|Quality|check [options] [paths...]|Run aggregate PHPProbe checks.
doctor|Configuration|doctor [--json]|Inspect setup health and integration status.
list-config|Configuration|list-config [--json]|Show where tool configurations resolve.
active-config|Configuration|active-config [files...] [--json] [--all]|Inspect effective tool configuration.
audit|Utilities|audit|Run the Composer security audit.
release-constraints|Utilities|release-constraints|Reject non-stable runtime dependency constraints.
phpstan-sarif|Utilities|phpstan-sarif <input.json> [output.sarif]|Convert PHPStan JSON output to SARIF.
COMMANDS;

    private const GROUPS = ['Quality', 'Configuration', 'Utilities'];

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
            'release-constraints' => $this->releaseConstraints(),
            'help', '--help', '-h' => $this->help(),
            default => $this->unknownCommand($command),
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

    /**
     * @return array<string, array{group: string, usage: string, description: string}>
     */
    private function commands(): array
    {
        $commands = [];

        foreach (explode("\n", trim(self::COMMAND_ROWS)) as $line) {
            $parts = explode('|', $line, 4);

            if (count($parts) === 4) {
                [$name, $group, $usage, $description] = $parts;
                $commands[$name] = compact('group', 'usage', 'description');
            }
        }

        return $commands;
    }

    private function help(): int
    {
        $lines = [
            'PHPForge',
            'Shared quality, security, refactoring, and release tooling for PHP projects.',
            '',
            'Usage:',
            '  phpforge <command> [options]',
        ];

        foreach (self::GROUPS as $group) {
            $lines[] = '';
            $lines[] = $group . ':';

            foreach ($this->commands() as $command) {
                if ($command['group'] !== $group) {
                    continue;
                }

                $lines[] = sprintf('  %-48s %s', $command['usage'], $command['description']);
            }
        }

        $lines[] = '';
        $lines[] = 'Examples:';
        $lines[] = '  phpforge doctor';
        $lines[] = '  phpforge ci';
        $lines[] = '  phpforge active-config phpstan.neon.dist --parameter=cognitive_complexity';
        $lines[] = '';
        $lines[] = 'Run "composer list ic" to see the complete Composer command catalog.';

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);

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

    private function releaseConstraints(): int
    {
        $composerFile = Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'composer.json';
        $violations = (new StableRuntimeConstraints())->violations($composerFile);

        if ($violations === []) {
            fwrite(STDOUT, 'Stable runtime constraint guard passed.' . PHP_EOL);

            return 0;
        }

        fwrite(STDERR, 'Stable runtime constraint guard failed:' . PHP_EOL);

        foreach ($violations as $violation) {
            fwrite(STDERR, '  - ' . $violation . PHP_EOL);
        }

        return 1;
    }

    private function suggestCommand(string $command): ?string
    {
        $normalized = strtolower($command);
        $bestCommand = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($this->commands() as $candidate => $_metadata) {
            $distance = levenshtein($normalized, $candidate);

            if ($distance >= $bestDistance) {
                continue;
            }

            $bestCommand = $candidate;
            $bestDistance = $distance;
        }

        $maximumDistance = max(2, intdiv(strlen($normalized), 3));

        return $bestDistance <= $maximumDistance ? $bestCommand : null;
    }

    private function unknownCommand(string $command): int
    {
        fwrite(STDERR, sprintf('Error: unknown command "%s".%s', $command, PHP_EOL));

        $suggestion = $this->suggestCommand($command);

        if (is_string($suggestion)) {
            fwrite(STDERR, sprintf('Did you mean "%s"?%s', $suggestion, PHP_EOL));
        }

        fwrite(STDERR, 'Run "phpforge help" to list available commands.' . PHP_EOL);

        return 2;
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
