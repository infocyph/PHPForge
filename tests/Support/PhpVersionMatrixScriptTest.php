<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, string>
 */
function phpVersionMatrixOutputs(string $requestedVersions): array
{
    $outputPath = tempnam(sys_get_temp_dir(), 'phpforge-matrix-');

    if (!is_string($outputPath)) {
        throw new RuntimeException('Unable to create the PHP matrix output file.');
    }

    try {
        $process = new Process(
            ['bash', dirname(__DIR__, 2).'/.github/scripts/resolve-php-matrix.sh'],
            dirname(__DIR__, 2),
            [
                'GITHUB_OUTPUT' => $outputPath,
                'INPUT_PHP_VERSIONS' => $requestedVersions,
                'SUPPORTED_PHP_VERSIONS' => '["8.4","8.5"]',
            ],
        );
        $process->mustRun();

        $contents = file_get_contents($outputPath);

        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the PHP matrix output file.');
        }

        $outputs = [];

        foreach (array_filter(explode("\n", $contents)) as $line) {
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $outputs[$name] = $value;
        }

        return $outputs;
    } finally {
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
    }
}

it('silently removes unsupported PHP versions from the workflow matrix', function (): void {
    $outputs = phpVersionMatrixOutputs('["8.2","8.4","8.3","8.5","8.4.12","8.4",9]');

    expect($outputs)->toMatchArray([
        'php_versions' => '["8.4","8.5","8.4.12"]',
        'clean_install_php_version' => '8.4.12',
        'has_supported_php_versions' => 'true',
    ]);
});

it('produces a successful empty matrix when every PHP version is unsupported', function (): void {
    $outputs = phpVersionMatrixOutputs('["7.4","8.1","8.2","8.3","9.0"]');

    expect($outputs)->toMatchArray([
        'php_versions' => '[]',
        'clean_install_php_version' => '',
        'has_supported_php_versions' => 'false',
    ]);
});

it('guards every PHP workflow job with the filtered matrix result', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $benchmarkSetupSteps = array_values(array_filter(
        $workflow['jobs']['benchmark']['steps'] ?? [],
        static fn(mixed $step): bool => is_array($step) && ($step['uses'] ?? null) === 'shivammathur/setup-php@v2',
    ));

    expect($workflow['jobs']['prepare']['outputs']['has_supported_php_versions'] ?? null)
        ->toBe('${{ steps.matrix.outputs.has_supported_php_versions }}')
        ->and($workflow['jobs']['run']['if'] ?? null)
        ->toContain("needs.prepare.outputs.has_supported_php_versions == 'true'")
        ->and($workflow['jobs']['clean-install']['if'] ?? null)
        ->toContain("needs.prepare.outputs.has_supported_php_versions == 'true'")
        ->and($workflow['jobs']['analyze']['if'] ?? null)
        ->toContain("needs.prepare.outputs.has_supported_php_versions == 'true'")
        ->and($workflow['jobs']['benchmark']['if'] ?? null)
        ->toContain("needs.prepare.outputs.has_supported_php_versions == 'true'")
        ->and($workflow['jobs']['benchmark']['name'] ?? null)
        ->toBe('Benchmark')
        ->and($benchmarkSetupSteps[0]['name'] ?? null)
        ->toBe('Setup benchmark - PHP ${{ matrix.php-version }}')
        ->and($workflow['jobs']['svg-report']['if'] ?? null)
        ->toContain("needs.prepare.outputs.has_supported_php_versions == 'true'");
});

it('preserves topology-aware integration DSNs in workflow YAML', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $environment = $workflow['jobs']['run']['env'] ?? [];

    expect($environment['IC_SQLITE_MEMORY_DSN'] ?? null)->toBe('sqlite::memory:')
        ->and($environment['IC_MONGODB_DSN'] ?? null)->toContain('directConnection=true')
        ->and($environment['IC_MONGODB_REPLICA_SET'] ?? null)->toContain('phpforge-rs');
});

