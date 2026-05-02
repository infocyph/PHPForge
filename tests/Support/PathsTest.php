<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\Paths;

function removePathsTestTree(string $path): void
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

it('falls back to bundled defaults when project config is missing', function (): void {
    expect(Paths::config('pint.json'))
        ->toBe(Paths::packageFile('resources/pint.json'));
});

it('falls back to bundled PHPProbe checker config', function (): void {
    expect(Paths::config('phpforge.json'))
        ->toBe(Paths::packageFile('resources/phpforge.json'));
});

it('uses bundled config when project config from list is missing', function (): void {
    expect(Paths::firstConfig(['pest.xml', 'phpunit.xml']))
        ->toBe(Paths::packageFile('resources/pest.xml'));
});

it('uses project config before vendor PHPForge resources', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';

    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'pint.json', '{}');
    file_put_contents($vendorResources.DIRECTORY_SEPARATOR.'pint.json', '{}');

    chdir($projectRoot);

    try {
        expect(Paths::config('pint.json'))->toBe($projectRoot.DIRECTORY_SEPARATOR.'pint.json');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('falls back to vendor PHPForge resources for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';

    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($vendorResources.DIRECTORY_SEPARATOR.'pint.json', '{}');

    chdir($projectRoot);

    try {
        expect(Paths::config('pint.json'))->toBe($vendorResources.DIRECTORY_SEPARATOR.'pint.json');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('hard fails for consuming projects when project and vendor configs are missing', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');

    chdir($projectRoot);

    try {
        expect(fn(): string => Paths::config('pint.json'))->toThrow(RuntimeException::class);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('ignores source-tree resources for non-PHPForge consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $resourcesPath = $projectRoot.DIRECTORY_SEPARATOR.'resources';

    mkdir($resourcesPath, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($resourcesPath.DIRECTORY_SEPARATOR.'pint.json', '{}');

    chdir($projectRoot);

    try {
        expect(fn(): string => Paths::config('pint.json'))->toThrow(RuntimeException::class);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('uses source-tree resources only for the PHPForge project itself', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $resourcesPath = $projectRoot.DIRECTORY_SEPARATOR.'resources';
    $resourceFile = $resourcesPath.DIRECTORY_SEPARATOR.'pint.json';

    mkdir($resourcesPath, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"infocyph/phpforge"}');
    file_put_contents($resourceFile, '{}');

    chdir($projectRoot);

    try {
        expect(Paths::config('pint.json'))->toBe($resourceFile);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('returns null when project config from a list does not exist', function (): void {
    expect(Paths::firstProjectConfig(['pest.xml', 'phpunit.xml']))
        ->toBeNull();
});

it('returns null when no project-only config exists', function (): void {
    expect(Paths::firstProjectConfig(['missing-a.xml', 'missing-b.xml']))
        ->toBeNull();
});

it('resolves package files from the PHPForge package root', function (): void {
    expect(Paths::packageFile('bin/phpforge'))
        ->toEndWith(DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpforge');
});
