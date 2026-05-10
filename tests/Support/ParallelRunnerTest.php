<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ParallelRunner;
use Symfony\Component\Console\Output\BufferedOutput;

function parallelRunnerTempPath(string $suffix): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-parallel-runner-'.uniqid('', true).'-'.$suffix;
}

function withParallelRunnerEnv(string $name, ?string $value, callable $callback): void
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

it('retries pest without internal parallelization after worker crash signature', function (): void {
    $tempDir = parallelRunnerTempPath('dir');
    $scriptPath = $tempDir.DIRECTORY_SEPARATOR.'pest';
    $configPath = parallelRunnerTempPath('pest.xml');
    $output = new BufferedOutput();

    mkdir($tempDir, 0755, true);
    file_put_contents($configPath, "<phpunit/>\n");
    file_put_contents(
        $scriptPath,
        <<<'PHP'
<?php
declare(strict_types=1);

$args = $_SERVER['argv'] ?? [];

if (in_array('--parallel', $args, true)) {
    fwrite(STDERR, "In WorkerCrashedException.php line 41:\n");
    fwrite(STDERR, "  The test \"PARATEST='1' TEST_TOKEN='2' UNIQUE_TEST_TOKEN='2_abcdef' /app/UID/tests/ArchTest.php\" failed.\n");
    fwrite(STDERR, "paratest [--functional]\n");
    exit(2);
}

fwrite(STDOUT, "fallback-pass\n");
exit(0);
PHP
    );

    $runner = new ParallelRunner($output);
    $exitCode = $runner->run([], [[PHP_BINARY, $scriptPath, '--configuration', $configPath, '--parallel', '--processes=4']], 1);
    $renderedOutput = $output->fetch();

    expect($exitCode)->toBe(0)
        ->and($renderedOutput)->toContain('retrying this task without Pest internal parallelization')
        ->and($renderedOutput)->toContain('[retry: no-pest-parallel]');

    if (is_file($scriptPath)) {
        unlink($scriptPath);
    }

    if (is_file($configPath)) {
        unlink($configPath);
    }

    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }
});

it('does not retry tasks without a pest crash signature', function (): void {
    $output = new BufferedOutput();
    $runner = new ParallelRunner($output);

    $exitCode = $runner->run([], [[PHP_BINARY, '-r', 'fwrite(STDERR, "generic error\n"); exit(5);']], 1);
    $renderedOutput = $output->fetch();

    expect($exitCode)->toBe(5)
        ->and($renderedOutput)->not()->toContain('retrying this task without Pest internal parallelization');
});

it('defaults parallel subprocesses to XDEBUG_MODE=off when unset', function (): void {
    withParallelRunnerEnv('XDEBUG_MODE', null, function (): void {
        $output = new BufferedOutput();
        $runner = new ParallelRunner($output);

        $exitCode = $runner->run([], [[PHP_BINARY, '-r', 'fwrite(STDOUT, "XDEBUG_MODE=".(getenv("XDEBUG_MODE") ?: "")."\n");']], 1);

        expect($exitCode)->toBe(0)
            ->and($output->fetch())->toContain('XDEBUG_MODE=off');
    });
});
