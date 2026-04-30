<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ConfigInventory;

it('lists bundled config files without duplicates', function (): void {
    expect(ConfigInventory::files())
        ->toContain('pest.xml')
        ->toContain('phpunit.xml')
        ->toContain('pest.xml.dist')
        ->toContain('phpunit.xml.dist')
        ->toContain('phpforge.json')
        ->toContain('pint.json')
        ->toContain('phpstan.neon.dist')
        ->toContain('psalm.xml')
        ->toContain('captainhook.json');

    expect(ConfigInventory::files())->toBe(array_values(array_unique(ConfigInventory::files())));
});

it('reports project config sources before bundled sources', function (): void {
    expect(ConfigInventory::source('pint.json'))->toBe('phpforge');
    expect(ConfigInventory::source('missing-tool.xml'))->toBe('missing');
});
