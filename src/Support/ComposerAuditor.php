<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class ComposerAuditor
{
    public function run(): int
    {
        $result = (new ProcRunner())->run('composer audit --format=json --no-interaction --abandoned=report');

        if (!$result instanceof ProcessResult) {
            fwrite(STDERR, 'Failed to start composer audit process.' . PHP_EOL);

            return 1;
        }

        $decoded = json_decode($result->stdout, true);

        if (!is_array($decoded)) {
            return $this->invalidJson($result);
        }

        $advisoryCount = $this->advisoryCount($decoded['advisories'] ?? []);
        $abandonedPackages = $this->abandonedPackages($decoded['abandoned'] ?? []);

        fwrite(STDOUT, sprintf(
            'Composer audit summary: %d advisories, %d abandoned packages.',
            $advisoryCount,
            count($abandonedPackages),
        ) . PHP_EOL);

        $this->reportAbandonedPackages($abandonedPackages);

        if ($advisoryCount > 0) {
            fwrite(STDERR, 'Security vulnerabilities detected by composer audit.' . PHP_EOL);

            return 1;
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function abandonedPackages(mixed $abandoned): array
    {
        if (!is_array($abandoned)) {
            return [];
        }

        $packages = [];

        foreach ($abandoned as $package => $replacement) {
            if (is_string($package) && $package !== '') {
                $packages[$package] = $replacement;
            }
        }

        return $packages;
    }

    private function advisoryCount(mixed $advisories): int
    {
        if (!is_array($advisories)) {
            return 0;
        }

        $count = 0;

        foreach ($advisories as $entries) {
            if (is_array($entries)) {
                $count += count($entries);
            }
        }

        return $count;
    }

    private function invalidJson(ProcessResult $result): int
    {
        fwrite(STDERR, 'Unable to parse composer audit JSON output.' . PHP_EOL);

        if (trim($result->stdout) !== '') {
            fwrite(STDERR, $result->stdout . PHP_EOL);
        }

        if (trim($result->stderr) !== '') {
            fwrite(STDERR, $result->stderr . PHP_EOL);
        }

        return $result->exitCode !== 0 ? $result->exitCode : 1;
    }

    /**
     * @param array<string, mixed> $abandonedPackages
     */
    private function reportAbandonedPackages(array $abandonedPackages): void
    {
        if ($abandonedPackages === []) {
            return;
        }

        fwrite(STDERR, 'Warning: abandoned packages detected (non-blocking):' . PHP_EOL);

        foreach ($abandonedPackages as $package => $replacement) {
            $target = is_string($replacement) && $replacement !== '' ? $replacement : 'none';
            fwrite(STDERR, sprintf(' - %s (replacement: %s)', $package, $target) . PHP_EOL);
        }
    }
}
