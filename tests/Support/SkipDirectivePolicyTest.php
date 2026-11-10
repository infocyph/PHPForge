<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\SkipDirectiveScanner;
use Symfony\Component\Process\Process;

function skipPolicyRoot(): string
{
    $root = sys_get_temp_dir() . '/phpforge-policy-' . bin2hex(random_bytes(6));
    mkdir($root, 0755, true);

    return $root;
}

function skipPolicySource(string $root, string $file, string $contents): void
{
    $directory = dirname($root . '/' . $file);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents($root . '/' . $file, $contents);
}

/**
 * @param list<array<string, mixed>> $rules
 * @throws JsonException
 */
function skipPolicyJson(array $rules): string
{
    return json_encode(['default' => 'deny', 'rules' => $rules], JSON_THROW_ON_ERROR);
}

/** @return array{paths: list<string>, directive: string, issue: string, reason: string} */
function skipPolicyIssueRule(): array
{
    return ['paths' => ['tools/source.php'], 'directive' => '@psalm-suppress', 'issue' => 'UnresolvableInclude', 'reason' => 'Intentional dynamic source loading.'];
}

it('authorizes only the exact path directive and issue without assuming a directory layout', function (): void {
    $root = skipPolicyRoot();
    $source = "<?php\n/** @psalm-suppress UnresolvableInclude */\n/** @psalm-suppress InvalidArgument */\n// @phpstan-ignore variable.undefined\n";
    skipPolicySource($root, 'tools/source.php', $source);
    skipPolicySource($root, 'src/source.php', $source);

    try {
        $scanner = new SkipDirectiveScanner();
        $strict = $scanner->scan($root);
        $scan = $scanner->scan($root, '[]', skipPolicyJson([skipPolicyIssueRule()]));
        $allowed = fixtureArray(fixtureValue($scan, ['allowed_findings']));

        expect($strict['findings'])->toHaveCount(6)
            ->and($scan['errors'])->toBe([])
            ->and($scan['findings'])->toHaveCount(5)
            ->and(array_column($allowed, 'file'))->toBe(['tools/source.php'])
            ->and($scanner->format($scan))->toContain('Allowed directives', 'UnresolvableInclude', 'reason: Intentional dynamic source loading.');
    } finally {
        removeFixtureDirectory($root);
    }
});

it('requires permission for every issue on a shared suppression', function (): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\n/** @psalm-suppress UnresolvableInclude, InvalidArgument reason */\n// @phpstan-ignore variable.undefined, argument.type (reviewed)\n// phpcs:ignore Generic.Files.LineLength, Generic.WhiteSpace.DisallowTabIndent -- reviewed\n");

    try {
        $scanner = new SkipDirectiveScanner();
        $partial = $scanner->scan($root, '[]', skipPolicyJson([skipPolicyIssueRule()]));
        $rules = [skipPolicyIssueRule()];

        foreach ([['@psalm-suppress', 'InvalidArgument'], ['@phpstan-ignore', 'variable.undefined'], ['@phpstan-ignore', 'argument.type'], ['phpcs:ignore', 'Generic.Files.LineLength'], ['phpcs:ignore', 'Generic.WhiteSpace.DisallowTabIndent']] as [$directive, $issue]) {
            $rule = skipPolicyIssueRule();
            $rule['directive'] = $directive;
            $rule['issue'] = $issue;
            $rules[] = $rule;
        }

        $complete = $scanner->scan($root, '[]', skipPolicyJson($rules));

        expect($partial['findings'])->toHaveCount(3)
            ->and($complete['errors'])->toBe([])
            ->and($complete['findings'])->toBe([])
            ->and(fixtureArray(fixtureValue($complete, ['allowed_findings'])))->toHaveCount(3);
    } finally {
        removeFixtureDirectory($root);
    }
});

it('does not authorize multiline or adjacent issues through a partial Psalm rule', function (): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\n/**\n * @psalm-suppress UnresolvableInclude,\n * InvalidArgument\n */\n/** @psalm-suppress InvalidArgument */\n");

    try {
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', skipPolicyJson([skipPolicyIssueRule()]));
        expect($scan['errors'])->toBe([])->and($scan['findings'])->toHaveCount(2);
    } finally {
        removeFixtureDirectory($root);
    }
});

