<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class Runner
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    /**
     * @param list<list<string>> $tasks
     */
    public function run(array $tasks): int
    {
        foreach ($tasks as $task) {
            $process = new Process($task, getcwd() ?: null);
            $process->setTimeout(null);

            $this->output->writeln('<info>$ ' . implode(' ', array_map($this->quote(...), $task)) . '</info>');

            $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer, false, $type === Process::ERR ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
            });

            if (!$process->isSuccessful()) {
                return $process->getExitCode() ?? 1;
            }
        }

        return 0;
    }

    private function quote(string $argument): string
    {
        if ($argument === '' || preg_match('/\s/', $argument) === 1) {
            return '"' . str_replace('"', '\"', $argument) . '"';
        }

        return $argument;
    }
}
