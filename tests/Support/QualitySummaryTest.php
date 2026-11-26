<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\QualitySummary;

it('keeps the previous quality report intact when a result cannot be encoded', function (): void {
    $directory = sys_get_temp_dir() . '/phpforge-summary-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $path = $directory . '/summary.json';
    $previous = getenv('PHPFORGE_QUALITY_SUMMARY');
    putenv('PHPFORGE_QUALITY_SUMMARY=' . $path);

    try {
        QualitySummary::write([['heading' => 'Custom Analyzer', 'status' => 'PASS', 'exit_code' => 0]]);
        $contents = fixtureFile($path);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        expect(fixtureValue($decoded, ['overall']))->toBe('success')
            ->and(fixtureValue($decoded, ['tools', 0, 'tool']))->toBe('custom_analyzer')
            ->and(fn() => QualitySummary::write([['heading' => "\xFF", 'status' => 'FAIL', 'exit_code' => 1]]))
            ->toThrow(JsonException::class)
            ->and(fixtureFile($path))->toBe($contents);
    } finally {
        putenv($previous === false ? 'PHPFORGE_QUALITY_SUMMARY' : 'PHPFORGE_QUALITY_SUMMARY=' . $previous);

        if (is_file($path)) {
            unlink($path);
        }

        rmdir($directory);
    }
});

it('accepts local report destinations and rejects stream wrappers', function (): void {
    $directory = sys_get_temp_dir() . '/phpforge-summary-' . bin2hex(random_bytes(8));
    $previousCwd = getcwd();
    $primary = getenv('PHPFORGE_QUALITY_SUMMARY');
    $alias = getenv('IC_QUALITY_SUMMARY');
    mkdir($directory, 0700);
    chdir($directory);

    try {
        putenv('PHPFORGE_QUALITY_SUMMARY');
        putenv('IC_QUALITY_SUMMARY=reports/summary.json');
        QualitySummary::write([]);
        expect(fixtureFile($directory . '/reports/summary.json'))->toContain('phpforge-quality-summary-v1');

        putenv('PHPFORGE_QUALITY_SUMMARY=file://' . $directory . '/wrapper.json');
        QualitySummary::write([]);
        expect(is_file($directory . '/wrapper.json'))->toBeFalse();
    } finally {
        putenv($primary === false ? 'PHPFORGE_QUALITY_SUMMARY' : 'PHPFORGE_QUALITY_SUMMARY=' . $primary);
        putenv($alias === false ? 'IC_QUALITY_SUMMARY' : 'IC_QUALITY_SUMMARY=' . $alias);
        chdir($previousCwd ?: dirname(__DIR__, 2));
        removeFixtureDirectory($directory);
    }
});
