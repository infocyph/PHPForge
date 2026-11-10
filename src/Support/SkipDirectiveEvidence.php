<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/** @internal Validates reviewed sanitizer evidence inside the consuming project. */
final class SkipDirectiveEvidence
{
    /** @throws \InvalidArgumentException */
    public static function relativePath(mixed $path): string
    {
        if (!is_string($path) || preg_match('~[\\\\:*?\[\]{}\x00-\x1f\x7f]~', $path) === 1 || $path === '' || str_starts_with($path, '/')
            || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new \InvalidArgumentException('Paths must be normalized project-relative paths without traversal or symlinks.');
        }

        return $path;
    }

    /**
     * @return array{sanitizer: string, audit: string}
     * @throws \InvalidArgumentException
     */
    public static function validate(mixed $value, string $root): array
    {
        if (!$value instanceof \stdClass || array_diff(array_keys(get_object_vars($value)), ['sanitizer', 'sha256', 'tests']) !== []) {
            throw new \InvalidArgumentException('security requires sanitizer, sha256 and tests.');
        }

        $sanitizer = self::file($value->sanitizer ?? null, $root);
        $hash = $value->sha256 ?? null;

        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || hash_file('sha256', $sanitizer) !== $hash) {
            throw new \InvalidArgumentException('security.sha256 must match the reviewed sanitizer; review implementation changes before updating it.');
        }

        $tests = $value->tests ?? null;

        if (!is_array($tests) || !array_is_list($tests) || $tests === []) {
            throw new \InvalidArgumentException('security.tests requires explicit regression test files.');
        }

        $testPaths = [];

        foreach ($tests as $test) {
            $testFile = self::file($test, $root);
            $testPaths[] = self::relativeFile($testFile, $root);

            if ($testFile === $sanitizer || strtolower(pathinfo($testFile, PATHINFO_EXTENSION)) !== 'php') {
                throw new \InvalidArgumentException('security.tests must name separate PHP regression test files.');
            }
        }

        $relative = self::relativeFile($sanitizer, $root);

        return ['sanitizer' => $relative, 'audit' => sprintf('sanitizer=%s sha256=%s tests=%s', $relative, $hash, implode(', ', $testPaths))];
    }

    /** @throws \InvalidArgumentException */
    private static function file(mixed $value, string $root): string
    {
        $relative = self::relativePath($value);
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $path = $prefix . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($path);

        if (!is_string($resolved) || $resolved !== $path || !is_file($resolved) || !is_readable($resolved) || filesize($resolved) === 0) {
            throw new \InvalidArgumentException('Security evidence must name readable nonempty project files without symlinks: ' . $relative);
        }

        return $resolved;
    }

    private static function relativeFile(string $path, string $root): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(rtrim($root, '/\\')) + 1));
    }
}
