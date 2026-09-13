<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\TaskEnvironment;

function withTaskEnvironmentVariable(string $name, ?string $value, callable $callback): void
{
    $previous = getenv($name);
    putenv($value === null ? $name : $name.'='.$value);

    try {
        $callback();
    } finally {
        putenv($previous === false ? $name : $name.'='.$previous);
    }
}

it('removes GitHub Actions detection only from PHPStan tasks', function (): void {
    withTaskEnvironmentVariable('GITHUB_ACTIONS', 'true', function (): void {
        $phpstan = TaskEnvironment::for([PHP_BINARY, 'vendor/bin/phpstan']);
        $psalm = TaskEnvironment::for([PHP_BINARY, 'vendor/bin/psalm.phar']);

        expect($phpstan)->toBeArray()
            ->and($phpstan['GITHUB_ACTIONS'] ?? null)->toBeFalse()
            ->and($psalm)->not->toHaveKey('GITHUB_ACTIONS');
    });
});

it('keeps the existing Xdebug subprocess policy', function (): void {
    withTaskEnvironmentVariable('XDEBUG_MODE', null, function (): void {
        expect(TaskEnvironment::for([PHP_BINARY, 'tool']))->toBe(['XDEBUG_MODE' => 'off']);
    });

    withTaskEnvironmentVariable('XDEBUG_MODE', 'coverage', function (): void {
        expect(TaskEnvironment::for([PHP_BINARY, 'tool']))->toBeNull();
    });
});
