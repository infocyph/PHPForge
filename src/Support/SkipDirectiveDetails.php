<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/** @internal Extracts bounded annotation subjects without interpreting executable expressions. */
final class SkipDirectiveDetails
{
    public const array ATTRIBUTE_TOOLS = [
        'codecoverageignore' => 'PHPUnit',
        'coversnothing' => 'PHPUnit',
        'doesnotperformassertions' => 'PHPUnit',
        'ignoredeprecations' => 'PHPUnit',
        'ignorephpunitdeprecations' => 'PHPUnit',
        'ignorephpunitwarnings' => 'PHPUnit',
        'requiresenvironmentvariable' => 'PHPUnit',
        'requiresfunction' => 'PHPUnit',
        'requiresmethod' => 'PHPUnit',
        'requiresoperatingsystem' => 'PHPUnit',
        'requiresoperatingsystemfamily' => 'PHPUnit',
        'requiresphp' => 'PHPUnit',
        'requiresphpextension' => 'PHPUnit',
        'requiresphpunit' => 'PHPUnit',
        'requiresphpunitextension' => 'PHPUnit',
        'requiressetting' => 'PHPUnit',
        'skip' => 'PHPBench',
        'withouterrorhandler' => 'PHPUnit',
    ];

    public const array COMMENT_RULES = [
        ['tool' => 'PHPStan', 'pattern' => '/@phpstan-ignore(?:-next-line|-line)?\b/i'],
        ['tool' => 'Psalm', 'pattern' => '/@psalm-(?:suppress|api|assert-untainted|taint-escape|ignore-(?:nullable-return|falsable-return|variable-property|variable-method))\b/i'],
        ['tool' => 'PHPCS', 'pattern' => '/(?:\bphpcs:(?:ignoreFile|ignore|disable|set)\b|@codingStandards(?:IgnoreFile|IgnoreStart|IgnoreLine|ChangeSetting)\b)/i'],
        ['tool' => 'PHPBench', 'pattern' => '/@Skip\b/'],
        ['tool' => 'Phan', 'pattern' => '/@phan-(?:file-suppress|suppress(?:-next-line|-current-line)?)\b/i'],
        ['tool' => 'PHPMD', 'pattern' => '/@SuppressWarnings\b/i'],
        ['tool' => 'PhpStorm', 'pattern' => '/@noinspection\b/i'],
        ['tool' => 'PHPUnit', 'pattern' => '/@(?:codeCoverageIgnore(?:Start|End)?|coversNothing|doesNotPerformAssertions|ignoreDeprecations|requires)\b/i'],
        ['tool' => 'Infection', 'pattern' => '/@infection-ignore-(?:all|source-code|return-value)\b/i'],
        ['tool' => 'Rector', 'pattern' => '/@noRector\b/i'],
    ];

    public const array PEST_ROOT_CALLS = [
        'afterall' => true,
        'aftereach' => true,
        'arch' => true,
        'beforeall' => true,
        'beforeeach' => true,
        'describe' => true,
        'it' => true,
        'pest' => true,
        'test' => true,
        'uses' => true,
    ];

    public const array PEST_SKIP_METHODS = [
        'only' => true,
        'onlyonlinux' => true,
        'onlyonmac' => true,
        'onlyonwindows' => true,
        'skip' => true,
        'skiplocally' => true,
        'skiponci' => true,
        'skiponlinux' => true,
        'skiponmac' => true,
        'skiponphp' => true,
        'skiponwindows' => true,
        'todo' => true,
    ];

