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
        ->and($workflow['jobs']['svg-report']['if'] ?? null)
        ->toContain("needs.prepare.outputs.has_supported_php_versions == 'true'");
});
