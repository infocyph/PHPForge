<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class WorkerSoakCommand extends Command
{
    public function __construct(string $name = 'ic:soak:worker')
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Soak-test a long-running web or queue worker for early exit and RSS growth.')
            ->addArgument('worker-command', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Worker command and arguments; place them after --.')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Required worker lifetime in seconds.', '300')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Startup warm-up before the RSS growth baseline is captured.', '5')
            ->addOption('sample-interval', null, InputOption::VALUE_REQUIRED, 'RSS sampling interval in seconds.', '1')
            ->addOption('max-rss-mb', null, InputOption::VALUE_REQUIRED, 'Optional absolute RSS ceiling in MiB.')
            ->addOption('max-growth-mb', null, InputOption::VALUE_REQUIRED, 'Optional RSS growth ceiling in MiB.')
            ->addOption('grace-period', null, InputOption::VALUE_REQUIRED, 'Graceful shutdown window in seconds.', '10')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Optional JSON report path.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $command = $input->getArgument('worker-command');

        if (!is_array($command) || $command === [] || array_filter($command, is_string(...)) !== $command) {
            $output->writeln('<error>A worker command is required after --.</error>');

            return 2;
        }

        $configuration = $this->configuration($input, $output);

        if ($configuration === null) {
            return 2;
        }

        $process = new Process(array_values($command), getcwd() ?: null, ['XDEBUG_MODE' => 'off']);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();

        $monitor = $this->monitor($process, $configuration);

        if ($process->isRunning()) {
            $process->stop($configuration['grace_period'], 15);
        }

        $report = $this->report(
            $command[0],
            $configuration['duration'],
            $configuration['warmup'],
            $configuration['interval'],
            $monitor['elapsed'],
            $monitor['samples'],
            $monitor['failure'],
        );
        $reportPath = $input->getOption('report');

        if (is_string($reportPath) && $reportPath !== '' && !$this->writeReport($reportPath, $report)) {
            $output->writeln(sprintf('<error>Unable to write soak report: %s</error>', $reportPath));

            return 1;
        }

        if (is_string($monitor['failure'])) {
            $output->writeln('<error>' . $monitor['failure'] . '</error>');

            return 1;
        }

        $output->writeln(sprintf(
            '<info>Worker soak passed: %.2fs, %d samples, initial %.2f MiB, peak %.2f MiB, growth %.2f MiB.</info>',
            $monitor['elapsed'],
            count($monitor['samples']),
            $report['rss']['initial_mb'],
            $report['rss']['peak_mb'],
            $report['rss']['growth_mb'],
        ));

        return 0;
    }

    /**
     * @return array{
     *     duration: float,
     *     warmup: float,
     *     interval: float,
     *     grace_period: float,
     *     max_rss: float|null,
     *     max_growth: float|null
     * }|null
     */
    private function configuration(InputInterface $input, OutputInterface $output): ?array
    {
        $duration = $this->numberOption($input, $output, 'duration', 1.0, 86_400.0);
        $warmup = $this->numberOption($input, $output, 'warmup', 0.0, 3_600.0);
        $interval = $this->numberOption($input, $output, 'sample-interval', 0.1, 60.0);
        $gracePeriod = $this->numberOption($input, $output, 'grace-period', 0.0, 300.0);
        $maxRss = $this->optionalNumberOption($input, $output, 'max-rss-mb');
        $maxGrowth = $this->optionalNumberOption($input, $output, 'max-growth-mb');

        if ($duration === null || $warmup === null || $interval === null || $gracePeriod === null || $maxRss === false || $maxGrowth === false) {
            return null;
        }

        return [
            'duration' => $duration,
            'warmup' => $warmup,
            'interval' => $interval,
            'grace_period' => $gracePeriod,
            'max_rss' => $maxRss,
            'max_growth' => $maxGrowth,
        ];
    }

    /**
     * @param array{
     *     duration: float,
     *     warmup: float,
     *     interval: float,
     *     grace_period: float,
     *     max_rss: float|null,
     *     max_growth: float|null
     * } $configuration
     * @return array{elapsed: float, samples: list<float>, failure: string|null}
     */
    private function monitor(Process $process, array $configuration): array
    {
        $startedAt = hrtime(true);
        $measurementStartedAt = $startedAt + (int) round($configuration['warmup'] * 1_000_000_000);
        $deadline = $measurementStartedAt + (int) round($configuration['duration'] * 1_000_000_000);
        $samples = [];
        $failure = null;

        while (hrtime(true) < $deadline) {
            $sample = $this->sample($process, $configuration, $measurementStartedAt, $samples);

            if (is_string($sample)) {
                $failure = $sample;

                break;
            }

            usleep((int) round($configuration['interval'] * 1_000_000));
        }

        return [
            'elapsed' => (hrtime(true) - $startedAt) / 1_000_000_000,
            'samples' => $samples,
            'failure' => $failure,
        ];
    }

    private function numberOption(
        InputInterface $input,
        OutputInterface $output,
        string $name,
        float $minimum,
        float $maximum,
    ): ?float {
        $value = filter_var($input->getOption($name), FILTER_VALIDATE_FLOAT);

        if (!is_float($value) || $value < $minimum || $value > $maximum) {
            $output->writeln(sprintf('<error>--%s must be between %s and %s.</error>', $name, $minimum, $maximum));

            return null;
        }

        return $value;
    }

    private function optionalNumberOption(InputInterface $input, OutputInterface $output, string $name): float|false|null
    {
        $raw = $input->getOption($name);

        if ($raw === null || $raw === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);

        if (!is_float($value) || $value < 0) {
            $output->writeln(sprintf('<error>--%s must be a non-negative number.</error>', $name));

            return false;
        }

        return $value;
    }

    /**
     * @param list<float> $samples
     * @return array{
     *     schema_version: int,
     *     status: 'failed'|'passed',
     *     command_name: string,
     *     configured_duration_seconds: float,
     *     warmup_seconds: float,
     *     elapsed_seconds: float,
     *     sample_interval_seconds: float,
     *     samples: int,
     *     rss: array{initial_mb:float,final_mb:float,peak_mb:float,growth_mb:float},
     *     failure: string|null
     * }
     */
    private function report(
        string $command,
        float $duration,
        float $warmup,
        float $interval,
        float $elapsed,
        array $samples,
        ?string $failure,
    ): array {
        $initial = $samples[0] ?? 0.0;
        $final = $samples[array_key_last($samples)] ?? 0.0;

        return [
            'schema_version' => 1,
            'status' => $failure === null ? 'passed' : 'failed',
            'command_name' => basename($command),
            'configured_duration_seconds' => $duration,
            'warmup_seconds' => $warmup,
            'elapsed_seconds' => round($elapsed, 5),
            'sample_interval_seconds' => $interval,
            'samples' => count($samples),
            'rss' => [
                'initial_mb' => round($initial, 5),
                'final_mb' => round($final, 5),
                'peak_mb' => round($samples === [] ? 0.0 : max($samples), 5),
                'growth_mb' => round($final - $initial, 5),
            ],
            'failure' => $failure,
        ];
    }

    private function rssMegabytes(int $pid): ?float
    {
        $statusFile = sprintf('/proc/%d/status', $pid);
        $status = is_readable($statusFile) ? file_get_contents($statusFile) : false;

        if (!is_string($status) || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] / 1024;
    }

    /**
     * @param array{duration:float, max_rss:float|null, max_growth:float|null} $configuration
     * @param list<float> $samples
     */
    private function sample(Process $process, array $configuration, int $measurementStartedAt, array &$samples): ?string
    {
        if (!$process->isRunning()) {
            return sprintf(
                'Worker exited before the %.2f second soak duration (exit code %s).',
                $configuration['duration'],
                (string) ($process->getExitCode() ?? 'unknown'),
            );
        }

        $pid = $process->getPid();
        $rss = is_int($pid) ? $this->rssMegabytes($pid) : null;

        if ($rss === null) {
            return 'Unable to read worker RSS from /proc; this soak helper requires a Linux process environment.';
        }

        if ($configuration['max_rss'] !== null && $rss > $configuration['max_rss']) {
            return sprintf('Worker RSS %.2f MiB exceeded the %.2f MiB ceiling.', $rss, $configuration['max_rss']);
        }

        if (hrtime(true) < $measurementStartedAt) {
            return null;
        }

        $samples[] = $rss;
        $growth = $rss - $samples[0];

        return $configuration['max_growth'] !== null && $growth > $configuration['max_growth']
            ? sprintf('Worker RSS growth %.2f MiB exceeded the %.2f MiB ceiling.', $growth, $configuration['max_growth'])
            : null;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeReport(string $path, array $report): bool
    {
        $directory = dirname($path);

        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            return false;
        }

        $temporary = tempnam($directory, '.phpforge-soak-');

        if (!is_string($temporary)) {
            return false;
        }

        $written = file_put_contents($temporary, $encoded . PHP_EOL);

        if ($written === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            return false;
        }

        return true;
    }
}
