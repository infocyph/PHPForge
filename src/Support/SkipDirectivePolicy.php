<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final readonly class SkipDirectivePolicy
{
    private const string INVALID_POLICY = 'IC_SKIPPER_ALLOWED_DIRECTIVES must be a JSON array of non-empty directive strings.';

    /**
     * @param array<string, true> $allowedDirectives
     * @param list<string> $errors
     */
    private function __construct(private array $allowedDirectives, private array $errors) {}

    /**
     * @param list<array{file: string, line: int, tool: string, directive: string}> $findings
     * @return list<string>
     */
    public static function allowedFindingLines(array $findings): array
    {
        if ($findings === []) {
            return [];
        }

        $lines = ['', 'Allowed directives', '==================', sprintf('Allowed %d finding(s) by explicit configuration:', count($findings))];

        foreach ($findings as $finding) {
            $lines[] = sprintf('  %s:%d [%s] %s', $finding['file'], $finding['line'], $finding['tool'], $finding['directive']);
        }

        return $lines;
    }

    public static function fromJson(string $json): self
    {
        try {
            $directives = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self([], [self::INVALID_POLICY]);
        }

        if (!is_array($directives) || !array_is_list($directives)) {
            return new self([], [self::INVALID_POLICY]);
        }

        $allowed = [];

        foreach ($directives as $directive) {
            if (!is_string($directive) || trim($directive) === '') {
                return new self([], [self::INVALID_POLICY]);
            }

            $allowed[strtolower(trim($directive))] = true;
        }

        return new self($allowed, []);
    }

    /**
     * @param array{files: int, findings: list<array{file: string, line: int, tool: string, directive: string}>, errors: list<string>} $scan
     * @return array{files: int, findings: list<array{file: string, line: int, tool: string, directive: string}>, errors: list<string>, allowed_findings?: list<array{file: string, line: int, tool: string, directive: string}>}
     */
    public function apply(array $scan): array
    {
        $allowed = [];
        $blocked = [];

        foreach ($scan['findings'] as $finding) {
            if (isset($this->allowedDirectives[strtolower($finding['directive'])])) {
                $allowed[] = $finding;
            } else {
                $blocked[] = $finding;
            }
        }

        $scan['findings'] = $blocked;
        $scan['errors'] = [...$scan['errors'], ...$this->errors];

        if ($allowed !== []) {
            $scan['allowed_findings'] = $allowed;
        }

        return $scan;
    }
}
