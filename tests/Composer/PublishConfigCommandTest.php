<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\PublishConfigCommand;
use Symfony\Component\Console\Output\BufferedOutput;

it('ships strict comment policy without tightening unrelated PHPProbe detectors', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpprobe.json');
    $config = is_string($contents) ? json_decode($contents, true) : null;

    expect($config)->toBe([
        'preset' => 'standard',
        'duplicates' => [
            'fail_on' => 'error',
            'error_duplicate_percentage' => 10,
        ],
        'commented_out_code' => ['policy' => 'strict'],
    ]);
});

function removePublishConfigCommandTree(string $path): void
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

it('applies strict phpprobe preset to bundled config json', function (): void {
    $command = new PublishConfigCommand();
    $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpprobe.json');
    $method = new ReflectionMethod(PublishConfigCommand::class, 'applyPhpprobePreset');
    $result = $method->invoke($command, $source, 'strict');

    $decoded = is_string($result) ? json_decode($result, true) : null;

    expect(is_array($decoded))->toBeTrue();
    expect($decoded['preset'] ?? null)->toBe('strict');
});

it('returns null when phpprobe config content is invalid json', function (): void {
    $command = new PublishConfigCommand();
    $method = new ReflectionMethod(PublishConfigCommand::class, 'applyPhpprobePreset');
    $result = $method->invoke($command, '{broken', 'strict');

    expect($result)->toBeNull();
});

it('throws for unknown phpprobe preset definitions', function (): void {
    $method = new ReflectionMethod(PublishConfigCommand::class, 'phpprobePreset');

    expect(fn () => $method->invoke(null, 'nope'))->toThrow(InvalidArgumentException::class);
});

it('rejects unsupported file selections for publish-config', function (): void {
    $command = new PublishConfigCommand();
    $method = new ReflectionMethod(PublishConfigCommand::class, 'validatedFiles');
    $output = new BufferedOutput();

    $result = $method->invoke($command, ['../outside.txt'], $output);

    expect($result)->toBeNull()
        ->and($output->fetch())->toContain('Invalid config file selection');
});

it('fails publish when target config path is not writable', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-publish-config-'.uniqid('', true);
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';
    $targetAsDirectory = $projectRoot.DIRECTORY_SEPARATOR.'phpprobe.json';
    $source = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpprobe.json';

    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    copy($source, $vendorResources.DIRECTORY_SEPARATOR.'phpprobe.json');
    mkdir($targetAsDirectory, 0755, true);

    chdir($projectRoot);

    try {
        $command = new PublishConfigCommand();
        $method = new ReflectionMethod(PublishConfigCommand::class, 'publishFile');
        $output = new BufferedOutput();

        set_error_handler(static fn (): bool => true);

        try {
            $published = $method->invoke($command, 'phpprobe.json', true, '', $output);
        } finally {
            restore_error_handler();
        }

        expect($published)->toBeFalse()
            ->and($output->fetch())->toContain('Unable to write config file');
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePublishConfigCommandTree($projectRoot);
    }
});

it('publishes the custom forbidden-functions sniff with PHPCS config', function (): void {
    $originalCwd = getcwd();
    $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-publish-phpcs-'.uniqid('', true);
    $vendorResources = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';
    $source = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpcs.xml.dist';

    mkdir($vendorResources, 0755, true);
    file_put_contents($projectRoot.DIRECTORY_SEPARATOR.'composer.json', '{"name":"example/project"}');
    copy($source, $vendorResources.DIRECTORY_SEPARATOR.'phpcs.xml.dist');
    chdir($projectRoot);

    try {
        $command = new PublishConfigCommand();
        $method = new ReflectionMethod(PublishConfigCommand::class, 'publishFile');
        $output = new BufferedOutput();
        $published = $method->invoke($command, 'phpcs.xml.dist', false, '', $output);

        expect($published)->toBeTrue()
            ->and(is_file($projectRoot.'/phpcs.xml.dist'))->toBeTrue()
            ->and(is_file($projectRoot.'/PHPForge/Sniffs/PHP/ForbiddenFunctionsSniff.php'))->toBeTrue();
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removePublishConfigCommandTree($projectRoot);
    }
});
