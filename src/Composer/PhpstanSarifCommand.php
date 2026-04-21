<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpstanSarifCommand extends Command
{
    public function __construct()
    {
        parent::__construct('ic:phpstan:sarif');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Convert PHPStan JSON output to SARIF 2.1.0.')
            ->addArgument('input', InputArgument::REQUIRED, 'PHPStan JSON result file.')
            ->addArgument('output', InputArgument::OPTIONAL, 'SARIF output file.', 'phpstan-results.sarif');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new Runner($output))->run([[
            Paths::php(),
            Paths::packageFile('bin/phpforge'),
            'phpstan-sarif',
            (string) $input->getArgument('input'),
            (string) $input->getArgument('output'),
        ]]);
    }
}
