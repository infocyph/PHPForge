<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Infocyph\PHPProbe\Config\CliOptions;
use Infocyph\PHPProbe\Config\PhpProbeConfig;

final class SkipDirectiveConfiguration
{
    private const string DEFAULT_PHPPROBE_DIRECTIVE = '@phpprobe-ignore';

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, list<string>> */
    private array $exclusions = [];

    private ?string $phpProbeDirective = self::DEFAULT_PHPPROBE_DIRECTIVE;

    private function __construct(private readonly string $root) {}

    public static function load(string $root): self
    {
        $configuration = new self($root);
        $configuration->loadPhpProbe();
        $configuration->loadXmlExclusions('PHPCS', ['phpcs.xml.dist'], '//*[local-name()="exclude-pattern"]');
        $configuration->loadPhpstan();
        $configuration->loadXmlExclusions(
            'Psalm',
            ['psalm.xml', 'psalm.xml.dist'],
            '//*[local-name()="projectFiles"]/*[local-name()="ignoreFiles"]/*[local-name()="directory"]/@name'
                . ' | //*[local-name()="projectFiles"]/*[local-name()="ignoreFiles"]/*[local-name()="file"]/@name',
        );
        $configuration->loadXmlExclusions(
            'Pest',
            ['pest.xml', 'pest.xml.dist', 'phpunit.xml', 'phpunit.xml.dist'],
            '//*[local-name()="testsuite"]/*[local-name()="exclude"]'
                . ' | //*[local-name()="testsuite"]/*[local-name()="exclude"]/*[local-name()="directory"]'
                . ' | //*[local-name()="testsuite"]/*[local-name()="exclude"]/*[local-name()="file"]',
        );
        $configuration->exclusions['PHPUnit'] = $configuration->exclusions['Pest'] ?? [];
        $configuration->loadRector();

        return $configuration;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function excludes(string $tool, string $relativePath): bool
    {
        foreach ($this->exclusions[$tool] ?? [] as $pattern) {
            if ($this->pathMatches($relativePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{tool: string, pattern: string}>
     */
    public function extraCommentRules(): array
    {
        if (!is_string($this->phpProbeDirective)) {
            return [];
        }

        return [[
            'tool' => 'PHPProbe',
            'pattern' => '/' . preg_quote($this->phpProbeDirective, '/') . '/',
        ]];
    }

    /**
     * @param list<array{file: string, line: int, tool: string, directive: string}> $findings
     * @return list<array{file: string, line: int, tool: string, directive: string}>
     */
    public function includedFindings(string $relativePath, array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn(array $finding): bool => !$this->excludes($finding['tool'], $relativePath),
        ));
    }

    /**
     * @param non-empty-list<string> $candidates
     */
    private function configPath(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $path = $this->root . DIRECTORY_SEPARATOR . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        $projectRoot = realpath(Paths::projectRootPath());

        if (!is_string($projectRoot) || $projectRoot !== $this->root) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $path = Paths::bundledConfigFileOrNull($candidate);

            if (is_string($path)) {
                return $path;
            }
        }

        return null;
    }

    private function decodedPhpString(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value)
            : stripcslashes($value);
    }

    /** @return list<string> */
    private function effectivePhpstanExclusions(string $path): array
    {
        $projectRoot = realpath(Paths::projectRootPath());

        if (is_string($projectRoot) && $projectRoot === $this->root) {
            $parameters = new PhpstanActiveConfig()->summary('excludePaths')['parameters'] ?? [];

            return $this->stringValues($parameters);
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new \RuntimeException('configuration is not readable');
        }

        return $this->neonExcludePaths($contents);
    }

    private function loadPhpProbe(): void
    {
        $path = $this->configPath(['phpprobe.json']);

        if (!is_string($path)) {
            return;
        }

        try {
            $options = new CliOptions()
                ->mergeConfigWithPreset(PhpProbeConfig::fromFile($path), '')
                ->applyCommentOptions([
                    'excludes' => [],
                    'suppressionEnabled' => true,
                    'suppressionDirective' => self::DEFAULT_PHPPROBE_DIRECTIVE,
                ]);
            $this->exclusions['PHPProbe'] = $this->stringValues($options['excludes'] ?? []);
            $enabled = $options['suppressionEnabled'] ?? true;
            $directive = $options['suppressionDirective'] ?? self::DEFAULT_PHPPROBE_DIRECTIVE;
            $this->phpProbeDirective = $enabled === true && is_string($directive) && trim($directive) !== ''
                ? trim($directive)
                : null;
        } catch (\Throwable $exception) {
            $this->errors[] = sprintf('Unable to read PHPProbe exclusions from %s: %s', $path, $exception->getMessage());
        }
    }

    private function loadPhpstan(): void
    {
        $path = $this->configPath(['phpstan.neon', 'phpstan.neon.dist']);

        if (!is_string($path)) {
            return;
        }

        try {
            $this->exclusions['PHPStan'] = $this->effectivePhpstanExclusions($path);
        } catch (\Throwable $exception) {
            $this->errors[] = sprintf('Unable to read PHPStan exclusions from %s: %s', $path, $exception->getMessage());
        }
    }

    private function loadRector(): void
    {
        $path = $this->configPath(['rector.php']);

        if (!is_string($path)) {
            return;
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            $this->errors[] = sprintf('Unable to read Rector exclusions from %s: configuration is not readable', $path);

            return;
        }

        $this->exclusions['Rector'] = $this->rectorSkipPaths($contents, dirname($path));
    }

    /**
     * @param non-empty-list<string> $candidates
     */
    private function loadXmlExclusions(string $tool, array $candidates, string $xpath): void
    {
        $path = $this->configPath($candidates);

        if (!is_string($path)) {
            return;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($path, \SimpleXMLElement::class, LIBXML_NONET);

            if (!$xml instanceof \SimpleXMLElement) {
                throw new \RuntimeException('configuration is not valid XML');
            }

            $matches = $xml->xpath($xpath);
            $this->exclusions[$tool] = is_array($matches)
                ? array_values(array_filter(array_map(static fn(\SimpleXMLElement $match): string => trim((string) $match), $matches)))
                : [];
        } catch (\Throwable $exception) {
            $this->errors[] = sprintf('Unable to read %s exclusions from %s: %s', $tool, $path, $exception->getMessage());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<string> */
    private function neonExcludePaths(string $contents): array
    {
        $patterns = [];
        $baseIndent = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if ($baseIndent === null) {
                if (preg_match('/^(\s*)excludePaths\s*:/', $line, $matches) === 1) {
                    $baseIndent = strlen($matches[1]);
                }

                continue;
            }

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent <= $baseIndent) {
                break;
            }

            if (preg_match('/^\s*-\s*(["\']?)(.+?)\1\s*(?:#.*)?$/', $line, $matches) === 1) {
                $patterns[] = trim($matches[2]);
            }
        }

        return array_values(array_unique($patterns));
    }

    private function pathMatches(string $relativePath, string $configuredPattern): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');
        $absolute = $this->root . '/' . $relative;
        $pattern = str_replace(['\\', '%currentWorkingDirectory%'], ['/', $this->root], trim($configuredPattern));
        $pattern = rtrim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        $candidates = str_starts_with($pattern, '/') ? [$absolute] : [$relative, '/' . $relative, $absolute];

        foreach ($candidates as $candidate) {
            $normalizedPattern = str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;

            if ($candidate === $normalizedPattern || str_starts_with($candidate, $normalizedPattern . '/')) {
                return true;
            }

            if (strpbrk($normalizedPattern, '*?[') !== false && fnmatch($normalizedPattern, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<string>
     */
    private function rectorCallStringPaths(array $tokens, int $nameIndex, string $configDirectory): array
    {
        return $this->rectorStringPaths($this->rectorCallTokens($tokens, $nameIndex), $configDirectory);
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<array{int, string, int}|string>
     */
    private function rectorCallTokens(array $tokens, int $nameIndex): array
    {
        $callTokens = [];
        $parenthesisDepth = 0;
        $insideCall = false;

        for ($index = $nameIndex + 1, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $parenthesisDepth++;
                $insideCall = true;

                continue;
            }

            if (!$insideCall) {
                continue;
            }

            if ($text === ')') {
                $parenthesisDepth--;

                if ($parenthesisDepth === 0) {
                    break;
                }
            }

            $callTokens[] = $token;
        }

        return $callTokens;
    }

    /** @return list<string> */
    private function rectorSkipPaths(string $contents, string $configDirectory): array
    {
        $tokens = token_get_all($contents);
        $paths = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), ['withskip', 'withskippath'], true)) {
                continue;
            }

            $paths = [...$paths, ...$this->rectorCallStringPaths($tokens, $index, $configDirectory)];
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @return list<string>
     */
    private function rectorStringPaths(array $tokens, string $configDirectory): array
    {
        $paths = [];
        $base = null;

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_DIR) {
                $base = $configDirectory;

                continue;
            }

            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'getcwd') {
                $base = $this->root;

                continue;
            }

            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = $this->decodedPhpString($token[1]);
            $paths[] = is_string($base) ? rtrim($base, '/\\') . '/' . ltrim($value, '/\\') : $value;
            $base = null;
        }

        return $paths;
    }

    /** @return list<string> */
    private function stringValues(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) !== '' ? [trim($value)] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $strings = [...$strings, ...$this->stringValues($item)];
        }

        return array_values(array_unique($strings));
    }
}