it('scopes execution exceptions to bounded globs and preserves all other findings', function (): void {
    $root = skipPolicyRoot();

    foreach (['specs/fixtures/a.php', 'specs/fixtures/nested/b.php', 'specs/fixtures-other/a.php', 'benchmarks/a.php'] as $file) {
        skipPolicySource($root, $file, "<?php\nit('pending')->skip();\nit('unfinished')->todo();\n");
    }

    try {
        $json = skipPolicyJson([['paths' => ['specs/fixtures/**'], 'directive' => '->SKIP()', 'reason' => 'Tests exercise a conditional execution fixture.']]);
        $scanner = new SkipDirectiveScanner();
        $first = $scanner->scan($root, '[]', $json);

        expect($first)->toBe($scanner->scan($root, '[]', $json))
            ->and($first['errors'])->toBe([])
            ->and($first['findings'])->toHaveCount(6)
            ->and(array_column(fixtureArray(fixtureValue($first, ['allowed_findings'])), 'file'))->toBe(['specs/fixtures/a.php', 'specs/fixtures/nested/b.php']);
    } finally {
        removeFixtureDirectory($root);
    }
});

it('refuses blanket annotations even when a configuration names an issue', function (): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\n// @phpstan-ignore-line\n// phpcs:disable\n// phpcs:ignoreFile Generic.Files.LineLength\n/** @psalm-ignore-nullable-return */\n");

    try {
        $rules = [];

        foreach (['@phpstan-ignore-line', '@psalm-ignore-nullable-return'] as $directive) {
            $rule = skipPolicyIssueRule();
            $rule['directive'] = $directive;
            $rules[] = $rule;
        }

        $rules[] = ['paths' => ['tools/source.php'], 'directive' => 'phpcs:ignoreFile', 'issue' => 'Generic.Files.LineLength', 'reason' => 'Reviewed fixture.'];
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', skipPolicyJson($rules));
        expect($scan['errors'])->toBe([])->and($scan['findings'])->toHaveCount(4)->and($scan)->not->toHaveKey('allowed_findings');
    } finally {
        removeFixtureDirectory($root);
    }
});

it('rejects an entire invalid policy without partially approving a valid first rule', function (string $invalid): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\n/** @psalm-suppress UnresolvableInclude */\n");

    try {
        $json = '{"default":"deny","rules":[' . json_encode(skipPolicyIssueRule(), JSON_THROW_ON_ERROR) . ',' . $invalid . ']}';
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', $json);
        expect($scan['errors'])->toHaveCount(1)->and($scan['findings'])->toHaveCount(1)->and($scan)->not->toHaveKey('allowed_findings');
    } finally {
        removeFixtureDirectory($root);
    }
})->with([
    'missing reason' => '{"paths":["tools/source.php"],"directive":"@psalm-suppress","issue":"UnresolvableInclude"}',
    'missing paths' => '{"directive":"@psalm-suppress","issue":"UnresolvableInclude","reason":"reviewed"}',
    'missing issue' => '{"paths":["tools/source.php"],"directive":"@psalm-suppress","reason":"reviewed"}',
    'root glob' => '{"paths":["**"],"directive":"->skip()","reason":"reviewed"}',
    'root star' => '{"paths":["*.php"],"directive":"->skip()","reason":"reviewed"}',
    'directive glob' => '{"paths":["tools/**"],"directive":"@psalm-*","reason":"reviewed"}',
    'issue glob' => '{"paths":["tools/**"],"directive":"@psalm-suppress","issue":"*","reason":"reviewed"}',
    'traversal' => '{"paths":["../tools/**"],"directive":"->skip()","reason":"reviewed"}',
    'absolute' => '{"paths":["/tools/**"],"directive":"->skip()","reason":"reviewed"}',
    'unknown key' => '{"paths":["tools/**"],"directive":"->skip()","reason":"reviewed","issues":[]}',
    'null issue' => '{"paths":["tools/**"],"directive":"@psalm-suppress","issue":null,"reason":"reviewed"}',
    'conflicting selectors' => '{"paths":["tools/**"],"directive":"@psalm-suppress","issue":"InvalidArgument","taint":"html","reason":"reviewed"}',
    'blank reason' => '{"paths":["tools/**"],"directive":"->skip()","reason":" "}',
    'broad sniff category' => '{"paths":["tools/**"],"directive":"phpcs:disable","issue":"Generic","reason":"reviewed"}',
    'unknown directive' => '{"paths":["tools/**"],"directive":"@psalm-typo","reason":"reviewed"}',
    'nested malformed recursion' => '{"paths":["tools/***/**"],"directive":"->skip()","reason":"reviewed"}',
    'incomplete recursive glob' => '{"paths":["tools/**suffix"],"directive":"->skip()","reason":"reviewed"}',
    'control characters' => '{"paths":["tools/**"],"directive":"->skip()","reason":"reviewed\\nforged"}',
]);

