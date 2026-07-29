<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class StableRuntimeConstraints
{
    /**
     * @return list<string>
     */
    public function violations(string $composerFile): array
    {
        if (!is_file($composerFile) || !is_readable($composerFile)) {
            return [sprintf('Composer manifest is not readable: %s', $composerFile)];
        }

        $contents = file_get_contents($composerFile);

        if (!is_string($contents) || $contents === '') {
            return [sprintf('Composer manifest is empty or unreadable: %s', $composerFile)];
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return [sprintf('Composer manifest is not valid JSON: %s', $composerFile)];
        }

        $violations = [];
        $minimumStability = $data['minimum-stability'] ?? 'stable';

        if (!is_string($minimumStability) || strtolower($minimumStability) !== 'stable') {
            $violations[] = sprintf(
                'minimum-stability must be omitted or "stable" for a release; found %s.',
                is_scalar($minimumStability) ? (string) $minimumStability : get_debug_type($minimumStability),
            );
        }

        $requirements = $data['require'] ?? [];

        if (!is_array($requirements)) {
            return [...$violations, 'The runtime require section must be an object.'];
        }

        foreach ($requirements as $package => $constraint) {
            if (!is_string($package) || !is_string($constraint) || $constraint === '') {
                $violations[] = 'Every runtime dependency must have a non-empty string constraint.';

                continue;
            }

            if ($this->isPlatformRequirement($package)) {
                continue;
            }

            $reason = $this->unstableReason($constraint);

            if (is_string($reason)) {
                $violations[] = sprintf('%s uses non-stable runtime constraint "%s" (%s).', $package, $constraint, $reason);
            }
        }

        return $violations;
    }

    private function isPlatformRequirement(string $package): bool
    {
        return $package === 'php'
            || $package === 'composer-plugin-api'
            || $package === 'composer-runtime-api'
            || str_starts_with($package, 'ext-')
            || str_starts_with($package, 'lib-');
    }

    private function unstableReason(string $constraint): ?string
    {
        $normalized = strtolower(trim($constraint));

        if ($normalized === '*' || $normalized === 'dev-*') {
            return 'unbounded development constraint';
        }

        if (preg_match('/(?:^|[\s|,])dev-[^\s|,]+/', $normalized) === 1) {
            return 'development branch';
        }

        if (preg_match('/\b[^\s|,]+\.x-dev\b/', $normalized) === 1) {
            return 'development branch';
        }

        if (preg_match('/@(dev|alpha|beta|rc)\b/i', $constraint) === 1) {
            return 'explicit pre-stable stability flag';
        }

        if (preg_match('/\bv?\d+(?:\.\d+)*-(?:dev|alpha|beta|rc)\d*\b/i', $constraint) === 1) {
            return 'explicit pre-stable version';
        }

        if (preg_match('/\bas\s+\d+(?:\.\d+)*(?:-(?:dev|alpha|beta|rc)\d*)?\b/i', $constraint) === 1) {
            return 'branch alias';
        }

        if (preg_match('/#[a-f0-9]{7,40}\b/i', $constraint) === 1) {
            return 'source commit reference';
        }

        return null;
    }
}
