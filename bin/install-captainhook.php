#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectConfig = getcwd() . DIRECTORY_SEPARATOR . 'captainhook.json';
$fallbackConfig = 'resources' . DIRECTORY_SEPARATOR . 'captainhook.json';
$configuration = is_file($projectConfig) ? 'captainhook.json' : $fallbackConfig;
$captainhook = 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'captainhook';

$command = sprintf(
    '%s %s install --configuration=%s --only-enabled -nf',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($captainhook),
    escapeshellarg($configuration),
);

passthru($command, $exitCode);

if ($exitCode !== 0) {
    throw new RuntimeException(sprintf('CaptainHook install failed with exit code %d.', $exitCode));
}