    /**
     * @param list<array{tool: string, pattern: non-empty-string}> $extraRules
     * @return list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>
     */
    public static function commentFindings(string $file, string $comment, int $startLine, array $extraRules): array
    {
        $findings = [];

        foreach ([...self::COMMENT_RULES, ...$extraRules] as $rule) {
            $matches = [];

            if (preg_match_all($rule['pattern'], $comment, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$directive, $offset]) {
                $line = $startLine + substr_count(substr($comment, 0, $offset), "\n");
                $finding = ['file' => $file, 'line' => $line, 'tool' => $rule['tool'], 'directive' => $directive];
                $finding['subjects'] = SkipDirectiveDetails::subjects($directive, $rule['tool'], substr($comment, $offset + strlen($directive)));
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    public static function kind(string $directive, string $tool): string
    {
        $name = strtolower($directive);

        if ($name === '@psalm-taint-escape' || $name === '@psalm-assert-untainted') {
            return 'sanitization';
        }

        if (in_array($tool, ['PHPStan', 'PHPCS', 'PHPProbe', 'Phan', 'PHPMD', 'PhpStorm', 'Infection', 'Rector'], true)
            || str_starts_with($name, '@psalm-suppress') || str_starts_with($name, '@psalm-ignore-')) {
            return 'suppression';
        }

        return 'execution';
    }

    /** @return list<string> */
    public static function subjects(string $directive, string $tool, string $tail): array
    {
        if (self::kind($directive, $tool) === 'execution') {
            return [];
        }

        // Only Psalm's own list parser may continue subjects across docblock lines.
        $body = explode('*/', $tail, 2)[0];
        $body = preg_replace('/^\h*\*\h?/m', '', $body) ?? '';
        $line = explode("\n", $body, 2)[0];
        $line = explode('*/', $line, 2)[0];
        $line = explode(' --', $line, 2)[0];
        $name = strtolower($directive);

        if ($name === '@psalm-suppress') {
            return array_values(\Psalm\DocComment::parseSuppressList(ltrim($body)));
        }

        if ($name === '@psalm-taint-escape') {
            return preg_match('/^\s+([a-z][a-z0-9_]*)\s*$/D', $line, $match) === 1 ? [$match[1]] : [];
        }

        // Blanket and conditional suppressions cannot be narrowed by a rule after the fact.
        if (in_array($name, ['@phpstan-ignore-line', '@phpstan-ignore-next-line', '@psalm-assert-untainted', 'phpcs:ignorefile', '@codingstandardsignorefile', '@codingstandardsignorestart', '@codingstandardsignoreline'], true)
            || str_starts_with($name, '@psalm-ignore-')) {
            return [];
        }

        if ($tool === 'PHPProbe') {
            $line = preg_split('/\s+(?:until|scope|symbol)=/', $line, 2)[0] ?? '';
        }

        if ($name === '@suppresswarnings') {
            return preg_match('/^\s*\(\s*([A-Za-z][A-Za-z0-9_.]*)\s*\)\s*$/D', $line, $match) === 1 ? [$match[1]] : [];
        }

        $line = preg_replace('/\([^()]*\)/', '', $line) ?? '';
        $line = trim($line);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.\\\\-]*(?:\s*,\s*[A-Za-z][A-Za-z0-9_.\\\\-]*)*$/D', $line) !== 1) {
            return [];
        }

        return array_map(trim(...), explode(',', $line));
    }

    /** @param list<array{tool: string, pattern: non-empty-string}> $extraRules */
    public static function tool(string $directive, array $extraRules = []): ?string
    {
        $name = strtolower($directive);

        foreach ([...self::COMMENT_RULES, ...$extraRules] as $rule) {
            $pattern = str_ends_with($rule['pattern'], '/i') ? $rule['pattern'] : $rule['pattern'] . 'i';

            if (preg_match($pattern, $directive, $match) === 1 && $match[0] === $directive) {
                return $rule['tool'];
            }
        }

        if ($name === '@phpprobe-ignore') {
            return 'PHPProbe';
        }

        if (str_starts_with($name, '#[') && str_ends_with($name, ']')) {
            return self::ATTRIBUTE_TOOLS[substr($name, 2, -1)] ?? null;
        }

        if (str_starts_with($name, '->') && str_ends_with($name, '()') && isset(self::PEST_SKIP_METHODS[substr($name, 2, -2)])) {
            return 'Pest';
        }

        return match ($name) {
            'todo()' => 'Pest',
            'marktestskipped()', 'marktestincomplete()' => 'PHPUnit',
            default => null,
        };
    }
}
