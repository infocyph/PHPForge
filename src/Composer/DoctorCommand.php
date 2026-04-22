<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Infocyph\PHPForge\Support\ConfigInventory;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    private const PLUGINS = [
        'infocyph/phpforge',
        'pestphp/pest-plugin',
    ];

    public function __construct()
    {
        parent::__construct('ic:doctor');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show PHPForge environment, config, and hook diagnostics.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $diagnostics = $this->diagnostics();

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $output->writeln('<info>PHPForge Doctor</info>');
        $output->writeln('Project root: ' . $diagnostics['project_root']);
        $output->writeln('Vendor dir:   ' . $diagnostics['vendor_dir']);
        $output->writeln('');
        $output->writeln('<info>Config files</info>');

        foreach ($diagnostics['configs'] as $config) {
            $output->writeln(sprintf('  %-18s %s', $config['file'], $config['source']));
        }

        $output->writeln('');
        $output->writeln('<info>Composer plugins</info>');

        foreach ($diagnostics['plugins'] as $plugin => $enabled) {
            $output->writeln(sprintf('  %-24s %s', $plugin, $enabled ? 'enabled' : 'not enabled'));
        }

        $output->writeln('');
        $output->writeln('Pre-commit hook: ' . ($diagnostics['pre_commit_hook'] ? 'installed' : 'not installed'));

        return 0;
    }

    private function allowPlugins(): mixed
    {
        $composerJson = Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($composerJson), true);

        if (!is_array($data)) {
            return [];
        }

        $config = $data['config'] ?? [];

        return is_array($config) ? ($config['allow-plugins'] ?? []) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostics(): array
    {
        $configs = [];

        foreach (ConfigInventory::files() as $file) {
            $configs[] = [
                'file' => $file,
                'source' => ConfigInventory::source($file),
                'path' => ConfigInventory::resolvedPath($file),
            ];
        }

        $allowPlugins = $this->allowPlugins();
        $plugins = [];

        foreach (self::PLUGINS as $plugin) {
            $plugins[$plugin] = $allowPlugins === true || (is_array($allowPlugins) && ($allowPlugins[$plugin] ?? false) === true);
        }

        return [
            'project_root' => Paths::projectRootPath(),
            'vendor_dir' => Paths::vendorDir(),
            'configs' => $configs,
            'plugins' => $plugins,
            'pre_commit_hook' => is_file(Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'pre-commit'),
        ];
    }
}
