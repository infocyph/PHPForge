<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Infocyph\PHPProbe\Config\PhpProbeConfig;
use Symfony\Component\Process\Process;

/**
 * Discovers and classifies files without claiming unsupported structural parsing.
 *
 * @phpstan-type FileRecord array{path:string,category:string,language:string,size:int,content_hash:string}
 */
final class KnowledgeFileCatalog
{
    private const array ALWAYS_EXCLUDED = [
        '.git', '.idea', '.phpforge', '.phpunit.cache', '.psalm-cache', '.vscode', 'build', 'coverage', 'dist',
        'graphify-out', 'node_modules', 'phpforge', 'phpforge-out', 'storage', 'tmp', 'var/cache', 'vendor',
    ];

    private const array BINARY_EXTENSIONS = [
        '7z', 'avi', 'avif', 'bmp', 'bz2', 'class', 'db', 'dll', 'doc', 'docx', 'eot', 'exe', 'gif', 'gz',
        'ico', 'jar', 'jpeg', 'jpg', 'mov', 'mp3', 'mp4', 'o', 'odt', 'pdf', 'png', 'so', 'sqlite', 'sqlite3',
        'tar', 'ttf', 'wav', 'webm', 'webp', 'woff', 'woff2', 'xls', 'xlsx', 'xz', 'zip',
    ];

    private const array LANGUAGE_BY_EXTENSION = [
        'bash' => 'shell', 'cjs' => 'javascript', 'conf' => 'configuration', 'css' => 'stylesheet',
        'cts' => 'typescript', 'env' => 'configuration', 'graphql' => 'graphql', 'htm' => 'html', 'html' => 'html',
        'ini' => 'configuration', 'inc' => 'php-template', 'js' => 'javascript', 'json' => 'json',
        'jsx' => 'javascript', 'less' => 'stylesheet', 'lock' => 'lockfile', 'md' => 'markdown',
        'mjs' => 'javascript', 'mts' => 'typescript', 'neon' => 'configuration', 'phtml' => 'php-template',
        'rst' => 'documentation', 'sass' => 'stylesheet', 'scss' => 'stylesheet', 'sh' => 'shell', 'sql' => 'sql',
        'svg' => 'svg', 'toml' => 'configuration', 'ts' => 'typescript', 'tsx' => 'typescript', 'twig' => 'template',
        'txt' => 'text', 'vue' => 'component', 'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml', 'zsh' => 'shell',
    ];

    private const array LANGUAGE_BY_FILENAME = [
        '.editorconfig' => 'configuration', '.gitattributes' => 'configuration', '.gitignore' => 'configuration',
        'CODEOWNERS' => 'configuration', 'Dockerfile' => 'container', 'LICENSE' => 'documentation', 'Makefile' => 'build',
    ];

    private const int MAX_INDEXED_FILE_BYTES = 2_000_000;

    private const array SENSITIVE_NAMES = [
        '.env', '.netrc', '.npmrc', '.pypirc', '.yarnrc', 'auth.json', 'credentials', 'credentials.json',
        'id_dsa', 'id_ed25519', 'id_rsa', 'secrets.json', 'service-account.json',
    ];

    /** @param list<string> $paths
     * @return list<FileRecord>
     */
    public function records(string $root, array $paths): array
    {
        $pathspecs = $this->pathspecs($root, $paths);
        $excludes = [...self::ALWAYS_EXCLUDED, ...$this->probeExcludes()];
        $files = $this->trackedFiles($root, $pathspecs) ?? $this->recursiveFiles($root, $pathspecs, $excludes);
        $records = [];

        foreach ($files as $relative) {
            $relative = str_replace('\\', '/', $relative);
            $relative = str_starts_with($relative, './') ? substr($relative, 2) : $relative;

            if ($relative === '') {
                continue;
            }

            if ($this->isExcluded($relative, $excludes)) {
                continue;
            }

            $record = $this->record($root, $relative);

            if (is_array($record)) {
                $records[] = $record;
            }
        }

        usort($records, static fn(array $left, array $right): int => $left['path'] <=> $right['path']);

        return $records;
    }

