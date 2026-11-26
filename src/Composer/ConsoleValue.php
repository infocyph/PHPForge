<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

/** @internal Normalizes values supplied by Symfony's mixed input interface. */
final class ConsoleValue
{
    public static function flag(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    /** @return list<mixed> */
    public static function values(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
