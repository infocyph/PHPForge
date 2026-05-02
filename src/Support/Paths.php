<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class Paths
{
    public static function bin(string $name): string
    {
        $vendorBinary = self::binDir() . DIRECTORY_SEPARATOR . $name;

        if (is_file($vendorBinary)) {
            return $vendorBinary;
        }

        if (!self::isPhpforgeRootPackage()) {
            return $vendorBinary;
        }

        return self::projectRoot() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name;
    }

    public static function bundledConfigFile(string $file): string
    {
        $resourceFile = self::bundledConfigFileOrNull($file);

        if (is_string($resourceFile)) {
            return $resourceFile;
        }

        throw new \RuntimeException(sprintf(
            'Missing PHPForge config "%s". Expected project config at "%s" or bundled config at "%s"%s.',
            $file,
            self::projectRoot() . DIRECTORY_SEPARATOR . $file,
            self::vendorResourceFile($file),
            self::isPhpforgeRootPackage() ? sprintf(' or PHPForge source config at "%s"', self::phpforgeRootResourceFile($file)) : '',
        ));
    }

    public static function bundledConfigFileOrNull(string $file): ?string
    {
        $vendorResourceFile = self::vendorResourceFile($file);

        if (is_file($vendorResourceFile)) {
            return $vendorResourceFile;
        }

        if (self::isPhpforgeRootPackage()) {
            $phpforgeRootResourceFile = self::phpforgeRootResourceFile($file);

            if (is_file($phpforgeRootResourceFile)) {
                return $phpforgeRootResourceFile;
            }
        }

        return null;
    }

    public static function config(string $file): string
    {
        $projectFile = self::projectRoot() . DIRECTORY_SEPARATOR . $file;

        if (is_file($projectFile)) {
            return $projectFile;
        }

        return self::bundledConfigFile($file);
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
        $projectConfig = self::firstProjectConfig($files);

        if (is_string($projectConfig)) {
            return $projectConfig;
        }

        foreach ($files as $file) {
            $packageFile = self::bundledConfigFileOrNull($file);

            if (is_string($packageFile)) {
                return $packageFile;
            }
        }

        return self::bundledConfigFile($files[0]);
    }

    /**
     * @param non-empty-list<string> $files
     */
    public static function firstProjectConfig(array $files): ?string
    {
        foreach ($files as $file) {
            $projectFile = self::projectRoot() . DIRECTORY_SEPARATOR . $file;

            if (is_file($projectFile)) {
                return $projectFile;
            }
        }

        return null;
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

    private static function binDir(): string
    {
        $configured = self::composerConfig('bin-dir');

        if (is_string($configured) && $configured !== '') {
            return self::absoluteProjectPath($configured);
        }

        return self::vendorDir() . DIRECTORY_SEPARATOR . 'bin';
    }

    private static function composerConfig(string $key): mixed
    {
        $config = self::composerData()['config'] ?? [];

        if (!is_array($config)) {
            return null;
        }

        return $config[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function composerData(): array
    {
        $composerJson = self::projectRoot() . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson) || !is_readable($composerJson)) {
            return [];
        }

        $contents = file_get_contents($composerJson);

        if (!is_string($contents)) {
            return [];
        }

        $data = json_decode($contents, true);

        return ArrayShape::stringKeyed($data);
    }

    private static function isPhpforgeRootPackage(): bool
    {
        $data = self::composerData();

        return ($data['name'] ?? null) === 'infocyph/phpforge';
    }

    private static function phpforgeRootResourceFile(string $file): string
    {
        return self::projectRoot() . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . ltrim($file, '/\\');
    }

    private static function projectRoot(): string
    {
        return getcwd() ?: dirname(__DIR__, 2);
    }

    private static function vendorPackageRoot(): string
    {
        return self::vendorDir() . DIRECTORY_SEPARATOR . 'infocyph' . DIRECTORY_SEPARATOR . 'phpforge';
    }

    private static function vendorResourceFile(string $file): string
    {
        return self::vendorPackageRoot() . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . ltrim($file, '/\\');
    }
}
