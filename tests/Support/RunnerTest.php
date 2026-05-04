<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Output\BufferedOutput;

function runnerTempPath(string $suffix): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-runner-'.uniqid('', true).'-'.$suffix;
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
