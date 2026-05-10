<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Output\BufferedOutput;

function runnerTempPath(string $suffix): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-runner-'.uniqid('', true).'-'.$suffix;
}

function withRunnerEnv(string $name, ?string $value, callable $callback): void
{
    $previous = getenv($name);

    if ($value === null) {
        putenv($name);
    } else {
        putenv($name.'='.$value);
    }

    try {
        $callback();
    } finally {
        if ($previous === false) {
            putenv($name);

            return;
        }

        putenv($name.'='.$previous);
    }
}

it('returns failure exit code after running all tasks when fail-fast is disabled', function (): void {
    $marker = runnerTempPath('continued.txt');
    $output = new BufferedOutput();
    $runner = new Runner($output, false);

    $exitCode = $runner->run([
        [PHP_BINARY, '-r', 'fwrite(STDERR, "first failed\n"); exit(3);'],
        [PHP_BINARY, '-r', 'file_put_contents('.var_export($marker, true).', "ran");'],
    ]);

    expect($exitCode)->toBe(3)
        ->and(is_file($marker))->toBeTrue();

    if (is_file($marker)) {
        unlink($marker);
    }
});

it('stops at first failure when fail-fast is enabled', function (): void {
    $marker = runnerTempPath('stopped.txt');
    $output = new BufferedOutput();
    $runner = new Runner($output, true);

    $exitCode = $runner->run([
        [PHP_BINARY, '-r', 'fwrite(STDERR, "first failed\n"); exit(4);'],
        [PHP_BINARY, '-r', 'file_put_contents('.var_export($marker, true).', "ran");'],
    ]);

    expect($exitCode)->toBe(4)
        ->and(is_file($marker))->toBeFalse();

    if (is_file($marker)) {
        unlink($marker);
    }
});

it('defaults subprocesses to XDEBUG_MODE=off when unset', function (): void {
    withRunnerEnv('XDEBUG_MODE', null, function (): void {
        $output = new BufferedOutput();
        $runner = new Runner($output, true);

        $exitCode = $runner->run([
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "XDEBUG_MODE=".(getenv("XDEBUG_MODE") ?: "")."\n");'],
        ]);

        expect($exitCode)->toBe(0)
            ->and($output->fetch())->toContain('XDEBUG_MODE=off');
    });
});
