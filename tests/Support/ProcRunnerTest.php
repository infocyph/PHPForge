<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\ProcessResult;
use Infocyph\PHPForge\Support\ProcRunner;

it('captures stdin, stdout, stderr, and the exit code', function (): void {
    $script = <<<'PHP'
fwrite(STDOUT, stream_get_contents(STDIN));
fwrite(STDERR, 'problem');
exit(7);
PHP;

    $result = (new ProcRunner())->run([PHP_BINARY, '-r', $script], 'payload');

    PHPUnit\Framework\Assert::assertInstanceOf(ProcessResult::class, $result);

    expect($result->stdout)->toBe('payload')
        ->and($result->stderr)->toBe('problem')
        ->and($result->exitCode)->toBe(7);
});

it('drains large stdout and stderr streams without blocking either pipe', function (): void {
    $bytes = 2 * 1024 * 1024;
    $script = <<<'PHP'
$bytes = (int) $argv[1];
fwrite(STDOUT, str_repeat('o', $bytes));
fwrite(STDERR, str_repeat('e', $bytes));
PHP;

    $result = (new ProcRunner())->run([PHP_BINARY, '-r', $script, (string) $bytes]);

    PHPUnit\Framework\Assert::assertInstanceOf(ProcessResult::class, $result);

    expect($result->successful())->toBeTrue()
        ->and(strlen($result->stdout))->toBe($bytes)
        ->and(strlen($result->stderr))->toBe($bytes);
});
