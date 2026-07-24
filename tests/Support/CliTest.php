<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ProcRunner;
use Infocyph\PHPForge\Support\ProcessResult;

/**
 * @param list<string> $arguments
 */
function runPhpforgeCli(array $arguments): ProcessResult
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpforge';
    $result = (new ProcRunner())->run([PHP_BINARY, $binary, ...$arguments]);

    expect($result)->toBeInstanceOf(ProcessResult::class);

    return $result;
}

it('renders grouped and scannable command help', function (): void {
    $result = runPhpforgeCli(['help']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stderr)->toBe('')
        ->and($result->stdout)->toContain('PHPForge')
        ->and($result->stdout)->toContain('Quality:')
        ->and($result->stdout)->toContain('Configuration:')
        ->and($result->stdout)->toContain('Utilities:')
        ->and($result->stdout)->toContain('phpforge active-config phpstan.neon.dist');
});

it('supports conventional help flags', function (string $flag): void {
    $result = runPhpforgeCli([$flag]);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain('Usage:')
        ->and($result->stderr)->toBe('');
})->with(['-h', '--help']);

it('reports invalid commands with a useful suggestion', function (): void {
    $result = runPhpforgeCli(['doctro']);

    expect($result->exitCode)->toBe(2)
        ->and($result->stdout)->toBe('')
        ->and($result->stderr)->toContain('unknown command "doctro"')
        ->and($result->stderr)->toContain('Did you mean "doctor"?')
        ->and($result->stderr)->toContain('phpforge help');
});
