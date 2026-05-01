<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\TaskCatalog;
use Infocyph\PHPForge\Support\TaskDisplay;

it('runs composer normalize as part of process all', function (): void {
    expect(TaskCatalog::processAll()[0])->toBe(['composer', 'normalize']);
});

it('runs duplicate detection against code paths', function (): void {
    expect(TaskCatalog::duplicates()[0])->toContain('duplicates')
        ->and(TaskCatalog::duplicates()[0])->toContain('--config')
        ->and(TaskCatalog::duplicates()[0])->toContain(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpforge.json')
        ->and(TaskCatalog::duplicates()[0])->not()->toContain('tests');
});

it('runs syntax checks with the native PHPForge config', function (): void {
    expect(TaskCatalog::syntax()[0])->toContain('syntax')
        ->and(TaskCatalog::syntax()[0])->toContain('--config')
        ->and(TaskCatalog::syntax()[0])->toContain(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpforge.json');
});

it('keeps syntax as preflight for parallel tests', function (): void {
    expect(TaskCatalog::testParallel())->not()->toContain(TaskCatalog::syntax()[0])
        ->and(TaskDisplay::heading(TaskCatalog::testParallel()[0]))->toStartWith('Pest');
});

it('uses the bundled phpbench config directly for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-task-catalog-'.uniqid('', true);
    $benchmarksPath = $projectRoot.DIRECTORY_SEPARATOR.'benchmarks';
    $bootstrapPath = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

    mkdir($projectRoot, 0755, true);
    mkdir($benchmarksPath, 0755, true);

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

        $configPath = substr((string) $configArgument, strlen('--config='));
        expect($configPath)->toBe(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpbench.json');
        expect($command)->toContain('--bootstrap');
        expect($command)->toContain($bootstrapPath);
        expect($command)->toContain($benchmarksPath);
        expect(basename($configPath))->not()->toStartWith('phpforge-phpbench-');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($projectRoot);
    }
});

it('uses the bundled pest config directly for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-task-catalog-'.uniqid('', true);
    $testsPath = $projectRoot.DIRECTORY_SEPARATOR.'tests';
    $vendorPath = $projectRoot.DIRECTORY_SEPARATOR.'vendor';
    $autoloadPath = $vendorPath.DIRECTORY_SEPARATOR.'autoload.php';

    mkdir($projectRoot, 0755, true);
    mkdir($testsPath, 0755, true);
    mkdir($vendorPath, 0755, true);
    touch($autoloadPath);

    chdir($projectRoot);

    try {
        $command = TaskCatalog::testCode()[0];

        expect($command)->toContain('--configuration');
        expect($command)->toContain(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'pest.xml');
        expect($command)->toContain('--bootstrap');
        expect($command)->toContain($autoloadPath);
        expect($command)->toContain('tests');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        unlink($autoloadPath);
        rmdir($vendorPath);
        rmdir($testsPath);
        rmdir($projectRoot);
    }
});

it('uses the project captainhook config when present', function (): void {
    $command = TaskCatalog::hooks()[0];

    expect($command)->toContain('--configuration='.getcwd().DIRECTORY_SEPARATOR.'captainhook.json');
});

it('lets project phpstan config define analysed paths', function (): void {
    $command = TaskCatalog::staticAnalysis()[0];
    $packageRoot = realpath(dirname(__DIR__, 2));

    expect($command)->toContain('--configuration='.$packageRoot.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpstan.neon.dist');
    expect($command)->not()->toContain('src');
    expect($command)->not()->toContain('app');
});

it('uses the bundled phpstan config directly for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-task-catalog-'.uniqid('', true);
    $srcPath = $projectRoot.DIRECTORY_SEPARATOR.'src';

    mkdir($projectRoot, 0755, true);
    mkdir($srcPath, 0755, true);

    chdir($projectRoot);

    try {
        $command = TaskCatalog::staticAnalysis()[0];

        expect($command)->toContain('--configuration='.dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpstan.neon.dist');
        expect($command)->toContain('.');
        expect($command)->not()->toContain('app');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($projectRoot);
    }
});
