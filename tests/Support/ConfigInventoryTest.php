<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ConfigInventory;
use Symfony\Component\Process\Process;

function removeConfigInventoryTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}

it('lists bundled config files without duplicates', function (): void {
    expect(ConfigInventory::files())
        ->toContain('pest.xml')
        ->toContain('phpunit.xml')
        ->toContain('phpprobe.json')
        ->toContain('pint.json')
        ->toContain('phpstan.neon.dist')
        ->toContain('psalm.xml')
        ->toContain('captainhook.json')
        ->toContain('deptrac.yaml');

    expect(ConfigInventory::files())->toBe(array_values(array_unique(ConfigInventory::files())));
});

it('only lists config files that have bundled resources', function (): void {
    foreach (ConfigInventory::files() as $file) {
        expect(ConfigInventory::resolvedPath($file))
            ->not->toBe('')
            ->and(is_file(ConfigInventory::resolvedPath($file)))->toBeTrue();
    }
});

it('keeps the bundled deptrac config project agnostic', function (): void {
    $contents = file_get_contents(ConfigInventory::resolvedPath('deptrac.yaml'));

    expect($contents)->toBeString()
        ->and($contents)->not->toContain('Infocyph\\\\PHPForge')
        ->and($contents)->toContain('type: directory');
});

it('keeps forbidden-function checks active in executable PHP entrypoints', function (): void {
    $contents = file_get_contents(ConfigInventory::resolvedPath('phpcs.xml.dist'));
    $sniff = file_get_contents(dirname(__DIR__, 2).'/resources/PHPForge/Sniffs/PHP/ForbiddenFunctionsSniff.php');

    expect($contents)->toBeString()
        ->not->toContain('<exclude-pattern type="relative">bin/*.php</exclude-pattern>')
        ->not->toContain('<exclude-pattern type="relative">.github/scripts/*.php</exclude-pattern>')
        ->toContain('./PHPForge/Sniffs/PHP/ForbiddenFunctionsSniff.php')
        ->and($sniff)->toBeString()
        ->toContain("tokens[\$stackPtr]['code'] === T_EXIT")
        ->toContain("#!/usr/bin/env php");
});

it('allows exit only for executable PHP entrypoints', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $directory = $projectRoot.DIRECTORY_SEPARATOR.'.phpcs-fixture-'.uniqid('', true);
    mkdir($directory, 0755, true);

    $check = static function (string $contents, string $name) use ($directory, $projectRoot): int {
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents);
        $process = new Process([
            PHP_BINARY,
            $projectRoot.'/vendor/bin/phpcs',
            '--standard='.$projectRoot.'/resources/phpcs.xml.dist',
            $path,
        ]);
        $process->run();

        return $process->getExitCode() ?? 1;
    };

    try {
        expect($check("#!/usr/bin/env php\n<?php\nexit(1);\n", 'entrypoint.php'))->toBe(0)
            ->and($check("<?php\nexit(1);\n", 'library.php'))->not->toBe(0)
            ->and($check("#!/usr/bin/env php\n<?php\neval('return 1;');\n", 'unsafe-entrypoint.php'))->not->toBe(0);
    } finally {
        removeConfigInventoryTree($directory);
    }
});

it('captures every PHP error level in bundled test configurations', function (): void {
    foreach (['pest.xml', 'phpunit.xml'] as $file) {
        $contents = file_get_contents(ConfigInventory::resolvedPath($file));

        expect($contents)
            ->toBeString()
            ->toContain('<ini name="error_reporting" value="-1"/>');
    }
});

it('lets Rector derive the target PHP version from the project contract', function (): void {
    $contents = file_get_contents(ConfigInventory::resolvedPath('rector.php'));

    expect($contents)
        ->toBeString()
        ->toContain('->withPhpSets()')
        ->not->toContain('->withPhpVersion(')
        ->not->toContain('PHP_MAJOR_VERSION');
});

it('reports project config sources before bundled sources', function (): void {
    expect(ConfigInventory::source('pint.json'))->toBe('phpforge');
    expect(ConfigInventory::source('missing-tool.xml'))->toBe('missing');
});

it('treats phpprobe.json as the project source when present', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-config-inventory-'.uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'phpprobe.json', '{}');

    chdir($projectRoot);

    try {
        expect(ConfigInventory::source('phpprobe.json'))->toBe('project');
        expect(ConfigInventory::resolvedPath('phpprobe.json'))->toBe($projectRoot.DIRECTORY_SEPARATOR.'phpprobe.json');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeConfigInventoryTree($projectRoot);
    }
});
