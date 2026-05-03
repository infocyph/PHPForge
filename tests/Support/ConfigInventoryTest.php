<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ConfigInventory;

function removeConfigInventoryTree(string $path): void
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

it('lists bundled config files without duplicates', function (): void {
    expect(ConfigInventory::files())
        ->toContain('pest.xml')
        ->toContain('phpunit.xml')
        ->toContain('phpprobe.json')
        ->toContain('pint.json')
        ->toContain('phpstan.neon.dist')
        ->toContain('psalm.xml')
        ->toContain('captainhook.json')
        ->toContain('deptrac.yaml');

    expect(ConfigInventory::files())->toBe(array_values(array_unique(ConfigInventory::files())));
});

it('only lists config files that have bundled resources', function (): void {
    foreach (ConfigInventory::files() as $file) {
        expect(ConfigInventory::resolvedPath($file))
            ->not->toBe('')
            ->and(is_file(ConfigInventory::resolvedPath($file)))->toBeTrue();
    }
});

it('keeps the bundled deptrac config project agnostic', function (): void {
    $contents = file_get_contents(ConfigInventory::resolvedPath('deptrac.yaml'));

    expect($contents)->toBeString()
        ->and($contents)->not->toContain('Infocyph\\\\PHPForge')
        ->and($contents)->toContain('type: directory');
});

it('reports project config sources before bundled sources', function (): void {
    expect(ConfigInventory::source('pint.json'))->toBe('phpforge');
    expect(ConfigInventory::source('missing-tool.xml'))->toBe('missing');
});

it('treats phpprobe.json as the project source when present', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-config-inventory-'.uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'phpprobe.json', '{}');

    chdir($projectRoot);

    try {
        expect(ConfigInventory::source('phpprobe.json'))->toBe('project');
        expect(ConfigInventory::resolvedPath('phpprobe.json'))->toBe($projectRoot.DIRECTORY_SEPARATOR.'phpprobe.json');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeConfigInventoryTree($projectRoot);
    }
});
