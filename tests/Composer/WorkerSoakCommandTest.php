<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\WorkerSoakCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param array<string, mixed> $input
 * @return array{exit_code:int,output:string}
 */
function runWorkerSoakCommand(array $input): array
{
    $command = new WorkerSoakCommand();

    // Give the command an Application instance so $this->getApplication() works inside it.
    $application = new Symfony\Component\Console\Application();
    $command->setApplication($application);

    // IMPORTANT: do NOT add a 'command' key here — we will use the command's own definition.
    // Build ArrayInput with the command's InputDefinition so the command's arguments/options are accepted.
    $inputObj = new ArrayInput($input, $command->getDefinition());

    $output = new BufferedOutput();
    $exitCode = $command->run($inputObj, $output);

    return [
        'exit_code' => $exitCode,
        'output' => $output->fetch(),
    ];
}

it('soak tests a generic long-running worker and writes bounded rss telemetry', function (): void {
    $report = tempnam(sys_get_temp_dir(), 'phpforge-soak-report-');
    unlink($report);

    try {
        $result = runWorkerSoakCommand([
            'worker-command' => [PHP_BINARY, '-r', 'while (true) { usleep(100000); }'],
            '--duration' => '1',
            '--warmup' => '0.2',
            '--sample-interval' => '0.1',
            '--max-growth-mb' => '8',
            '--report' => $report,
        ]);
        $decoded = json_decode((string) file_get_contents($report), true);

        expect($result['exit_code'])->toBe(0)
            ->and($decoded['status'] ?? null)->toBe('passed')
            ->and($decoded['samples'] ?? 0)->toBeGreaterThan(0)
            ->and($decoded['command_name'] ?? null)->toBe(basename(PHP_BINARY));
    } finally {
        if (is_file($report)) {
            unlink($report);
        }
    }
});

it('fails when a worker exits before the requested soak duration', function (): void {
    $result = runWorkerSoakCommand([
        'worker-command' => [PHP_BINARY, '-r', 'exit(0);'],
        '--duration' => '1',
        '--warmup' => '0',
        '--sample-interval' => '0.1',
    ]);

    expect($result['exit_code'])->toBe(1)
        ->and($result['output'])->toContain('exited before');
});
