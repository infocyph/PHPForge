<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\BenchmarkResult;

/**
 * @return array<string, mixed>
 */
function phpforgeBenchmarkDocument(float $rpm = 60_000.0, string $fingerprint = 'runner-a'): array
{
    return [
        'schema_version' => 1,
        'generated_at' => '2026-07-29T12:00:00+00:00',
        'environment' => [
            'stable' => true,
            'fingerprint' => $fingerprint,
            'php_version' => '8.4.23',
            'php_sapi' => 'cli',
            'operating_system' => 'Linux 6.8',
            'cpu_model' => 'Dedicated benchmark CPU',
            'memory_limit' => '-1',
            'opcache' => false,
            'jit' => false,
            'xdebug' => false,
            'extensions' => ['json'],
            'runner' => 'custom-runner 1.0',
            'release' => 'candidate',
        ],
        'workloads' => [[
            'name' => 'map-and-filter',
            'type' => 'component',
            'metadata' => [
                'fixture_size' => 1000,
                'command' => 'composer benchmark:representative',
            ],
            'repetitions' => 3,
            'warmup_operations' => 100,
            'duration_seconds' => 30.0,
            'concurrency' => 1,
            'result' => [
                'attempted_operations' => 30_000,
                'successful_operations' => 30_000,
                'failed_operations' => 0,
                'timeouts' => 0,
                'successful_rpm' => $rpm,
                'error_rate' => 0.0,
                'latency_ms' => [
                    'minimum' => 0.5,
                    'average' => 0.8,
                    'p50' => 0.7,
                    'p95' => 1.0,
                    'p99' => 1.2,
                    'maximum' => 2.0,
                ],
                'cpu' => [
                    'average_percent' => 90.0,
                    'peak_percent' => 100.0,
                ],
                'memory' => [
                    'average_mb' => 20.0,
                    'peak_mb' => 24.0,
                    'growth_mb' => 0.5,
                ],
                'stability' => [
                    'status' => 'stable',
                    'spread_percent' => 1.0,
                ],
            ],
        ]],
    ];
}

/**
 * @param array<string, mixed> $document
 */
function writePhpforgeBenchmarkDocument(array $document): string
{
    $path = tempnam(sys_get_temp_dir(), 'phpforge-benchmark-');
    file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

    return $path;
}

it('validates a workload-neutral representative benchmark result', function (): void {
    $path = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument());

    try {
        expect((new BenchmarkResult())->load($path)['errors'])->toBe([]);
    } finally {
        unlink($path);
    }
});

it('rejects inconsistent result counters and latency percentiles', function (): void {
    $document = phpforgeBenchmarkDocument();
    $document['workloads'][0]['result']['successful_operations'] = 29_999;
    $document['workloads'][0]['result']['latency_ms']['p95'] = 0.5;
    $path = writePhpforgeBenchmarkDocument($document);

    try {
        $errors = (new BenchmarkResult())->load($path)['errors'];

        expect(implode("\n", $errors))
            ->toContain('attempted operations must equal successful plus failed operations')
            ->toContain('p50 <= p95 <= p99');
    } finally {
        unlink($path);
    }
});

it('rejects malformed timestamps and error rates that disagree with counters', function (): void {
    $document = phpforgeBenchmarkDocument();
    $document['generated_at'] = 'tomorrow';
    $document['workloads'][0]['result']['successful_operations'] = 29_999;
    $document['workloads'][0]['result']['failed_operations'] = 1;
    $path = writePhpforgeBenchmarkDocument($document);

    try {
        $errors = implode("\n", (new BenchmarkResult())->load($path)['errors']);

        expect($errors)
            ->toContain('RFC 3339')
            ->toContain('failed_operations divided by attempted_operations');
    } finally {
        unlink($path);
    }
});

it('skips regression enforcement unless a stable environment is asserted', function (): void {
    $baseline = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument());
    $candidate = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument(50_000.0));

    try {
        $comparison = (new BenchmarkResult())->compare($baseline, $candidate, 2.0, false);

        expect($comparison['status'])->toBe('skipped');
    } finally {
        unlink($baseline);
        unlink($candidate);
    }
});

it('enforces successful rpm only for matching stable workloads', function (): void {
    $baseline = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument());
    $passing = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument(59_000.0));
    $failing = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument(57_000.0));

    try {
        $contract = new BenchmarkResult();

        expect($contract->compare($baseline, $passing, 2.0, true)['status'])->toBe('passed')
            ->and($contract->compare($baseline, $failing, 2.0, true)['status'])->toBe('failed');
    } finally {
        unlink($baseline);
        unlink($passing);
        unlink($failing);
    }
});

it('rejects regression comparisons across different environments', function (): void {
    $baseline = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument());
    $candidate = writePhpforgeBenchmarkDocument(phpforgeBenchmarkDocument(60_000.0, 'runner-b'));

    try {
        $comparison = (new BenchmarkResult())->compare($baseline, $candidate, 2.0, true);

        expect($comparison['status'])->toBe('failed')
            ->and(implode("\n", $comparison['messages']))->toContain('same stable environment fingerprint');
    } finally {
        unlink($baseline);
        unlink($candidate);
    }
});
