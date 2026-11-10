<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/** @internal Immutable authorization for an exact directive and subject within explicit paths. */
final readonly class SkipDirectiveRule
{
    /** @param non-empty-list<non-empty-string> $patterns */
    private function __construct(
        private array $patterns,
        private string $directive,
        private ?string $subject,
        public string $reason,
        public string $security,
    ) {}

    /**
     * @param list<array{tool: string, pattern: non-empty-string}> $extraRules
     * @throws \InvalidArgumentException
     */
    public static function fromConfiguration(mixed $value, string $root, array $extraRules = []): self
    {
        if (!$value instanceof \stdClass || array_diff(array_keys(get_object_vars($value)), ['paths', 'directive', 'issue', 'taint', 'reason', 'security']) !== []) {
            throw new \InvalidArgumentException('Each rule must contain only paths, directive, issue or taint, reason and security.');
        }

        $directive = self::text($value->directive ?? null);
        $reason = self::text($value->reason ?? null);

        $tool = SkipDirectiveDetails::tool($directive, $extraRules);

        if ($tool === null) {
            throw new \InvalidArgumentException('Unknown directive; use the exact name shown by the scanner.');
        }

        if (strpbrk($directive, '*?{}') !== false) {
            throw new \InvalidArgumentException('directive must be an exact name, without wildcards.');
        }

        $subject = self::subject($value, $directive, $tool);
        $security = '';

        if (SkipDirectiveDetails::kind($directive, $tool) === 'sanitization') {
            $evidence = SkipDirectiveEvidence::validate($value->security ?? null, $root);
            $security = $evidence['audit'];

            if (($value->paths ?? null) !== [$evidence['sanitizer']]) {
                throw new \InvalidArgumentException('Sanitization permissions must name the exact reviewed sanitizer file.');
            }
        } elseif (property_exists($value, 'security')) {
            throw new \InvalidArgumentException('security evidence is reserved for sanitization contracts.');
        }

        return new self(self::patterns($value->paths ?? null), strtolower($directive), $subject, $reason, $security);
    }

    public function matches(string $file, string $directive, ?string $subject): bool
    {
        if ($this->directive !== strtolower($directive) || $this->subject !== $subject) {
            return false;
        }

        return array_any($this->patterns, fn($pattern) => preg_match($pattern, $file) === 1);
    }

    /** @return non-empty-string
     * @throws \InvalidArgumentException
     */
    private static function pattern(mixed $path): string
    {
        $path = self::text($path);
        SkipDirectiveEvidence::relativePath(str_replace(['*', '?'], 'X', $path));
        $first = explode('/', $path, 2)[0];
        $prefix = str_ends_with($path, '/**') ? substr($path, 0, -3) : $path;

        if (strpbrk($first, '*?') !== false || str_contains($prefix, '**')) {
            throw new \InvalidArgumentException('Wildcard paths require a literal leading directory; ** is supported only as the final complete segment.');
        }

        $quoted = preg_quote($path, '~');
        $regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], $quoted);

        return '~^' . $regex . '$~D';
    }

    /** @return non-empty-list<non-empty-string>
     * @throws \InvalidArgumentException
     */
    private static function patterns(mixed $paths): array
    {
        if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
            throw new \InvalidArgumentException('paths requires a nonempty list of project-relative files or bounded globs.');
        }

        return array_map(self::pattern(...), $paths);
    }

    /** @throws \InvalidArgumentException */
    private static function subject(\stdClass $value, string $directive, string $tool): ?string
    {
        $kind = SkipDirectiveDetails::kind($directive, $tool);
        $key = match ($kind) {
            'suppression' => 'issue',
            'sanitization' => 'taint',
            default => null,
        };

        if ($key === null) {
            if (property_exists($value, 'issue') || property_exists($value, 'taint')) {
                throw new \InvalidArgumentException('This directive does not accept issue or taint selectors.');
            }

            return null;
        }

        if (property_exists($value, $key === 'issue' ? 'taint' : 'issue')) {
            throw new \InvalidArgumentException('Use issue for suppressions and taint for sanitization contracts.');
        }

        $subject = self::text($value->{$key} ?? null);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.\\\\-]*$/D', $subject) !== 1 || in_array(strtolower($subject), ['all', 'input', 'tainted', 'input_except_sleep'], true)) {
            throw new \InvalidArgumentException('issue and taint must be individual exact identifiers, never broad categories or wildcards.');
        }

        if ($tool === 'PHPCS' && substr_count($subject, '.') < 2) {
            throw new \InvalidArgumentException('PHPCS issue must name an exact sniff or message code, not a standard or category.');
        }

        return $subject;
    }

    /** @throws \InvalidArgumentException */
    private static function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('Rule names, selectors, paths and reasons must be nonempty single-line strings.');
        }

        return trim($value);
    }
}
