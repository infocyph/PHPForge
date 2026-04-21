<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\Paths;

it('prefers project config files over bundled defaults', function (): void {
    expect(Paths::config('pint.json'))
        ->toBe(getcwd() . DIRECTORY_SEPARATOR . 'pint.json');
});

it('uses the first available project config from a list', function (): void {
    expect(Paths::firstConfig(['pest.xml', 'phpunit.xml']))
        ->toBe(getcwd() . DIRECTORY_SEPARATOR . 'pest.xml');
});

it('resolves package files from the PHPForge package root', function (): void {
    expect(Paths::packageFile('bin/phpforge'))
        ->toBe(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpforge');
});
