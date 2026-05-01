<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class QualitySummary
{
    /**
     * @param list<array{heading:string,status:string,exit_code:int}> $results
     */
    public static function write(array $results): void
    {
        $path = self::path();

        if (!is_string($path)) {
            return;
        }

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode(self::payload($results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private static function labelFromHeading(string $heading): string
    {
        $label = preg_replace('/\s+\(.+\)$/', '', $heading);

        return is_string($label) && $label !== '' ? $label : $heading;
    }

    /**
     * @param list<array{tool:string,label:string,status:string,exit_code:int}> $tools
     */
    private static function overall(array $tools): string
    {
        foreach ($tools as $tool) {
            if ($tool['status'] === 'failure') {
                return 'failure';
            }
        }

        return 'success';
    }

    private static function path(): ?string
    {
        foreach (['PHPFORGE_QUALITY_SUMMARY', 'IC_QUALITY_SUMMARY'] as $name) {
            $path = getenv($name);

            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param list<array{heading:string,status:string,exit_code:int}> $results
     * @return array{schema:string,generated_at:string,overall:string,tools:list<array{tool:string,label:string,status:string,exit_code:int}>}
     */
    private static function payload(array $results): array
    {
        $tools = array_map(
            static fn(array $result): array => [
                'tool' => self::toolKey($result['heading']),
                'label' => self::labelFromHeading($result['heading']),
                'status' => self::status($result),
                'exit_code' => $result['exit_code'],
            ],
            $results,
        );

        return [
            'schema' => 'phpforge-quality-summary-v1',
            'generated_at' => gmdate('c'),
            'overall' => self::overall($tools),
            'tools' => $tools,
        ];
    }

    /**
     * @param array{heading:string,status:string,exit_code:int} $result
     */
    private static function status(array $result): string
    {
        return match ($result['status']) {
            'PASS' => 'success',
            'SKIP' => 'skipped',
            default => $result['exit_code'] === 0 ? 'success' : 'failure',
        };
    }

    private static function toolKey(string $heading): string
    {
        $label = strtolower(self::labelFromHeading($heading));

        return match ($label) {
            'checking syntax' => 'syntax',
            'duplicate code' => 'duplicates',
            'phpcs' => 'phpcs',
            'pest' => 'pest',
            'pint' => 'pint',
            'phpstan' => 'phpstan',
            'psalm' => 'psalm',
            'rector' => 'rector',
            default => preg_replace('/[^a-z0-9]+/', '_', $label) ?: 'unknown',
        };
    }
}
