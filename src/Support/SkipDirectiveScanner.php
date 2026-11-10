<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class SkipDirectiveScanner
{
    private const array EXCLUDED_DIRECTORIES = [
        '.git',
        '.idea',
        '.phpforge-report',
        '.phpforge-workflow',
        '.phpunit.cache',
        '.psalm-cache',
        '.tmp',
        '.vscode',
        'bootstrap/cache',
        'build',
        'coverage',
        'dist',
        'graphify-out',
        'node_modules',
        'storage',
        'tmp',
        'var/cache',
        'vendor',
    ];

    private const array PHP_EXTENSIONS = [
        'inc' => true,
        'php' => true,
        'phtml' => true,
    ];

    /**
     * @param array{
     *     files: int,
     *     findings: list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>,
     *     allowed_findings?: list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>,
     *     errors: list<string>
     * } $scan
     */
    public function format(array $scan): string
    {
        $allowedLines = SkipDirectivePolicy::allowedFindingLines($scan['allowed_findings'] ?? []);

        if ($scan['findings'] === [] && $scan['errors'] === []) {
            return implode(PHP_EOL, [sprintf('Skip directive scan passed: %d PHP file(s) checked.', $scan['files']), ...$allowedLines]) . PHP_EOL;
        }

        $groupedFindings = $this->groupedFindings($scan['findings']);
        $lines = [
            ...$this->summaryLines($scan['files'], $groupedFindings, count($scan['errors'])),
            '',
            'Failure details',
            '===============',
            ...$this->findingDetailLines($groupedFindings),
            ...$this->scannerErrorLines($scan['errors']),
            ...$allowedLines,
        ];

        if ($scan['findings'] !== []) {
            $lines = [...$lines, '', 'Resolution', '----------', 'Resolve the underlying issue and remove each skip directive.'];
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return array{
     *     files: int,
     *     findings: list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>,
     *     allowed_findings?: list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>,
     *     errors: list<string>
     * }
     */
    public function scan(string $root, string $allowedDirectivesJson = '[]', string $policyJson = ''): array
    {
        $resolvedRoot = realpath($root);

        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot) || !is_readable($resolvedRoot)) {
            return [
                'files' => 0,
                'findings' => [],
                'errors' => [sprintf('Scan root is not a readable directory: %s', $root)],
            ];
        }

        $configuration = SkipDirectiveConfiguration::load($resolvedRoot);
        $policy = SkipDirectivePolicy::load($resolvedRoot, $allowedDirectivesJson, $policyJson, $configuration->extraCommentRules());
        [$files, $errors] = $this->phpFiles($resolvedRoot);
        $errors = [...$configuration->errors(), ...$errors];
        $findings = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if (!is_string($contents)) {
                $errors[] = sprintf('PHP source file is not readable: %s', $this->relativePath($resolvedRoot, $file));

                continue;
            }

            $relativeFile = $this->relativePath($resolvedRoot, $file);
            $findings = [
                ...$findings,
                ...$this->fileFindings($relativeFile, $contents, $configuration->extraCommentRules()),
            ];
        }

        usort(
            $findings,
            static fn(array $left, array $right): int => [$left['tool'], $left['file'], $left['line'], $left['directive']]
                <=> [$right['tool'], $right['file'], $right['line'], $right['directive']],
        );

        return $policy->apply([
            'files' => count($files),
            'findings' => $findings,
            'errors' => $errors,
        ]);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     * @return array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}|null
     */
    private function attributeFinding(string $file, array|string $token, int $depth): ?array
    {
        if ($depth === 0 || !is_array($token) || !$this->isNameToken($token[0])) {
            return null;
        }

        $attribute = $this->baseName($token[1]);
        $tool = SkipDirectiveDetails::ATTRIBUTE_TOOLS[strtolower($attribute)] ?? null;

        return is_string($tool)
            ? $this->finding($file, $token[2], $tool, '#[' . $attribute . ']')
            : null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>
     */
    private function attributeFindings(string $file, array $tokens): array
    {
        $findings = [];
        $attributeDepth = 0;

        foreach ($tokens as $token) {
            $finding = $this->attributeFinding($file, $token, $attributeDepth);

            if (is_array($finding)) {
                $findings[] = $finding;
            }

            $attributeDepth = $this->nextAttributeDepth($token, $attributeDepth);
        }

        return $findings;
    }

    private function baseName(string $name): string
    {
        $parts = explode('\\', ltrim($name, '\\'));

        return end($parts) ?: $name;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<array{tool: string, pattern: non-empty-string}> $extraRules
     * @return list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>
     */
    private function commentTokenFindings(string $file, array $tokens, array $extraRules): array
    {
        $findings = [];

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $findings = [...$findings, ...SkipDirectiveDetails::commentFindings($file, $token[1], $token[2], $extraRules)];
            }
        }

        return $findings;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     * @return array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}|null
     */
    private function executableFinding(string $file, array $tokens, int $index): ?array
    {
        $token = $tokens[$index];
        $name = strtolower($token['text']);
        $previous = $tokens[$index - 1]['text'] ?? null;

        if ($name === 'todo' && !in_array($previous, ['->', '?->', '::', 'function'], true)) {
            return $this->finding($file, $token['line'], 'Pest', 'todo()');
        }

        if (in_array($name, ['marktestskipped', 'marktestincomplete'], true)) {
            return $this->finding($file, $token['line'], 'PHPUnit', $token['text'] . '()');
        }

        if (!isset(SkipDirectiveDetails::PEST_SKIP_METHODS[$name]) || $previous !== '->') {
            return null;
        }

        $rootCall = $this->rootCallBeforeOperator($tokens, $index - 1);

        if (is_string($rootCall) && isset(SkipDirectiveDetails::PEST_ROOT_CALLS[$rootCall])) {
            return $this->finding($file, $token['line'], 'Pest', '->' . $token['text'] . '()');
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     * @return list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>
     */
    private function executableFindings(string $file, array $tokens): array
    {
        $findings = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_STRING || ($tokens[$index + 1]['text'] ?? null) !== '(') {
                continue;
            }

            $finding = $this->executableFinding($file, $tokens, $index);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param list<array{tool: string, pattern: non-empty-string}> $extraRules
     * @return list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>
     */
    private function fileFindings(string $file, string $contents, array $extraRules): array
    {
        $tokens = token_get_all($contents);

        return [
            ...$this->commentTokenFindings($file, $tokens, $extraRules),
            ...$this->attributeFindings($file, $tokens),
            ...$this->executableFindings($file, $this->significantTokens($tokens)),
        ];
    }

    /**
     * @return array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}
     */
    private function finding(string $file, int $line, string $tool, string $directive): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'tool' => $tool,
            'directive' => $directive,
        ];
    }

    /**
     * @param array<string, list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>> $groups
     * @return list<string>
     */
    private function findingDetailLines(array $groups): array
    {
        $lines = [];

        foreach ($groups as $tool => $findings) {
            $title = 'FAIL ' . $tool;
            $lines = [...$lines, '', $title, str_repeat('-', strlen($title)), sprintf('Found %d skip directive finding(s):', count($findings))];

            foreach ($findings as $index => $finding) {
                $subjects = implode(', ', $finding['subjects'] ?? []);
                $lines[] = sprintf('  %d. %s:%d [%s]%s', $index + 1, $finding['file'], $finding['line'], $finding['directive'], $subjects === '' ? '' : ' ' . $subjects);
            }
        }

        return $lines;
    }

    /**
     * @param list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}> $findings
     * @return array<string, list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>>
     */
    private function groupedFindings(array $findings): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            $groups[$finding['tool']][] = $finding;
        }

        return $groups;
    }

    private function isNameToken(int $id): bool
    {
        return in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private function isScannableEntry(\SplFileInfo|\RecursiveDirectoryIterator|string $entry, string $root): bool
    {
        if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
            return false;
        }

        if ($entry->isDir()) {
            $relative = ltrim(substr($entry->getPathname(), strlen($root)), DIRECTORY_SEPARATOR);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            return !in_array($entry->getFilename(), self::EXCLUDED_DIRECTORIES, true)
                && !in_array($relative, self::EXCLUDED_DIRECTORIES, true);
        }

        return isset(self::PHP_EXTENSIONS[strtolower($entry->getExtension())]);
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private function matchingOpenParenthesis(array $tokens, int $closeIndex): ?int
    {
        $depth = 0;

        for ($index = $closeIndex; $index >= 0; $index--) {
            $text = $tokens[$index]['text'];
            $depth += match ($text) {
                ')' => 1,
                '(' => -1,
                default => 0,
            };

            if ($depth === 0 && $text === '(') {
                return $index;
            }
        }

        return null;
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private function nextAttributeDepth(array|string $token, int $depth): int
    {
        if (is_array($token) && $token[0] === T_ATTRIBUTE) {
            return 1;
        }

        if (!is_string($token) || $depth === 0) {
            return $depth;
        }

        return match ($token) {
            '[' => $depth + 1,
            ']' => max(0, $depth - 1),
            default => $depth,
        };
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        $errors = [];

        try {
            $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator(
                $directory,
                fn(\SplFileInfo|\RecursiveDirectoryIterator|string $entry): bool => $this->isScannableEntry($entry, $root),
            );
            $iterator = new \RecursiveIteratorIterator($filter);

            foreach ($iterator as $entry) {
                if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                    $files[] = $entry->getPathname();
                }
            }
        } catch (\UnexpectedValueException $exception) {
            $errors[] = 'Unable to traverse PHP source files: ' . $exception->getMessage();
        }

        sort($files, SORT_STRING);

        return [$files, $errors];
    }

    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private function rootCallBeforeOperator(array $tokens, int $operatorIndex): ?string
    {
        $closeIndex = $operatorIndex - 1;

        if (($tokens[$closeIndex]['text'] ?? null) !== ')') {
            return null;
        }

        $openIndex = $this->matchingOpenParenthesis($tokens, $closeIndex);

        if (!is_int($openIndex) || $openIndex === 0) {
            return null;
        }

        $nameIndex = $openIndex - 1;
        $nameToken = $tokens[$nameIndex] ?? null;

        if ($nameToken === null || $nameToken['id'] !== T_STRING) {
            return null;
        }

        if (($tokens[$nameIndex - 1]['text'] ?? null) === '->') {
            return $this->rootCallBeforeOperator($tokens, $nameIndex - 1);
        }

        if (($tokens[$nameIndex - 1]['text'] ?? null) === '::') {
            return null;
        }

        return strtolower($nameToken['text']);
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private function scannerErrorLines(array $errors): array
    {
        if ($errors === []) {
            return [];
        }

        $title = 'FAIL Scanner';
        $lines = ['', $title, str_repeat('-', strlen($title)), sprintf('Found %d scanner error(s):', count($errors))];

        foreach ($errors as $index => $error) {
            $lines[] = sprintf('  %d. %s', $index + 1, $error);
        }

        return $lines;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private function significantTokens(array $tokens): array
    {
        $significant = [];
        $currentLine = 1;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;

                if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                    $significant[] = ['id' => $id, 'text' => $text, 'line' => $line];
                }

                $currentLine = $line + substr_count($text, "\n");

                continue;
            }

            $significant[] = ['id' => null, 'text' => $token, 'line' => $currentLine];
            $currentLine += substr_count($token, "\n");
        }

        return $significant;
    }

    /**
     * @param array<string, list<array{file: string, line: int, tool: string, directive: string, subjects?: list<string>, reason?: string, security?: string}>> $groups
     * @return list<string>
     */
    private function summaryLines(int $files, array $groups, int $errorCount): array
    {
        $toolWidth = 12;

        foreach (array_keys($groups) as $tool) {
            $toolWidth = max($toolWidth, strlen($tool));
        }

        $lines = [
            'Skip directive scan summary:',
            sprintf('  Files checked:  %d', $files),
            sprintf('  Findings:       %d', array_sum(array_map(count(...), $groups))),
            sprintf('  Scanner errors: %d', $errorCount),
            '',
            sprintf('  %-' . $toolWidth . 's  %8s', 'Tool', 'Findings'),
            sprintf('  %s  %s', str_repeat('-', $toolWidth), '--------'),
        ];

        foreach ($groups as $tool => $findings) {
            $lines[] = sprintf('  %-' . $toolWidth . 's  %8d', $tool, count($findings));
        }

        return $lines;
    }
}