it('enables APCu for CLI when it is present in the resolved extension list', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $steps = $workflow['jobs']['run']['steps'] ?? [];
    $setupSteps = array_values(array_filter(
        $steps,
        static fn(mixed $step): bool => is_array($step) && ($step['uses'] ?? null) === 'shivammathur/setup-php@v2',
    ));

    expect($setupSteps)->toHaveCount(1)
        ->and($setupSteps[0]['with']['extensions'] ?? null)
        ->toBe('${{ needs.prepare.outputs.php_extensions }}')
        ->and($setupSteps[0]['with']['ini-values'] ?? null)
        ->toBe("\${{ contains(needs.prepare.outputs.php_extensions, 'apcu') && 'apc.enable_cli=1, apcu.enable_cli=1' || '' }}");
});

it('exposes the compact service controls in the project workflow', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/phpforge.yml');
    $template = Yaml::parseFile($root.'/resources/workflows/security-standards.yml');
    $expected = [
        'fail_on_skipped_tests' => true,
        'integration_services' => '[]',
        'service_topologies' => '{}',
    ];

    expect($workflow['jobs']['security-standards']['with'] ?? null)->toBe($expected)
        ->and($template['jobs']['phpforge']['with'] ?? null)->toBe($expected);
});

it('fails workflow Pest runs when tests are skipped by default', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $inputs = $workflow['on']['workflow_call']['inputs'] ?? [];
    $steps = $workflow['jobs']['run']['steps'] ?? [];
    $stepsByName = array_column($steps, null, 'name');
    $environment = $stepsByName['Run quality suite once']['env'] ?? [];

    expect($inputs['fail_on_skipped_tests']['default'] ?? null)->toBeTrue()
        ->and($environment['IC_PEST_FAIL_ON_SKIPPED'] ?? null)
        ->toBe('${{ inputs.fail_on_skipped_tests }}');
});

it('bounds each workflow quality tool and exposes the timeout input', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $inputs = $workflow['on']['workflow_call']['inputs'] ?? [];
    $steps = $workflow['jobs']['run']['steps'] ?? [];
    $stepsByName = array_column($steps, null, 'name');
    $environment = $stepsByName['Run quality suite once']['env'] ?? [];

    expect($inputs['quality_task_timeout_seconds']['default'] ?? null)->toBe(300)
        ->and($environment['IC_TEST_TASK_TIMEOUT'] ?? null)
        ->toBe('${{ inputs.quality_task_timeout_seconds }}');
});

it('passes CI flags as options to the registered Composer command', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $steps = $workflow['jobs']['run']['steps'] ?? [];
    $stepsByName = array_column($steps, null, 'name');
    $script = $stepsByName['Run quality suite once']['run'] ?? '';

    expect($script)->toContain('args+=(--prefer-lowest)')
        ->toContain('args+=(--without-analysis)')
        ->toContain('composer ic:ci "${args[@]}"')
        ->not->toContain('composer ic:ci -- "${args[@]}"');
});

it('installs dependencies before one analyzer execution produces gates, sarif, and failure diagnostics', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $steps = $workflow['jobs']['analyze']['steps'] ?? [];
    $stepsByName = array_column($steps, null, 'name');
    $stepNames = array_column($steps, 'name');

    $installIndex = array_search('Install dependencies', $stepNames, true);
    $analyzerIndex = array_search('Run audit and analyzers once', $stepNames, true);
    $analyzerScript = $stepsByName['Run audit and analyzers once']['run'] ?? '';
    $enforcementScript = $stepsByName['Enforce analyzer results']['run'] ?? '';

    expect($installIndex)->toBeInt()
        ->and($analyzerIndex)->toBeInt()->toBeGreaterThan($installIndex)
        ->and(substr_count($analyzerScript, 'bin/phpstan'))
        ->toBe(1)
        ->and(substr_count($analyzerScript, 'bin/psalm.phar'))
        ->toBe(1)
        ->and($analyzerScript)->toContain('--threads=1')
        ->and($analyzerScript)->toContain('phpstan-results.sarif')
        ->and($analyzerScript)->toContain('psalm-results.sarif')
        ->and($analyzerScript)->toContain('audit-results.log')
        ->and($analyzerScript)->toContain('phpstan-results.log')
        ->and($analyzerScript)->toContain('phpstan-sarif.log')
        ->and($analyzerScript)->toContain('psalm-results.log')
        ->and($enforcementScript)->toContain('echo "::stop-commands::$command_marker"')
        ->and($enforcementScript)->toContain("printf '| Tool | Result |\\n| --- | --- |\\n'")
        ->and($enforcementScript)->toContain('>> "$GITHUB_STEP_SUMMARY"')
        ->and($enforcementScript)->toContain("echo '::group::FAIL Composer audit'")
        ->and($enforcementScript)->toContain("echo '::group::FAIL PHPStan'")
        ->and($enforcementScript)->toContain("show_log 'Analysis diagnostics' phpstan-results.log")
        ->and($enforcementScript)->toContain("show_log 'SARIF conversion diagnostics' phpstan-sarif.log")
        ->and($enforcementScript)->toContain("echo '::group::FAIL Psalm'")
        ->and($enforcementScript)->toContain("show_log 'Analysis diagnostics' psalm-results.log");
});