    private function absolutePath(string $path, string $root): string
    {
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /** @return array{category:string,language:string} */
    private function classify(string $path, int $size): array
    {
        $basename = basename($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($this->isSensitive($path)) {
            return ['category' => 'skipped', 'language' => 'sensitive'];
        }

        if ($size > self::MAX_INDEXED_FILE_BYTES) {
            return ['category' => 'unsupported', 'language' => 'oversized'];
        }

        if ($extension === 'php' && !str_ends_with(strtolower($path), '.blade.php')) {
            return ['category' => 'structural', 'language' => 'php'];
        }

        if (isset(self::LANGUAGE_BY_FILENAME[$basename])) {
            return ['category' => 'inventory', 'language' => self::LANGUAGE_BY_FILENAME[$basename]];
        }

        if (isset(self::LANGUAGE_BY_EXTENSION[$extension])) {
            return ['category' => 'inventory', 'language' => self::LANGUAGE_BY_EXTENSION[$extension]];
        }

        return in_array($extension, self::BINARY_EXTENSIONS, true)
            ? ['category' => 'skipped', 'language' => 'binary']
            : ['category' => 'inventory', 'language' => 'text'];
    }

    /** @param list<string> $excludes */
    private function isExcluded(string $path, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            $exclude = trim(str_replace('\\', '/', $exclude), '/');

            if ($exclude !== '' && ($path === $exclude || str_starts_with($path, $exclude . '/'))) {
                return true;
            }
        }

        return false;
    }

    private function isSensitive(string $path): bool
    {
        $lower = strtolower($path);
        $basename = basename($lower);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($basename, self::SENSITIVE_NAMES, true)
            || str_starts_with($basename, '.env.')
            || in_array($extension, ['key', 'p12', 'pfx', 'pem'], true)
            || str_starts_with($lower, 'credentials/')
            || str_starts_with($lower, 'secrets/')
            || str_contains($lower, '/credentials/')
            || str_contains($lower, '/secrets/');
    }

    private function isUtf8Text(string $path): bool
    {
        $contents = file_get_contents($path);

        return is_string($contents) && !str_contains($contents, "\0") && preg_match('//u', $contents) === 1;
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    private function pathspecs(string $root, array $paths): array
    {
        if ($paths === []) {
            return ['.'];
        }

        $normalized = [];
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ($paths as $path) {
            $absolute = realpath($this->absolutePath($path, $root));

            if (!is_string($absolute) || (!is_file($absolute) && !is_dir($absolute))) {
                throw new \InvalidArgumentException(sprintf('Knowledge path does not exist: %s', $path));
            }

            if ($absolute !== $root && !str_starts_with($absolute, $prefix)) {
                throw new \InvalidArgumentException(sprintf('Knowledge path must be inside project root: %s', $path));
            }

            $normalized[] = $absolute === $root ? '.' : str_replace('\\', '/', substr($absolute, strlen($prefix)));
        }

        return array_values(array_unique($normalized));
    }

    /** @return list<string> */
    private function probeExcludes(): array
    {
        $config = PhpProbeConfig::fromFile(Paths::config('phpprobe.json'));
        $preset = $config->preset();

        if (is_string($preset)) {
            $reflection = new \ReflectionClass(PhpProbeConfig::class);
            $packageRoot = dirname((string) $reflection->getFileName(), 3);
            $presetFile = $packageRoot . DIRECTORY_SEPARATOR . 'resources/presets/' . $preset . '.json';

            if (is_file($presetFile)) {
                $config = PhpProbeConfig::fromFile($presetFile)->merge($config);
            }
        }

        $options = $config->applyGraphOptions([
            'paths' => [], 'excludes' => [], 'changedOnly' => false, 'changedBase' => '',
            'output' => '', 'pretty' => false, 'root' => '',
        ]);
        $excludes = $options['excludes'] ?? [];

        return is_array($excludes) ? array_values(array_filter($excludes, is_string(...))) : [];
    }

    /** @return FileRecord|null */
    private function record(string $root, string $relative): ?array
    {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_file($absolute) || is_link($absolute)) {
            return null;
        }

        $size = filesize($absolute);

        if (!is_int($size)) {
            throw new \RuntimeException(sprintf('Could not inspect project file: %s', $relative));
        }

        $classification = $this->classify($relative, $size);
        $hash = '';

        if (in_array($classification['category'], ['structural', 'inventory'], true)) {
            $hash = hash_file('sha256', $absolute);

            if (!is_string($hash)) {
                throw new \RuntimeException(sprintf('Could not inspect project file: %s', $relative));
            }

            if ($classification['category'] === 'inventory' && !$this->isUtf8Text($absolute)) {
                $classification = ['category' => 'skipped', 'language' => 'binary'];
                $hash = '';
            }
        }

        return [
            'path' => $relative,
            'category' => $classification['category'],
            'language' => $classification['language'],
            'size' => $size,
            'content_hash' => $hash,
        ];
    }

    /** @param list<string> $pathspecs
     * @param list<string> $excludes
     * @return list<string>
     */
    private function recursiveFiles(string $root, array $pathspecs, array $excludes): array
    {
        $files = [];

        foreach ($pathspecs as $pathspec) {
            $absolute = $pathspec === '.' ? $root : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pathspec);

            if (is_file($absolute)) {
                $files[] = $pathspec;

                continue;
            }

            $directory = new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator(
                $directory,
                fn(\SplFileInfo $file): bool => !$file->isDir() || !$this->isExcluded($this->relative($file->getPathname(), $root), $excludes),
            );
            $iterator = new \RecursiveIteratorIterator($filter);

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    private function relative(string $path, string $root): string
    {
        return str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }

    /** @param list<string> $pathspecs
     * @return list<string>|null
     */
    private function trackedFiles(string $root, array $pathspecs): ?array
    {
        $process = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', ...$pathspecs], $root, timeout: 30);
        $process->run();

        return $process->isSuccessful()
            ? array_values(array_filter(explode("\0", $process->getOutput()), static fn(string $path): bool => $path !== ''))
            : null;
    }
}