it('rejects malformed top-level policy and global legacy permissions', function (string $json): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\nit('pending')->skip();\n");

    try {
        $scanner = new SkipDirectiveScanner();
        $scan = $scanner->scan($root, '[]', $json);
        $legacy = $scanner->scan($root, '["->skip()"]', skipPolicyJson([]));
        expect($scan['errors'])->toHaveCount(1)->and($scan['findings'])->toHaveCount(1)
            ->and($legacy['findings'])->toHaveCount(1)->and(implode(' ', $legacy['errors']))->toContain('migrate to skipper_policy');
    } finally {
        removeFixtureDirectory($root);
    }
})->with(['{', '{}', 'null', 'false', '["->skip()"]', '{"default":"allow","rules":[]}', '{"default":"deny","rules":{}}']);

it('loads the consuming Composer policy and rejects conflicting environment rules', function (): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\n/** @psalm-suppress UnresolvableInclude */\n");
    $policy = json_decode(skipPolicyJson([skipPolicyIssueRule()]), false, 512, JSON_THROW_ON_ERROR);
    skipPolicySource($root, 'composer.json', json_encode(['name' => 'example/consumer', 'extra' => ['phpforge' => ['skipper_policy' => $policy]]], JSON_THROW_ON_ERROR));

    try {
        $scanner = new SkipDirectiveScanner();
        $scan = $scanner->scan($root);
        $conflict = $scanner->scan($root, '[]', skipPolicyJson([]));
        expect($scan['errors'])->toBe([])->and($scan['findings'])->toBe([])
            ->and($conflict['errors'])->toHaveCount(1)->and($conflict['findings'])->toHaveCount(1);
    } finally {
        removeFixtureDirectory($root);
    }
});

it('keeps configuration errors blocking even when a rule permits every finding', function (): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\n/** @psalm-suppress UnresolvableInclude */\n");
    skipPolicySource($root, 'phpprobe.json', '{broken');

    try {
        $scanner = new SkipDirectiveScanner();
        $scan = $scanner->scan($root, '[]', skipPolicyJson([skipPolicyIssueRule()]));
        expect($scan['errors'])->not->toBeEmpty()->and($scan['findings'])->toBe([])
            ->and($scanner->format($scan))->toContain('FAIL Scanner', 'Allowed 1 finding(s)');
    } finally {
        removeFixtureDirectory($root);
    }
});

it('enforces scoped workflow permissions in the consuming project CLI', function (string $selector, int $exit): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'composer.json', '{"name":"example/consumer"}');
    skipPolicySource($root, 'tools/source.php', "<?php\n/** @psalm-suppress UnresolvableInclude */\n");
    $rule = skipPolicyIssueRule();
    $rule['issue'] = $selector;

    try {
        $process = new Process([PHP_BINARY, Paths::packageFile('bin/phpforge'), 'skipper'], $root, [
            'IC_SKIPPER_ALLOWED_DIRECTIVES' => '[]',
            'IC_SKIPPER_POLICY' => skipPolicyJson([$rule]),
        ]);
        $process->run();
        expect($process->getExitCode())->toBe($exit)->and($process->getOutput() . $process->getErrorOutput())->toContain('tools/source.php');
    } finally {
        removeFixtureDirectory($root);
    }
})->with([['UnresolvableInclude', 0], ['InvalidArgument', 1]]);

