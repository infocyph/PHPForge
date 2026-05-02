<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class ConfigInventory
{
    /**
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [];

        foreach (self::tools() as $toolFiles) {
            foreach ($toolFiles as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    public static function resolvedPath(string $file): string
    {
        if (self::source($file) === 'project') {
            return Paths::projectRootPath() . DIRECTORY_SEPARATOR . $file;
        }

        return Paths::bundledConfigFileOrNull($file) ?? '';
    }

    public static function source(string $file): string
    {
        $projectFile = Paths::projectRootPath() . DIRECTORY_SEPARATOR . $file;

        if (is_file($projectFile)) {
            return 'project';
        }

        if (is_string(Paths::bundledConfigFileOrNull($file))) {
            return 'phpforge';
        }

        return 'missing';
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    public static function tools(): array
    {
        return [
            'pest' => ['pest.xml', 'phpunit.xml'],
            'phpbench' => ['phpbench.json'],
            'phpforge' => ['phpforge.json'],
            'phpcs' => ['phpcs.xml.dist'],
            'phpstan' => ['phpstan.neon.dist'],
            'pint' => ['pint.json'],
            'psalm' => ['psalm.xml'],
            'rector' => ['rector.php'],
            'captainhook' => ['captainhook.json'],
            'deptrac' => ['deptrac.yaml'],
        ];
    }
}
