<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class ComposerAuditor
{
    private const int MAX_NETWORK_ATTEMPTS = 3;

    public function run(): int
    {
        for ($attempt = 1; $attempt <= self::MAX_NETWORK_ATTEMPTS; $attempt++) {
            $result = new ProcRunner()->run([
                'composer',
                'audit',
                '--format=json',
                '--no-interaction',
                '--abandoned=report',
            ]);

            if (!$result instanceof ProcessResult) {
                fwrite(STDERR, 'Failed to start composer audit process.' . PHP_EOL);

                return 1;
            }

            $decoded = json_decode($result->stdout, true);

            if (is_array($decoded)) {
                return $this->evaluate(ArrayShape::stringKeyed($decoded));
            }

            if (!$this->isNetworkFailure($result)) {
                return $this->invalidJson($result);
            }

            if ($attempt === self::MAX_NETWORK_ATTEMPTS) {
                return $this->networkFailure($result);
            }

            fwrite(STDERR, sprintf(
                'Composer audit could not reach Packagist (attempt %d/%d); retrying.',
                $attempt,
                self::MAX_NETWORK_ATTEMPTS,
            ) . PHP_EOL);
        }

        return 1;
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

    /**
     * @param array<string, mixed> $decoded
     */
    private function evaluate(array $decoded): int
    {
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

    private function invalidJson(ProcessResult $result): int
    {
        fwrite(STDERR, 'Unable to parse composer audit JSON output.' . PHP_EOL);

        $this->reportProcessOutput($result);

        return $result->exitCode !== 0 ? $result->exitCode : 1;
    }

    private function isNetworkFailure(ProcessResult $result): bool
    {
        $output = strtolower($result->stdout . PHP_EOL . $result->stderr);

        return str_contains($output, 'curl error')
            || str_contains($output, 'connection timed out')
            || str_contains($output, 'could not resolve host')
            || str_contains($output, 'network is unreachable');
    }

    private function networkFailure(ProcessResult $result): int
    {
        fwrite(STDERR, sprintf(
            'Composer audit could not reach Packagist after %d attempts; the security audit was not completed.',
            self::MAX_NETWORK_ATTEMPTS,
        ) . PHP_EOL);

        $this->reportProcessOutput($result);

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

    private function reportProcessOutput(ProcessResult $result): void
    {
        if (trim($result->stdout) !== '') {
            fwrite(STDERR, $result->stdout . PHP_EOL);
        }

        if (trim($result->stderr) !== '') {
            fwrite(STDERR, $result->stderr . PHP_EOL);
        }
    }
}
