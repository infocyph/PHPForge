<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Infocyph\PHPForge\Composer\TaskCatalog;
use PHPStan\DependencyInjection\ContainerFactory;

final class PhpstanActiveConfig
{
    public const DEFAULT_PARAMETER = 'cognitive_complexity';

    /**
     * @return array{
     *     tool: string,
     *     source: string,
     *     config_file: string,
     *     config_path: string,
     *     memory_limit: string,
     *     analyse_paths: list<string>
     * }
     */
    public function context(): array
    {
        $configPath = Paths::firstConfig(['phpstan.neon', 'phpstan.neon.dist']);
        $configFile = basename($configPath);

        return [
            'tool' => 'phpstan',
            'source' => ConfigInventory::source($configFile),
            'config_file' => $configFile,
            'config_path' => $configPath,
            'memory_limit' => $this->phpstanMemoryLimit(),
            'analyse_paths' => $this->phpstanAnalysePaths(),
        ];
    }

    /**
     * @return array{
     *     tool: string,
     *     source: string,
     *     config_file: string,
     *     config_path: string,
     *     memory_limit: string,
     *     analyse_paths: list<string>,
     *     parameter: string|null,
     *     parameters: mixed
     * }
     */
    public function summary(string $parameter = self::DEFAULT_PARAMETER, bool $all = false): array
    {
        $configPath = Paths::firstConfig(['phpstan.neon', 'phpstan.neon.dist']);
        $configFile = basename($configPath);
        $parameters = $this->phpstanParameters($configPath);

        return [
            'tool' => 'phpstan',
            'source' => ConfigInventory::source($configFile),
            'config_file' => $configFile,
            'config_path' => $configPath,
            'memory_limit' => $this->phpstanMemoryLimit(),
            'analyse_paths' => $this->phpstanAnalysePaths(),
            'parameter' => $all ? null : $parameter,
            'parameters' => $all
                ? $this->normalizeValue($parameters)
                : $this->normalizeValue($parameters[$parameter] ?? null),
        ];
    }

    /**
     * @param array{
     *     tool: string,
     *     source: string,
     *     config_file: string,
     *     config_path: string,
     *     memory_limit: string,
     *     analyse_paths: list<string>,
     *     parameter: string|null,
     *     parameters: mixed
     * } $activeConfig
     */
    public function text(array $activeConfig): string
    {
        $lines = [
            'Active PHPStan Config',
            'Source:       ' . $activeConfig['source'],
            'Config file:  ' . $activeConfig['config_file'],
            'Config path:  ' . $activeConfig['config_path'],
            'Memory limit: ' . $activeConfig['memory_limit'],
            'Analyse path: ' . implode(', ', $activeConfig['analyse_paths'] !== [] ? $activeConfig['analyse_paths'] : ['(from config only)']),
        ];

        if (is_string($activeConfig['parameter'])) {
            $lines[] = 'Parameter:    ' . $activeConfig['parameter'];
        }

        $encoded = json_encode($activeConfig['parameters'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $lines[] = 'Value:';
        $lines[] = is_string($encoded) ? $encoded : 'null';

        return implode(PHP_EOL, $lines);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    /**
     * @return list<string>
     */
    private function phpstanAnalysePaths(): array
    {
        $command = TaskCatalog::staticAnalysis()[0] ?? [];
        $paths = [];
        $seenAnalyse = false;

        foreach ($command as $argument) {
            if (!$seenAnalyse) {
                $seenAnalyse = $argument === 'analyse';

                continue;
            }

            if ($argument === '' || str_starts_with($argument, '--')) {
                continue;
            }

            $paths[] = $argument;
        }

        return $paths;
    }

    private function phpstanMemoryLimit(): string
    {
        $value = getenv('IC_PHPSTAN_MEMORY_LIMIT');

        return is_string($value) && $value !== '' ? $value : '1G';
    }

    /**
     * @return array<string, mixed>
     */
    private function phpstanParameters(string $configPath): array
    {
        $factory = new ContainerFactory(Paths::projectRootPath());
        $container = $factory->create($this->phpstanTempDir(), [$configPath], []);
        $parameters = $container->getParameters();

        return ArrayShape::stringKeyed($parameters);
    }

    private function phpstanTempDir(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'phpforge-phpstan-'
            . md5(strtolower(Paths::projectRootPath()));
    }
}