/**
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function skipPolicySanitizerRule(string $root): array
{
    skipPolicySource($root, 'security/Escape.php', "<?php\n/** @psalm-taint-escape html */\nfunction escape(string \$input): string { return htmlspecialchars(\$input, ENT_QUOTES); }\n");
    skipPolicySource($root, 'checks/EscapeTest.php', "<?php\nfunction verifiesEscaping(): bool { return escape('<script>') === '&lt;script&gt;'; }\n");
    $hash = hash_file('sha256', $root . '/security/Escape.php');

    if (!is_string($hash)) {
        throw new RuntimeException('Unable to hash fixture sanitizer.');
    }

    return ['paths' => ['security/Escape.php'], 'directive' => '@psalm-taint-escape', 'taint' => 'html',
        'reason' => 'Reviewed HTML output encoding boundary; regression covers hostile markup.',
        'security' => ['sanitizer' => 'security/Escape.php', 'sha256' => $hash, 'tests' => ['checks/EscapeTest.php']]];
}

it('requires a reviewed sanitizer and tests and invalidates approval when the implementation changes', function (): void {
    $root = skipPolicyRoot();
    $rule = skipPolicySanitizerRule($root);

    try {
        $scanner = new SkipDirectiveScanner();
        $allowed = $scanner->scan($root, '[]', skipPolicyJson([$rule]));
        $wrongTaint = $scanner->scan($root, '[]', skipPolicyJson([[...$rule, 'taint' => 'file']]));
        file_put_contents($root . '/security/Escape.php', "\n// Implementation changed.\n", FILE_APPEND);
        $changed = $scanner->scan($root, '[]', skipPolicyJson([$rule]));
        expect($allowed['errors'])->toBe([])->and($allowed['findings'])->toBe([])
            ->and($scanner->format($allowed))->toContain('Security review:', 'checks/EscapeTest.php', 'sha256=')
            ->and($wrongTaint['findings'])->toHaveCount(1)
            ->and($changed['errors'])->toHaveCount(1)->and($changed['findings'])->toHaveCount(1);
    } finally {
        removeFixtureDirectory($root);
    }
});

it('rejects missing broad or invalid security evidence', function (string $case): void {
    $root = skipPolicyRoot();
    $rule = skipPolicySanitizerRule($root);
    $security = fixtureArray($rule['security']);

    match ($case) {
        'missing evidence' => $rule['security'] = null,
        'broad taint' => $rule['taint'] = 'input',
        'missing tests' => $rule['security'] = [...$security, 'tests' => []],
        'missing file' => $rule['security'] = [...$security, 'tests' => ['checks/Missing.php']],
        'self evidence' => $rule['security'] = [...$security, 'tests' => ['security/Escape.php']],
        'outside project' => $rule['security'] = [...$security, 'sanitizer' => '../Escape.php'],
        'unknown key' => $rule['security'] = [...$security, 'reviewed' => true],
        'mismatched hash' => $rule['security'] = [...$security, 'sha256' => str_repeat('0', 64)],
        'unscoped assertion' => $rule['directive'] = '@psalm-assert-untainted',
        default => throw new LogicException('Unknown fixture.'),
    };

    if ($case === 'unscoped assertion') {
        skipPolicySource($root, 'security/Escape.php', "<?php\n/** @psalm-assert-untainted \$value */\n");
    }

    try {
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', skipPolicyJson([$rule]));
        expect($scan['findings'])->toHaveCount(1)->and($scan)->not->toHaveKey('allowed_findings');

        if ($case !== 'unscoped assertion') {
            expect($scan['errors'])->toHaveCount(1);
        }
    } finally {
        removeFixtureDirectory($root);
    }
})->with(['missing evidence', 'broad taint', 'missing tests', 'missing file', 'self evidence', 'outside project', 'unknown key', 'mismatched hash', 'unscoped assertion']);

