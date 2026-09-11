<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class SkipDirectiveScanner
{
    private const array COMMENT_RULES = [
        ['tool' => 'PHPStan', 'pattern' => '/@phpstan-ignore(?:-next-line|-line)?\b/i'],
        ['tool' => 'Psalm', 'pattern' => '/@psalm-(?:suppress|api|assert-untainted|taint-escape|ignore-(?:nullable-return|falsable-return|variable-property|variable-method))\b/i'],
        ['tool' => 'PHPCS', 'pattern' => '/(?:\bphpcs:(?:ignoreFile|ignore|disable|set)\b|@codingStandards(?:IgnoreFile|IgnoreStart|IgnoreLine|ChangeSetting)\b)/i'],
        ['tool' => 'Phan', 'pattern' => '/@phan-(?:file-suppress|suppress(?:-next-line|-current-line)?)\b/i'],
        ['tool' => 'PHPMD', 'pattern' => '/@SuppressWarnings\b/i'],
        ['tool' => 'PhpStorm', 'pattern' => '/@noinspection\b/i'],
        ['tool' => 'PHPUnit', 'pattern' => '/@(?:codeCoverageIgnore(?:Start|End)?|coversNothing|doesNotPerformAssertions|ignoreDeprecations|requires)\b/i'],
        ['tool' => 'Infection', 'pattern' => '/@infection-ignore-(?:all|source-code|return-value)\b/i'],
        ['tool' => 'Rector', 'pattern' => '/@noRector\b/i'],
    ];

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

    private const array PEST_ROOT_CALLS = [
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

    private const array PEST_SKIP_METHODS = [
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

    private const array PHP_EXTENSIONS = [
        'inc' => true,
        'php' => true,
        'phtml' => true,
    ];

    private const array PHPUNIT_ATTRIBUTES = [
        'codecoverageignore' => true,
        'coversnothing' => true,
        'doesnotperformassertions' => true,
        'ignoredeprecations' => true,
        'ignorephpunitdeprecations' => true,
        'ignorephpunitwarnings' => true,
        'requiresenvironmentvariable' => true,
        'requiresfunction' => true,
        'requiresmethod' => true,
        'requiresoperatingsystem' => true,
        'requiresoperatingsystemfamily' => true,
        'requiresphp' => true,
        'requiresphpextension' => true,
        'requiresphpunit' => true,
        'requiresphpunitextension' => true,
        'requiressetting' => true,
        'withouterrorhandler' => true,
    ];

    /**
     * @param array{
     *     files: int,
     *     findings: list<array{file: string, line: int, tool: string, directive: string}>,
     *     errors: list<string>
     * } $scan
     */
    public function format(array $scan): string
    {
        if ($scan['findings'] === [] && $scan['errors'] === []) {
            return sprintf('Skip directive scan passed: %d PHP file(s) checked.%s', $scan['files'], PHP_EOL);
        }

        $lines = [
            sprintf(
                'Skip directive scan failed: %d finding(s) in %d PHP file(s).',
                count($scan['findings']),
                $scan['files'],
            ),
        ];
        $activeTool = null;

        foreach ($scan['findings'] as $finding) {
            if ($finding['tool'] !== $activeTool) {
                $activeTool = $finding['tool'];
                $lines[] = '';
                $lines[] = $activeTool;
                $lines[] = str_repeat('-', strlen($activeTool));
            }

            $lines[] = sprintf('  %s:%d  %s', $finding['file'], $finding['line'], $finding['directive']);
        }

        if ($scan['errors'] !== []) {
            $lines[] = '';
            $lines[] = 'Scanner errors';
            $lines[] = '--------------';

            foreach ($scan['errors'] as $error) {
                $lines[] = '  ' . $error;
            }
        }

        if ($scan['findings'] !== []) {
            $lines[] = '';
            $lines[] = 'Resolve the underlying issue and remove each skip directive.';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return array{
     *     files: int,
     *     findings: list<array{file: string, line: int, tool: string, directive: string}>,
     *     errors: list<string>
     * }
     */
    public function scan(string $root): array
    {
        $resolvedRoot = realpath($root);

        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot) || !is_readable($resolvedRoot)) {
            return [
                'files' => 0,
                'findings' => [],
                'errors' => [sprintf('Scan root is not a readable directory: %s', $root)],
            ];
        }

        [$files, $errors] = $this->phpFiles($resolvedRoot);
        $findings = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if (!is_string($contents)) {
                $errors[] = sprintf('PHP source file is not readable: %s', $this->relativePath($resolvedRoot, $file));

                continue;
            }

            $relativeFile = $this->relativePath($resolvedRoot, $file);
            $findings = [...$findings, ...$this->fileFindings($relativeFile, $contents)];
        }

        usort(
            $findings,
            static fn(array $left, array $right): int => [$left['tool'], $left['file'], $left['line'], $left['directive']]
                <=> [$right['tool'], $right['file'], $right['line'], $right['directive']],
        );

        return [
            'files' => count($files),
            'findings' => $findings,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<array{file: string, line: int, tool: string, directive: string}>
     */
    private function attributeFindings(string $file, array $tokens): array
    {
        $findings = [];
        $attributeDepth = 0;

        foreach ($tokens as $token) {
            $finding = $this->phpunitAttributeFinding($file, $token, $attributeDepth);

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
     * @return list<array{file: string, line: int, tool: string, directive: string}>
     */
    private function commentFindings(string $file, string $comment, int $startLine): array
    {
        $findings = [];

        foreach (self::COMMENT_RULES as $rule) {
            $matches = [];

            if (preg_match_all($rule['pattern'], $comment, $matches, PREG_OFFSET_CAPTURE) !== false) {
                foreach ($matches[0] as [$directive, $offset]) {
                    $line = $startLine + substr_count(substr($comment, 0, $offset), "\n");
                    $findings[] = $this->finding($file, $line, $rule['tool'], $directive);
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<array{file: string, line: int, tool: string, directive: string}>
     */
    private function commentTokenFindings(string $file, array $tokens): array
    {
        $findings = [];

        foreach ($tokens as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $findings = [...$findings, ...$this->commentFindings($file, $token[1], $token[2])];
            }
        }

        return $findings;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     * @return list<array{file: string, line: int, tool: string, directive: string}>
     */
    private function executableFindings(string $file, array $tokens): array
    {
        $findings = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_STRING || ($tokens[$index + 1]['text'] ?? null) !== '(') {
                continue;
            }

            $name = strtolower($token['text']);

            if ($name === 'todo' && !in_array(($tokens[$index - 1]['text'] ?? null), ['->', '?->', '::', 'function'], true)) {
                $findings[] = $this->finding($file, $token['line'], 'Pest', 'todo()');

                continue;
            }

            if ($name === 'marktestskipped' || $name === 'marktestincomplete') {
                $findings[] = $this->finding($file, $token['line'], 'PHPUnit', $token['text'] . '()');

                continue;
            }

            if (!isset(self::PEST_SKIP_METHODS[$name]) || ($tokens[$index - 1]['text'] ?? null) !== '->') {
                continue;
            }

            $rootCall = $this->rootCallBeforeOperator($tokens, $index - 1);

            if (is_string($rootCall) && isset(self::PEST_ROOT_CALLS[$rootCall])) {
                $findings[] = $this->finding($file, $token['line'], 'Pest', '->' . $token['text'] . '()');
            }
        }

        return $findings;
    }

    /**
     * @return list<array{file: string, line: int, tool: string, directive: string}>
     */
    private function fileFindings(string $file, string $contents): array
    {
        $tokens = token_get_all($contents);

        return [
            ...$this->commentTokenFindings($file, $tokens),
            ...$this->attributeFindings($file, $tokens),
            ...$this->executableFindings($file, $this->significantTokens($tokens)),
        ];
    }

    /**
     * @return array{file: string, line: int, tool: string, directive: string}
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

    private function isNameToken(int $id): bool
    {
        return $id === T_STRING
            || $id === T_NAME_FULLY_QUALIFIED
            || $id === T_NAME_QUALIFIED
            || $id === T_NAME_RELATIVE;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     */
    private function matchingOpenParenthesis(array $tokens, int $closeIndex): ?int
    {
        $depth = 0;

        for ($index = $closeIndex; $index >= 0; $index--) {
            if ($tokens[$index]['text'] === ')') {
                $depth++;
            } elseif ($tokens[$index]['text'] === '(') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @param array{int, string, int}|string $token */
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
                static function (\SplFileInfo $entry) use ($root): bool {
                    if ($entry->isLink()) {
                        return false;
                    }

                    if ($entry->isDir()) {
                        $relative = ltrim(substr($entry->getPathname(), strlen($root)), DIRECTORY_SEPARATOR);
                        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                        return !in_array($entry->getFilename(), self::EXCLUDED_DIRECTORIES, true)
                            && !in_array($relative, self::EXCLUDED_DIRECTORIES, true);
                    }

                    return isset(self::PHP_EXTENSIONS[strtolower($entry->getExtension())]);
                },
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

    /**
     * @param array{int, string, int}|string $token
     * @return array{file: string, line: int, tool: string, directive: string}|null
     */
    private function phpunitAttributeFinding(string $file, array|string $token, int $depth): ?array
    {
        if ($depth === 0 || !is_array($token) || !$this->isNameToken($token[0])) {
            return null;
        }

        $attribute = $this->baseName($token[1]);

        return isset(self::PHPUNIT_ATTRIBUTES[strtolower($attribute)])
            ? $this->finding($file, $token[2], 'PHPUnit', '#[' . $attribute . ']')
            : null;
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

        if (!is_array($nameToken) || ($nameToken['id'] ?? null) !== T_STRING) {
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
     * @param list<array{int, string, int}|string> $tokens
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
}
