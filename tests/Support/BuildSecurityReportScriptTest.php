<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function removeSecurityReportFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }

    rmdir($path);
}

it('maps current workflow jobs and benchmark steps into the security report', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $fixtureRoot = sys_get_temp_dir().'/phpforge-security-report-'.bin2hex(random_bytes(8));
    $binDirectory = $fixtureRoot.'/bin';
    $artifactDirectory = $fixtureRoot.'/.phpforge-report/in/benchmark';

    mkdir($binDirectory, 0777, true);
    mkdir($artifactDirectory, 0777, true);

    $jobs = [
        'jobs' => [
            ['id' => 101, 'name' => 'InterMix / security-standards / QA - PHP 8.4 - prefer-lowest', 'conclusion' => 'success'],
            ['id' => 102, 'name' => 'InterMix / security-standards / QA - PHP 8.4 - prefer-stable', 'conclusion' => 'success'],
            ['id' => 103, 'name' => 'InterMix / security-standards / QA - PHP 8.5 - prefer-lowest', 'conclusion' => 'success'],
            ['id' => 104, 'name' => 'InterMix / security-standards / QA - PHP 8.5 - prefer-stable', 'conclusion' => 'success'],
            ['id' => 105, 'name' => 'InterMix / security-standards / Analysis - PHP 8.4', 'conclusion' => 'success'],
            ['id' => 106, 'name' => 'InterMix / security-standards / Analysis - PHP 8.5', 'conclusion' => 'success'],
            [
                'id' => 107,
                'name' => 'InterMix / security-standards / Benchmark (8.4)',
                'conclusion' => 'success',
                'steps' => [['name' => 'Setup benchmark - PHP 8.4']],
            ],
            [
                'id' => 108,
                'name' => 'InterMix / security-standards / Benchmark (8.5)',
                'conclusion' => 'success',
                'steps' => [['name' => 'Setup benchmark - PHP 8.5']],
            ],
        ],
    ];

    $benchmarkRow = [
        'benchmark' => 'InterMixBench',
        'subject' => 'benchDispatch',
        'set' => 0,
        'revs' => 10,
        'its' => 3,
        'mem_peak' => '1.000mb',
        'mode' => '1.000μs',
        'rstdev' => '0.10%',
    ];

    try {
        file_put_contents($fixtureRoot.'/jobs.json', json_encode($jobs, JSON_THROW_ON_ERROR));
        file_put_contents(
            $artifactDirectory.'/benchmark-results-php-8.4.json',
            json_encode([$benchmarkRow], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $artifactDirectory.'/benchmark-results-php-8.5.json',
            json_encode([$benchmarkRow], JSON_THROW_ON_ERROR),
        );
        file_put_contents($binDirectory.'/curl', <<<'BASH'
#!/usr/bin/env bash
cat "$PHPFORGE_TEST_JOBS_JSON"
BASH);
        file_put_contents($binDirectory.'/composer', <<<'BASH'
#!/usr/bin/env bash
if [ "${1:-}" = "list" ]; then
  echo "ic:bench:quick"
  exit 0
fi
if [ "${1:-}" = "show" ]; then
  echo '{"versions":["test-version"]}'
  exit 0
fi
exit 1
BASH);
        chmod($binDirectory.'/curl', 0755);
        chmod($binDirectory.'/composer', 0755);

        $summaryPath = $fixtureRoot.'/step-summary.md';
        $process = new Process(
            ['bash', $projectRoot.'/.github/scripts/build-security-report.sh'],
            $fixtureRoot,
            [
                'PATH' => $binDirectory.PATH_SEPARATOR.(getenv('PATH') ?: ''),
                'PHPFORGE_TEST_JOBS_JSON' => $fixtureRoot.'/jobs.json',
                'GITHUB_API_URL' => 'https://api.github.test',
                'GITHUB_REPOSITORY' => 'infocyph/InterMix',
                'GITHUB_RUN_ID' => '33291640667',
                'GITHUB_RUN_NUMBER' => '1',
                'GITHUB_SHA' => str_repeat('a', 40),
                'GITHUB_STEP_SUMMARY' => $summaryPath,
                'GH_TOKEN' => 'test-token',
                'RUN_RESULT' => 'success',
                'ANALYZE_RESULT' => 'success',
                'BENCHMARK_JOB_RESULT' => 'success',
                'PHP_VERSIONS_INPUT' => '["8.4","8.5"]',
            ],
        );
        $process->setTimeout(60);
        $process->mustRun();

        $report = json_decode(
            (string) file_get_contents($fixtureRoot.'/.phpforge-report/out/security-summary.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($report['matrix_results'] ?? null)->toBe([
            [
                'php_version' => '8.4',
                'code_analysis_prefer_lowest' => 'success',
                'code_analysis_prefer_stable' => 'success',
                'security_analysis' => 'success',
            ],
            [
                'php_version' => '8.5',
                'code_analysis_prefer_lowest' => 'success',
                'code_analysis_prefer_stable' => 'success',
                'security_analysis' => 'success',
            ],
        ])->and(array_column($report['check_results'] ?? [], 'source_job'))->toBe([
            'QA - PHP 8.4 - prefer-lowest',
            'QA - PHP 8.4 - prefer-stable',
            'Analysis - PHP 8.4',
            'QA - PHP 8.5 - prefer-lowest',
            'QA - PHP 8.5 - prefer-stable',
            'Analysis - PHP 8.5',
        ])->and(count($report['benchmark_results'] ?? []))->toBe(2)
            ->and(array_column($report['benchmark_results'] ?? [], 'php_version'))->toBe(['8.4', '8.5'])
            ->and($report['rollup'] ?? null)->toBe([
                'code_analysis_prefer_lowest' => 'success',
                'code_analysis_prefer_stable' => 'success',
                'security_analysis' => 'success',
                'benchmark' => 'success',
            ]);
    } finally {
        removeSecurityReportFixture($fixtureRoot);
    }
});
