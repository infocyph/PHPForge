<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Composer\InstalledVersions;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class VersionCommand extends Command
{
    /**
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct()
    {
        parent::__construct('ic:version');
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Show PHPForge, PHP, Composer, and vendor-dir versions.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($input);
        $version = InstalledVersions::isInstalled('infocyph/phpforge')
            ? InstalledVersions::getPrettyVersion('infocyph/phpforge')
            : null;
        $version = in_array($version, [null, '', '0'], true) ? 'root/dev' : $version;

        $output->writeln('PHPForge:   ' . $version);
        $output->writeln('PHP:        ' . PHP_VERSION);
        $output->writeln('PHP binary: ' . PHP_BINARY);
        $output->writeln('Vendor dir: ' . Paths::vendorDir());

        return 0;
    }
}
