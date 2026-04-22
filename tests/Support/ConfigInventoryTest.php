<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ConfigInventory;

it('lists bundled config files without duplicates', function (): void {
    expect(ConfigInventory::files())
        ->toContain('pint.json')
        ->toContain('phpstan.neon.dist')
        ->toContain('psalm.xml')
        ->toContain('captainhook.json');

    expect(ConfigInventory::files())->toBe(array_values(array_unique(ConfigInventory::files())));
});

it('reports project config sources before bundled sources', function (): void {
    expect(ConfigInventory::source('pint.json'))->toBe('project');
    expect(ConfigInventory::source('missing-tool.xml'))->toBe('missing');
});
