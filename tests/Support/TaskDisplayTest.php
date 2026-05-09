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

it('formats api checks with a friendly title', function (): void {
    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpforge', 'api']))
        ->toBe('Public API');
});

it('formats comment checks with a friendly title', function (): void {
    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpforge', 'comments']))
        ->toBe('Comment Policy');
});

it('formats aggregate PHPProbe checks with a friendly title', function (): void {
    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpforge', 'check']))
        ->toBe('PHPProbe Checks');
});

it('formats phpprobe checker tasks with friendly titles', function (): void {
    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpprobe', 'syntax']))
        ->toBe('Checking Syntax')
        ->and(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpprobe', 'duplicates']))
        ->toBe('Duplicate Code')
        ->and(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpprobe', 'check']))
        ->toBe('PHPProbe Checks')
        ->and(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpprobe', 'comments']))
        ->toBe('Comment Policy')
        ->and(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/phpprobe', 'api']))
        ->toBe('Public API');
});

it('formats deptrac tasks with config-file labels', function (): void {
    $projectConfig = getcwd() . DIRECTORY_SEPARATOR . 'deptrac.yaml';

    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/deptrac', 'analyse', '--config-file=' . $projectConfig]))
        ->toBe('Deptrac (Config: ' . str_replace('\\', '/', $projectConfig) . ')');
});

it('formats composer normalize with a friendly title', function (): void {
    expect(TaskDisplay::heading(['composer', 'normalize']))
        ->toBe('Composer Normalize');
});

it('labels config paths clearly', function (): void {
    $projectConfig = getcwd() . DIRECTORY_SEPARATOR . 'pest.xml';
    $resolvedConfig = realpath($projectConfig);

    expect(TaskDisplay::heading([PHP_BINARY, 'vendor/bin/pest', '--configuration', $projectConfig]))
        ->toBe('Pest (Config: ' . str_replace('\\', '/', is_string($resolvedConfig) ? $resolvedConfig : $projectConfig) . ')');
});

it('labels bundled vendor config paths as config paths', function (): void {
    $stockVendorConfig = '/app/UID/vendor/infocyph/phpforge/pint.json';

    expect(TaskDisplay::heading([PHP_BINARY, '/app/UID/vendor/bin/pint', '--config', $stockVendorConfig]))
        ->toBe('Pint (Config: /app/UID/vendor/infocyph/phpforge/pint.json)');
});
