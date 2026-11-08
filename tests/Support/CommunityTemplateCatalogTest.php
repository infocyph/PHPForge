<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\CommunityTemplateCatalog;

it('defines publish pairs for community templates', function (): void {
    $pairs = CommunityTemplateCatalog::publishPairs();

    expect($pairs)->toHaveCount(17);

    foreach ($pairs as $pair) {
        expect($pair['target_relative'])->toMatch('/\S/')
            ->and($pair['target_relative'])->not()->toBe('')
            ->and(is_file($pair['source']))->toBeTrue();
    }
});

it('keeps repository community files synchronized with their canonical resources', function (): void {
    $projectRoot = dirname(__DIR__, 2);

    foreach (CommunityTemplateCatalog::files() as $targetRelative => $sourceRelative) {
        $source = fixtureFile($projectRoot . DIRECTORY_SEPARATOR . $sourceRelative);
        $target = fixtureFile($projectRoot . DIRECTORY_SEPARATOR . $targetRelative);

        expect($source)->not->toBeFalse()
            ->and($target)->toBe($source);
    }
});
