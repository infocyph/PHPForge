<?php

declare(strict_types=1);

/**
 * @param list<int|string> $path
 *
 * @throws UnexpectedValueException
 * @throws OutOfBoundsException
 */
function fixtureValue(mixed $value, array $path, bool $allowMissing = false): mixed
{
    foreach ($path as $key) {
        if ($allowMissing && $value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new UnexpectedValueException('Expected an array at fixture key ' . (string) $key . '.');
        }

        if (!array_key_exists($key, $value)) {
            if ($allowMissing) {
                return null;
            }

            throw new OutOfBoundsException('Missing fixture key ' . (string) $key . '.');
        }

        $value = $value[$key];
    }

    return $value;
}

/**
 * @return array<array-key, mixed>
 *
 * @throws UnexpectedValueException
 */
function fixtureArray(mixed $value): array
{
    if (!is_array($value)) {
        throw new UnexpectedValueException('Expected a fixture array.');
    }

    return $value;
}

/**
 * @return list<string>
 *
 * @throws UnexpectedValueException
 */
function fixtureStringList(mixed $value): array
{
    $items = fixtureArray($value);

    if (!array_is_list($items)) {
        throw new UnexpectedValueException('Expected a fixture list.');
    }

    return array_map(fixtureString(...), $items);
}

/**
 * @return array<string, string>
 *
 * @throws UnexpectedValueException
 */
function fixtureStringMap(mixed $value): array
{
    $result = [];

    foreach (fixtureArray($value) as $key => $item) {
        if (!is_string($key)) {
            throw new UnexpectedValueException('Expected a string fixture key.');
        }

        $result[$key] = fixtureString($item);
    }

    return $result;
}

/** @throws UnexpectedValueException */
function fixtureString(mixed $value): string
{
    if (!is_string($value)) {
        throw new UnexpectedValueException('Expected a fixture string.');
    }

    return $value;
}

/** @throws UnexpectedValueException */
function fixtureInt(mixed $value): int
{
    if (!is_int($value)) {
        throw new UnexpectedValueException('Expected a fixture integer.');
    }

    return $value;
}

/** @throws RuntimeException */
function fixtureTempFile(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);

    if ($path === false) {
        throw new RuntimeException('Unable to create a fixture file.');
    }

    return $path;
}

/**
 * @return list<string>
 *
 * @throws RuntimeException
 */
function fixtureGlob(string $pattern): array
{
    $paths = glob($pattern);

    if ($paths === false) {
        throw new RuntimeException('Unable to list fixture paths.');
    }

    return $paths;
}

/** @throws UnexpectedValueException */
function fixtureFile(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new UnexpectedValueException('Unable to read fixture file ' . $path . '.');
    }

    return $contents;
}

/** @throws UnexpectedValueException */
function removeFixtureDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            throw new UnexpectedValueException('Expected a fixture filesystem entry.');
        }

        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}
