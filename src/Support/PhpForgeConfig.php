<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class PhpForgeConfig
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(private readonly array $config) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            return new self([]);
        }

        $contents = file_get_contents($path);

        if (!is_string($contents) || $contents === '') {
            return new self([]);
        }

        $decoded = json_decode($contents, true);

        return new self(self::stringKeyedArray($decoded));
    }

    /**
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>} $options
     *
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>}
     */
    public function applyDuplicateOptions(array $options): array
    {
        $section = $this->section('duplicates');

        $mode = $this->stringValue($section, 'mode');
        $json = $this->boolValue($section, 'json');
        $normalize = $this->boolValue($section, 'normalize');
        $fuzzy = $this->boolValue($section, 'fuzzy');
        $nearMiss = $this->boolValue($section, 'near_miss');
        $minLines = $this->intValue($section, 'min_lines');
        $minTokens = $this->intValue($section, 'min_tokens');
        $minStatements = $this->intValue($section, 'min_statements');
        $minSimilarity = $this->floatValue($section, 'min_similarity');
        $baseline = $this->stringValue($section, 'baseline');
        $writeBaseline = $this->stringValue($section, 'write_baseline');

        $paths = $this->stringList($this->value($section, 'paths'));

        if ($mode !== null) {
            $options['mode'] = $mode;
        }

        if ($json !== null) {
            $options['json'] = $json;
        }

        if ($normalize !== null) {
            $options['normalize'] = $normalize;
        }

        if ($fuzzy !== null) {
            $options['fuzzy'] = $fuzzy;
        }

        if ($nearMiss !== null) {
            $options['nearMiss'] = $nearMiss;
        }

        if ($minLines !== null) {
            $options['minLines'] = max(1, $minLines);
        }

        if ($minTokens !== null) {
            $options['minTokens'] = max(1, $minTokens);
        }

        if ($minStatements !== null) {
            $options['minStatements'] = max(1, $minStatements);
        }

        if ($minSimilarity !== null) {
            $options['minSimilarity'] = $this->normalizeSimilarity($minSimilarity);
        }

        if ($baseline !== null) {
            $options['baseline'] = $baseline;
        }

        if ($writeBaseline !== null) {
            $options['writeBaseline'] = $writeBaseline;
        }

        if ($paths !== []) {
            $options['paths'] = $paths;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function syntaxPaths(): array
    {
        return $this->stringList($this->value($this->section('syntax'), 'paths'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $array = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $array[$key] = $item;
            }
        }

        return $array;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function boolValue(array $section, string $key): ?bool
    {
        $value = $this->value($section, $key);

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function floatValue(array $section, string $key): ?float
    {
        $value = $this->value($section, $key);

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function intValue(array $section, string $key): ?int
    {
        $value = $this->value($section, $key);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function normalizeSimilarity(float $value): float
    {
        return $value > 1.0 ? min(100.0, $value) / 100.0 : max(0.0, min(1.0, $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        $section = $this->config[$name] ?? [];

        return self::stringKeyedArray($section);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function stringValue(array $section, string $key): ?string
    {
        $value = $this->value($section, $key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function value(array $section, string $key): mixed
    {
        foreach ([$key, str_replace('_', '-', $key), lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))))] as $name) {
            if (array_key_exists($name, $section)) {
                return $section[$name];
            }
        }

        return null;
    }
}
