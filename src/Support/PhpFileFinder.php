<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class PhpFileFinder
{
    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function find(array $paths): array
    {
        $files = $this->gitAwarePhpFiles($paths);

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @return list<string>
     */
    private function filterUnignoredPhpFiles(string $stdout): array
    {
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

        return $this->filterUnignoredPhpFiles($stdout);
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
