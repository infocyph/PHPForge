<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final class ProcRunner
{
    private const int DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * @param list<string>|string $command
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException|\Symfony\Component\Process\Exception\ProcessTimedOutException|\Symfony\Component\Process\Exception\ProcessSignaledException|\Symfony\Component\Process\Exception\LogicException|\Symfony\Component\Process\Exception\InvalidArgumentException
     */
    public function run(array|string $command, string $stdin = ''): ?ProcessResult
    {
        $process = is_array($command)
            ? new Process($command, getcwd() ?: null)
            : Process::fromShellCommandline($command, getcwd() ?: null);
        $process->setInput($stdin);
        $process->setTimeout(self::DEFAULT_TIMEOUT_SECONDS);

        try {
            $exitCode = $process->run();
        } catch (ProcessStartFailedException) {
            return null;
        }

        return new ProcessResult($exitCode, $process->getOutput(), $process->getErrorOutput());
    }
}
