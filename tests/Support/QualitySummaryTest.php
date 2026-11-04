<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\QualitySummary;

it('keeps the previous quality report intact when a result cannot be encoded', function (): void {
    $directory = sys_get_temp_dir().'/phpforge-summary-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $path = $directory.'/summary.json';
    $previous = getenv('PHPFORGE_QUALITY_SUMMARY');
    putenv('PHPFORGE_QUALITY_SUMMARY='.$path);

    try {
        QualitySummary::write([['heading' => 'Custom Analyzer', 'status' => 'PASS', 'exit_code' => 0]]);
        $contents = file_get_contents($path);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded['overall'])->toBe('success')
            ->and($decoded['tools'][0]['tool'])->toBe('custom_analyzer')
            ->and(fn() => QualitySummary::write([['heading' => "\xFF", 'status' => 'FAIL', 'exit_code' => 1]]))
            ->toThrow(JsonException::class)
            ->and(file_get_contents($path))->toBe($contents);
    } finally {
        putenv($previous === false ? 'PHPFORGE_QUALITY_SUMMARY' : 'PHPFORGE_QUALITY_SUMMARY='.$previous);

        if (is_file($path)) {
            unlink($path);
        }

        rmdir($directory);
    }
});
