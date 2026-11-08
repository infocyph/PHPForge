<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$projectRoot = getcwd();

if ($projectRoot === false) {
    throw new RuntimeException('Unable to resolve the project working directory for Rector.');
}

return RectorConfig::configure()
    ->withPaths([$projectRoot])
    ->withSkip([
        $projectRoot . '/vendor',
        $projectRoot . '/node_modules',
        $projectRoot . '/coverage',
        $projectRoot . '/.phpunit.cache',
        $projectRoot . '/.psalm-cache',
        $projectRoot . '/build',
        $projectRoot . '/dist',
        $projectRoot . '/tmp',
        $projectRoot . '/.tmp',
        $projectRoot . '/storage',
        $projectRoot . '/bootstrap/cache',
        $projectRoot . '/var/cache',
        $projectRoot . '/tests',
        $projectRoot . '/resources',
        $projectRoot . '/bin',
        $projectRoot . '/benchmarks',
        $projectRoot . '/examples',
    ])
    ->withPreparedSets(deadCode: true)
    ->withPhpSets();
