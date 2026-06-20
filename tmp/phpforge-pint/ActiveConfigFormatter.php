<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class ActiveConfigFormatter
{
    public function renderValue(mixed $value, ?string $format): string
    {
        if (is_string($value) && in_array($format, ['text', 'yaml', 'neon', 'php'], true)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'null';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function text(array $rows): string
    {
        $blocks = [];

        foreach ($rows as $summary) {
            $lines = [
                strtoupper($this->stringValue($summary, 'tool', 'config')),
                'Source:      ' . $this->stringValue($summary, 'source'),
                'Config file: ' . $this->stringValue($summary, 'config_file'),
                'Config path: ' . $this->stringValue($summary, 'config_path'),
            ];

            if (array_key_exists('parameter', $summary)) {
                $lines[] = 'Parameter:   ' . $this->stringValue($summary, 'parameter');
                $lines[] = 'Value:';
                $lines[] = $this->renderValue($summary['value'] ?? null, null);
                $blocks[] = implode(PHP_EOL, $lines);

                continue;
            }

            $lines[] = 'Config:';
            $lines[] = $this->renderValue($summary['config'] ?? null, $this->nullableStringValue($summary, 'format'));

            if (($summary['tool'] ?? null) === 'phpstan') {
                $lines[] = 'Effective:';
                $lines[] = $this->renderValue($summary['effective'] ?? null, 'json');
            }

            $blocks[] = implode(PHP_EOL, $lines);
        }

        return implode(PHP_EOL . PHP_EOL, $blocks);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function nullableStringValue(array $summary, string $key): ?string
    {
        $value = $summary[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function stringValue(array $summary, string $key, string $fallback = ''): string
    {
        $value = $summary[$key] ?? null;

        return is_string($value) ? $value : $fallback;
    }
}
