<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\PublishConfigCommand;

it('applies strict phpprobe preset overrides to bundled config json', function (): void {
    $command = new PublishConfigCommand();
    $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpprobe.json');
    $method = new ReflectionMethod(PublishConfigCommand::class, 'applyPhpprobePreset');
    $method->setAccessible(true);
    $result = $method->invoke($command, $source, 'strict');

    $decoded = is_string($result) ? json_decode($result, true) : null;

    expect(is_array($decoded))->toBeTrue();
    expect($decoded['duplicates']['mode'] ?? null)->toBe('audit');
    expect($decoded['duplicates']['near_miss'] ?? null)->toBeTrue();
    expect($decoded['duplicates']['min_lines'] ?? null)->toBe(4);
    expect($decoded['duplicates']['min_tokens'] ?? null)->toBe(70);
    expect($decoded['duplicates']['min_statements'] ?? null)->toBe(3);
    expect($decoded['duplicates']['min_similarity'] ?? null)->toBe(0.80);
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
