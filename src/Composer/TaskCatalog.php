<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Infocyph\PHPForge\Support\Paths;

final class TaskCatalog
{
    private const PHP_ERROR_REPORTING_WITHOUT_ENGINE_DEPRECATIONS = 'error_reporting=24575';

    /**
     * @return list<list<string>>
     */
    public static function benchChart(): array
    {
        return [self::benchCommand(['--report=chart'])];
    }

    /**
     * @return list<list<string>>
     */
    public static function benchQuick(): array
    {
        return [self::benchCommand(['--report=aggregate', '--revs=10', '--iterations=3', '--warmup=1'])];
    }

    /**
     * @return list<list<string>>
     */
    public static function benchRun(): array
    {
        return [self::benchCommand(['--report=aggregate'])];
    }

    /**
     * @return list<list<string>>
     */
    public static function ci(bool $skipHeavyAnalysis = false): array
    {
        $tasks = [
            ...self::syntax(),
            ...self::testCode(),
            ...self::lintCheck(),
            ...self::sniff(),
            ...self::refactorCheck(),
        ];

        if (!$skipHeavyAnalysis) {
            $tasks = [
                ...$tasks,
                ...self::staticAnalysis(),
                ...self::security(),
            ];
        }

        return $tasks;
    }

    /**
     * @return list<list<string>>
     */
    public static function hooks(): array
    {
        return [[Paths::php(), Paths::bin('captainhook'), 'install', '--configuration=' . Paths::config('captainhook.json'), '--only-enabled', '-nf']];
    }

    /**
     * @return list<list<string>>
     */
    public static function lintCheck(): array
    {
        return [[Paths::php(), Paths::bin('pint'), '--test', '--config', Paths::config('pint.json')]];
    }

    /**
     * @return list<list<string>>
     */
    public static function lintFix(): array
    {
        return [[Paths::php(), Paths::bin('pint'), '--config', Paths::config('pint.json')]];
    }

    /**
     * @return list<list<string>>
     */
    public static function normalizeComposer(): array
    {
        return [['composer', 'normalize']];
    }

