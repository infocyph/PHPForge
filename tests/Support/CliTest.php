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
        ->and($result->stdout)->toContain('Knowledge:')
        ->and($result->stdout)->toContain('Utilities:')
        ->and($result->stdout)->toContain('reference [options] [paths...]')
        ->and($result->stdout)->toContain('skipper')
        ->and($result->stdout)->toContain('kb:build')
        ->and($result->stdout)->toContain('phpforge active-config phpstan.neon.dist');
});

it('runs the skip directive scanner without the Composer command runtime', function (): void {
    $result = runPhpforgeCli(['skipper']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain('Skip directive scan passed:')
        ->and($result->stderr)->toBe('');
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

it('checks stable runtime constraints without the Composer command runtime', function (): void {
    $result = runPhpforgeCli(['release-constraints']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain('Stable runtime constraint guard passed.')
        ->and($result->stderr)->toBe('');
});

it('consumes active config parameter values regardless of option order', function (array $arguments): void {
    $result = runPhpforgeCli(['active-config', ...$arguments]);
    $configs = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);

    expect($result->exitCode)->toBe(0)
        ->and($result->stderr)->toBe('')
        ->and($configs)->toHaveCount(1)
        ->and($configs[0]['config_file'])->toBe('phpstan.neon.dist')
        ->and($configs[0]['value'])->toBe(['class' => 80, 'function' => 12]);
})->with([
    'parameter first' => [['--parameter', 'cognitive_complexity', '--json', 'phpstan.neon.dist']],
    'parameter last' => [['--json', '--phpstan.neon.dist', '--parameter', 'cognitive_complexity']],
]);
