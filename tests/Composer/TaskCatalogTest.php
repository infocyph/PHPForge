<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\TaskCatalog;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\TaskDisplay;

function mirrorTaskCatalogConfig(string $projectRoot, string $file): string
{
    $vendorResources = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'infocyph' . DIRECTORY_SEPARATOR . 'phpforge' . DIRECTORY_SEPARATOR . 'resources';

    if (!is_dir($vendorResources)) {
        mkdir($vendorResources, 0755, true);
    }

    $target = $vendorResources . DIRECTORY_SEPARATOR . $file;
    copy(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . $file, $target);

    return $target;
}

function removeTaskCatalogTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}

function withTaskCatalogEnv(string $name, ?string $value, callable $callback): void
{
    $previous = getenv($name);

    if ($value === null) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }

    try {
        $callback();
    } finally {
        if ($previous === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $previous);
    }
}

it('runs composer normalize as part of process all', function (): void {
    expect(TaskCatalog::processAll()[0])->toBe(['composer', 'normalize']);
});

it('keeps fail-on-skipped opt-in outside the workflow environment', function (): void {
    withTaskCatalogEnv('IC_PEST_FAIL_ON_SKIPPED', null, function (): void {
        expect(TaskCatalog::testCode()[0])->not->toContain('--fail-on-skipped');
    });

    withTaskCatalogEnv('IC_PEST_FAIL_ON_SKIPPED', 'true', function (): void {
        expect(TaskCatalog::testCode()[0])->toContain('--fail-on-skipped');
    });
});

it('runs stable runtime constraints before audit and quality in the release guard', function (): void {
    $tasks = TaskCatalog::releaseGuard();

    expect($tasks[0])->toBe(['composer', 'validate', '--strict'])
        ->and($tasks[1])->toBe(TaskCatalog::releaseConstraints()[0])
        ->and($tasks[2])->toBe(TaskCatalog::releaseAudit()[0]);
});

it('runs duplicate detection against code paths', function (): void {
    $command = TaskCatalog::duplicates()[0];
    $cacheArg = null;

    foreach ($command as $argument) {
        if (str_starts_with($argument, '--cache-file=')) {
            $cacheArg = $argument;

            break;
        }
    }

    expect(basename(str_replace('\\', '/', $command[1])))->toBe('phpprobe')
        ->and($command)->toContain('duplicates')
        ->and(TaskCatalog::duplicates()[0])->toContain('--config')
        ->and(TaskCatalog::duplicates()[0])->toContain(Paths::packageFile('resources/phpprobe.json'))
        ->and(TaskCatalog::duplicates()[0])->not()->toContain('tests')
        ->and(is_string($cacheArg))->toBeTrue()
        ->and($cacheArg)->toStartWith('--cache-file=')
        ->and($cacheArg)->toContain('phpprobe-duplicates-cache-');
});

it('runs comment policy checks with the PHPProbe checker config', function (): void {
    $command = TaskCatalog::comments()[0];

    expect(basename(str_replace('\\', '/', $command[1])))->toBe('phpprobe')
        ->and($command)->toContain('comments')
        ->and($command)->toContain('--config')
        ->and($command)->toContain(Paths::packageFile('resources/phpprobe.json'));
});

it('runs CI comment policy checks with error-focused output', function (): void {
    $command = TaskCatalog::commentsCi()[0];

    expect($command)->toContain('comments')
        ->and($command)->toContain('--ci')
        ->and($command)->toContain('--config')
        ->and($command)->toContain(Paths::packageFile('resources/phpprobe.json'));
});

it('keeps aggregate PHPProbe CI checks aligned with the configured thresholds', function (): void {
    $command = TaskCatalog::probeCheckCi()[0];

    expect($command)->toContain('check')
        ->and($command)->toContain('--config')
        ->and($command)->toContain(Paths::packageFile('resources/phpprobe.json'))
        ->and($command)->toContain('--format=json')
        ->and($command)->not->toContain('--preset=ci')
        ->and(TaskCatalog::probeCheck()[0])->not->toContain('--format=json');
});

it('uses detailed failure reports for tools whose compact modes hide findings', function (): void {
    $suite = TaskCatalog::testAllCi();
    $phpcs = array_values(array_filter(
        $suite,
        static fn(array $command): bool => basename(str_replace('\\', '/', $command[1] ?? '')) === 'phpcs',
    ));

    expect($phpcs)->toHaveCount(1)
        ->and($phpcs[0])->toContain('--report=full')
        ->and($phpcs[0])->not->toContain('--report=summary');
});

