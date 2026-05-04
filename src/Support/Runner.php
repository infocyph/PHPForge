<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class Runner
{
    private ?string $composerVersion = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $failFast = true,
    ) {}

    /**
     * @param list<list<string>> $tasks
     */
    public function run(array $tasks): int
    {
        $this->renderSystemInfo();

        $isFirstTask = true;
        $results = [];
        $failureExitCode = 0;

        foreach ($tasks as $task) {
            $result = $this->runTask($task, $isFirstTask);
            $isFirstTask = false;
            $results[] = $result;

            if ($result['status'] === 'FAIL') {
                if ($failureExitCode === 0) {
                    $failureExitCode = $result['exit_code'];
                }

                if ($this->failFast) {
                    QualitySummary::write($results);

                    return $result['exit_code'];
                }
            }
        }

        QualitySummary::write($results);

        return $failureExitCode;
    }

    private function detectComposerVersion(): string
    {
        if (is_string($this->composerVersion)) {
            return $this->composerVersion;
        }

        $process = new Process(['composer', '--version'], getcwd() ?: null);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->composerVersion = 'unknown';
        }

        $line = trim($process->getOutput());

        if ($line === '') {
            return $this->composerVersion = 'unknown';
        }

        if (preg_match('/Composer version ([^\s]+)/i', $line, $matches) === 1) {
            return $this->composerVersion = $matches[1];
        }

        return $this->composerVersion = $line;
    }

    private function renderSystemInfo(): void
    {
        $this->output->writeln('<info>System Info</info>');
        $this->output->writeln('PHP Version: ' . PHP_VERSION);
        $this->output->writeln('Composer Version: ' . $this->detectComposerVersion());
        $this->output->writeln('');

        if ($this->output->isVerbose()) {
            $this->output->writeln('PHP Binary: ' . PHP_BINARY);
            $this->output->writeln('Project Root: ' . (getcwd() ?: dirname(__DIR__, 2)));
            $this->output->writeln('');
        }
    }

    /**
     * @param list<string> $task
     * @return array{heading:string,status:string,exit_code:int}
     */
    private function runTask(array $task, bool $isFirstTask): array
    {
        if (!$isFirstTask) {
            $this->output->writeln('');
        }

        $heading = TaskDisplay::heading($task);
        $this->output->writeln('<info>' . $heading . '</info>');

        $process = new Process($task, getcwd() ?: null);
        $process->setTimeout(null);

        $stdout = '';
        $stderr = '';
        $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr): void {
            if ($type === Process::ERR) {
                $stderr .= $buffer;
            } else {
                $stdout .= $buffer;
            }

            $this->output->write($buffer, false, $type === Process::ERR ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
        });

        $isSkipped = !$process->isSuccessful() && TaskSkipPolicy::shouldSkipUnavailablePerPreset($task, $stdout, $stderr);

        if ($isSkipped) {
            $this->output->writeln("<comment>Pint preset 'per' is unavailable; skipping this Pint task.</comment>");
        }

        return [
            'heading' => $heading,
            'status' => $process->isSuccessful() ? 'PASS' : ($isSkipped ? 'SKIP' : 'FAIL'),
            'exit_code' => $process->isSuccessful() || $isSkipped ? 0 : ($process->getExitCode() ?? 1),
        ];
    }
}
