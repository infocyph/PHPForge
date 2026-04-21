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
        return [[Paths::php(), Paths::bin('phpbench'), 'run', '--config=' . Paths::config('phpbench.json'), '--report=chart']];
    }

    /**
     * @return list<list<string>>
     */
    public static function benchQuick(): array
    {
        return [[Paths::php(), Paths::bin('phpbench'), 'run', '--config=' . Paths::config('phpbench.json'), '--report=aggregate', '--revs=10', '--iterations=3', '--warmup=1']];
    }

    /**
     * @return list<list<string>>
     */
    public static function benchRun(): array
    {
        return [[Paths::php(), Paths::bin('phpbench'), 'run', '--config=' . Paths::config('phpbench.json'), '--report=aggregate']];
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
    public static function processAll(): array
    {
        return [
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
        return [[Paths::php(), Paths::packageFile('bin/phpforge'), 'audit']];
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
        return [[Paths::php(), Paths::bin('phpstan'), 'analyse', '--configuration=' . Paths::config('phpstan.neon.dist'), '--memory-limit=' . self::phpstanMemoryLimit(), '--no-progress', '--debug']];
    }

    /**
     * @return list<list<string>>
     */
    public static function syntax(): array
    {
        return [[Paths::php(), Paths::packageFile('bin/phpforge'), 'syntax']];
    }

    /**
     * @return list<list<string>>
     */
    public static function testAll(): array
    {
        return [
            ...self::syntax(),
            [Paths::php(), Paths::bin('pest'), '--configuration', Paths::firstConfig(['pest.xml', 'phpunit.xml']), '--parallel', '--processes=' . self::pestProcesses()],
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
        return [[Paths::php(), Paths::bin('pest'), '--configuration', Paths::firstConfig(['pest.xml', 'phpunit.xml'])]];
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
