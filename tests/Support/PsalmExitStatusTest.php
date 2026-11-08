<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\TaskEnvironment;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('enforces Psalm errors while retaining SARIF in GitHub Actions', function (string $execution, bool $hasErrors): void {
    $project = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/phpforge-psalm-exit-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/vendor/bin', 0700, true);
    copy($project . '/resources/psalm.xml', $directory . '/psalm.xml');
    // Model Psalm's upstream exit contract without booting its unsupported prefer-lowest dependencies.
    file_put_contents($directory . '/vendor/bin/psalm', <<<'PHP'
<?php
$hasErrors = getenv('PSALM_FIXTURE_ERRORS') === '1';
$results = $hasErrors
    ? [['level' => 'error'], ['level' => 'error']]
    : [['level' => 'note']];
if (in_array('--report=psalm-results.sarif', $argv, true)) {
    file_put_contents('psalm-results.sarif', json_encode(['runs' => [['results' => $results]]], JSON_THROW_ON_ERROR));
}
echo $hasErrors ? "InvalidReturnType\nInvalidReturnStatement\n2 errors found\n" : "No errors found\n";
exit($hasErrors && getenv('GITHUB_WORKFLOW') === false ? 2 : 0);
PHP);

    try {
        $environment = [
            'GITHUB_ACTIONS' => 'true',
            'GITHUB_WORKFLOW' => 'Consumer QA',
            'vendor_dir' => $directory . '/vendor',
            'psalm_config' => $directory . '/psalm.xml',
            'PSALM_FIXTURE_ERRORS' => $hasErrors ? '1' : '0',
        ];

        if ($execution === 'workflow') {
            $workflow = Yaml::parseFile($project . '/.github/workflows/security-standards.yml');
            $steps = array_column(fixtureArray(fixtureValue($workflow, ['jobs', 'analyze', 'steps'])), null, 'name');
            $lines = explode("\n", fixtureString(fixtureValue($steps, ['Run audit and analyzers', 'run'])));
            $commands = array_values(array_filter($lines, static fn(string $line): bool => str_contains($line, 'bin/psalm"')));
            expect($commands)->toHaveCount(1);
            $command = ['bash', '-c', $commands[0]];
        } else {
            $command = [
                PHP_BINARY, $directory . '/vendor/bin/psalm',
                '--config=' . $directory . '/psalm.xml', '--security-analysis',
                '--threads=1', '--no-progress', '--no-cache', '--output-format=console',
                '--report=psalm-results.sarif',
            ];
            $environment = array_replace($environment, TaskEnvironment::for($command) ?? []);
        }

        $process = new Process($command, $directory, $environment);
        $process->setTimeout(60);
        $process->run();
        $output = $execution === 'workflow'
            ? fixtureFile($directory . '/psalm-results.log')
            : $process->getOutput() . $process->getErrorOutput();
        expect(is_file($directory . '/psalm-results.sarif'))->toBeTrue($output);
        $report = json_decode(fixtureFile($directory . '/psalm-results.sarif'), true, 512, JSON_THROW_ON_ERROR);
        $results = fixtureValue($report, ['runs', 0, 'results']);
        $errors = array_values(array_filter(fixtureArray($results), static fn(mixed $result): bool => fixtureValue($result, ['level']) === 'error'));

        expect($process->getExitCode())->toBe($hasErrors ? 2 : 0, $output)
            ->and($results)->not->toBeEmpty()
            ->and($errors)->toHaveCount($hasErrors ? 2 : 0);

        if ($hasErrors) {
            expect($output)->toContain('InvalidReturnType')->toContain('InvalidReturnStatement');
        } else {
            expect(array_column(fixtureArray($results), 'level'))->each->toBe('note');
        }
    } finally {
        unlink($directory . '/vendor/bin/psalm');
        rmdir($directory . '/vendor/bin');
        rmdir($directory . '/vendor');
        foreach (fixtureGlob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(['task', 'workflow'])->with(['errors' => true, 'information only' => false]);
