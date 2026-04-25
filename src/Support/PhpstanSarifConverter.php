<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class PhpstanSarifConverter
{
    public function convert(string $input, string $output): int
    {
        if ($input === '') {
            fwrite(STDERR, 'Error: missing input file.' . PHP_EOL);
            fwrite(STDERR, 'Usage: phpforge phpstan-sarif <phpstan-json> [sarif-output]' . PHP_EOL);

            return 2;
        }

        if (!is_file($input) || !is_readable($input)) {
            fwrite(STDERR, "Error: input file not found or unreadable: {$input}" . PHP_EOL);

            return 2;
        }

        $raw = file_get_contents($input);

        if ($raw === false) {
            fwrite(STDERR, "Error: failed to read input file: {$input}" . PHP_EOL);

            return 2;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            fwrite(STDERR, 'Error: input is not valid JSON.' . PHP_EOL);

            return 2;
        }

        return $this->writeSarif($decoded, $output);
    }

    private function intValue(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    private function normalizeUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $cwd = getcwd() ?: null;

        if ($cwd !== null) {
            $cwd = rtrim(str_replace('\\', '/', $cwd), '/');

            if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 && stripos($normalized, $cwd . '/') === 0) {
                $normalized = substr($normalized, strlen($cwd) + 1);
            } elseif (str_starts_with($normalized, '/') && str_starts_with($normalized, $cwd . '/')) {
                $normalized = substr($normalized, strlen($cwd) + 1);
            }
        }

        $normalized = ltrim($normalized, './');

        return $normalized === '' ? 'unknown.php' : $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function phpstanFileMessages(mixed $files): array
    {
        if (!is_array($files)) {
            return [];
        }

        $results = [];

        foreach ($files as $filePath => $fileData) {
            if (!is_string($filePath) || !is_array($fileData)) {
                continue;
            }

            $results = [
                ...$results,
                ...$this->phpstanMessagesForFile($filePath, $fileData['messages'] ?? []),
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function phpstanInternalErrors(mixed $errors): array
    {
        if (!is_array($errors)) {
            return [];
        }

        $results = [];

        foreach ($errors as $error) {
            if (!is_string($error) || $error === '') {
                continue;
            }

            $results[] = [
                'ruleId' => 'phpstan.internal',
                'level' => 'error',
                'message' => ['text' => $error],
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function phpstanMessagesForFile(string $filePath, mixed $messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $results = [];

        foreach ($messages as $messageData) {
            if (!is_array($messageData)) {
                continue;
            }

            $ruleId = $this->stringValue($messageData['identifier'] ?? null, 'phpstan.issue');
            $results[] = [
                'ruleId' => $ruleId,
                'level' => 'error',
                'message' => ['text' => $this->stringValue($messageData['message'] ?? null, 'PHPStan issue')],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $this->normalizeUri($filePath)],
                        'region' => ['startLine' => max(1, $this->intValue($messageData['line'] ?? null, 1))],
                    ],
                ]],
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return list<array<string, mixed>>
     */
    private function phpstanResults(array $decoded): array
    {
        return [
            ...$this->phpstanInternalErrors($decoded['errors'] ?? []),
            ...$this->phpstanFileMessages($decoded['files'] ?? []),
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return list<array{id: string, name: string, shortDescription: array{text: string}}>
     */
    private function ruleDescriptors(array $results): array
    {
        $rules = [];

        foreach ($results as $result) {
            if (isset($result['ruleId']) && is_string($result['ruleId'])) {
                $rules[$result['ruleId']] = true;
            }
        }

        $ruleIds = array_keys($rules);
        sort($ruleIds);

        return array_map(
            static fn(string $ruleId): array => [
                'id' => $ruleId,
                'name' => $ruleId,
                'shortDescription' => ['text' => $ruleId],
            ],
            $ruleIds,
        );
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function writeSarif(array $decoded, string $output): int
    {
        $results = $this->phpstanResults($decoded);
        $sarif = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => 'PHPStan',
                        'informationUri' => 'https://phpstan.org/',
                        'rules' => $this->ruleDescriptors($results),
                    ],
                ],
                'results' => $results,
            ]],
        ];

        $encoded = json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            fwrite(STDERR, 'Error: failed to encode SARIF JSON.' . PHP_EOL);

            return 2;
        }

        if (file_put_contents($output, $encoded . PHP_EOL) === false) {
            fwrite(STDERR, "Error: failed to write output file: {$output}" . PHP_EOL);

            return 2;
        }

        fwrite(STDOUT, sprintf('SARIF generated: %s (%d findings)', $output, count($results)) . PHP_EOL);

        return 0;
    }
}
