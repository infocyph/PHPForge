<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class ArrayShape
{
    /**
     * @return array<string, mixed>
     */
    public static function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
