<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class SyntaxChecker
{
    /**
     * @param list<string> $paths
     */
    public function run(array $paths): int
    {
        $options = $this->parseArgs($paths);

        if ($options['help']) {
            return $this->help();
        }

        $files = (new PhpFileFinder())->find($options['paths']);

        if ($files === []) {
            fwrite(STDOUT, 'No PHP files found.' . PHP_EOL);

            return 0;
        }

        return $this->lintFiles($files);
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpforge syntax [options] [paths...]',
            '',
            'Options:',
            '  --config=FILE                  read PHPForge native checker settings',
            '  --help                         show this help',
        ]) . PHP_EOL);

        return 0;
    }

    private function lintFile(string $file): ?string
    {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-l', $file], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return 'Could not start PHP lint process';
        }

        $output = stream_get_contents($pipes[1]) ?: '';
        $error = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) === 0) {
            return null;
        }

        $message = trim($output . PHP_EOL . $error);

        return $message !== '' ? $message : 'Unknown lint failure';
    }

    /**
     * @param list<string> $files
     */
    private function lintFiles(array $files): int
    {
        $failures = [];

        foreach ($files as $file) {
            $failure = $this->lintFile($file);

            if (is_string($failure)) {
                $failures[] = [$file, $failure];
            }
        }

        if ($failures === []) {
            fwrite(STDOUT, sprintf('Syntax OK: %d PHP files checked.', count($files)) . PHP_EOL);

            return 0;
        }

        fwrite(STDERR, sprintf('Syntax errors in %d file(s):', count($failures)) . PHP_EOL);

        foreach ($failures as [$file, $message]) {
            fwrite(STDERR, "- {$file}" . PHP_EOL . $message . PHP_EOL);
        }

        return 1;
    }

    private function optionValue(string $arg, string $name): ?string
    {
        if (!str_starts_with($arg, $name . '=')) {
            return null;
        }

        return substr($arg, strlen($name) + 1);
    }

    /**
     * @param list<string> $args
     *
     * @return array{help:bool,config:string,paths:list<string>}
     */
    private function parseArgs(array $args): array
    {
        $options = [
            'help' => false,
            'config' => Paths::config('phpforge.json'),
            'paths' => [],
        ];

        for ($index = 0; $index < count($args); $index++) {
            $arg = $args[$index];

            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;

                continue;
            }

            $config = $this->optionValue($arg, '--config');

            if ($config !== null) {
                $options['config'] = $config;

                continue;
            }

            if ($arg === '--config') {
                if (isset($args[$index + 1])) {
                    $options['config'] = $args[++$index];
                }

                continue;
            }

            $options['paths'][] = $arg;
        }

        if ($options['paths'] === []) {
            $options['paths'] = PhpForgeConfig::fromFile($options['config'])->syntaxPaths();
        }

        return $options;
    }
}
