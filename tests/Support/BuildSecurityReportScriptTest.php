<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('maps current workflow jobs and benchmark steps into the security report', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $fixtureRoot = sys_get_temp_dir() . '/phpforge-security-report-' . bin2hex(random_bytes(8));
    $binDirectory = $fixtureRoot . '/bin';
    $artifactDirectory = $fixtureRoot . '/.phpforge-report/in/benchmark';
    $psalmVersion = fixtureString(Composer\InstalledVersions::getPrettyVersion('vimeo/psalm'));
    $path = getenv('PATH');

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
        file_put_contents($fixtureRoot . '/jobs.json', json_encode($jobs, JSON_THROW_ON_ERROR));
        file_put_contents(
            $artifactDirectory . '/benchmark-results-php-8.4.json',
            json_encode([$benchmarkRow], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $artifactDirectory . '/benchmark-results-php-8.5.json',
            json_encode([$benchmarkRow], JSON_THROW_ON_ERROR),
        );
        file_put_contents($binDirectory . '/curl', <<<'BASH'
#!/usr/bin/env bash
cat "$PHPFORGE_TEST_JOBS_JSON"
BASH);
        file_put_contents($binDirectory . '/composer', <<<'BASH'
#!/usr/bin/env bash
if [ "${1:-}" = "list" ]; then
  echo "ic:bench:quick"
  exit 0
fi
if [ "${1:-}" = "show" ]; then
  if [ "${2:-}" = "vimeo/psalm" ]; then
    printf '{"versions":["%s"]}\n' "$PHPFORGE_TEST_PSALM_VERSION"
    exit 0
  fi
  echo '{"versions":["test-version"]}'
  exit 0
fi
exit 1
BASH);
        chmod($binDirectory . '/curl', 0755);
        chmod($binDirectory . '/composer', 0755);

        $summaryPath = $fixtureRoot . '/step-summary.md';
        $process = new Process(
            ['bash', $projectRoot . '/.github/scripts/build-security-report.sh'],
            $fixtureRoot,
            [
                'PATH' => $binDirectory . PATH_SEPARATOR . ($path === false ? '' : $path),
                'PHPFORGE_TEST_JOBS_JSON' => $fixtureRoot . '/jobs.json',
                'PHPFORGE_TEST_PSALM_VERSION' => $psalmVersion,
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
            fixtureFile($fixtureRoot . '/.phpforge-report/out/security-summary.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $summary = file_get_contents($summaryPath);
        $psalm = array_values(array_filter(fixtureArray(fixtureValue($report, ['tools'])), static fn(mixed $tool): bool => fixtureValue($tool, ['name']) === 'Psalm'));

        expect($psalm)->toBe([['name' => 'Psalm', 'package' => 'vimeo/psalm', 'version' => $psalmVersion]])
            ->and(fixtureValue($report, ['matrix_results'], true) ?? null)->toBe([
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
            ])->and(fixtureValue($report, ['enabled'], true) ?? null)->toBe([
                'qa' => true,
                'analysis' => true,
                'benchmark' => true,
            ])->and(array_column(fixtureArray(fixtureValue($report, ['check_results'], true) ?? []), 'source_job'))->toBe([
                'QA - PHP 8.4 - prefer-lowest',
                'QA - PHP 8.4 - prefer-stable',
                'Analysis - PHP 8.4',
                'QA - PHP 8.5 - prefer-lowest',
                'QA - PHP 8.5 - prefer-stable',
                'Analysis - PHP 8.5',
            ])->and(count(fixtureArray(fixtureValue($report, ['benchmark_results'], true) ?? [])))->toBe(2)
            ->and(array_column(fixtureArray(fixtureValue($report, ['benchmark_results'], true) ?? []), 'php_version'))->toBe(['8.4', '8.5'])
            ->and(fixtureValue($report, ['rollup'], true) ?? null)->toBe([
                'code_analysis_prefer_lowest' => 'success',
                'code_analysis_prefer_stable' => 'success',
                'security_analysis' => 'success',
                'benchmark' => 'success',
            ])
            ->and($summary)->toBeString()
            ->toContain('### Security Report Summary')
            ->toContain('| Overall | PASS |')
            ->toContain('| 8.4 | PASS | PASS | PASS |')
            ->toContain('#### Benchmark Results')
            ->toContain('<summary>PHP 8.4 — 1 benchmark result(s) — PASS</summary>')
            ->toContain('| InterMixBench | benchDispatch | 0 | 10 | 3 | 1.000mb | 1.000μs | 0.10% |')
            ->toContain('Artifact: security-report/security-summary.json')
            ->not->toContain('Security SVG Report')
            ->and(is_file($fixtureRoot . '/.phpforge-report/out/security-report.svg'))->toBeFalse();
    } finally {
        removeFixtureDirectory($fixtureRoot);
    }
});
