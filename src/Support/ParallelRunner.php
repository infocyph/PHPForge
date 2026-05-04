<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final readonly class ParallelRunner
{
    private const DEFAULT_CONCURRENCY = 3;

    private const MAX_CONCURRENCY = 16;

    public function __construct(
        private OutputInterface $output,
    ) {}

    public static function concurrencyFrom(mixed $value): int
    {
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return self::boundedConcurrency((int) $value);
        }

        foreach (['PHPFORGE_PARALLEL', 'IC_TEST_CONCURRENCY'] as $name) {
            $envValue = getenv($name);

            if (is_string($envValue) && $envValue !== '' && filter_var($envValue, FILTER_VALIDATE_INT) !== false) {
                return self::boundedConcurrency((int) $envValue);
            }
        }

        return self::DEFAULT_CONCURRENCY;
    }

    /**
     * @param list<list<string>> $preflightTasks
     * @param list<list<string>> $parallelTasks
     */
    public function run(array $preflightTasks, array $parallelTasks, ?int $concurrency = null): int
    {
        $concurrency ??= self::concurrencyFrom(null);
        $preflightResults = [];

        $this->output->writeln(sprintf('<info>Parallel Tests</info>'));
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

    /**
     * @param array<string, array{process:Process,task:list<string>,heading:string,stdout:string,stderr:string,started_at:float}> $active
     * @return list<array{heading:string,exit_code:int,status:string}>
     */
    private function collectFinished(array &$active): array
    {
        $finished = [];

        foreach ($active as $id => &$entry) {
            $entry['stdout'] .= $entry['process']->getIncrementalOutput();
            $entry['stderr'] .= $entry['process']->getIncrementalErrorOutput();

            if ($entry['process']->isRunning()) {
                continue;
            }

            $entry['stdout'] .= $entry['process']->getIncrementalOutput();
            $entry['stderr'] .= $entry['process']->getIncrementalErrorOutput();

            $finished[] = $this->renderFinished($entry);
            unset($active[$id]);
        }

        unset($entry);

        return $finished;
    }

    /**
     * @param list<string> $task
     */
    private function isPestParallelTask(array $task): bool
    {
        if ($task === []) {
            return false;
        }

        $tool = strtolower(basename(str_replace('\\', '/', $task[1] ?? $task[0])));

        return $tool === 'pest' && in_array('--parallel', $task, true);
    }

    /**
     * @param array{process:Process,task:list<string>,heading:string,stdout:string,stderr:string,started_at:float} $entry
     * @return array{heading:string,exit_code:int,status:string}
     */
    private function renderFinished(array $entry): array
    {
        $process = $entry['process'];
        $exitCode = $process->getExitCode() ?? 1;
        $status = $process->isSuccessful() ? 'PASS' : 'FAIL';

        if (!$process->isSuccessful() && TaskSkipPolicy::shouldSkipUnavailablePerPreset($entry['task'], $entry['stdout'], $entry['stderr'])) {
            $exitCode = 0;
            $status = 'SKIP';
        }

        if ($status === 'FAIL' && $this->shouldRetryPestWithoutParallel($entry['task'], $entry['stdout'], $entry['stderr'])) {
            return $this->retryPestWithoutParallel($entry);
        }

        $this->output->writeln(sprintf('<info>%s</info>', $entry['heading']));
        $this->writeBuffered($entry['stdout'], false);
        $this->writeBuffered($entry['stderr'], true);
        $this->output->writeln(sprintf(
            '<%s>%s</%s> %s (%0.2fs)',
            $status === 'FAIL' ? 'error' : 'info',
            $status,
            $status === 'FAIL' ? 'error' : 'info',
            $entry['heading'],
            microtime(true) - $entry['started_at'],
        ));
        $this->output->writeln('');

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
     * @param array{process:Process,task:list<string>,heading:string,stdout:string,stderr:string,started_at:float} $entry
     * @return array{heading:string,exit_code:int,status:string}
     */
    private function retryPestWithoutParallel(array $entry): array
    {
        $this->output->writeln(sprintf('<info>%s</info>', $entry['heading']));
        $this->writeBuffered($entry['stdout'], false);
        $this->writeBuffered($entry['stderr'], true);
        $this->output->writeln('<comment>Pest parallel worker crashed; retrying this task without Pest internal parallelization.</comment>');

        $fallbackTask = $this->withoutPestParallelArgs($entry['task']);
        $startedAt = microtime(true);
        $heading = $entry['heading'] . ' [retry: no-pest-parallel]';
        $result = $this->runSynchronousCheck($fallbackTask, $heading);
        $status = $result['status'];

        $this->output->writeln(sprintf(
            '<%s>%s</%s> %s (%0.2fs)',
            $status === 'FAIL' ? 'error' : 'info',
            $status,
            $status === 'FAIL' ? 'error' : 'info',
            $result['heading'],
            microtime(true) - $startedAt,
        ));
        $this->output->writeln('');

        return $result;
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
        $nextId = 0;

        while ($pending !== [] || $active !== []) {
            while ($pending !== [] && count($active) < $concurrency) {
                $task = array_shift($pending);
                $id = 'task-' . ++$nextId;
                $active[$id] = $this->startTask($task);
            }

            foreach ($this->collectFinished($active) as $result) {
                $results[] = $result;
            }

            if ($active !== []) {
                usleep(100_000);
            }
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
        $process = new Process($task, getcwd() ?: null);
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
     */
    private function shouldRetryPestWithoutParallel(array $task, string $stdout, string $stderr): bool
    {
        if (!$this->isPestParallelTask($task)) {
            return false;
        }

        $combined = $stdout . "\n" . $stderr;

        return str_contains($combined, 'WorkerCrashedException')
            || str_contains($combined, 'The test "PARATEST=')
            || str_contains($combined, 'paratest [');
    }

    /**
     * @param list<string> $task
     * @return array{process:Process,task:list<string>,heading:string,stdout:string,stderr:string,started_at:float}
     */
    private function startTask(array $task): array
    {
        $process = new Process($task, getcwd() ?: null);
        $process->setTimeout(null);
        $process->start();

        return [
            'process' => $process,
            'task' => $task,
            'heading' => TaskDisplay::heading($task),
            'stdout' => '',
            'stderr' => '',
            'started_at' => microtime(true),
        ];
    }

    /**
     * @param list<string> $task
     * @return list<string>
     */
    private function withoutPestParallelArgs(array $task): array
    {
        $filtered = [];
        $count = count($task);

        for ($index = 0; $index < $count; $index++) {
            $argument = $task[$index];

            if ($argument === '--parallel') {
                continue;
            }

            if ($argument === '--processes') {
                $index++;

                continue;
            }

            if (str_starts_with($argument, '--processes=')) {
                continue;
            }

            $filtered[] = $argument;
        }

        return $filtered;
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
}
