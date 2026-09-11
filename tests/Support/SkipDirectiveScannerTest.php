<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\SkipDirectiveScanner;

function removeSkipDirectiveScannerTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());

            continue;
        }

        unlink($entry->getPathname());
    }

    rmdir($path);
}

function skipDirectiveScannerRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-skipper-'.bin2hex(random_bytes(6));
    mkdir($root.DIRECTORY_SEPARATOR.'src', 0755, true);

    return $root;
}

it('finds inline quality suppressions and explicit test skips', function (): void {
    $root = skipDirectiveScannerRoot();
    file_put_contents($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Suppressed.php', <<<'PHP'
<?php

// @phpstan-ignore-next-line
$missing = $undefined;
/** @psalm-suppress UndefinedVariable */
$other = $undefined;
/** @psalm-taint-escape html */
$escaped = $input;
// phpcs:disable Generic.Files.LineLength
/** @phan-file-suppress PhanUndeclaredVariable */
/** @SuppressWarnings(PHPMD.UnusedLocalVariable) */
/** @noinspection PhpUndefinedVariableInspection */
/** @codeCoverageIgnore */
/** @infection-ignore-return-value */
/** @noRector */
function ignored(): void {}

#[PHPUnit\Framework\Attributes\RequiresPhpExtension('redis')]
function conditionalTest(): void {}

#[PHPUnit\Framework\Attributes\IgnoreDeprecations]
function mutedTest(): void {}

#[PHPUnit\Framework\Attributes\IgnorePhpunitWarnings]
function mutedPhpunitWarning(): void {}

#[PHPUnit\Framework\Attributes\CoversNothing]
function uncoveredTest(): void {}

$this->markTestSkipped('not ready');
self::markTestIncomplete('not implemented');
it('runs')->skipOnCi();
test('pending')->with([1])->todo();
pest()->only();
todo('not implemented');
PHP);

    try {
        $scan = (new SkipDirectiveScanner())->scan($root);
        $tools = array_values(array_unique(array_column($scan['findings'], 'tool')));
        $directives = array_column($scan['findings'], 'directive');

        expect($scan['errors'])->toBe([])
            ->and($scan['files'])->toBe(1)
            ->and($tools)->toBe([
                'Infection',
                'PHPCS',
                'PHPMD',
                'PHPStan',
                'PHPUnit',
                'Pest',
                'Phan',
                'PhpStorm',
                'Psalm',
                'Rector',
            ])
            ->and($directives)->toContain('@phpstan-ignore-next-line')
            ->toContain('@psalm-suppress')
            ->toContain('phpcs:disable')
            ->toContain('#[RequiresPhpExtension]')
            ->toContain('markTestSkipped()')
            ->toContain('->skipOnCi()')
            ->toContain('->todo()')
            ->toContain('->only()')
            ->toContain('todo()')
            ->toContain('@noRector');
    } finally {
        removeSkipDirectiveScannerTree($root);
    }
});

it('ignores directive-shaped strings, unrelated methods, dependencies, and generated workflow sources', function (): void {
    $root = skipDirectiveScannerRoot();
    mkdir($root.DIRECTORY_SEPARATOR.'vendor', 0755, true);
    mkdir($root.DIRECTORY_SEPARATOR.'.phpforge-workflow', 0755, true);
    mkdir($root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache', 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Clean.php', <<<'PHP'
<?php

$examples = ['@phpstan-ignore', '@psalm-suppress', 'phpcs:ignore'];
$queue->skip();
$task->todo();
PHP);
    file_put_contents($root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'Ignored.php', "<?php\n// @phpstan-ignore-line\n");
    file_put_contents($root.DIRECTORY_SEPARATOR.'.phpforge-workflow'.DIRECTORY_SEPARATOR.'Ignored.php', "<?php\n/** @psalm-suppress all */\n");
    file_put_contents($root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'Ignored.php', "<?php\n// phpcs:ignore\n");

    try {
        $scan = (new SkipDirectiveScanner())->scan($root);

        expect($scan['errors'])->toBe([])
            ->and($scan['files'])->toBe(1)
            ->and($scan['findings'])->toBe([]);
    } finally {
        removeSkipDirectiveScannerTree($root);
    }
});

it('groups actionable findings by tool and fails closed for an unreadable root', function (): void {
    $scanner = new SkipDirectiveScanner();
    $output = $scanner->format([
        'files' => 2,
        'findings' => [[
            'file' => 'src/Example.php',
            'line' => 12,
            'tool' => 'PHPStan',
            'directive' => '@phpstan-ignore',
        ]],
        'errors' => [],
    ]);
    $invalid = $scanner->scan(sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-'.bin2hex(random_bytes(6)));

    expect($output)->toContain('Skip directive scan failed: 1 finding(s) in 2 PHP file(s).')
        ->toContain('PHPStan')
        ->toContain('src/Example.php:12  @phpstan-ignore')
        ->toContain('Resolve the underlying issue')
        ->and($invalid['errors'])->toHaveCount(1)
        ->and($scanner->format($invalid))->toContain('Scanner errors');
});
