<?php

declare(strict_types=1);

use Composer\Semver\Semver;
use Infocyph\PHPForge\Support\StableRuntimeConstraints;

/**
 * @param array<string, mixed> $manifest
 *
 * @throws RuntimeException
 */
function writePhpforgeComposerManifest(array $manifest): string
{
    $path = fixtureTempFile('phpforge-composer-');
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    return $path;
}

it('requires the PHP 8.5 array helper polyfill used by analyzers on PHP 8.4', function (): void {
    $composer = json_decode(
        fixtureFile(dirname(__DIR__, 2) . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $lock = json_decode(
        fixtureFile(dirname(__DIR__, 2) . '/composer.lock'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $constraint = fixtureString(fixtureValue($composer, ['require', 'symfony/polyfill-php85'], true) ?? '');
    $lockedVersion = '';

    foreach ([...fixtureArray(fixtureValue($lock, ['packages'])), ...fixtureArray(fixtureValue($lock, ['packages-dev']))] as $package) {
        if ((fixtureValue($package, ['name'], true) ?? '') === 'symfony/polyfill-php85') {
            $lockedVersion = fixtureString(fixtureValue($package, ['version'], true) ?? '');

            break;
        }
    }

    expect($constraint)->not->toBeEmpty()
        ->and($lockedVersion)->not->toBeEmpty()
        ->and(Semver::satisfies('1.32.0', $constraint))->toBeFalse()
        ->and(Semver::satisfies($lockedVersion, $constraint))->toBeTrue();
});

it('requires PHPProbe 1.3 or newer within the current major version', function (): void {
    $composer = json_decode(
        fixtureFile(dirname(__DIR__, 2) . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $constraint = fixtureString(fixtureValue($composer, ['require', 'infocyph/phpprobe'], true) ?? '');

    expect($constraint)->not->toBeEmpty()
        ->and(Semver::satisfies('1.3.1', $constraint))->toBeTrue();
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
