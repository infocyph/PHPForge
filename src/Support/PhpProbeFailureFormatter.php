<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class PhpProbeFailureFormatter
{
    /**
     * @param list<string> $task
     */
    public static function format(array $task, string $stdout): ?string
    {
        if (!self::supports($task)) {
            return null;
        }

        try {
            $document = ArrayShape::stringKeyed(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }

        $results = ArrayShape::stringKeyed($document['results'] ?? null);
        $details = self::failureDetails($results);

        if ($details === []) {
            return null;
        }

        return implode(PHP_EOL, [
            ...self::summary(ArrayShape::stringKeyed($document['summary'] ?? null)),
            '',
            'Failure details',
            '===============',
            ...$details,
        ]) . PHP_EOL;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function commentDetails(array $payload): array
    {
        $findings = self::list($payload['findings'] ?? null);
        $lines = [sprintf('Found %d comment policy finding(s):', count($findings))];

        foreach ($findings as $finding) {
            $finding = ArrayShape::stringKeyed($finding);
            $file = self::text($finding['file'] ?? null, 'unknown file');
            $line = self::integer($finding['line'] ?? null, 1);
            $severity = strtoupper(self::text($finding['severity'] ?? null, 'warning'));
            $type = self::text($finding['subtype'] ?? null, self::text($finding['type'] ?? null, 'comment_policy'));
            $message = self::text($finding['message'] ?? null, 'Comment policy finding.');
            $lines[] = sprintf('  %s %s:%d [%s]', $severity, $file, $line, $type);
            $lines[] = self::indent($message, 4);

            $suggestion = self::text($finding['suggestion'] ?? null);

            if ($suggestion !== '') {
                $lines[] = self::indent('Suggestion: ' . $suggestion, 4);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function duplicateDetails(array $payload): array
    {
        $clones = self::list($payload['clones'] ?? null);
        $lines = [sprintf(
            'Found %d clone group(s) with %d duplicated lines in %d PHP files:',
            count($clones),
            self::integer($payload['duplicated_lines'] ?? null),
            self::integer($payload['files'] ?? null),
        )];

        foreach ($clones as $index => $clone) {
            $clone = ArrayShape::stringKeyed($clone);
            $lines[] = sprintf(
                '  %d. Lines: %d, Similarity: %.0f%%, Engine: %s, Score: %.1f',
                $index + 1,
                self::integer($clone['lines'] ?? null),
                self::number($clone['similarity'] ?? null) * 100,
                self::text($clone['source'] ?? null, 'unknown'),
                self::number($clone['score'] ?? null),
            );

            foreach (self::list($clone['occurrences'] ?? null) as $occurrence) {
                $occurrence = ArrayShape::stringKeyed($occurrence);
                $lines[] = sprintf(
                    '     %s:%d-%d',
                    self::text($occurrence['file'] ?? null, 'unknown file'),
                    self::integer($occurrence['start_line'] ?? null, 1),
                    self::integer($occurrence['end_line'] ?? null, 1),
                );
            }
        }

        $lines[] = sprintf('%.2f%% duplicated lines.', self::number($payload['duplicate_percentage'] ?? null));

        return $lines;
    }

    /**
     * @param array<string, mixed> $results
     * @return list<string>
     */
    private static function failureDetails(array $results): array
    {
        $details = [];

        foreach ($results as $checker => $value) {
            $result = ArrayShape::stringKeyed($value);

            if (self::integer($result['exit_code'] ?? null) === 0) {
                continue;
            }

            $payload = ArrayShape::stringKeyed($result['payload'] ?? null);
            $lines = match ($checker) {
                'syntax' => self::syntaxDetails($payload),
                'duplicates' => self::duplicateDetails($payload),
                'comments' => self::commentDetails($payload),
                default => self::unknownDetails($payload),
            };
            $stderr = trim(self::text($result['stderr'] ?? null));

            if ($stderr !== '') {
                $lines[] = self::indent($stderr, 2);
            }

            $title = match ($checker) {
                'syntax' => 'Syntax',
                'duplicates' => 'Duplicate Code',
                'comments' => 'Comment Policy',
                default => ucfirst($checker),
            };
            $details = [...$details, '', 'FAIL ' . $title, str_repeat('-', strlen($title) + 5), ...$lines];
        }

        return $details;
    }

    private static function indent(string $value, int $spaces): string
    {
        return preg_replace('/^/m', str_repeat(' ', $spaces), $value) ?? $value;
    }

    private static function integer(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : $default;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private static function number(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string, mixed> $summary
     * @return list<string>
     */
    private static function summary(array $summary): array
    {
        $checks = ArrayShape::stringKeyed($summary['checks'] ?? null);
        $skipped = array_fill_keys(array_filter(self::list($summary['skipped'] ?? null), is_string(...)), true);
        $lines = ['PHPProbe check summary:', sprintf('  %-14s %s', 'Checker', 'Result'), sprintf('  %-14s %s', '--------------', '------')];

        foreach (['syntax', 'duplicates', 'comments'] as $checker) {
            if (array_key_exists($checker, $skipped)) {
                $result = 'SKIP';
            } elseif (array_key_exists($checker, $checks)) {
                $result = self::integer($checks[$checker], 1) === 0 ? 'PASS' : 'FAIL';
            } else {
                continue;
            }

            $lines[] = sprintf('  %-14s %s', $checker, $result);
        }

        $lines[] = sprintf('Overall exit: %d', self::integer($summary['exit_code'] ?? null, 1));

        return $lines;
    }

    /**
     * @param list<string> $task
     */
    private static function supports(array $task): bool
    {
        $binary = strtolower(basename(str_replace('\\', '/', $task[1] ?? '')));

        return $binary === 'phpprobe'
            && ($task[2] ?? null) === 'check'
            && in_array('--format=json', $task, true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function syntaxDetails(array $payload): array
    {
        $failures = self::list($payload['failures'] ?? null);
        $lines = [sprintf('Syntax errors in %d file(s):', count($failures))];

        foreach ($failures as $failure) {
            $failure = ArrayShape::stringKeyed($failure);
            $lines[] = '  ' . self::text($failure['file'] ?? null, 'unknown file');
            $lines[] = self::indent(self::text($failure['message'] ?? null, 'Unknown syntax failure'), 4);
        }

        return $lines;
    }

    private static function text(mixed $value, string $default = ''): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function unknownDetails(array $payload): array
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [is_string($json) ? $json : 'No diagnostic output was produced.'];
    }
}
