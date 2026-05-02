<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class ProcRunner
{
    /**
     * @param list<string>|string $command
     */
    public function run(array|string $command, string $stdin = ''): ?ProcessResult
    {
        $pipes = [];

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return null;
        }

        if (!self::hasProcessPipes($pipes)) {
            proc_close($process);

            return null;
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new ProcessResult(proc_close($process), $stdout, $stderr);
    }

    /**
     * @phpstan-assert-if-true array{0: resource, 1: resource, 2: resource} $pipes
     */
    private static function hasProcessPipes(mixed $pipes): bool
    {
        return is_array($pipes)
            && isset($pipes[0], $pipes[1], $pipes[2])
            && is_resource($pipes[0])
            && is_resource($pipes[1])
            && is_resource($pipes[2]);
    }
}
