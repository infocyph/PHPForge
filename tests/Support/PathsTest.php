<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\ServiceCatalog;
use Symfony\Component\Process\Process;

function removePathsTestTree(string $path): void
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

it('loads installed package resources independently of the consumer working directory', function (string $vendorDirectory): void {
    $consumer = sys_get_temp_dir().'/phpforge-consumer-'.bin2hex(random_bytes(8));
    $package = $consumer.'/'.$vendorDirectory.'/infocyph/phpforge';
    $files = [
        'src/Support/ArrayShape.php',
        'src/Support/Paths.php',
        'src/Support/ServiceCatalog.php',
        'resources/runtime.php',
        'resources/services/catalog.php',
        'resources/psalm.xml',
    ];

    foreach ($files as $file) {
        $target = $package.'/'.$file;

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        copy(Paths::packageFile($file), $target);
    }

    file_put_contents($consumer.'/composer.json', json_encode([
        'name' => 'example/consumer',
        'config' => ['vendor-dir' => $vendorDirectory],
    ], JSON_THROW_ON_ERROR));
    mkdir($consumer.'/resources/services', 0755, true);
    file_put_contents($consumer.'/resources/services/catalog.php', '<?php throw new RuntimeException("Consumer resource selected");');

    try {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require $argv[1].'/src/Support/ArrayShape.php';
require $argv[1].'/src/Support/Paths.php';
require $argv[1].'/src/Support/ServiceCatalog.php';
$runtimeFile = Infocyph\PHPForge\Support\Paths::packageFile('resources/runtime.php');
$runtime = require $runtimeFile;
echo json_encode([
    'runtime_file' => $runtimeFile,
    'php_versions' => $runtime['php_versions'],
    'psalm_config' => Infocyph\PHPForge\Support\Paths::config('psalm.xml'),
    'services' => Infocyph\PHPForge\Support\ServiceCatalog::names(),
], JSON_THROW_ON_ERROR);
PHP, $package], $consumer);
        $process->setTimeout(30);
        $process->run();
        $runtime = require Paths::packageFile('resources/runtime.php');
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(str_replace('\\', '/', $result['runtime_file']))->toBe(str_replace('\\', '/', $package.'/resources/runtime.php'))
            ->and($result['php_versions'])->toBe($runtime['php_versions'])
            ->and(str_replace('\\', '/', $result['psalm_config']))->toBe(str_replace('\\', '/', $package.'/resources/psalm.xml'))
            ->and($result['services'])->toBe(ServiceCatalog::names());
    } finally {
        removePathsTestTree($consumer);
    }
})->with(['vendor', 'deps']);

it('falls back to bundled defaults when project config is missing', function (): void {
    expect(Paths::config('pint.json'))
        ->toBe(Paths::packageFile('resources/pint.json'));
});

it('falls back to bundled PHPProbe checker config', function (): void {
    expect(Paths::config('phpprobe.json'))
        ->toBe(Paths::packageFile('resources/phpprobe.json'));
});

it('uses bundled config when project config from list is missing', function (): void {
    expect(Paths::firstConfig(['pest.xml', 'phpunit.xml']))
        ->toBe(Paths::packageFile('resources/pest.xml'));
});

it('uses project config before vendor PHPForge resources', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';

    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'pint.json', '{}');
    file_put_contents($vendorResources.DIRECTORY_SEPARATOR.'pint.json', '{}');

    chdir($projectRoot);

    try {
        expect(Paths::config('pint.json'))->toBe($projectRoot.DIRECTORY_SEPARATOR.'pint.json');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('falls back to vendor PHPForge resources for consuming projects', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';

    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($vendorResources.DIRECTORY_SEPARATOR.'pint.json', '{}');

    chdir($projectRoot);

    try {
        expect(Paths::config('pint.json'))->toBe($vendorResources.DIRECTORY_SEPARATOR.'pint.json');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('prefers the vendor package captainhook config over bundled resources', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-paths-' . uniqid('', true);
    $vendorPackage = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'infocyph' . DIRECTORY_SEPARATOR . 'phpforge';
    $vendorResources = $vendorPackage . DIRECTORY_SEPARATOR . 'resources';
    $packageConfig = $vendorPackage . DIRECTORY_SEPARATOR . 'captainhook.json';

    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    file_put_contents($packageConfig, '{"source":"package"}');
    file_put_contents($vendorResources . DIRECTORY_SEPARATOR . 'captainhook.json', '{"source":"resources"}');
    chdir($projectRoot);

    try {
        expect(Paths::config('captainhook.json'))->toBe($packageConfig);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('hard fails for consuming projects when project and vendor configs are missing', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');

    chdir($projectRoot);

    try {
        expect(fn(): string => Paths::config('pint.json'))->toThrow(RuntimeException::class);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('ignores consuming-project resources and falls back to vendor PHPForge config', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $resourcesPath = $projectRoot.DIRECTORY_SEPARATOR.'resources';
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';
    $vendorConfig = $vendorResources.DIRECTORY_SEPARATOR.'deptrac.yaml';

    mkdir($resourcesPath, 0755, true);
    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($resourcesPath.DIRECTORY_SEPARATOR.'deptrac.yaml', 'project resources');
    file_put_contents($vendorConfig, 'vendor resources');

    chdir($projectRoot);

    try {
        expect(Paths::config('deptrac.yaml'))->toBe($vendorConfig);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('uses source-tree resources only for the PHPForge project itself', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-'.uniqid('', true);
    $resourcesPath = $projectRoot.DIRECTORY_SEPARATOR.'resources';
    $resourceFile = $resourcesPath.DIRECTORY_SEPARATOR.'pint.json';

    mkdir($resourcesPath, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"infocyph/phpforge"}');
    file_put_contents($resourceFile, '{}');

    chdir($projectRoot);

    try {
        expect(Paths::config('pint.json'))->toBe($resourceFile);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});

it('does not treat PHPForge source resources as project config', function (): void {
    expect(Paths::firstProjectConfig(['pest.xml', 'phpunit.xml']))->toBeNull();
});

it('returns null when no project-only config exists', function (): void {
    expect(Paths::firstProjectConfig(['missing-a.xml', 'missing-b.xml']))
        ->toBeNull();
});

it('resolves package files from the PHPForge package root', function (): void {
    expect(Paths::packageFile('bin/phpforge'))
        ->toEndWith(DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpforge');
});

it('resolves project root from subdirectories with composer.json ancestor', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-paths-subdir-'.uniqid('', true);
    $nested = $projectRoot.DIRECTORY_SEPARATOR.'a'.DIRECTORY_SEPARATOR.'b';
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';
    $configFile = $vendorResources.DIRECTORY_SEPARATOR.'pint.json';

    mkdir($nested, 0755, true);
    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($configFile, '{}');

    chdir($nested);

    try {
        expect(Paths::projectRootPath())->toBe($projectRoot)
            ->and(Paths::config('pint.json'))->toBe($configFile);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePathsTestTree($projectRoot);
    }
});
