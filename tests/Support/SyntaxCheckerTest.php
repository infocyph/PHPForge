<?php

declare(strict_types=1);

it('loads syntax paths from phpforge config', function (): void {
    $root = makeSyntaxCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpforge.json', json_encode([
        'syntax' => [
            'paths' => ['configured'],
            'exclude' => ['configured/excluded'],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Example.php', <<<'PHP'
<?php

final class Example
{
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Broken.php', <<<'PHP'
<?php

final class Broken
{
PHP);

    try {
        $run = runSyntaxCheckerCommand($root, []);
    } finally {
        removeSyntaxCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($run['stdout'])->toContain('Syntax OK: 1 PHP files checked.');
});

it('supports excluding syntax paths from CLI arguments', function (): void {
    $root = makeSyntaxCheckerFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Example.php', <<<'PHP'
<?php

final class Example
{
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Broken.php', <<<'PHP'
<?php

final class Broken
{
PHP);

    try {
        $run = runSyntaxCheckerCommand($root, ['configured', '--exclude=configured/excluded']);
    } finally {
        removeSyntaxCheckerFixture($root);
    }

    expect($run['exitCode'])->toBe(0)
        ->and($run['stdout'])->toContain('Syntax OK: 1 PHP files checked.');
});

/**
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runSyntaxCheckerCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpforge';
    $process = proc_open([PHP_BINARY, $binary, 'syntax', ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start syntax checker.');
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exitCode' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function makeSyntaxCheckerFixture(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-syntax-'.uniqid('', true);
    $resources = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';

    mkdir($root, 0755, true);
    mkdir($resources, 0755, true);
    copy(
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpforge.json',
        $resources.DIRECTORY_SEPARATOR.'phpforge.json',
    );

    return $root;
}

function removeSyntaxCheckerFixture(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($root);
}
