<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\TaskDisplay;

it('formats syntax checks with a friendly title', function (): void {
    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpforge', 'syntax']))
        ->toBe('Checking Syntax');
});

it('formats composer normalize with a friendly title', function (): void {
    expect(TaskDisplay::heading(['composer', 'normalize']))
        ->toBe('Composer Normalize');
});

it('labels project configs as project source', function (): void {
    $projectConfig = getcwd() . DIRECTORY_SEPARATOR . 'pest.xml';

    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/pest', '--configuration', $projectConfig]))
        ->toBe('Pest (Project)');
});

it('labels normalized temporary configs as stock source', function (): void {
    $stockTempConfig = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-pest-example.xml';

    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/pest', '--configuration', $stockTempConfig]))
        ->toBe('Pest (Stock)');
});

it('labels bundled vendor config paths as stock source', function (): void {
    $stockVendorConfig = '/app/UID/vendor/infocyph/phpforge/pint.json';

    expect(TaskDisplay::heading([PHP_BINARY, '/app/UID/vendor/bin/pint', '--config', $stockVendorConfig]))
        ->toBe('Pint (Stock)');
});
