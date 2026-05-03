<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\IO\NullIO;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Infocyph\PHPForge\Composer\Plugin;

function removePluginTestTree(string $path): void
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

it('copies bundled captainhook config into project root when missing', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-plugin-'.uniqid('', true);
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';
    $vendorBin = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin';
    $bundledConfig = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'captainhook.json';

    mkdir($vendorResources, 0755, true);
    mkdir($vendorBin, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    copy($bundledConfig, $vendorResources.DIRECTORY_SEPARATOR.'captainhook.json');
    file_put_contents($vendorBin.DIRECTORY_SEPARATOR.'captainhook', "<?php\nexit(0);\n");

    chdir($projectRoot);

    try {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, new Composer(), new NullIO());
        $plugin = new Plugin();
        $projectConfig = $projectRoot.DIRECTORY_SEPARATOR.'captainhook.json';

        expect(fn () => $plugin->installHooks($event))->not->toThrow(RuntimeException::class);
        expect(is_file($projectConfig))->toBeTrue();
        expect(file_get_contents($projectConfig))->toBe(file_get_contents($bundledConfig));
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});

it('keeps strict hook installation when project captainhook config exists', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-plugin-'.uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'captainhook.json', '{}');

    chdir($projectRoot);

    try {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, new Composer(), new NullIO());
        $plugin = new Plugin();

        expect(fn () => $plugin->installHooks($event))->toThrow(RuntimeException::class);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});
