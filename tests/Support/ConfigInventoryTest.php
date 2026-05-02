<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ConfigInventory;

it('lists bundled config files without duplicates', function (): void {
    expect(ConfigInventory::files())
        ->toContain('pest.xml')
        ->toContain('phpunit.xml')
        ->toContain('phpforge.json')
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
