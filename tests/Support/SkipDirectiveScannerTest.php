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
// @phpprobe-ignore commented_out_code_without_reason until=2026-12-31
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
                'PHPProbe',
                'PHPStan',
                'PHPUnit',
                'Pest',
                'Phan',
                'PhpStorm',
                'Psalm',
                'Rector',
            ])
            ->and($directives)->toContain('@phpstan-ignore-next-line')
            ->toContain('@phpprobe-ignore')
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

it('honors each active tool configuration without hiding findings from other tools', function (): void {
    $root = skipDirectiveScannerRoot();
    mkdir($root.DIRECTORY_SEPARATOR.'excluded', 0755, true);
    mkdir($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'ignored', 0755, true);
    mkdir($root.DIRECTORY_SEPARATOR.'rector-only', 0755, true);
    file_put_contents($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Visible.php', <<<'PHP'
<?php

// @probe-skip commented_out_code_without_reason until=2026-12-31
// phpcs:ignore
// @phpstan-ignore-line
/** @psalm-suppress UndefinedVariable */
PHP);
    file_put_contents($root.DIRECTORY_SEPARATOR.'excluded'.DIRECTORY_SEPARATOR.'Hidden.php', <<<'PHP'
<?php

// @probe-skip commented_out_code_without_reason until=2026-12-31
// phpcs:ignore
// @phpstan-ignore-line
/** @psalm-suppress UndefinedVariable */
PHP);
    file_put_contents($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'ActiveTest.php', <<<'PHP'
<?php

// @phpstan-ignore-line
test('active')->skip();
PHP);
    file_put_contents($root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'ignored'.DIRECTORY_SEPARATOR.'IgnoredTest.php', <<<'PHP'
<?php

test('ignored')->skip();
PHP);
    file_put_contents($root.DIRECTORY_SEPARATOR.'rector-only'.DIRECTORY_SEPARATOR.'Ignored.php', "<?php\n/** @noRector */\n");
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpprobe.json', <<<'JSON'
{
  "preset": "default",
  "comments": {"exclude": ["excluded"]},
  "commented_out_code": {
    "ignore_paths": ["excluded"],
    "suppression": {"enabled": true, "directive": "@probe-skip"}
  }
}
JSON);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpcs.xml.dist', <<<'XML'
<?xml version="1.0"?>
<ruleset name="test"><exclude-pattern>*/excluded/*</exclude-pattern></ruleset>
XML);
    file_put_contents($root.DIRECTORY_SEPARATOR.'phpstan.neon.dist', <<<'NEON'
parameters:
    excludePaths:
        analyseAndScan:
            - excluded/*
            - tests/*
NEON);
    file_put_contents($root.DIRECTORY_SEPARATOR.'psalm.xml', <<<'XML'
<?xml version="1.0"?>
<psalm xmlns="https://getpsalm.org/schema/config"><projectFiles><directory name="."/><ignoreFiles><directory name="excluded"/></ignoreFiles></projectFiles></psalm>
XML);
    file_put_contents($root.DIRECTORY_SEPARATOR.'pest.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit><testsuites><testsuite name="tests"><directory>tests</directory><exclude>tests/ignored</exclude></testsuite></testsuites></phpunit>
XML);
    file_put_contents($root.DIRECTORY_SEPARATOR.'rector.php', <<<'PHP'
<?php

use Rector\Config\RectorConfig;

return RectorConfig::configure()->withSkip([getcwd().'/rector-only']);
PHP);

    try {
        $scan = (new SkipDirectiveScanner())->scan($root);
        $files = array_column($scan['findings'], 'file');
        $tools = array_column($scan['findings'], 'tool');

        expect($scan['errors'])->toBe([])
            ->and($scan['files'])->toBe(6)
            ->and($files)->not->toContain('excluded/Hidden.php')
            ->not->toContain('tests/ignored/IgnoredTest.php')
            ->not->toContain('rector-only/Ignored.php')
            ->and($tools)->toContain('PHPProbe', 'PHPCS', 'PHPStan', 'Psalm', 'Pest')
            ->and(array_values(array_filter(
                $scan['findings'],
                static fn(array $finding): bool => $finding['file'] === 'tests/ActiveTest.php' && $finding['tool'] === 'PHPStan',
            )))->toBe([])
            ->and(array_values(array_filter(
                $scan['findings'],
                static fn(array $finding): bool => $finding['file'] === 'tests/ActiveTest.php' && $finding['tool'] === 'Pest',
            )))->toHaveCount(1);
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
        'files' => 3,
        'findings' => [
            [
                'file' => 'src/Example.php',
                'line' => 12,
                'tool' => 'PHPStan',
                'directive' => '@phpstan-ignore',
            ],
            [
                'file' => 'tests/ExampleTest.php',
                'line' => 24,
                'tool' => 'Pest',
                'directive' => '->skip()',
            ],
        ],
        'errors' => [],
    ]);
    $invalid = $scanner->scan(sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-'.bin2hex(random_bytes(6)));

    expect($output)->toContain('Skip directive scan summary:')
        ->toContain('Files checked:  3')
        ->toContain('Findings:       2')
        ->toContain('PHPStan              1')
        ->toContain('Pest                 1')
        ->toContain('Failure details')
        ->toContain('FAIL PHPStan')
        ->toContain('1. src/Example.php:12 [@phpstan-ignore]')
        ->toContain('FAIL Pest')
        ->toContain('1. tests/ExampleTest.php:24 [->skip()]')
        ->not->toContain('## PHPStan')
        ->toContain('Resolve the underlying issue')
        ->and($invalid['errors'])->toHaveCount(1)
        ->and($scanner->format($invalid))->toContain('FAIL Scanner');
});
