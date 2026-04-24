<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Infocyph\PHPForge\Support\Paths;

final class TaskCatalog
{
    /**
     * @return list<list<string>>
     */
    public static function benchChart(): array
    {
        return [[Paths::php(), Paths::bin('phpbench'), 'run', '--config=' . self::benchConfigPath(), '--report=chart']];
    }

    /**
     * @return list<list<string>>
     */
    public static function benchQuick(): array
    {
        return [[Paths::php(), Paths::bin('phpbench'), 'run', '--config=' . self::benchConfigPath(), '--report=aggregate', '--revs=10', '--iterations=3', '--warmup=1']];
    }

    /**
     * @return list<list<string>>
     */
    public static function benchRun(): array
    {
        return [[Paths::php(), Paths::bin('phpbench'), 'run', '--config=' . self::benchConfigPath(), '--report=aggregate']];
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
        return [[Paths::php(), Paths::bin('captainhook'), 'install', '--only-enabled', '-nf']];
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
        return [[Paths::php(), Paths::bin('psalm'), '--config=' . Paths::config('psalm.xml'), '--security-analysis', '--threads=' . self::psalmThreads(), '--no-cache']];
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
        return [[Paths::php(), Paths::bin('phpstan'), 'analyse', '--configuration=' . Paths::config('phpstan.neon.dist'), '--memory-limit=' . self::phpstanMemoryLimit(), '--no-progress', '--debug', ...self::staticAnalysisPaths()]];
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
            [Paths::php(), Paths::bin('psalm'), '--config=' . Paths::config('psalm.xml'), '--show-info=false', '--security-analysis', '--threads=' . self::psalmThreads(), '--no-progress', '--no-cache'],
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

    private static function benchConfigPath(): string
    {
        return self::resolveBenchConfigPath(Paths::config('phpbench.json'));
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
        $config = Paths::firstConfig(['pest.xml', 'phpunit.xml', 'pest.xml.dist', 'phpunit.xml.dist']);

        return ['--configuration', self::resolvePestConfigPath($config)];
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

    private static function projectAdjustedBundledBenchConfig(string $configPath): string
    {
        $contents = file_get_contents($configPath);

        if (!is_string($contents) || $contents === '') {
            return $configPath;
        }

        $config = json_decode($contents, true);

        if (!is_array($config)) {
            return $configPath;
        }

        $projectRoot = Paths::projectRootPath();

        foreach (['runner.bootstrap', 'runner.path'] as $key) {
            $value = $config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $config[$key] = self::absoluteProjectPath($projectRoot, $value);
            }
        }

        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded) || $encoded === '') {
            return $configPath;
        }

        $encoded .= "\n";
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-phpbench-' . sha1($configPath . $projectRoot . $encoded) . '.json';

        if (!is_file($tempPath)) {
            $written = file_put_contents($tempPath, $encoded);

            if ($written === false) {
                return $configPath;
            }
        }

        return $tempPath;
    }

    private static function projectAdjustedBundledPestConfig(string $configPath): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return $configPath;
        }

        $contents = file_get_contents($configPath);

        if (!is_string($contents) || $contents === '') {
            return $configPath;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;

        set_error_handler(static fn(): bool => true);

        try {
            $loaded = $dom->loadXML($contents);
        } finally {
            restore_error_handler();
        }

        if (!$loaded) {
            return $configPath;
        }

        $projectRoot = Paths::projectRootPath();
        $phpunit = $dom->documentElement;

        if ($phpunit instanceof \DOMElement) {
            $bootstrap = trim((string) $phpunit->getAttribute('bootstrap'));

            if ($bootstrap !== '') {
                $phpunit->setAttribute('bootstrap', self::absoluteProjectPath($projectRoot, $bootstrap));
            }
        }

        $directories = $dom->getElementsByTagName('directory');

        foreach ($directories as $directory) {
            if (!$directory instanceof \DOMElement) {
                continue;
            }

            $path = trim($directory->textContent);

            if ($path === '') {
                continue;
            }

            $directory->nodeValue = self::absoluteProjectPath($projectRoot, $path);
        }

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-pest-' . sha1($configPath . $projectRoot . $contents) . '.xml';

        if (!is_file($tempPath)) {
            $written = file_put_contents($tempPath, $dom->saveXML());

            if ($written === false) {
                return $configPath;
            }
        }

        return $tempPath;
    }

    private static function psalmThreads(): string
    {
        return self::envInt('IC_PSALM_THREADS', 1, 1, 64);
    }

    private static function resolveBenchConfigPath(string $configPath): string
    {
        if (!self::isBundledConfigInConsumingProject($configPath)) {
            return $configPath;
        }

        return self::projectAdjustedBundledBenchConfig($configPath);
    }

    private static function resolvePestConfigPath(string $configPath): string
    {
        if (!self::isBundledConfigInConsumingProject($configPath)) {
            return $configPath;
        }

        return self::projectAdjustedBundledPestConfig($configPath);
    }

    /**
     * @return list<string>
     */
    private static function staticAnalysisPaths(): array
    {
        $paths = Paths::existingProjectPaths('src', 'app');

        if ($paths !== []) {
            return $paths;
        }

        $fallbackPaths = Paths::existingProjectPaths('config', 'database', 'tests', 'benchmarks', 'examples');

        return $fallbackPaths !== [] ? $fallbackPaths : ['.'];
    }
}
