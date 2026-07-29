<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\BenchmarkResult;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BenchmarkCommand extends Command
{
    public function __construct(
        private readonly string $mode,
        string $name,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        if ($this->mode === 'validate') {
            $this
                ->setDescription('Validate a representative benchmark JSON result.')
                ->addArgument('result', InputArgument::REQUIRED, 'Benchmark result JSON file.');

            return;
        }

        $this
            ->setDescription('Compare like-for-like representative benchmark results.')
            ->addArgument('baseline', InputArgument::REQUIRED, 'Baseline benchmark result JSON file.')
            ->addArgument('candidate', InputArgument::REQUIRED, 'Candidate benchmark result JSON file.')
            ->addOption('max-regression', null, InputOption::VALUE_REQUIRED, 'Maximum successful-RPM regression percentage.', '2')
            ->addOption('stable-environment', null, InputOption::VALUE_NONE, 'Assert that both runs came from a stable, comparable environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->mode === 'validate'
            ? $this->validate($input, $output)
            : $this->compare($input, $output);
    }

    private function compare(InputInterface $input, OutputInterface $output): int
    {
        $maximumRegression = filter_var($input->getOption('max-regression'), FILTER_VALIDATE_FLOAT);
        $baseline = $input->getArgument('baseline');
        $candidate = $input->getArgument('candidate');

        if (
            !is_float($maximumRegression)
            || $maximumRegression < 0
            || $maximumRegression > 100
            || !is_string($baseline)
            || $baseline === ''
            || !is_string($candidate)
            || $candidate === ''
        ) {
            $output->writeln('<error>--max-regression must be a number between 0 and 100.</error>');

            return 2;
        }

        $result = (new BenchmarkResult())->compare(
            $baseline,
            $candidate,
            $maximumRegression,
            (bool) $input->getOption('stable-environment'),
        );

        foreach ($result['comparisons'] as $comparison) {
            $output->writeln(sprintf(
                '%s: %.2f -> %.2f successful RPM (%+.2f%% regression)',
                $comparison['workload'],
                $comparison['baseline_rpm'],
                $comparison['candidate_rpm'],
                $comparison['regression_percent'],
            ));
        }

        foreach ($result['messages'] as $message) {
            $output->writeln(($result['status'] === 'failed' ? '<error>' : '<comment>') . $message . ($result['status'] === 'failed' ? '</error>' : '</comment>'));
        }

        if ($result['status'] === 'passed') {
            $output->writeln(sprintf('<info>Benchmark regression budget passed (maximum %.2f%%).</info>', $maximumRegression));
        }

        return $result['status'] === 'failed' ? 1 : 0;
    }

    private function validate(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('result');

        if (!is_string($file) || $file === '') {
            $output->writeln('<error>A benchmark result JSON file is required.</error>');

            return 2;
        }

        $result = (new BenchmarkResult())->load($file);

        if ($result['errors'] === []) {
            $output->writeln(sprintf('<info>Benchmark result contract passed: %s</info>', $file));

            return 0;
        }

        $output->writeln('<error>Benchmark result contract failed:</error>');

        foreach ($result['errors'] as $error) {
            $output->writeln('  - ' . $error);
        }

        return 1;
    }
}
