<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand;
use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CiCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ic:ci')
            ->setDescription('Run the CI quality set, optionally skipping heavyweight analysis.')
            ->addOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Skip PHPStan and Psalm for prefer-lowest dependency jobs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new Runner($output))->run(TaskCatalog::ci((bool) $input->getOption('prefer-lowest')));
    }
}
