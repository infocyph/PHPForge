<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('executes Pest parallel workers with the supported dependency set', function (): void {
    $project = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/phpforge-pest-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $fixture = $directory.'/ParallelTest.php';
    file_put_contents($fixture, "<?php test('worker executes', function () { expect(2 + 2)->toBe(4); });\n");
    file_put_contents($directory.'/PhpunitWorkerTest.php', <<<'PHP'
<?php

final class PhpunitWorkerTest extends \PHPUnit\Framework\TestCase
{
    public function testWorkerExecutes(): void
    {
        self::assertSame(4, 2 + 2);
    }
}
PHP);

    try {
        $process = new Process([
            PHP_BINARY,
            $project.'/vendor/bin/pest',
            '--configuration', $project.'/resources/pest.xml',
            '--bootstrap', $project.'/vendor/autoload.php',
            $directory,
            '--parallel', '--processes=2', '--no-coverage',
            '--colors=never',
        ], $project, ['TMPDIR' => $directory, 'TMP' => $directory, 'TEMP' => $directory]);
        $process->setTimeout(30);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())->toContain('2 passed');
    } finally {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
