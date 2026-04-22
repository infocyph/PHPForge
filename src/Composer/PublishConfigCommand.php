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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing project files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = $input->getArgument('files');

        if (!is_array($files) || $files === [] || (bool) $input->getOption('all')) {
            $files = ConfigInventory::files();
        }

        $force = (bool) $input->getOption('force');
        $published = 0;

        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }

            $source = Paths::packageFile($file);
            $target = Paths::projectRootPath() . DIRECTORY_SEPARATOR . $file;

            if (!is_file($source)) {
                $output->writeln(sprintf('<error>Missing bundled config: %s</error>', $file));

                continue;
            }

            if (is_file($target) && !$force) {
                $output->writeln(sprintf('<comment>Skipped existing config: %s</comment>', $file));

                continue;
            }

            copy($source, $target);
            $published++;
            $output->writeln(sprintf('<info>Published config: %s</info>', $file));
        }

        $output->writeln(sprintf('<info>Published %d config file(s).</info>', $published));

        return 0;
    }
}
