<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\ConfigInventory;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PublishConfigCommand extends Command
{
    /**
     * @var non-empty-list<string>
     */
    private const PHPPROBE_PRESETS = ['phpstorm', 'standard', 'strict'];

    public function __construct()
    {
        parent::__construct('ic:publish-config');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Publish bundled PHPForge config files into the project.')
            ->addArgument('files', InputArgument::IS_ARRAY, 'Specific config files to publish.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Publish all bundled config files.')
            ->addOption('phpprobe-preset', null, InputOption::VALUE_REQUIRED, 'Apply a PHPProbe preset when publishing phpprobe.json: phpstorm, standard, or strict.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing project files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = $this->resolveFiles($input);
        $force = (bool) $input->getOption('force');

        $phpprobePreset = $this->resolvePhpprobePreset($input, $output);

        if ($phpprobePreset === null) {
            return 1;
        }

        $published = 0;

        foreach ($files as $file) {
            if ($this->publishFile($file, $force, $phpprobePreset, $output)) {
                $published++;
            }
        }

        $output->writeln(sprintf('<info>Published %d config file(s).</info>', $published));

        return 0;
    }

    /**
     * @return array{duplicates: array{mode: string, normalize: bool, fuzzy: bool, near_miss: bool, min_lines: int, min_tokens: int, min_statements: int, min_similarity: float}}
     */
    private static function phpprobePreset(string $preset): array
    {
        return match ($preset) {
            'phpstorm' => [
                'duplicates' => [
                    'mode' => 'audit',
                    'normalize' => true,
                    'fuzzy' => true,
                    'near_miss' => true,
                    'min_lines' => 5,
                    'min_tokens' => 90,
                    'min_statements' => 4,
                    'min_similarity' => 0.85,
                ],
            ],
            'standard' => [
                'duplicates' => [
                    'mode' => 'gate',
                    'normalize' => true,
                    'fuzzy' => true,
                    'near_miss' => false,
                    'min_lines' => 6,
                    'min_tokens' => 100,
                    'min_statements' => 5,
                    'min_similarity' => 0.90,
                ],
            ],
            'strict' => [
                'duplicates' => [
                    'mode' => 'audit',
                    'normalize' => true,
                    'fuzzy' => true,
                    'near_miss' => true,
                    'min_lines' => 4,
                    'min_tokens' => 70,
                    'min_statements' => 3,
                    'min_similarity' => 0.80,
                ],
            ],
            default => throw new \InvalidArgumentException(sprintf('Unknown PHPProbe preset "%s". Expected phpstorm, standard, or strict.', $preset)),
        };
    }

    private function applyPhpprobePreset(string $contents, string $preset): ?string
    {
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return null;
        }

        $patched = array_replace_recursive($decoded, self::phpprobePreset($preset));
        $encoded = json_encode($patched, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            return null;
        }

        return $encoded . PHP_EOL;
    }

    private function publishFile(
        string $file,
        bool $force,
        string $phpprobePreset,
        OutputInterface $output,
    ): bool {
        $source = Paths::bundledConfigFile($file);
        $target = Paths::projectRootPath() . DIRECTORY_SEPARATOR . $file;

        if (!is_file($source)) {
            $output->writeln(sprintf('<error>Missing bundled config: %s</error>', $file));

            return false;
        }

        if (is_file($target) && !$force) {
            $output->writeln(sprintf('<comment>Skipped existing config: %s</comment>', $file));

            return false;
        }

        $contents = file_get_contents($source);

        if (!is_string($contents)) {
            $output->writeln(sprintf('<error>Unable to read bundled config: %s</error>', $file));

            return false;
        }

        if ($file === 'phpprobe.json' && $phpprobePreset !== '') {
            $contents = $this->applyPhpprobePreset($contents, $phpprobePreset);

            if (!is_string($contents)) {
                $output->writeln(sprintf(
                    '<error>Unable to apply PHPProbe preset "%s".</error>',
                    $phpprobePreset,
                ));

                return false;
            }
        }

        file_put_contents($target, $contents);

        $output->writeln(sprintf('<info>Published config: %s</info>', $file));

        return true;
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(InputInterface $input): array
    {
        $files = $input->getArgument('files');

        if (!is_array($files) || $files === [] || (bool) $input->getOption('all')) {
            return ConfigInventory::files();
        }

        return array_values(array_filter(
            $files,
            static fn(mixed $file): bool => is_string($file) && $file !== '',
        ));
    }

    private function resolvePhpprobePreset(InputInterface $input, OutputInterface $output): ?string
    {
        $preset = $this->stringOption($input->getOption('phpprobe-preset'));

        if ($preset === '') {
            return '';
        }

        if (in_array($preset, self::PHPPROBE_PRESETS, true)) {
            return $preset;
        }

        $output->writeln(sprintf(
            '<error>Invalid --phpprobe-preset "%s". Expected one of: %s.</error>',
            $preset,
            implode(', ', self::PHPPROBE_PRESETS),
        ));

        return null;
    }

    private function stringOption(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
