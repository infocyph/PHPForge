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
            'syntax' => $this->syntax(array_slice($argv, 2)),
            'phpstan-sarif' => $this->phpstanSarif((string) ($argv[2] ?? ''), (string) ($argv[3] ?? 'phpstan-results.sarif')),
            'audit' => $this->audit(),
            default => $this->help(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function abandonedPackages(mixed $abandoned): array
    {
        if (!is_array($abandoned)) {
            return [];
        }

        $packages = [];

        foreach ($abandoned as $package => $replacement) {
            if (is_string($package) && $package !== '') {
                $packages[$package] = $replacement;
            }
        }

        return $packages;
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
    }

    private function advisoryCount(mixed $advisories): int
    {
        if (!is_array($advisories)) {
            return 0;
        }

        $count = 0;

        foreach ($advisories as $entries) {
            if (is_array($entries)) {
                $count += count($entries);
            }
        }

        return $count;
    }

    private function audit(): int
    {
        $process = proc_open('composer audit --format=json --no-interaction --abandoned=report', [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            fwrite(STDERR, 'Failed to start composer audit process.' . PHP_EOL);

            return 1;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $decoded = json_decode($stdout, true);

        if (!is_array($decoded)) {
            fwrite(STDERR, 'Unable to parse composer audit JSON output.' . PHP_EOL);

            if (trim($stdout) !== '') {
                fwrite(STDERR, $stdout . PHP_EOL);
            }

            if (trim($stderr) !== '') {
                fwrite(STDERR, $stderr . PHP_EOL);
            }

            return $exitCode !== 0 ? $exitCode : 1;
        }

        $advisoryCount = $this->advisoryCount($decoded['advisories'] ?? []);
        $abandonedPackages = $this->abandonedPackages($decoded['abandoned'] ?? []);

        fwrite(STDOUT, sprintf(
            'Composer audit summary: %d advisories, %d abandoned packages.',
            $advisoryCount,
            count($abandonedPackages),
        ) . PHP_EOL);

        if ($abandonedPackages !== []) {
            fwrite(STDERR, 'Warning: abandoned packages detected (non-blocking):' . PHP_EOL);

            foreach ($abandonedPackages as $package => $replacement) {
                $target = is_string($replacement) && $replacement !== '' ? $replacement : 'none';
                fwrite(STDERR, sprintf(' - %s (replacement: %s)', $package, $target) . PHP_EOL);
            }
        }

        if ($advisoryCount > 0) {
            fwrite(STDERR, 'Security vulnerabilities detected by composer audit.' . PHP_EOL);

            return 1;
        }

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function ci(array $args): int
    {
        $preferLowest = in_array('--prefer-lowest', $args, true);
        $commands = [
            'composer ic:tests',
        ];

        if (!$preferLowest) {
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

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function gitAwarePhpFiles(array $paths): array
    {
        $gitFiles = $this->gitTrackedAndUnignoredPhpFiles($paths);

        if ($gitFiles !== null) {
            return $gitFiles;
        }

        return $this->recursivePhpFiles($paths === [] ? ['.'] : $paths);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, true>
     */
    private function gitIgnoredPaths(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $process = proc_open(['git', 'check-ignore', '-z', '--stdin', '--no-index'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return [];
        }

        fwrite($pipes[0], implode("\0", $paths) . "\0");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $ignored = [];

        foreach (explode("\0", $stdout) as $path) {
            if ($path !== '') {
                $ignored[$path] = true;
            }
        }

        return $ignored;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>|null
     */
    private function gitTrackedAndUnignoredPhpFiles(array $paths): ?array
    {
        $command = ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'];

        if ($paths !== []) {
            $command[] = '--';

            foreach ($paths as $path) {
                if ($path !== '') {
                    $command[] = $path;
                }
            }
        }

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return null;
        }

        $candidates = [];

        foreach (explode("\0", $stdout) as $file) {
            if ($file === '' || !str_ends_with($file, '.php')) {
                continue;
            }

            $absolute = $this->absolutePath($file);

            if (is_file($absolute)) {
                $candidates[$file] = $absolute;
            }
        }

        $ignored = $this->gitIgnoredPaths(array_keys($candidates));
        $files = [];

        foreach ($candidates as $path => $absolute) {
            if (!isset($ignored[$path])) {
                $files[] = $absolute;
            }
        }

        return $files;
    }

    private function help(): int
    {
        fwrite(STDOUT, 'Usage: phpforge ci [--prefer-lowest] | syntax [paths...] | audit | phpstan-sarif <phpstan-json> [sarif-output]' . PHP_EOL);

        return 0;
    }

    private function normalizeUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $cwd = getcwd();

        if (is_string($cwd) && $cwd !== '') {
            $cwd = rtrim(str_replace('\\', '/', $cwd), '/');

            if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 && stripos($normalized, $cwd . '/') === 0) {
                $normalized = substr($normalized, strlen($cwd) + 1);
            } elseif (str_starts_with($normalized, '/') && str_starts_with($normalized, $cwd . '/')) {
                $normalized = substr($normalized, strlen($cwd) + 1);
            }
        }

        $normalized = ltrim($normalized, './');

        return $normalized === '' ? 'unknown.php' : $normalized;
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

    private function phpstanSarif(string $input, string $output): int
    {
        if ($input === '') {
            fwrite(STDERR, 'Error: missing input file.' . PHP_EOL);
            fwrite(STDERR, 'Usage: phpforge phpstan-sarif <phpstan-json> [sarif-output]' . PHP_EOL);

            return 2;
        }

        if (!is_file($input) || !is_readable($input)) {
            fwrite(STDERR, "Error: input file not found or unreadable: {$input}" . PHP_EOL);

            return 2;
        }

        $raw = file_get_contents($input);

        if ($raw === false) {
            fwrite(STDERR, "Error: failed to read input file: {$input}" . PHP_EOL);

            return 2;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            fwrite(STDERR, 'Error: input is not valid JSON.' . PHP_EOL);

            return 2;
        }

        $results = [];
        $rules = [];

        foreach (($decoded['errors'] ?? []) as $error) {
            if (!is_string($error) || $error === '') {
                continue;
            }

            $ruleId = 'phpstan.internal';
            $rules[$ruleId] = true;
            $results[] = [
                'ruleId' => $ruleId,
                'level' => 'error',
                'message' => ['text' => $error],
            ];
        }

        $files = $decoded['files'] ?? [];

        if (is_array($files)) {
            foreach ($files as $filePath => $fileData) {
                if (!is_string($filePath) || !is_array($fileData)) {
                    continue;
                }

                $messages = $fileData['messages'] ?? [];

                if (!is_array($messages)) {
                    continue;
                }

                foreach ($messages as $messageData) {
                    if (!is_array($messageData)) {
                        continue;
                    }

                    $ruleId = (string) ($messageData['identifier'] ?? '') ?: 'phpstan.issue';
                    $line = max(1, (int) ($messageData['line'] ?? 1));
                    $rules[$ruleId] = true;
                    $results[] = [
                        'ruleId' => $ruleId,
                        'level' => 'error',
                        'message' => ['text' => (string) ($messageData['message'] ?? 'PHPStan issue')],
                        'locations' => [[
                            'physicalLocation' => [
                                'artifactLocation' => ['uri' => $this->normalizeUri($filePath)],
                                'region' => ['startLine' => $line],
                            ],
                        ]],
                    ];
                }
            }
        }

        $ruleIds = array_keys($rules);
        sort($ruleIds);

        $ruleDescriptors = array_map(
            static fn(string $ruleId): array => [
                'id' => $ruleId,
                'name' => $ruleId,
                'shortDescription' => ['text' => $ruleId],
            ],
            $ruleIds,
        );

        $sarif = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => 'PHPStan',
                        'informationUri' => 'https://phpstan.org/',
                        'rules' => $ruleDescriptors,
                    ],
                ],
                'results' => $results,
            ]],
        ];

        $encoded = json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            fwrite(STDERR, 'Error: failed to encode SARIF JSON.' . PHP_EOL);

            return 2;
        }

        if (file_put_contents($output, $encoded . PHP_EOL) === false) {
            fwrite(STDERR, "Error: failed to write output file: {$output}" . PHP_EOL);

            return 2;
        }

        fwrite(STDOUT, sprintf('SARIF generated: %s (%d findings)', $output, count($results)) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function recursivePhpFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }

            $absolute = $this->absolutePath($path);

            if (is_file($absolute) && str_ends_with($absolute, '.php')) {
                $files[] = $absolute;

                continue;
            }

            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                    $this->syntaxFilter(...),
                ),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * @param list<string> $paths
     */
    private function syntax(array $paths): int
    {
        $files = $this->gitAwarePhpFiles($paths);

        $files = array_values(array_unique($files));
        sort($files);

        if ($files === []) {
            fwrite(STDOUT, 'No PHP files found.' . PHP_EOL);

            return 0;
        }

        $failed = false;
        $failures = [];

        foreach ($files as $file) {
            $command = [PHP_BINARY, '-d', 'display_errors=1', '-l', $file];
            $process = proc_open($command, [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (!is_resource($process)) {
                $failures[] = [$file, 'Could not start PHP lint process'];
                $failed = true;

                continue;
            }

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_close($process);

            if ($status !== 0) {
                $message = trim($output . PHP_EOL . $error);
                $failures[] = [$file, $message !== '' ? $message : 'Unknown lint failure'];
                $failed = true;
            }
        }

        if (!$failed) {
            fwrite(STDOUT, sprintf('Syntax OK: %d PHP files checked.', count($files)) . PHP_EOL);

            return 0;
        }

        fwrite(STDERR, sprintf('Syntax errors in %d file(s):', count($failures)) . PHP_EOL);

        foreach ($failures as [$file, $message]) {
            fwrite(STDERR, "- {$file}" . PHP_EOL . $message . PHP_EOL);
        }

        return 1;
    }

    private function syntaxFilter(\SplFileInfo $file): bool
    {
        if (!$file->isDir()) {
            return true;
        }

        return !in_array($file->getFilename(), [
            '.git',
            '.idea',
            '.phpunit.cache',
            '.psalm-cache',
            '.vscode',
            'coverage',
            'node_modules',
            'vendor',
        ], true);
    }
}
