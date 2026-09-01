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

it('defaults and bounds the per-task timeout', function (): void {
    withParallelRunnerEnv('IC_TEST_TASK_TIMEOUT', null, function (): void {
        expect(ParallelRunner::timeoutFrom(null))->toBe(300)
            ->and(ParallelRunner::timeoutFrom('0'))->toBe(1)
            ->and(ParallelRunner::timeoutFrom('7200'))->toBe(3600)
            ->and(ParallelRunner::timeoutFrom('invalid'))->toBe(300);
    });
});

it('terminates and reports a task that exceeds its configured timeout', function (): void {
    withParallelRunnerEnv('IC_TEST_TASK_TIMEOUT', '1', function (): void {
        $output = new BufferedOutput();
        $exitCode = (new ParallelRunner($output))->run([], [[PHP_BINARY, '-r', 'sleep(5);']], 1);

        expect($exitCode)->not->toBe(0)
            ->and($output->fetch())->toContain('Task exceeded the configured timeout of 1 seconds');
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

it('renders successful tasks only in the summary and every failed task with complete output', function (): void {
    $output = new BufferedOutput();
    $tasks = [
        [PHP_BINARY, '-r', 'fwrite(STDOUT, "hidden-success-detail");'],
        [PHP_BINARY, '-r', 'fwrite(STDOUT, "visible-failure-stdout\\n"); fwrite(STDERR, "visible-failure-stderr\\n"); exit(4);'],
    ];

    $exitCode = (new ParallelRunner($output))->run([], $tasks, 2);
    $rendered = $output->fetch();

    expect($exitCode)->toBe(4)
        ->and($rendered)->not->toContain('hidden-success-detail')
        ->toContain('visible-failure-stdout')
        ->toContain('visible-failure-stderr')
        ->and(substr_count($rendered, 'PASS '))->toBe(1)
        ->and(substr_count($rendered, 'FAIL '))->toBe(2);
});

it('renders aggregate PHPProbe failures as checker-grouped details', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-phpprobe-output-'.bin2hex(random_bytes(6));
    $probe = $directory.DIRECTORY_SEPARATOR.'phpprobe';
    mkdir($directory, 0755, true);
    file_put_contents($probe, <<<'PHP'
<?php

$payload = [
    'summary' => [
        'checker' => 'check',
        'exit_code' => 1,
        'checks' => ['syntax' => 1, 'duplicates' => 1, 'comments' => 1],
        'skipped' => [],
    ],
    'results' => [
        'syntax' => [
            'exit_code' => 1,
            'stderr' => '',
            'payload' => [
                'files_checked' => 2,
                'failures' => [[
                    'file' => 'src/Broken.php',
                    'message' => 'Parse error: unexpected token',
                ]],
            ],
        ],
        'duplicates' => [
            'exit_code' => 1,
            'stderr' => '',
            'payload' => [
                'files' => 2,
                'duplicated_lines' => 12,
                'duplicate_percentage' => 8.5,
                'clones' => [[
                    'lines' => 12,
                    'similarity' => 0.95,
                    'source' => 'statements',
                    'score' => 140.2,
                    'occurrences' => [
                        ['file' => 'src/One.php', 'start_line' => 10, 'end_line' => 21],
                        ['file' => 'src/Two.php', 'start_line' => 30, 'end_line' => 41],
                    ],
                ]],
            ],
        ],
        'comments' => [
            'exit_code' => 1,
            'stderr' => '',
            'payload' => [
                'files' => 2,
                'findings' => [[
                    'file' => 'src/Three.php',
                    'line' => 44,
                    'severity' => 'error',
                    'subtype' => 'commented_out_code_without_reason',
                    'message' => 'Commented-out code requires a reason.',
                    'suggestion' => 'Add a tagged reason.',
                ]],
            ],
        ],
    ],
];

echo json_encode($payload, JSON_THROW_ON_ERROR);
exit(1);
PHP);

    try {
        $output = new BufferedOutput();
        $exitCode = (new ParallelRunner($output))->run([], [[PHP_BINARY, $probe, 'check', '--format=json']], 1);
        $rendered = $output->fetch();

        expect($exitCode)->toBe(1)
            ->and($rendered)->toContain('PHPProbe check summary:')
            ->toContain('syntax         FAIL')
            ->toContain('duplicates     FAIL')
            ->toContain('comments       FAIL')
            ->toContain('FAIL Syntax')
            ->toContain('src/Broken.php')
            ->toContain('Parse error: unexpected token')
            ->toContain('FAIL Duplicate Code')
            ->toContain('src/One.php:10-21')
            ->toContain('src/Two.php:30-41')
            ->toContain('FAIL Comment Policy')
            ->toContain('ERROR src/Three.php:44 [commented_out_code_without_reason]')
            ->toContain('Commented-out code requires a reason.')
            ->not->toContain('"results"');
    } finally {
        unlink($probe);
        rmdir($directory);
    }
});

it('defaults parallel subprocesses to XDEBUG_MODE off when unset', function (): void {
    withParallelRunnerEnv('XDEBUG_MODE', null, function (): void {
        $output = new BufferedOutput();
        $exitCode = (new ParallelRunner($output))->run([], [[PHP_BINARY, '-r', 'exit(getenv("XDEBUG_MODE") === "off" ? 0 : 1);']], 1);

        expect($exitCode)->toBe(0);
    });
});