    /**
     * @return list<list<string>>
     */
    public static function processAll(): array
    {
        return [
            ...self::normalizeComposer(),
            ...self::refactorFix(),
            ...self::lintFix(),
            ...self::sniffFix(),
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function refactorCheck(): array
    {
        return [[Paths::php(), Paths::bin('rector'), 'process', '--config=' . Paths::config('rector.php'), '--dry-run', '--debug']];
    }

    /**
     * @return list<list<string>>
     */
    public static function refactorFix(): array
    {
        return [[Paths::php(), Paths::bin('rector'), 'process', '--config=' . Paths::config('rector.php')]];
    }

    /**
     * @return list<list<string>>
     */
    public static function releaseAudit(): array
    {
        return [[Paths::php(), Paths::bin('phpforge'), 'audit']];
    }

    /**
     * @return list<list<string>>
     */
    public static function releaseGuard(): array
    {
        return [
            ['composer', 'validate', '--strict'],
            ...self::releaseAudit(),
            ...self::testAll(),
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function security(): array
    {
        return [[Paths::php(), '-d', self::PHP_ERROR_REPORTING_WITHOUT_ENGINE_DEPRECATIONS, Paths::bin('psalm'), '--config=' . Paths::config('psalm.xml'), '--security-analysis', '--threads=' . self::psalmThreads(), '--no-cache']];
    }

    /**
     * @return list<list<string>>
     */
    public static function sniff(): array
    {
        return [[Paths::php(), Paths::bin('phpcs'), '--standard=' . Paths::config('phpcs.xml.dist'), '--report=full', ...self::codePaths()]];
    }

    /**
     * @return list<list<string>>
     */
    public static function sniffFix(): array
    {
        return [[Paths::php(), Paths::bin('phpcbf'), '--standard=' . Paths::config('phpcs.xml.dist'), '--runtime-set', 'ignore_errors_on_exit', '1', ...self::codePaths()]];
    }

    /**
     * @return list<list<string>>
     */
    public static function staticAnalysis(): array
    {
        return [[Paths::php(), Paths::bin('phpstan'), 'analyse', '--configuration=' . Paths::config('phpstan.neon.dist'), '--memory-limit=' . self::phpstanMemoryLimit(), '--no-progress', '--debug']];
    }

    /**
     * @return list<list<string>>
     */
    public static function syntax(): array
    {
        return [[Paths::php(), Paths::bin('phpforge'), 'syntax']];
    }

    /**
     * @return list<list<string>>
     */
    public static function testAll(): array
    {
        return [
            ...self::syntax(),
            [Paths::php(), Paths::bin('pest'), ...self::pestConfigArgs(), '--parallel', '--processes=' . self::pestProcesses()],
            [Paths::php(), Paths::bin('pint'), '--test', '--config', Paths::config('pint.json')],
            [Paths::php(), Paths::bin('phpcs'), '--standard=' . Paths::config('phpcs.xml.dist'), '--report=summary', ...self::codePaths()],
            ...self::staticAnalysis(),
            [Paths::php(), '-d', self::PHP_ERROR_REPORTING_WITHOUT_ENGINE_DEPRECATIONS, Paths::bin('psalm'), '--config=' . Paths::config('psalm.xml'), '--show-info=false', '--security-analysis', '--threads=' . self::psalmThreads(), '--no-progress', '--no-cache'],
            ...self::refactorCheck(),
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function testCode(): array
    {
        return [[Paths::php(), Paths::bin('pest'), ...self::pestConfigArgs()]];
    }

    /**
     * @return list<list<string>>
     */
    public static function testDetails(): array
    {
        return [
            ...self::syntax(),
            ...self::testCode(),
            ...self::lintCheck(),
            ...self::sniff(),
            ...self::staticAnalysis(),
            ...self::security(),
            ...self::refactorCheck(),
        ];
    }

    private static function absoluteProjectPath(string $projectRoot, string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $normalized = ltrim($normalized, '.\\/ ');

        return $projectRoot . DIRECTORY_SEPARATOR . $normalized;
    }

    /**
     * @param list<string> $options
     *
     * @return list<string>
     */
    private static function benchCommand(array $options): array
    {
        $configPath = Paths::config('phpbench.json');

        return [
            Paths::php(),
            Paths::bin('phpbench'),
            'run',
            '--config=' . $configPath,
            ...self::bundledBenchBootstrapArgs($configPath),
            ...$options,
            ...self::bundledBenchPathArgs($configPath),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function benchConfig(string $configPath): array
    {
        $contents = file_get_contents($configPath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $config = json_decode($contents, true);

        if (!is_array($config)) {
            return [];
        }

        $stringKeyedConfig = [];

        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $stringKeyedConfig[$key] = $value;
            }
        }

        return $stringKeyedConfig;
    }

    /**
     * @return list<string>
     */
    private static function bundledBenchBootstrapArgs(string $configPath): array
    {
        if (!self::isBundledConfigInConsumingProject($configPath)) {
            return [];
        }

        $bootstrap = self::benchConfig($configPath)['runner.bootstrap'] ?? null;

        return is_string($bootstrap) && $bootstrap !== ''
            ? ['--bootstrap', self::absoluteProjectPath(Paths::projectRootPath(), $bootstrap)]
            : [];
    }

    /**
     * @return list<string>
     */
    private static function bundledBenchPathArgs(string $configPath): array
    {
        if (!self::isBundledConfigInConsumingProject($configPath)) {
            return [];
        }

        $paths = self::benchConfig($configPath)['runner.path'] ?? [];
        $paths = is_string($paths) ? [$paths] : $paths;

        if (!is_array($paths)) {
            return [];
        }

        $projectRoot = Paths::projectRootPath();
        $resolvedPaths = [];

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $resolvedPaths[] = self::absoluteProjectPath($projectRoot, $path);
            }
        }

        return $resolvedPaths;
    }

    /**
     * @return list<string>
     */
    private static function codePaths(): array
    {
        return Paths::existingProjectPaths('src', 'app', 'config', 'database', 'tests', 'benchmarks', 'examples');
    }

    private static function envInt(string $name, int $default, int $minimum, int $maximum): string
    {
        $value = getenv($name);

        if (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return (string) $default;
        }

        return (string) max($minimum, min($maximum, (int) $value));
    }

    private static function isBundledConfigInConsumingProject(string $configPath): bool
    {
        $projectRoot = realpath(Paths::projectRootPath());
        $packageRoot = realpath(dirname(__DIR__, 2));
        $configRealPath = realpath($configPath);

        if (!is_string($projectRoot) || !is_string($packageRoot) || !is_string($configRealPath)) {
            return false;
        }

        if ($projectRoot === $packageRoot) {
            return false;
        }

        return str_starts_with($configRealPath, $packageRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<string>
     */
    private static function pestConfigArgs(): array
    {
        return ['--configuration', Paths::firstConfig(['pest.xml', 'phpunit.xml', 'pest.xml.dist', 'phpunit.xml.dist'])];
    }

    private static function pestProcesses(): string
    {
        return self::envInt('IC_PEST_PROCESSES', 10, 1, 64);
    }

    private static function phpstanMemoryLimit(): string
    {
        $value = getenv('IC_PHPSTAN_MEMORY_LIMIT');

        return is_string($value) && $value !== '' ? $value : '1G';
    }

    private static function psalmThreads(): string
    {
        return self::envInt('IC_PSALM_THREADS', 1, 1, 64);
    }
}
