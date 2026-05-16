<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\CommunityTemplateCatalog;

it('defines publish pairs for community templates', function (): void {
    $pairs = CommunityTemplateCatalog::publishPairs();

    expect($pairs)->toHaveCount(11);

    foreach ($pairs as $pair) {
        expect(is_string($pair['target_relative']))->toBeTrue()
            ->and($pair['target_relative'])->not()->toBe('')
            ->and(is_file($pair['source']))->toBeTrue();
    }
});
