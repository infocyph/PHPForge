<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\TaskCatalog;

it('rewrites bundled phpbench config paths for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-task-catalog-' . uniqid('', true);
    $benchmarksPath = $projectRoot . DIRECTORY_SEPARATOR . 'benchmarks';

    mkdir($projectRoot, 0777, true);
    mkdir($benchmarksPath, 0777, true);

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
        expect(is_file($configPath))->toBeTrue();

        $contents = file_get_contents($configPath);
        expect(is_string($contents))->toBeTrue();

        $config = json_decode((string) $contents, true);
        expect(is_array($config))->toBeTrue();
        expect($config['runner.bootstrap'] ?? null)->toBe($projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
        expect($config['runner.path'] ?? null)->toBe($benchmarksPath);
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
