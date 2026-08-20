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

it('exposes the compact service controls in the project workflow', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/phpforge.yml');
    $template = Yaml::parseFile($root.'/resources/workflows/security-standards.yml');
    $expected = [
        'integration_services' => '[]',
        'service_topologies' => '{}',
    ];

    expect($workflow['jobs']['security-standards']['with'] ?? null)->toBe($expected)
        ->and($template['jobs']['phpforge']['with'] ?? null)->toBe($expected);
});

it('installs dependencies before one analyzer execution produces gates and sarif', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security-standards.yml');
    $steps = $workflow['jobs']['analyze']['steps'] ?? [];
    $stepsByName = array_column($steps, null, 'name');
    $stepNames = array_column($steps, 'name');

    $installIndex = array_search('Install dependencies', $stepNames, true);
    $analyzerIndex = array_search('Run audit and analyzers once', $stepNames, true);
    $analyzerScript = $stepsByName['Run audit and analyzers once']['run'] ?? '';

    expect($installIndex)->toBeInt()
        ->and($analyzerIndex)->toBeInt()->toBeGreaterThan($installIndex)
        ->and(substr_count($analyzerScript, 'bin/phpstan'))
        ->toBe(1)
        ->and(substr_count($analyzerScript, 'bin/psalm.phar'))
        ->toBe(1)
        ->and($analyzerScript)->toContain('--threads=1')
        ->and($analyzerScript)->toContain('phpstan-results.sarif')
        ->and($analyzerScript)->toContain('psalm-results.sarif');
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
