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

        if (self::isPhpstan($task)) {
            $environment['GITHUB_ACTIONS'] = false;
        }

        return $environment === [] ? null : $environment;
    }

    /** @param list<string> $task */
    private static function isPhpstan(array $task): bool
    {
        return basename(str_replace('\\', '/', $task[1] ?? '')) === 'phpstan';
    }
}
