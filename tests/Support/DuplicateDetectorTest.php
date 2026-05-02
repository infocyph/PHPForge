<?php

declare(strict_types=1);

it('detects fuzzy token duplicates across php files', function (): void {
    $root = makeDuplicateDetectorFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Alpha.php', <<<'PHP'
<?php

final class Alpha
{
    public function render(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = strtoupper((string) $item);
        }

        return $result;
    }
}
PHP);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Beta.php', <<<'PHP'
<?php

final class Beta
{
    public function build(array $values): array
    {
        $output = [];
        foreach ($values as $value) {
            $output[] = strtoupper((string) $value);
        }

        return $output;
    }
}
PHP);

    try {
        $run = runDuplicateDetectorCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', 'src']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    expect($run['exitCode'])->toBe(1);

    $result = json_decode($run['stdout'], true);

    expect($result['clones'])->toHaveCount(1)
        ->and($result['clones'][0]['occurrences'])->toHaveCount(2)
        ->and($result['duplicated_lines'])->toBeGreaterThanOrEqual(10);
});

it('passes when no duplicate reaches the configured threshold', function (): void {
    $root = makeDuplicateDetectorFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);

    try {
        $run = runDuplicateDetectorCommand($root, ['--json', '--min-lines=5', '--min-tokens=20', 'src']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('detects near-miss block clones in audit mode', function (): void {
    $root = makeDuplicateDetectorFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'NearMiss.php', <<<'PHP'
<?php

function first(array $items): array
{
    $result = [];
    foreach ($items as $item) {
        $result[] = trim((string) $item);
    }
    sort($result);

    return $result;
}

function second(array $values): array
{
    $output = [];
    foreach ($values as $value) {
        $output[] = trim((string) $value);
    }
    $output[] = 'extra';
    sort($output);

    return $output;
}
PHP);

    try {
        $run = runDuplicateDetectorCommand($root, ['--json', '--near-miss', '--min-lines=5', '--min-tokens=999', '--min-statements=3', '--min-similarity=0.60', 'src']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['clones'])->not()->toBeEmpty()
        ->and(array_column($result['clones'], 'source'))->toContain('near_miss');
});

it('can write and use a duplicate baseline', function (): void {
    $root = makeDuplicateDetectorFixture();
    $src = $root.DIRECTORY_SEPARATOR.'src';
    $baseline = $root.DIRECTORY_SEPARATOR.'duplicates-baseline.json';

    mkdir($src, 0755, true);
    file_put_contents($src.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($src.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $write = runDuplicateDetectorCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--write-baseline='.$baseline, 'src']);
        $check = runDuplicateDetectorCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', '--baseline='.$baseline, 'src']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    $writeResult = json_decode($write['stdout'], true);
    $checkResult = json_decode($check['stdout'], true);

    expect($write['exitCode'])->toBe(0)
        ->and($writeResult['clones'])->not()->toBeEmpty()
        ->and($check['exitCode'])->toBe(0)
        ->and($checkResult['clones'])->toBe([]);
});

it('loads duplicate options and paths from phpforge config', function (): void {
    $root = makeDuplicateDetectorFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';

    mkdir($configured, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpforge.json', json_encode([
        'duplicates' => [
            'paths' => ['configured'],
            'fuzzy' => true,
            'min_lines' => 5,
            'min_tokens' => 20,
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $run = runDuplicateDetectorCommand($root, ['--json']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(1)
        ->and($result['clones'])->not()->toBeEmpty();
});

it('supports excluding duplicate paths from phpforge config', function (): void {
    $root = makeDuplicateDetectorFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpforge.json', json_encode([
        'duplicates' => [
            'paths' => ['configured'],
            'exclude' => ['configured/excluded'],
            'fuzzy' => true,
            'min_lines' => 5,
            'min_tokens' => 20,
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $run = runDuplicateDetectorCommand($root, ['--json']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('supports excluding duplicate paths from CLI arguments', function (): void {
    $root = makeDuplicateDetectorFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $excluded = $configured.DIRECTORY_SEPARATOR.'excluded';

    mkdir($configured, 0755, true);
    mkdir($excluded, 0755, true);
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($excluded.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));

    try {
        $run = runDuplicateDetectorCommand($root, ['--json', '--fuzzy', '--min-lines=5', '--min-tokens=20', 'configured', '--exclude=configured/excluded']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

it('lets duplicate command paths override phpforge config paths', function (): void {
    $root = makeDuplicateDetectorFixture();
    $configured = $root.DIRECTORY_SEPARATOR.'configured';
    $explicit = $root.DIRECTORY_SEPARATOR.'explicit';

    mkdir($configured, 0755, true);
    mkdir($explicit, 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpforge.json', json_encode([
        'duplicates' => [
            'paths' => ['configured'],
            'fuzzy' => true,
            'min_lines' => 5,
            'min_tokens' => 20,
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'One.php', duplicateBaselineFixture('One'));
    file_put_contents($configured.DIRECTORY_SEPARATOR.'Two.php', duplicateBaselineFixture('Two'));
    file_put_contents($explicit.DIRECTORY_SEPARATOR.'Solo.php', <<<'PHP'
<?php

final class Solo
{
    public function value(): int
    {
        return 42;
    }
}
PHP);

    try {
        $run = runDuplicateDetectorCommand($root, ['--json', 'explicit']);
    } finally {
        removeDuplicateDetectorFixture($root);
    }

    $result = json_decode($run['stdout'], true);

    expect($run['exitCode'])->toBe(0)
        ->and($result['clones'])->toBe([]);
});

function duplicateBaselineFixture(string $class): string
{
    return <<<PHP
<?php

final class {$class}
{
    public function names(array \$items): array
    {
        \$result = [];
        foreach (\$items as \$item) {
            \$result[] = strtoupper((string) \$item);
        }

        return \$result;
    }
}
PHP;
}

function makeDuplicateDetectorFixture(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-duplicates-'.uniqid('', true);
    $resources = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'infocyph'.DIRECTORY_SEPARATOR.'phpforge'.DIRECTORY_SEPARATOR.'resources';

    mkdir($root, 0755, true);
    mkdir($resources, 0755, true);
    copy(
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'phpforge.json',
        $resources.DIRECTORY_SEPARATOR.'phpforge.json',
    );

    return $root;
}

function removeDuplicateDetectorFixture(string $root): void
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

/**
 * @param  list<string>  $args
 * @return array{exitCode:int,stdout:string,stderr:string}
 */
function runDuplicateDetectorCommand(string $cwd, array $args): array
{
    $binary = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpforge';
    $process = proc_open([PHP_BINARY, $binary, 'duplicates', ...$args], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start duplicate detector.');
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