it('runs Psalm through the dependency-isolated PHAR binary', function (): void {
    $security = TaskCatalog::security()[0];
    $suitePsalm = array_values(array_filter(
        TaskCatalog::testAll(),
        static fn(array $command): bool => basename(str_replace('\\', '/', $command[1] ?? '')) === 'psalm.phar',
    ));

    expect(basename(str_replace('\\', '/', $security[1])))->toBe('psalm.phar')
        ->and($suitePsalm)->toHaveCount(1);
});

it('runs aggregated PHPProbe checks with the PHPProbe checker config', function (): void {
    $command = TaskCatalog::probeCheck()[0];

    expect(basename(str_replace('\\', '/', $command[1])))->toBe('phpprobe')
        ->and($command)->toContain('check')
        ->and($command)->toContain('--config')
        ->and($command)->toContain(Paths::packageFile('resources/phpprobe.json'));
});

it('runs syntax checks with the PHPProbe checker config', function (): void {
    $command = TaskCatalog::syntax()[0];

    expect(basename(str_replace('\\', '/', $command[1])))->toBe('phpprobe')
        ->and($command)->toContain('syntax')
        ->and(TaskCatalog::syntax()[0])->toContain('--config')
        ->and(TaskCatalog::syntax()[0])->toContain(Paths::packageFile('resources/phpprobe.json'));
});

it('runs architecture checks with the bundled deptrac config', function (): void {
    $command = TaskCatalog::architecture()[0];

    expect(basename(str_replace('\\', '/', $command[1])))->toBe('deptrac')
        ->and($command)->toContain('--no-cache')
        ->and($command)->toContain('analyse')
        ->and($command)->toContain('--config-file=' . Paths::packageFile('resources/deptrac.yaml'))
        ->and($command)->toContain('--no-progress');
});

it('uses the complete aggregate suite for the parallel tests alias', function (): void {
    expect(TaskCatalog::testParallel())->toBe(TaskCatalog::testAll());
});

it('uses the complete aggregate CI suite for the parallel CI alias', function (): void {
    expect(TaskCatalog::testParallelCi())->toBe(TaskCatalog::testAllCi());
});

it('runs one non-nested pest process in aggregate suites', function (): void {
    $pestCommands = array_values(array_filter(
        TaskCatalog::testAll(),
        static fn(array $command): bool => str_contains(str_replace('\\', '/', $command[1] ?? ''), '/pest'),
    ));

    expect($pestCommands)->toHaveCount(1)
        ->and($pestCommands[0])->not->toContain('--parallel')
        ->and(implode(' ', $pestCommands[0]))->not->toContain('--processes=');
});

it('includes comment policy checks in full and detailed quality suites', function (): void {
    $commentsTask = TaskCatalog::comments()[0];

    expect(TaskCatalog::testAll())->toContain(TaskCatalog::probeCheck()[0])
        ->and(TaskCatalog::testDetails())->toContain($commentsTask);
});

it('pins aggregate psalm to one thread', function (): void {
    $psalm = array_values(array_filter(
        TaskCatalog::testAll(),
        static fn(array $command): bool => str_contains(str_replace('\\', '/', $command[1] ?? ''), '/psalm'),
    ));

    expect($psalm)->toHaveCount(1)
        ->and($psalm[0])->toContain('--threads=1');
});

it('uses the bundled phpbench config directly for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);
    $benchmarksPath = $projectRoot . DIRECTORY_SEPARATOR . 'benchmarks';
    $bootstrapPath = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    mkdir($projectRoot, 0755, true);
    $configPath = mirrorTaskCatalogConfig($projectRoot, 'phpbench.json');
    mkdir($benchmarksPath, 0755, true);
    touch($bootstrapPath);

    chdir($projectRoot);

    try {
        $command = TaskCatalog::benchRun()[0];
        $configArgument = null;

        foreach ($command as $argument) {
            if (str_starts_with($argument, '--config=')) {
                $configArgument = $argument;

                break;
            }
        }

        expect(is_string($configArgument))->toBeTrue();

        expect(substr((string) $configArgument, strlen('--config=')))->toBe($configPath);
        expect($command)->toContain('--bootstrap');
        expect($command)->toContain($bootstrapPath);
        expect($command)->toContain($benchmarksPath);
        expect(basename($configPath))->toBe('phpbench.json');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeTaskCatalogTree($projectRoot);
    }
});

