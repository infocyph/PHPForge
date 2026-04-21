<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends BaseCommand
{
    private const CONFIGS = [
        'pest.xml',
        'phpunit.xml',
        'phpbench.json',
        'phpcs.xml.dist',
        'phpstan.neon.dist',
        'pint.json',
        'psalm.xml',
        'rector.php',
        'captainhook.json',
    ];

    private const PLUGINS = [
        'infocyph/phpforge',
        'pestphp/pest-plugin',
        'captainhook/captainhook',
    ];

    protected function configure(): void
    {
        $this
            ->setName('ic:doctor')
            ->setDescription('Show PHPForge environment, config, and hook diagnostics.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>PHPForge Doctor</info>');
        $output->writeln('Project root: ' . Paths::projectRootPath());
        $output->writeln('Vendor dir:   ' . Paths::vendorDir());
        $output->writeln('');
        $output->writeln('<info>Config files</info>');

        foreach (self::CONFIGS as $file) {
            $projectFile = Paths::projectRootPath() . DIRECTORY_SEPARATOR . $file;
            $source = is_file($projectFile) ? 'project' : (is_file(Paths::packageFile($file)) ? 'phpforge' : 'missing');
            $output->writeln(sprintf('  %-18s %s', $file, $source));
        }

        $output->writeln('');
        $output->writeln('<info>Composer plugins</info>');
        $allowPlugins = $this->allowPlugins();

        foreach (self::PLUGINS as $plugin) {
            $enabled = $allowPlugins === true || (is_array($allowPlugins) && ($allowPlugins[$plugin] ?? false) === true);
            $output->writeln(sprintf('  %-24s %s', $plugin, $enabled ? 'enabled' : 'not enabled'));
        }

        $hook = Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'pre-commit';
        $output->writeln('');
        $output->writeln('Pre-commit hook: ' . (is_file($hook) ? 'installed' : 'not installed'));

        return 0;
    }

    private function allowPlugins(): mixed
    {
        $composerJson = Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'composer.json';

        if (! is_file($composerJson)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($composerJson), true);

        if (! is_array($data)) {
            return [];
        }

        $config = $data['config'] ?? [];

        return is_array($config) ? ($config['allow-plugins'] ?? []) : [];
    }
}
