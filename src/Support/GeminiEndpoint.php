<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/** @internal */
final class GeminiEndpoint
{
    /**
     * The model supplies a single restricted segment on Gemini's fixed HTTPS origin.
     *
     * @psalm-taint-escape url_path
     *
     * @throws \RuntimeException
     */
    public static function forModel(string $model, string $label): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $model) !== 1) {
            throw new \RuntimeException($label . ' contains invalid characters.');
        }

        return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    }
}
