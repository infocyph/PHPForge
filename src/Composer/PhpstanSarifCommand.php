<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpstanSarifCommand extends Command
{
    /**
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct()
    {
        parent::__construct('ic:phpstan:sarif');
    }

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    #[\Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Convert PHPStan JSON output to SARIF 2.1.0.')
            ->addArgument('input', InputArgument::REQUIRED, 'PHPStan JSON result file.')
            ->addArgument('output', InputArgument::OPTIONAL, 'SARIF output file.', 'phpstan-results.sarif');
    }

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     * @throws \Symfony\Component\Process\Exception\LogicException|\Symfony\Component\Process\Exception\InvalidArgumentException|\Symfony\Component\Process\Exception\ProcessStartFailedException|\Symfony\Component\Process\Exception\RuntimeException|\Symfony\Component\Process\Exception\ProcessTimedOutException|\Symfony\Component\Process\Exception\ProcessSignaledException
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = $input->getArgument('input');
        $outputPath = $input->getArgument('output');

        return new Runner($output)->run([[
            Paths::php(),
            Paths::bin('phpforge'),
            'phpstan-sarif',
            is_string($inputPath) ? $inputPath : '',
            is_string($outputPath) ? $outputPath : 'phpstan-results.sarif',
        ]]);
    }
}
