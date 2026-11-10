<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final readonly class SkipDirectivePolicy
{
    /**
     * @param list<SkipDirectiveRule> $rules
     * @param list<string> $errors
     */
    private function __construct(private array $rules, private array $errors) {}

    /**
     * @param list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}> $findings
     * @return list<string>
     */
    public static function allowedFindingLines(array $findings): array
    {
        if ($findings === []) {
            return [];
        }

        $lines = ['', 'Allowed directives', '==================', sprintf('Allowed %d finding(s) by explicit configuration:', count($findings))];

        foreach ($findings as $finding) {
            $lines[] = sprintf('  %s:%d [%s] %s %s | reason: %s', $finding['file'], $finding['line'], $finding['tool'], $finding['directive'], implode(', ', $finding['subjects'] ?? []), $finding['reason'] ?? '');

            $security = $finding['security'] ?? '';

            if ($security !== '') {
                $lines[] = '    Security review: ' . $security;
            }
        }

        return $lines;
    }

    /** @param list<array{tool: string, pattern: non-empty-string}> $extraRules */
    public static function fromJson(string $json, string $root = '', array $extraRules = []): self
    {
        try {
            $policy = json_decode($json, false, 64, JSON_THROW_ON_ERROR);

            if (!$policy instanceof \stdClass || array_diff(array_keys(get_object_vars($policy)), ['default', 'rules']) !== []
                || ($policy->default ?? null) !== 'deny') {
                throw new \InvalidArgumentException('Expected {"default":"deny","rules":[...]} with no unknown keys.');
            }

            return new self(self::rules($policy->rules ?? null, $root, $extraRules), []);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return new self([], ['Invalid skipper_policy: ' . $exception->getMessage()]);
        }
    }

    /** @param list<array{tool: string, pattern: non-empty-string}> $extraRules */
    public static function load(string $root, string $legacyJson, string $policyJson, array $extraRules = []): self
    {
        try {
            if (json_decode($legacyJson, false, 64, JSON_THROW_ON_ERROR) !== []) {
                throw new \InvalidArgumentException('IC_SKIPPER_ALLOWED_DIRECTIVES / skipper_allowed_directives no longer accepts global permissions; migrate to skipper_policy with paths, selectors and reasons.');
            }

            $project = self::projectJson($root);

            if ($project !== null && $policyJson !== '') {
                throw new \InvalidArgumentException('Configure policy in composer.json or IC_SKIPPER_POLICY, not both.');
            }

            return self::fromJson($policyJson !== '' ? $policyJson : ($project ?? '{"default":"deny","rules":[]}'), $root, $extraRules);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return new self([], ['Invalid skipper_policy: ' . $exception->getMessage()]);
        }
    }

    /**
     * @param array{files: int, findings: list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>, errors: list<string>} $scan
     * @return array{files: int, findings: list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>, errors: list<string>, allowed_findings?: list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>}
     */
    public function apply(array $scan): array
    {
        $allowed = [];
        $blocked = [];

        foreach ($scan['findings'] as $finding) {
            $approval = $this->approval($finding);

            if ($approval === null) {
                $blocked[] = $finding;
            } else {
                $allowed[] = [...$finding, ...$approval];
            }
        }

        $scan['findings'] = $blocked;
        $scan['errors'] = [...$scan['errors'], ...$this->errors];

        if ($allowed !== []) {
            $scan['allowed_findings'] = $allowed;
        }

        return $scan;
    }

    /**
     * @throws \JsonException
     * @throws \InvalidArgumentException
     */
    private static function projectJson(string $root): ?string
    {
        $manifest = $root . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($manifest)) {
            return null;
        }

        $contents = file_get_contents($manifest);

        if (!is_string($contents)) {
            throw new \InvalidArgumentException('Unable to read project composer.json.');
        }

        return self::projectPolicy(json_decode($contents, false, 64, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \JsonException
     * @throws \InvalidArgumentException
     */
    private static function projectPolicy(mixed $decoded): ?string
    {
        if (!$decoded instanceof \stdClass || !isset($decoded->extra) || !$decoded->extra instanceof \stdClass) {
            return null;
        }

        $forge = $decoded->extra->phpforge ?? null;

        if ($forge !== null && !$forge instanceof \stdClass) {
            throw new \InvalidArgumentException('extra.phpforge must be an object.');
        }

        return $forge instanceof \stdClass && property_exists($forge, 'skipper_policy')
            ? json_encode($forge->skipper_policy, JSON_THROW_ON_ERROR) : null;
    }

    /**
     * @param list<array{tool: string, pattern: non-empty-string}> $extraRules
     * @return list<SkipDirectiveRule>
     * @throws \InvalidArgumentException
     */
    private static function rules(mixed $value, string $root, array $extraRules): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('rules must be a JSON array of scoped rule objects.');
        }

        return array_map(static fn(mixed $rule): SkipDirectiveRule => SkipDirectiveRule::fromConfiguration($rule, $root, $extraRules), $value);
    }

    /**
     * @param array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string} $finding
     * @return array{reason: string, security: string}|null
     */
    private function approval(array $finding): ?array
    {
        $subjects = $finding['subjects'] ?? [];

        if ($subjects === [] && SkipDirectiveDetails::kind($finding['directive'], $finding['tool']) !== 'execution') {
            return null;
        }

        $reasons = [];
        $security = [];

        foreach ($subjects === [] ? [null] : $subjects as $subject) {
            $rule = $this->matchingRule($finding['file'], $finding['directive'], $subject);

            if ($rule === null) {
                return null;
            }

            $reasons[] = $rule->reason;
            $security[] = $rule->security;
        }

        return ['reason' => implode('; ', array_unique($reasons)), 'security' => implode('; ', array_filter(array_unique($security)))];
    }

    private function matchingRule(string $file, string $directive, ?string $subject): ?SkipDirectiveRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($file, $directive, $subject)) {
                return $rule;
            }
        }

        return null;
    }
}