it('groups analyzer failures by tool and renders status tables', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $steps = $workflow['jobs']['analyze']['steps'] ?? [];
    $stepsByName = array_column($steps, null, 'name');
    $script = $stepsByName['Enforce analyzer results']['run'] ?? '';
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-analysis-output-'.bin2hex(random_bytes(6));
    $summaryPath = $directory.DIRECTORY_SEPARATOR.'summary.md';

    mkdir($directory, 0755, true);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'phpstan-results.log', 'src/Example.php:12: PHPStan failure');
    file_put_contents($directory.DIRECTORY_SEPARATOR.'psalm-results.log', 'src/Example.php:18: Psalm failure');

    try {
        $script = strtr($script, [
            '${{ steps.analyzers.outputs.audit_status }}' => '0',
            '${{ steps.analyzers.outputs.phpstan_status }}' => '1',
            '${{ steps.analyzers.outputs.sarif_status }}' => '0',
            '${{ steps.analyzers.outputs.psalm_status }}' => '1',
        ]);
        $process = new Process(['bash', '-c', $script], $directory, ['GITHUB_STEP_SUMMARY' => $summaryPath]);
        $process->run();
        $output = $process->getOutput();
        $summary = file_get_contents($summaryPath);

        expect($process->getExitCode())->toBe(1)
            ->and($output)->toContain('Analysis Summary')
            ->and($output)->toContain('Composer audit       PASS')
            ->and($output)->toContain('PHPStan              FAIL')
            ->and($output)->toContain('Psalm                FAIL')
            ->and($output)->toContain('::group::FAIL PHPStan')
            ->and($output)->toContain('src/Example.php:12: PHPStan failure')
            ->and($output)->toContain('::group::FAIL Psalm')
            ->and($output)->toContain('src/Example.php:18: Psalm failure')
            ->and($output)->not->toContain('::group::FAIL Composer audit')
            ->and($summary)->toContain('| Composer audit | PASS |')
            ->and($summary)->toContain('| PHPStan | FAIL |')
            ->and($summary)->toContain('| Psalm | FAIL |');
    } finally {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
});

it('resolves benchmark config from the project root or the correct PHPForge package location', function (): void {
    $script = file_get_contents(dirname(__DIR__, 2).'/.github/scripts/run-benchmark.sh');

    expect($script)->toBeString()
        ->and($script)->toContain('package_name="$(composer config name --no-plugins --no-scripts')
        ->and($script)->toContain('"phpbench.json"')
        ->and($script)->toContain('"phpbench.json.dist"')
        ->and($script)->toContain('if [ "$package_name" = "infocyph/phpforge" ]; then')
        ->and($script)->toContain('config_candidates+=("resources/phpbench.json")')
        ->and($script)->toContain('${vendor_dir}/infocyph/phpforge/resources/phpbench.json');
});

it('skips the default benchmark run when the consuming project has no benchmark directory', function (): void {
    $script = file_get_contents(dirname(__DIR__, 2).'/.github/scripts/run-benchmark.sh');

    expect($script)->toBeString()
        ->and($script)->toContain('benchmark_path=""')
        ->and($script)->toContain('[ -z "$custom_benchmark_script" ] && [ -z "$benchmark_path" ]')
        ->and($script)->toContain('No benchmark directory found; skipping benchmark run.')
        ->and($script)->toContain('benchmark_status=skipped');
});
