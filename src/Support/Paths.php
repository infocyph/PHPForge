<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class Paths
{
    public static function bin(string $name): string
    {
        $path = self::vendorDir() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name;

        if (is_file($path)) {
            return $path;
        }

        return $path;
    }

    public static function config(string $file): string
    {
        $projectFile = self::projectRoot() . DIRECTORY_SEPARATOR . $file;

        if (is_file($projectFile)) {
            return $projectFile;
        }

        return self::packageFile($file);
    }

    /**
     * @return list<string>
     */
    public static function existingProjectPaths(string ...$paths): array
    {
        $paths = $paths === [] ? ['src', 'tests', 'benchmarks', 'examples'] : $paths;

        return array_values(array_filter(
            $paths,
            static fn(string $path): bool => is_dir(self::projectRoot() . DIRECTORY_SEPARATOR . $path),
        ));
    }

    /**
     * @param non-empty-list<string> $files
     */
    public static function firstConfig(array $files): string
    {
        foreach ($files as $file) {
            $projectFile = self::projectRoot() . DIRECTORY_SEPARATOR . $file;

            if (is_file($projectFile)) {
                return $projectFile;
            }
        }

        foreach ($files as $file) {
            $packageFile = self::packageFile($file);

            if (is_file($packageFile)) {
                return $packageFile;
            }
        }

        return self::packageFile($files[0]);
    }

    public static function packageFile(string $file): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    }

    public static function php(): string
    {
        return PHP_BINARY;
    }

    public static function projectRootPath(): string
    {
        return self::projectRoot();
    }

    public static function vendorDir(): string
    {
        $configured = self::composerConfig('vendor-dir');

        if (is_string($configured) && $configured !== '') {
            return self::absoluteProjectPath($configured);
        }

        return self::projectRoot() . DIRECTORY_SEPARATOR . 'vendor';
    }

    private static function absoluteProjectPath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return self::projectRoot() . DIRECTORY_SEPARATOR . $path;
    }

    private static function composerConfig(string $key): mixed
    {
        $composerJson = self::projectRoot() . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson) || !is_readable($composerJson)) {
            return null;
        }

        $contents = file_get_contents($composerJson);

        if (!is_string($contents)) {
            return null;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return null;
        }

        $config = $data['config'] ?? [];

        if (!is_array($config)) {
            return null;
        }

        return $config[$key] ?? null;
    }

    private static function projectRoot(): string
    {
        return getcwd() ?: dirname(__DIR__, 2);
    }
}
