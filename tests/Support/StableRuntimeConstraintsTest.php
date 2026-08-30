<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\StableRuntimeConstraints;
use Composer\Semver\Semver;

/**
 * @param array<string, mixed> $manifest
 */
function writePhpforgeComposerManifest(array $manifest): string
{
    $path = tempnam(sys_get_temp_dir(), 'phpforge-composer-');
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    return $path;
}

it('requires the PHP 8.5 array helper polyfill used by analyzers on PHP 8.4', function (): void {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $constraint = (string) ($composer['require']['symfony/polyfill-php85'] ?? '');

    expect(Semver::satisfies('1.32.0', $constraint))->toBeFalse()
        ->and(Semver::satisfies('1.33.0', $constraint))->toBeTrue();
});

it('accepts stable tagged runtime ranges and platform wildcards', function (): void {
    $path = writePhpforgeComposerManifest([
        'require' => [
            'php' => '>=8.4',
            'ext-json' => '*',
            'vendor/library' => '^2.0 || ^3.0',
        ],
    ]);

    try {
        expect((new StableRuntimeConstraints())->violations($path))->toBe([]);
    } finally {
        unlink($path);
    }
});

it('rejects development stability and non-stable runtime references', function (): void {
    $path = writePhpforgeComposerManifest([
        'minimum-stability' => 'dev',
        'require' => [
            'vendor/branch' => 'dev-main',
            'vendor/alias' => 'dev-next as 2.0.x-dev',
            'vendor/flag' => '^2.0@beta',
            'vendor/prerelease' => '2.0.0-RC1',
            'vendor/commit' => 'dev-main#0123456789abcdef',
        ],
    ]);

    try {
        $violations = implode("\n", (new StableRuntimeConstraints())->violations($path));

        expect($violations)
            ->toContain('minimum-stability')
            ->toContain('vendor/branch')
            ->toContain('vendor/alias')
            ->toContain('vendor/flag')
            ->toContain('vendor/prerelease')
            ->toContain('vendor/commit');
    } finally {
        unlink($path);
    }
});
