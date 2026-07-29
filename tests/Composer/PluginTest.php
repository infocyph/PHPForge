<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\IO\BufferIO;
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
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-plugin-' . uniqid('', true);
    $vendorResources = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'infocyph' . DIRECTORY_SEPARATOR . 'phpforge' . DIRECTORY_SEPARATOR . 'resources';
    $vendorBin = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin';
    $bundledConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'captainhook.json';

    mkdir($vendorResources, 0755, true);
    mkdir($vendorBin, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    copy($bundledConfig, $vendorResources . DIRECTORY_SEPARATOR . 'captainhook.json');
    file_put_contents($vendorBin . DIRECTORY_SEPARATOR . 'captainhook', "<?php\nexit(0);\n");

    chdir($projectRoot);

    try {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, new Composer(), new NullIO(), true);
        $plugin = new Plugin();
        $projectConfig = $projectRoot . DIRECTORY_SEPARATOR . 'captainhook.json';

        expect(fn() => $plugin->installHooks($event))->not
            ->toThrow(RuntimeException::class)
            ->and(is_file($projectConfig))
            ->toBeTrue()
            ->and(file_get_contents($projectConfig))
            ->toBe(
                file_get_contents($bundledConfig),
            )
            ->and(is_dir($projectRoot . DIRECTORY_SEPARATOR . '.codex'))
            ->toBeFalse();
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});

it('does not publish or refresh the engineering skill on install hook run', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-plugin-' . uniqid('', true);
    $vendorResources = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'infocyph' . DIRECTORY_SEPARATOR . 'phpforge' . DIRECTORY_SEPARATOR . 'resources';
    $vendorBin = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin';
    $skillTarget = $projectRoot . DIRECTORY_SEPARATOR . '.codex' . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'phpforge-engineering' . DIRECTORY_SEPARATOR . 'SKILL.md';

    mkdir($vendorResources, 0755, true);
    mkdir($vendorBin, 0755, true);
    mkdir(dirname($skillTarget), 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'captainhook.json', '{}');
    copy(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'captainhook.json', $vendorResources . DIRECTORY_SEPARATOR . 'captainhook.json');
    file_put_contents($vendorBin . DIRECTORY_SEPARATOR . 'captainhook', "<?php\nexit(0);\n");
    file_put_contents($skillTarget, "old contents\n");

    chdir($projectRoot);

    try {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, new Composer(), new NullIO(), true);
        $plugin = new Plugin();

        expect(fn() => $plugin->installHooks($event))->not->toThrow(RuntimeException::class);
        expect(file_get_contents($skillTarget))->toBe("old contents\n");
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});

it('keeps strict hook installation when project captainhook config exists', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-plugin-' . uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'captainhook.json', '{}');

    chdir($projectRoot);

    try {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, new Composer(), new NullIO(), true);
        $plugin = new Plugin();

        expect(fn() => $plugin->installHooks($event))->toThrow(RuntimeException::class);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});

it('skips dev-only hook installation during no-dev autoload dumps', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-plugin-' . uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . 'captainhook.json', '{}');

    chdir($projectRoot);

    try {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, new Composer(), new NullIO(), false);
        $plugin = new Plugin();

        expect(fn() => $plugin->installHooks($event))->not->toThrow(RuntimeException::class);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});

it('reports only Composer plugins that are not explicitly allowed', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-plugin-' . uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'composer.json',
        json_encode([
            'config' => [
                'allow-plugins' => [
                    'infocyph/phpforge' => true,
                    'ergebnis/composer-normalize' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    );

    chdir($projectRoot);

    try {
        $io = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate(new Composer(), $io);
        $output = $io->getOutput();

        expect($output)
            ->not->toContain('allow-plugins.infocyph/phpforge')
            ->toContain('allow-plugins.ergebnis/composer-normalize true')
            ->toContain('allow-plugins.pestphp/pest-plugin true');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});

it('does not report recommendations when all Composer plugins are allowed', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-plugin-' . uniqid('', true);

    mkdir($projectRoot, 0755, true);
    file_put_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'composer.json',
        '{"config":{"allow-plugins":true}}',
    );

    chdir($projectRoot);

    try {
        $io = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate(new Composer(), $io);

        expect($io->getOutput())->toBe('');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePluginTestTree($projectRoot);
    }
});
