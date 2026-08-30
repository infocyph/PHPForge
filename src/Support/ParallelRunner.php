<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class ParallelRunner
{
    private const int DEFAULT_TASK_TIMEOUT_SECONDS = 300;

    private const int LONG_RUNNING_REPORT_SECONDS = 60;

    private const int MAX_CONCURRENCY = 16;

    private const int MAX_OUTPUT_BYTES = 4_194_304;

    private const int MAX_TASK_TIMEOUT_SECONDS = 3_600;

    public function __construct(
        private OutputInterface $output,
    ) {}

    public static function concurrencyFrom(mixed $value, int $eligibleTasks = 1): int
    {
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return self::boundedConcurrency((int) $value);
        }

        foreach (['IC_TEST_CONCURRENCY', 'PHPFORGE_PARALLEL'] as $name) {
            $envValue = getenv($name);

            if (is_string($envValue) && $envValue !== '' && filter_var($envValue, FILTER_VALIDATE_INT) !== false) {
                return self::boundedConcurrency((int) $envValue);
            }
        }

        return self::boundedConcurrency($eligibleTasks);
    }

    public static function timeoutFrom(mixed $value): int
    {
        $candidate = $value;
        $candidate ??= getenv('IC_TEST_TASK_TIMEOUT');

        if (is_string($candidate) && $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_INT) !== false) {
            return self::boundedTimeout((int) $candidate);
        }

        if (is_int($candidate)) {
            return self::boundedTimeout($candidate);
        }

        return self::DEFAULT_TASK_TIMEOUT_SECONDS;
    }

    /**
     * @param list<list<string>> $preflightTasks
     * @param list<list<string>> $parallelTasks
     */
    public function run(array $preflightTasks, array $parallelTasks, ?int $concurrency = null): int
    {
        $concurrency = self::boundedConcurrency($concurrency ?? self::concurrencyFrom(null, count($parallelTasks)));
        $preflightResults = [];

        $this->output->writeln('<info>Parallel Tests</info>');
        $this->output->writeln(sprintf('Concurrency: %d', $concurrency));
        $this->output->writeln('');

        foreach ($preflightTasks as $task) {
            $result = $this->runPreflight($task);
            $preflightResults[] = $result;

            if ($result['exit_code'] !== 0) {
                QualitySummary::write($preflightResults);

                return $result['exit_code'];
            }

            $this->output->writeln('');
        }

        return $this->runParallel($parallelTasks, $concurrency, $preflightResults);
    }

    private static function boundedConcurrency(int $value): int
    {
        return max(1, min(self::MAX_CONCURRENCY, $value));
    }

    private static function boundedTimeout(int $value): int
    {
        return max(1, min(self::MAX_TASK_TIMEOUT_SECONDS, $value));
    }

    /**
     * @param array<int, array{process:Process,task:list<string>,heading:string,stdout:resource,stderr:resource,stdout_truncated:bool,stderr_truncated:bool,started_at:float,index:int,long_running_reported:bool}> $active
     * @return list<array{process:Process,task:list<string>,heading:string,stdout:resource,stderr:resource,stdout_truncated:bool,stderr_truncated:bool,started_at:float,index:int,long_running_reported:bool}>
     */
    private function collectFinished(array &$active): array
    {
        $finished = [];

        foreach ($active as $id => $entry) {
            try {
                $entry['process']->checkTimeout();
            } catch (ProcessTimedOutException) {
                fwrite($entry['stderr'], sprintf(
                    "Task exceeded the configured timeout of %d seconds (IC_TEST_TASK_TIMEOUT).\n",
                    (int) $entry['process']->getTimeout(),
                ));
                $finished[] = $entry;
                unset($active[$id]);

                continue;
            }

            if ($entry['process']->isRunning()) {
                continue;
            }

            $finished[] = $entry;
            unset($active[$id]);
        }

        return $finished;
    }

    /**
     * @param array{process:Process,task:list<string>,heading:string,stdout:resource,stderr:resource,stdout_truncated:bool,stderr_truncated:bool,started_at:float,index:int,long_running_reported:bool} $entry
     * @return array{heading:string,exit_code:int,status:string}
     */
    private function renderFinished(array $entry): array
    {
        $process = $entry['process'];
        $exitCode = $process->getExitCode() ?? 1;
        $status = $process->isSuccessful() ? 'PASS' : 'FAIL';
        $stdout = $this->streamContents($entry['stdout'], $entry['stdout_truncated']);
        $stderr = $this->streamContents($entry['stderr'], $entry['stderr_truncated']);

        if (!$process->isSuccessful() && TaskSkipPolicy::shouldSkipUnavailablePerPreset($entry['task'], $stdout, $stderr)) {
            $exitCode = 0;
            $status = 'SKIP';
        }

        if ($status === 'FAIL') {
            $this->output->writeln(sprintf('<info>%s</info>', $entry['heading']));
            $this->writeBuffered($stdout, false);
            $this->writeBuffered($stderr, true);
            $this->output->writeln(sprintf(
                '<error>FAIL</error> %s (%0.2fs)',
                $entry['heading'],
                microtime(true) - $entry['started_at'],
            ));
            $this->output->writeln('');
        }

        return [
            'heading' => $entry['heading'],
            'exit_code' => $exitCode,
            'status' => $status,
        ];
    }

    /**
     * @param list<array{heading:string,status:string,exit_code:int}> $results
     */
    private function renderSummary(array $results): int
    {
        $exitCode = 0;

        $this->output->writeln('<info>Summary</info>');

        foreach ($results as $result) {
            if ($result['exit_code'] !== 0) {
                $exitCode = $result['exit_code'];
            }

            $tag = $result['status'] === 'FAIL' ? 'error' : 'info';
            $this->output->writeln(sprintf('<%s>%s</%s> %s', $tag, $result['status'], $tag, $result['heading']));
        }

        return $exitCode;
    }

    /**
     * @param array<int, array{process:Process,task:list<string>,heading:string,stdout:resource,stderr:resource,stdout_truncated:bool,stderr_truncated:bool,started_at:float,index:int,long_running_reported:bool}> $active
     */
    private function reportLongRunning(array &$active): void
    {
        foreach ($active as &$entry) {
            if ($entry['long_running_reported'] || microtime(true) - $entry['started_at'] < self::LONG_RUNNING_REPORT_SECONDS) {
                continue;
            }

            $this->output->writeln(sprintf(
                '<comment>Still running after %d seconds: %s</comment>',
                self::LONG_RUNNING_REPORT_SECONDS,
                $entry['heading'],
            ));
            $entry['long_running_reported'] = true;
        }

        unset($entry);
    }

    /**
     * @param list<string> $task
     * @return array{heading:string,status:string,exit_code:int}
     */
    private function resultFromProcess(Process $process, array $task, string $stdout, string $stderr, string $heading): array
    {
        if ($process->isSuccessful() || TaskSkipPolicy::shouldSkipUnavailablePerPreset($task, $stdout, $stderr)) {
            return [
                'heading' => $heading,
                'status' => $process->isSuccessful() ? 'PASS' : 'SKIP',
                'exit_code' => 0,
            ];
        }

        return [
            'heading' => $heading,
            'status' => 'FAIL',
            'exit_code' => $process->getExitCode() ?? 1,
        ];
    }

    /**
     * @param list<list<string>> $tasks
     * @param list<array{heading:string,status:string,exit_code:int}> $preflightResults
     */
    private function runParallel(array $tasks, int $concurrency, array $preflightResults): int
    {
        $pending = $tasks;
        $active = [];
        $results = [];
        $finished = [];
        $nextIndex = 0;

        while ($pending !== [] || $active !== []) {
            while ($pending !== [] && count($active) < $concurrency) {
                $task = array_shift($pending);
                $active[$nextIndex] = $this->startTask($task, $nextIndex);
                $nextIndex++;
            }

            foreach ($this->collectFinished($active) as $entry) {
                $finished[$entry['index']] = $entry;
            }

            if ($active !== []) {
                $this->reportLongRunning($active);
                usleep(100_000);
            }
        }

        ksort($finished);

        foreach ($finished as $entry) {
            $results[] = $this->renderFinished($entry);
        }

        $results = [...$preflightResults, ...$results];
        QualitySummary::write($results);

        return $this->renderSummary($results);
    }

    /**
     * @param list<string> $task
     * @return array{heading:string,status:string,exit_code:int}
     */
    private function runPreflight(array $task): array
    {
        $heading = TaskDisplay::heading($task);
        $this->output->writeln(sprintf('<info>%s</info>', $heading));

        return $this->runSynchronousCheck($task, $heading);
    }

    /**
     * @param list<string> $task
     * @return array{heading:string,status:string,exit_code:int}
     */
    private function runSynchronousCheck(array $task, string $heading): array
    {
        $run = $this->runSynchronousTask($task);

        return $this->resultFromProcess($run['process'], $task, $run['stdout'], $run['stderr'], $heading);
    }

    /**
     * @param list<string> $task
     * @return array{process:Process,stdout:string,stderr:string}
     */
    private function runSynchronousTask(array $task): array
    {
        $stdout = '';
        $stderr = '';
        $process = new Process($task, getcwd() ?: null, $this->taskEnvironment());
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr): void {
            if ($type === Process::ERR) {
                $stderr .= $buffer;
                $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);

                return;
            }

            $stdout .= $buffer;
            $this->output->write($buffer);
        });

        return [
            'process' => $process,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param list<string> $task
     * @return array{process:Process,task:list<string>,heading:string,stdout:resource,stderr:resource,stdout_truncated:bool,stderr_truncated:bool,started_at:float,index:int,long_running_reported:bool}
     */
    private function startTask(array $task, int $index): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();

        if (!is_resource($stdout) || !is_resource($stderr)) {
            throw new \RuntimeException('Unable to create bounded task output streams.');
        }

        $stdoutBytes = 0;
        $stderrBytes = 0;
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $process = new Process($task, getcwd() ?: null, $this->taskEnvironment());
        $process->setTimeout(self::timeoutFrom(null));
        $process->disableOutput();
        $process->start(function (string $type, string $buffer) use (
            $stdout,
            $stderr,
            &$stdoutBytes,
            &$stderrBytes,
            &$stdoutTruncated,
            &$stderrTruncated,
        ): void {
            if ($type === Process::ERR) {
                $this->writeChunk($stderr, $buffer, $stderrBytes, $stderrTruncated);

                return;
            }

            $this->writeChunk($stdout, $buffer, $stdoutBytes, $stdoutTruncated);
        });

        return [
            'process' => $process,
            'task' => $task,
            'heading' => TaskDisplay::heading($task),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdout_truncated' => &$stdoutTruncated,
            'stderr_truncated' => &$stderrTruncated,
            'started_at' => microtime(true),
            'index' => $index,
            'long_running_reported' => false,
        ];
    }

    /** @param resource $stream */
    private function streamContents($stream, bool $truncated): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        $contents = is_string($contents) ? $contents : '';

        return $truncated
            ? $contents . PHP_EOL . sprintf('[output truncated after %d bytes]', self::MAX_OUTPUT_BYTES) . PHP_EOL
            : $contents;
    }

    /**
     * @return array<string, string>|null
     */
    private function taskEnvironment(): ?array
    {
        $xdebugMode = getenv('XDEBUG_MODE');

        if (is_string($xdebugMode) && $xdebugMode !== '') {
            return null;
        }

        return ['XDEBUG_MODE' => 'off'];
    }

    private function writeBuffered(string $buffer, bool $error): void
    {
        if ($buffer === '') {
            return;
        }

        $this->output->write($buffer, false, $error ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);

        if (!str_ends_with($buffer, PHP_EOL)) {
            $this->output->writeln('');
        }
    }

    /** @param resource $stream */
    private function writeChunk($stream, string $buffer, int &$bytes, bool &$truncated): void
    {
        $remaining = self::MAX_OUTPUT_BYTES - $bytes;

        if ($remaining <= 0) {
            $truncated = true;

            return;
        }

        $chunk = strlen($buffer) > $remaining ? substr($buffer, 0, $remaining) : $buffer;
        fwrite($stream, $chunk);
        $bytes += strlen($chunk);
        $truncated = $truncated || strlen($chunk) < strlen($buffer);
    }
}
