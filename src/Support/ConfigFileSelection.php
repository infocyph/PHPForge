<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Console\Output\OutputInterface;

final class ConfigFileSelection
{
    /**
     * @param list<string> $files
     * @param list<string> $supported
     * @return list<string>
     */
    public static function invalid(array $files, array $supported): array
    {
        return array_values(array_filter(
            $files,
            static fn(string $file): bool => !in_array($file, $supported, true),
        ));
    }

    /**
     * @param list<mixed> $files
     * @param list<string> $supported
     * @return list<string>
     */
    public static function normalize(array $files, array $supported): array
    {
        $normalized = [];

        foreach ($files as $file) {
            $candidate = self::normalizeOne($file, $supported);

            if ($candidate !== null) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $files
     * @param list<string> $supported
     * @return list<string>|null
     */
    public static function validatedOrWriteError(
        array $files,
        array $supported,
        string $prefix,
        OutputInterface $output,
    ): ?array {
        $error = self::validationError($files, $supported, $prefix);

        if (!is_string($error)) {
            return $files;
        }

        $output->writeln(sprintf('<error>%s</error>', $error));

        return null;
    }

    /**
     * @param list<string> $files
     * @param list<string> $supported
     */
    public static function validationError(array $files, array $supported, string $prefix): ?string
    {
        $invalid = self::invalid($files, $supported);

        if ($invalid === []) {
            return null;
        }

        return sprintf(
            '%s: %s. Supported files: %s',
            $prefix,
            implode(', ', $invalid),
            implode(', ', $supported),
        );
    }

    /**
     * @param list<string> $supported
     */
    private static function normalizeOne(mixed $file, array $supported): ?string
    {
        if (!is_string($file) || $file === '') {
            return null;
        }

        if (in_array($file, $supported, true)) {
            return $file;
        }

        if (str_starts_with($file, '--')) {
            $candidate = substr($file, 2);

            if (in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return $file;
    }
}
