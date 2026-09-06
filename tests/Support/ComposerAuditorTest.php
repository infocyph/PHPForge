<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @return array{process: Process, attempts: int}
 */
function runComposerAuditFixture(int $successfulAttempt): array
{
    $projectRoot = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpforge-audit-'.uniqid('', true);
    $attemptFile = $directory.DIRECTORY_SEPARATOR.'attempts';
    $composer = $directory.DIRECTORY_SEPARATOR.'composer';
    mkdir($directory, 0755, true);
    file_put_contents($composer, <<<'PHP'
#!/usr/bin/env php
<?php

$attemptFile = (string) getenv('PHPFORGE_AUDIT_ATTEMPT_FILE');
$successfulAttempt = (int) getenv('PHPFORGE_AUDIT_SUCCESSFUL_ATTEMPT');
$attempt = is_file($attemptFile) ? ((int) file_get_contents($attemptFile)) + 1 : 1;
file_put_contents($attemptFile, (string) $attempt);

if ($successfulAttempt > 0 && $attempt >= $successfulAttempt) {
    fwrite(STDOUT, '{"advisories":[],"abandoned":[]}');
    exit(0);
}

fwrite(STDERR, 'curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out'.PHP_EOL);
exit(100);
PHP);
    chmod($composer, 0755);

    $path = $directory.PATH_SEPARATOR.(getenv('PATH') ?: '');
    $process = new Process(
        [PHP_BINARY, $projectRoot.'/bin/phpforge', 'audit'],
        $projectRoot,
        [
            'PATH' => $path,
            'PHPFORGE_AUDIT_ATTEMPT_FILE' => $attemptFile,
            'PHPFORGE_AUDIT_SUCCESSFUL_ATTEMPT' => (string) $successfulAttempt,
        ],
    );
    $process->run();
    $attempts = is_file($attemptFile) ? (int) file_get_contents($attemptFile) : 0;

    unlink($composer);
    unlink($attemptFile);
    rmdir($directory);

    return ['process' => $process, 'attempts' => $attempts];
}

it('retries transient Packagist failures and succeeds with valid audit data', function (): void {
    $result = runComposerAuditFixture(3);

    expect($result['process']->getExitCode())->toBe(0)
        ->and($result['attempts'])->toBe(3)
        ->and($result['process']->getOutput())->toContain('Composer audit summary: 0 advisories, 0 abandoned packages.')
        ->and($result['process']->getErrorOutput())->toContain('attempt 1/3')
        ->toContain('attempt 2/3');
});

it('fails closed with a network diagnostic after exhausting retries', function (): void {
    $result = runComposerAuditFixture(0);

    expect($result['process']->getExitCode())->toBe(100)
        ->and($result['attempts'])->toBe(3)
        ->and($result['process']->getErrorOutput())
        ->toContain('could not reach Packagist after 3 attempts')
        ->toContain('security audit was not completed')
        ->not->toContain('Unable to parse composer audit JSON output.');
});
