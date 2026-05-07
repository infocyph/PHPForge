<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class WorkflowWrapper
{
    /**
     * @param array<string, string> $withValues
     */
    public static function update(string $contents, string $workflowRef, array $withValues): ?string
    {
        $lines = preg_split('/\R/', $contents);

        if (!is_array($lines)) {
            return null;
        }

        $endsWithLineBreak = preg_match('/\R\z/', $contents) === 1;
        self::replaceWorkflowRef($lines, $workflowRef);

        $withBlock = self::withBlockBounds($lines);

        if (!is_array($withBlock)) {
            return null;
        }

        [$blockStart, $blockEnd, $withIndent] = $withBlock;
        [$keyIndent, $keyIndexes] = self::indexWithKeys($lines, $blockStart, $blockEnd, $withIndent);
        self::applyWithValues($lines, $withValues, $keyIndent, $keyIndexes, $blockEnd);

        $updated = implode(PHP_EOL, $lines);

        return $endsWithLineBreak ? ($updated . PHP_EOL) : $updated;
    }

    public static function yamlDoubleQuoted(string $value): string
    {
        return '"' . str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '\\r', '\\n'],
            $value,
        ) . '"';
    }

    public static function yamlSingleQuoted(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, string> $withValues
     * @param array<string, int> $keyIndexes
     */
    private static function applyWithValues(array &$lines, array $withValues, string $keyIndent, array $keyIndexes, int $blockEnd): void
    {
        foreach ($withValues as $key => $value) {
            $updatedLine = $keyIndent . $key . ': ' . $value;

            if (isset($keyIndexes[$key])) {
                $lines[$keyIndexes[$key]] = $updatedLine;

                continue;
            }

            array_splice($lines, $blockEnd, 0, [$updatedLine]);
            $blockEnd++;
        }
    }

    /**
     * @param array<int, string> $lines
     * @return array{int|null, int}
     */
    private static function findWithLine(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)with:\s*$/', $line, $matches) === 1) {
                return [$index, strlen($matches[1])];
            }
        }

        return [null, 0];
    }

    /**
     * @param array<int, string> $lines
     * @return array{string, array<string, int>}
     */
    private static function indexWithKeys(array $lines, int $blockStart, int $blockEnd, int $withIndent): array
    {
        $keyIndent = str_repeat(' ', $withIndent + 2);
        $keyIndexes = [];

        for ($index = $blockStart; $index < $blockEnd; $index++) {
            if (preg_match('/^(\s*)([A-Za-z_][A-Za-z0-9_]*):\s*(.*?)\s*$/', $lines[$index], $matches) !== 1) {
                continue;
            }

            if (strlen($matches[1]) <= $withIndent) {
                continue;
            }

            $keyIndent = $matches[1];
            $keyIndexes[$matches[2]] = $index;
        }

        return [$keyIndent, $keyIndexes];
    }

    private static function lineIndent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * @param array<int, string> $lines
     */
    private static function replaceWorkflowRef(array &$lines, string $workflowRef): void
    {
        $workflowUsesPattern = '/^(\s*uses:\s*infocyph\/phpforge\/\.github\/workflows\/security-standards\.yml)@([^\s#]+)(\s*(?:#.*)?)$/';

        foreach ($lines as $index => $line) {
            if (preg_match($workflowUsesPattern, $line, $matches) === 1) {
                $lines[$index] = $matches[1] . '@' . $workflowRef . $matches[3];
            }
        }
    }

    /**
     * @param array<int, string> $lines
     * @return array{int, int, int}|null
     */
    private static function withBlockBounds(array $lines): ?array
    {
        [$withIndex, $withIndent] = self::findWithLine($lines);

        if (!is_int($withIndex)) {
            return null;
        }

        $blockStart = $withIndex + 1;
        $blockEnd = count($lines);

        for ($index = $blockStart; $index < count($lines); $index++) {
            if (trim($lines[$index]) === '') {
                continue;
            }

            if (self::lineIndent($lines[$index]) <= $withIndent) {
                $blockEnd = $index;

                break;
            }
        }

        return [$blockStart, $blockEnd, $withIndent];
    }
}
