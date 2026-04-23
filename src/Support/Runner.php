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
    ) {}

    /**
     * @param list<list<string>> $tasks
     */
    public function run(array $tasks): int
    {
        $this->renderSystemInfo();

        $isFirstTask = true;

        foreach ($tasks as $task) {
            if (!$isFirstTask) {
                $this->output->writeln('');
            }

            $isFirstTask = false;
            $this->output->writeln('<info>' . TaskDisplay::heading($task) . '</info>');

            $process = new Process($task, getcwd() ?: null);
            $process->setTimeout(null);

            $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer, false, $type === Process::ERR ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
            });

            if (!$process->isSuccessful()) {
                return $process->getExitCode() ?? 1;
            }
        }

        return 0;
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
}
