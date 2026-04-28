<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\Paths;

it('falls back to bundled defaults when project config is missing', function (): void {
    expect(Paths::config('pint.json'))
        ->toBe(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'pint.json');
});

it('uses bundled config when project config from list is missing', function (): void {
    expect(Paths::firstConfig(['pest.xml', 'phpunit.xml']))
        ->toBe(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'pest.xml');
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
        ->toBe(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpforge');
});
