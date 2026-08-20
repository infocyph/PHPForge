<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ParallelRunner;
use Symfony\Component\Console\Output\BufferedOutput;

function withParallelRunnerEnv(string $name, ?string $value, callable $callback): void
{
    $previous = getenv($name);
    putenv($value === null ? $name : $name.'='.$value);

    try {
        $callback();
    } finally {
        putenv($previous === false ? $name : $name.'='.$previous);
    }
}

it('uses eligible task count by default and caps concurrency at sixteen', function (): void {
    withParallelRunnerEnv('IC_TEST_CONCURRENCY', null, function (): void {
        withParallelRunnerEnv('PHPFORGE_PARALLEL', null, function (): void {
            expect(ParallelRunner::concurrencyFrom(null, 7))->toBe(7)
                ->and(ParallelRunner::concurrencyFrom(null, 99))->toBe(16)
                ->and(ParallelRunner::concurrencyFrom(null, 0))->toBe(1);
        });
    });
});

it('prefers the canonical concurrency variable and bounds explicit overrides', function (): void {
    withParallelRunnerEnv('PHPFORGE_PARALLEL', '3', function (): void {
        withParallelRunnerEnv('IC_TEST_CONCURRENCY', '9', function (): void {
            expect(ParallelRunner::concurrencyFrom(null, 2))->toBe(9)
                ->and(ParallelRunner::concurrencyFrom('50', 2))->toBe(16)
                ->and(ParallelRunner::concurrencyFrom('-2', 2))->toBe(1);
        });
    });
});

it('lets every started peer finish when another task fails', function (): void {
    $marker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-peer-'.uniqid('', true);
    $output = new BufferedOutput();
    $tasks = [
        [PHP_BINARY, '-r', 'usleep(50000); exit(7);'],
        [PHP_BINARY, '-r', 'usleep(200000); file_put_contents($argv[1], "done");', $marker],
    ];

    $exitCode = (new ParallelRunner($output))->run([], $tasks, 2);

    expect($exitCode)->toBe(7)
        ->and(file_get_contents($marker))->toBe('done');

    unlink($marker);
});

it('renders completed tasks in declaration order', function (): void {
    $output = new BufferedOutput();
    $tasks = [
        [PHP_BINARY, '-r', 'usleep(150000); fwrite(STDERR, "first-marker\\n"); exit(2);'],
        [PHP_BINARY, '-r', 'fwrite(STDERR, "second-marker\\n"); exit(3);'],
    ];

    (new ParallelRunner($output))->run([], $tasks, 2);
    $rendered = $output->fetch();

    expect(strpos($rendered, 'first-marker'))->toBeLessThan(strpos($rendered, 'second-marker'));
});

it('keeps successful task output concise', function (): void {
    $output = new BufferedOutput();
    $exitCode = (new ParallelRunner($output))->run([], [[PHP_BINARY, '-r', 'fwrite(STDOUT, "noisy-success");']], 1);

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->not->toContain('noisy-success')
        ->toContain('PASS')
        ->not->toContain('(0.');
});

it('renders successful tasks only in the summary and failed tasks with details', function (): void {
    $output = new BufferedOutput();
    $tasks = [
        [PHP_BINARY, '-r', 'fwrite(STDOUT, "hidden-success-detail");'],
        [PHP_BINARY, '-r', 'fwrite(STDERR, "visible-failure-detail\\n"); exit(4);'],
    ];

    $exitCode = (new ParallelRunner($output))->run([], $tasks, 2);
    $rendered = $output->fetch();

    expect($exitCode)->toBe(4)
        ->and($rendered)->not->toContain('hidden-success-detail')
        ->toContain('visible-failure-detail')
        ->and(substr_count($rendered, 'PASS '))->toBe(1)
        ->and(substr_count($rendered, 'FAIL '))->toBe(2);
});

it('defaults parallel subprocesses to XDEBUG_MODE off when unset', function (): void {
    withParallelRunnerEnv('XDEBUG_MODE', null, function (): void {
        $output = new BufferedOutput();
        $exitCode = (new ParallelRunner($output))->run([], [[PHP_BINARY, '-r', 'exit(getenv("XDEBUG_MODE") === "off" ? 0 : 1);']], 1);

        expect($exitCode)->toBe(0);
    });
});