it('rejects symlinked security evidence', function (): void {
    $root = skipPolicyRoot();
    $rule = skipPolicySanitizerRule($root);
    symlink($root . '/checks/EscapeTest.php', $root . '/checks/Linked.php');
    $security = fixtureArray($rule['security']);
    $rule['security'] = [...$security, 'tests' => ['checks/Linked.php']];

    try {
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', skipPolicyJson([$rule]));
        expect($scan['errors'])->toHaveCount(1)->and($scan['findings'])->toHaveCount(1);
    } finally {
        unlink($root . '/checks/Linked.php');
        removeFixtureDirectory($root);
    }
});

it('rejects malformed legacy input while retaining the empty-list compatibility default', function (string $legacy): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'Root.php', "<?php\nit('pending')->skip();\n");

    try {
        $scanner = new SkipDirectiveScanner();
        $scan = $scanner->scan($root, $legacy);
        expect($scan['errors'])->toHaveCount(1)->and($scan['findings'])->toHaveCount(1)
            ->and($scanner->scan($root, ' [ ] ')['errors'])->toBe([]);
    } finally {
        removeFixtureDirectory($root);
    }
})->with(['[', '', '{}', 'null', '"@psalm-taint-escape"', '{"0":"->skip()"}', '["->skip()",false]', '[" "]', '[["->skip()"]]']);

it('supports exact root files and arbitrary directory names across PHP source extensions', function (): void {
    $root = skipPolicyRoot();

    foreach (['Root.php', 'custom layout/স্কোপ.phtml', '.hidden/Fixture.INC'] as $file) {
        skipPolicySource($root, $file, "<?php\nit('pending')->skip();\n");
    }

    try {
        $rule = ['paths' => ['Root.php', 'custom layout/**'], 'directive' => '->skip()', 'reason' => 'Reviewed fixture behavior.'];
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', skipPolicyJson([$rule]));
        expect($scan['errors'])->toBe([])->and($scan['files'])->toBe(3)
            ->and(array_column($scan['findings'], 'file'))->toBe(['.hidden/Fixture.INC'])
            ->and(fixtureArray(fixtureValue($scan, ['allowed_findings'])))->toHaveCount(2);
    } finally {
        removeFixtureDirectory($root);
    }
});

it('keeps custom PHPProbe suppressions issue-specific', function (): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'tools/source.php', "<?php\n// @probe-skip commented_out_code_without_reason until=2026-12-31 scope=symbol symbol=example\n// @probe-skip another_issue\n");
    skipPolicySource($root, 'phpprobe.json', '{"preset":"default","commented_out_code":{"suppression":{"enabled":true,"directive":"@probe-skip"}}}');

    try {
        $rule = ['paths' => ['tools/source.php'], 'directive' => '@probe-skip', 'issue' => 'commented_out_code_without_reason', 'reason' => 'Reviewed fixture reproduces the detector.'];
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', skipPolicyJson([$rule]));
        expect($scan['errors'])->toBe([])->and($scan['findings'])->toHaveCount(1)
            ->and(fixtureArray(fixtureValue($scan, ['allowed_findings'])))->toHaveCount(1);
    } finally {
        removeFixtureDirectory($root);
    }
});

it('matches configured benchmark annotation names case-insensitively', function (): void {
    $root = skipPolicyRoot();
    skipPolicySource($root, 'Root.php', "<?php\n/** @Skip */\nfunction benchmarkFixture(): void {}\n");

    try {
        $rule = ['paths' => ['Root.php'], 'directive' => '@SKIP', 'reason' => 'Reviewed benchmark fixture.'];
        $scan = (new SkipDirectiveScanner())->scan($root, '[]', skipPolicyJson([$rule]));
        expect($scan['errors'])->toBe([])->and($scan['findings'])->toBe([])
            ->and(fixtureArray(fixtureValue($scan, ['allowed_findings'])))->toHaveCount(1);
    } finally {
        removeFixtureDirectory($root);
    }
});
