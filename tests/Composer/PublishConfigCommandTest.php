<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\PublishConfigCommand;

it('applies strict phpprobe preset to bundled config json', function (): void {
    $command = new PublishConfigCommand();
    $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpprobe.json');
    $method = new ReflectionMethod(PublishConfigCommand::class, 'applyPhpprobePreset');
    $method->setAccessible(true);
    $result = $method->invoke($command, $source, 'strict');

    $decoded = is_string($result) ? json_decode($result, true) : null;

    expect(is_array($decoded))->toBeTrue();
    expect($decoded['preset'] ?? null)->toBe('strict');
});

it('normalizes legacy phpprobe preset aliases to current preset names', function (): void {
    $command = new PublishConfigCommand();
    $source = '{"preset":"standard"}';
    $method = new ReflectionMethod(PublishConfigCommand::class, 'applyPhpprobePreset');
    $method->setAccessible(true);

    $phpstorm = $method->invoke($command, $source, 'phpstorm');
    $legacyStandard = $method->invoke($command, $source, 'legacy-standard');

    expect(is_string($phpstorm) ? json_decode($phpstorm, true)['preset'] ?? null : null)->toBe('standard');
    expect(is_string($legacyStandard) ? json_decode($legacyStandard, true)['preset'] ?? null : null)->toBe('ci');
});

it('returns null when phpprobe config content is invalid json', function (): void {
    $command = new PublishConfigCommand();
    $method = new ReflectionMethod(PublishConfigCommand::class, 'applyPhpprobePreset');
    $method->setAccessible(true);
    $result = $method->invoke($command, '{broken', 'strict');

    expect($result)->toBeNull();
});

it('throws for unknown phpprobe preset definitions', function (): void {
    $method = new ReflectionMethod(PublishConfigCommand::class, 'phpprobePreset');
    $method->setAccessible(true);

    expect(fn () => $method->invoke(null, 'nope'))->toThrow(InvalidArgumentException::class);
});
