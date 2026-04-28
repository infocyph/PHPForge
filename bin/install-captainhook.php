#!/usr/bin/env php
<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\CaptainHook;
use Infocyph\PHPForge\Support\Paths;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (getenv('CI') === 'true') {
    fwrite(STDOUT, 'Skipping CaptainHook install in CI environment.' . PHP_EOL);

    return;
}

$command = CaptainHook::installCommand(Paths::config('captainhook.json'));

$escapedCommand = implode(' ', array_map(
    static fn(string $argument): string => escapeshellarg($argument),
    $command,
));

passthru($escapedCommand, $exitCode);

if ($exitCode !== 0) {
    throw new RuntimeException(sprintf('CaptainHook install failed with exit code %d.', $exitCode));
}
