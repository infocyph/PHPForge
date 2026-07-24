<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
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
