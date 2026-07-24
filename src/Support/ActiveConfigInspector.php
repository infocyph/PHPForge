<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class ActiveConfigInspector
{
    /**
     * @param list<string> $selectedFiles
     * @return list<array<string, mixed>>
     */
    public function inspect(array $selectedFiles = [], ?string $parameter = null): array
    {
        $rows = [];

        foreach (ConfigInventory::activeTools() as $tool => $candidates) {
            if (!$this->toolSelected($candidates, $selectedFiles)) {
                continue;
            }

            $path = Paths::firstConfig($candidates);
            $file = basename($path);
            $summary = [
                'tool' => $tool,
                'candidates' => $candidates,
                'config_file' => $file,
                'config_path' => $path,
                'source' => ConfigInventory::source($file),
                'format' => $this->format($file),
                'config' => $this->parsedConfig($path, $file),
            ];

            if ($tool === 'phpstan') {
                $phpstan = new PhpstanActiveConfig();
                $summary['effective'] = $phpstan->context();
            }

            if (is_string($parameter) && $parameter !== '') {
                $summary['parameter'] = $parameter;
                $summary['value'] = $this->parameterValue($summary, $parameter);
                unset($summary['config'], $summary['effective']);
            }

            $rows[] = $summary;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decodeJson(string $contents): ?array
    {
        return $this->normalizedDecodedArray(json_decode($contents, true));
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decodeXml(string $contents): ?array
    {
        $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);

        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }

        $encoded = json_encode($xml, JSON_UNESCAPED_SLASHES);
        $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

        return $this->normalizedDecodedArray($decoded);
    }

    /**
     * @param array<mixed, mixed> $config
     */
    private function dotPath(array $config, string $parameter): mixed
    {
        $current = $config;

        foreach (explode('.', $parameter) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function format(string $file): string
    {
        if (str_ends_with($file, '.xml') || str_ends_with($file, '.xml.dist')) {
            return 'xml';
        }

        if (str_ends_with($file, '.neon') || str_ends_with($file, '.neon.dist')) {
            return 'neon';
        }

        if (str_ends_with($file, '.yaml') || str_ends_with($file, '.yml')) {
            return 'yaml';
        }

        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            'json' => 'json',
            'php' => 'php',
            default => 'text',
        };
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function normalizedDecodedArray(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        return ArrayShape::stringKeyed($decoded);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function parameterValue(array $summary, string $parameter): mixed
    {
        if (($summary['tool'] ?? null) === 'phpstan') {
            $effectiveParameters = (new PhpstanActiveConfig())->summary($parameter, true)['parameters'] ?? null;

            if (is_array($effectiveParameters) && array_key_exists($parameter, $effectiveParameters)) {
                return $effectiveParameters[$parameter];
            }
        }

        $config = $summary['config'] ?? null;

        if (!is_array($config)) {
            return null;
        }

        if (array_key_exists($parameter, $config)) {
            return $config[$parameter];
        }

        return $this->dotPath($config, $parameter);
    }

    private function parsedConfig(string $path, string $file): mixed
    {
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return null;
        }

        $decoded = match ($this->format($file)) {
            'json' => $this->decodeJson($contents),
            'xml' => $this->decodeXml($contents),
            'neon' => null,
            default => $contents,
        };

        return $decoded ?? $contents;
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $selectedFiles
     */
    private function toolSelected(array $candidates, array $selectedFiles): bool
    {
        if ($selectedFiles === []) {
            return true;
        }

        foreach ($selectedFiles as $selectedFile) {
            if (in_array($selectedFile, $candidates, true)) {
                return true;
            }
        }

        return false;
    }
}
