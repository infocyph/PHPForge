<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Infocyph\PHPForge\Support\ConfigInventory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ListConfigCommand extends Command
{
    public function __construct()
    {
        parent::__construct('ic:list-config');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List PHPForge config resolution sources.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];

        foreach (ConfigInventory::tools() as $tool => $files) {
            foreach ($files as $file) {
                $rows[] = [
                    'tool' => $tool,
                    'file' => $file,
                    'source' => ConfigInventory::source($file),
                    'path' => ConfigInventory::resolvedPath($file),
                ];
            }
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf('%-12s %-18s %-8s %s', $row['tool'], $row['file'], $row['source'], $row['path']));
        }

        return 0;
    }
}
