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

    private ?string $phpProbeDirective = self::DEFAULT_PHPPROBE_DIRECTIVE;

    private function __construct(private readonly string $root) {}

    public static function load(string $root): self
    {
        $configuration = new self($root);
        $configuration->loadPhpProbe();

        return $configuration;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<array{tool: string, pattern: non-empty-string}>
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
            $enabled = $options['suppressionEnabled'] ?? true;
            $directive = $options['suppressionDirective'] ?? self::DEFAULT_PHPPROBE_DIRECTIVE;
            $this->phpProbeDirective = $enabled === true && is_string($directive) && trim($directive) !== ''
                ? trim($directive)
                : null;
        } catch (\Throwable $exception) {
            $this->errors[] = sprintf('Unable to read PHPProbe directives from %s: %s', $path, $exception->getMessage());
        }
    }
}
