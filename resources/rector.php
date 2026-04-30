<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

function resolveRectorPhpVersion(): ?int
{
    $reflection = new ReflectionClass(PhpVersion::class);
    $constants = $reflection->getConstants();
    $current = (PHP_MAJOR_VERSION * 10) + PHP_MINOR_VERSION;
    $candidates = [];

    foreach ($constants as $name => $value) {
        if (! is_int($value) || ! is_string($name) || ! str_starts_with($name, 'PHP_')) {
            continue;
        }

        $suffix = substr($name, 4);

        if ($suffix === false || $suffix === '' || ! ctype_digit($suffix)) {
            continue;
        }

        $versionId = (int) $suffix;

        if ($versionId <= $current) {
            $candidates[$versionId] = $value;
        }
    }

    if ($candidates === []) {
        return null;
    }

    ksort($candidates);

    return end($candidates) ?: null;
}

$config = RectorConfig::configure()
    ->withPaths([getcwd()])
    ->withSkip([
        getcwd().'/vendor',
        getcwd().'/node_modules',
        getcwd().'/coverage',
        getcwd().'/.phpunit.cache',
        getcwd().'/.psalm-cache',
        getcwd().'/build',
        getcwd().'/dist',
        getcwd().'/tmp',
        getcwd().'/.tmp',
        getcwd().'/storage',
        getcwd().'/bootstrap/cache',
        getcwd().'/var/cache',
        getcwd().'/tests',
        getcwd().'/resources',
        getcwd().'/bin',
        getcwd().'/benchmarks',
        getcwd().'/examples',
    ])
    ->withPreparedSets(deadCode: true)
    ->withPhpSets();

$resolvedPhpVersion = resolveRectorPhpVersion();

if (is_int($resolvedPhpVersion)) {
    $config = $config->withPhpVersion($resolvedPhpVersion);
}

return $config;
