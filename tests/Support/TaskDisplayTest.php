<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\TaskDisplay;

it('formats syntax checks with a friendly title', function (): void {
    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpforge', 'syntax']))
        ->toBe('Checking Syntax');
});

it('formats duplicate checks with a friendly title', function (): void {
    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpforge', 'duplicates']))
        ->toBe('Duplicate Code');
});

it('formats composer normalize with a friendly title', function (): void {
    expect(TaskDisplay::heading(['composer', 'normalize']))
        ->toBe('Composer Normalize');
});

it('labels config paths clearly', function (): void {
    $projectConfig = getcwd().DIRECTORY_SEPARATOR.'pest.xml';
    $resolvedConfig = realpath($projectConfig);

    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/pest', '--configuration', $projectConfig]))
        ->toBe('Pest (Config: '.str_replace('\\', '/', is_string($resolvedConfig) ? $resolvedConfig : $projectConfig).')');
});

it('labels bundled vendor config paths as config paths', function (): void {
    $stockVendorConfig = '/app/UID/vendor/infocyph/phpforge/pint.json';

    expect(TaskDisplay::heading([PHP_BINARY, '/app/UID/vendor/bin/pint', '--config', $stockVendorConfig]))
        ->toBe('Pint (Config: /app/UID/vendor/infocyph/phpforge/pint.json)');
});