it('skips benchmark tasks when the project has no benchmarks directory', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');

    chdir($projectRoot);

    try {
        expect(TaskCatalog::benchRun())->toBe([])
            ->and(TaskCatalog::benchQuick())->toBe([])
            ->and(TaskCatalog::benchChart())->toBe([]);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeTaskCatalogTree($projectRoot);
    }
});

it('uses the bundled pest config directly for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);
    $testsPath = $projectRoot . DIRECTORY_SEPARATOR . 'tests';
    $vendorPath = $projectRoot . DIRECTORY_SEPARATOR . 'vendor';
    $autoloadPath = $vendorPath . DIRECTORY_SEPARATOR . 'autoload.php';

    mkdir($projectRoot, 0755, true);
    $configPath = mirrorTaskCatalogConfig($projectRoot, 'pest.xml');
    mkdir($testsPath, 0755, true);
    touch($autoloadPath);

    chdir($projectRoot);

    try {
        $command = TaskCatalog::testCode()[0];

        expect($command)->toContain('--configuration');
        expect($command)->toContain($configPath);
        expect($command)->toContain('--bootstrap');
        expect($command)->toContain($autoloadPath);
        expect($command)->toContain('tests');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeTaskCatalogTree($projectRoot);
    }
});

it('skips Pest when the project has no tests directory', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');

    foreach ([
        'deptrac.yaml',
        'phpcs.xml.dist',
        'phpprobe.json',
        'phpstan.neon.dist',
        'pint.json',
        'psalm.xml',
        'rector.php',
    ] as $config) {
        mirrorTaskCatalogConfig($projectRoot, $config);
    }

    chdir($projectRoot);

    try {
        $pestTasks = array_filter(
            TaskCatalog::testAll(),
            static fn(array $task): bool => basename(str_replace('\\', '/', $task[1] ?? $task[0])) === 'pest',
        );

        expect(TaskCatalog::testCode())->toBe([])
            ->and($pestTasks)->toBe([]);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeTaskCatalogTree($projectRoot);
    }
});

it('uses the project captainhook config when present', function (): void {
    $command = TaskCatalog::hooks()[0];

    expect($command)->toContain('--configuration=' . getcwd() . DIRECTORY_SEPARATOR . 'captainhook.json');
});

it('falls back to the vendor package captainhook config', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);
    $vendorPackage = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'infocyph' . DIRECTORY_SEPARATOR . 'phpforge';
    $configPath = $vendorPackage . DIRECTORY_SEPARATOR . 'captainhook.json';

    mkdir($vendorPackage, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    file_put_contents($configPath, '{}');
    chdir($projectRoot);

    try {
        expect(TaskCatalog::hooks()[0])->toContain('--configuration=' . $configPath);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeTaskCatalogTree($projectRoot);
    }
});

it('lets project phpstan config define analysed paths', function (): void {
    $command = TaskCatalog::staticAnalysis()[0];
    expect($command)->toContain('--configuration=' . Paths::packageFile('resources/phpstan.neon.dist'));
    expect($command)->not()->toContain('src');
    expect($command)->not()->toContain('app');
});

it('uses the bundled phpstan config directly for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);
    $srcPath = $projectRoot . DIRECTORY_SEPARATOR . 'src';

    mkdir($projectRoot, 0755, true);
    $configPath = mirrorTaskCatalogConfig($projectRoot, 'phpstan.neon.dist');
    mkdir($srcPath, 0755, true);

    chdir($projectRoot);

    try {
        $command = TaskCatalog::staticAnalysis()[0];

        expect($command)->toContain('--configuration=' . $configPath);
        expect($command)->toContain('.');
        expect($command)->not()->toContain('app');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeTaskCatalogTree($projectRoot);
    }
});

it('prefers project phpstan.neon over phpstan.neon.dist', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);
    $srcPath = $projectRoot . DIRECTORY_SEPARATOR . 'src';
    $projectConfigPath = $projectRoot . DIRECTORY_SEPARATOR . 'phpstan.neon';

    mkdir($projectRoot, 0755, true);
    mkdir($srcPath, 0755, true);
    file_put_contents($projectConfigPath, "parameters:\n    level: max\n");
    mirrorTaskCatalogConfig($projectRoot, 'phpstan.neon.dist');

    chdir($projectRoot);

    try {
        $command = TaskCatalog::staticAnalysis()[0];

        expect($command)->toContain('--configuration=' . $projectConfigPath)
            ->and($command)->not()->toContain('--configuration=' . $projectRoot . DIRECTORY_SEPARATOR . 'phpstan.neon.dist');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeTaskCatalogTree($projectRoot);
    }
});
