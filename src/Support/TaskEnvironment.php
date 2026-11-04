<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class TaskEnvironment
{
    /**
     * @param list<string> $task
     * @return array<string, string|false>|null
     */
    public static function for(array $task): ?array
    {
        $environment = [];
        $xdebugMode = getenv('XDEBUG_MODE');

        if (!is_string($xdebugMode) || $xdebugMode === '') {
            $environment['XDEBUG_MODE'] = 'off';
        }

        if (self::isTool($task, 'phpstan')) {
            $environment['GITHUB_ACTIONS'] = false;
        }

        if (self::isTool($task, 'psalm')) {
            // Psalm otherwise returns success for taint analysis with reports in GitHub Actions.
            $environment['GITHUB_WORKFLOW'] = false;
        }

        return $environment === [] ? null : $environment;
    }

    /** @param list<string> $task */
    private static function isTool(array $task, string $name): bool
    {
        return basename(str_replace('\\', '/', $task[1] ?? '')) === $name;
    }
}
