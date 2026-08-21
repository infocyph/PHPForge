<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final readonly class GeminiCommitMessageGenerator
{
    public const string DEFAULT_MODEL = 'gemini-flash-lite-latest';

    private const int MAX_DIFF_BYTES = 1_000_000;

    /** @param null|\Closure(string, string, string): string $request */
    public function __construct(private ?\Closure $request = null) {}

    public function configured(): bool
    {
        return $this->environment('GEMINI_API_KEY') !== null;
    }

    public function generate(string $diff): string
    {
        $apiKey = $this->environment('GEMINI_API_KEY');

        if ($apiKey === null) {
            throw new \RuntimeException('GEMINI_API_KEY is not set.');
        }

        if (str_contains($apiKey, "\r") || str_contains($apiKey, "\n")) {
            throw new \RuntimeException('GEMINI_API_KEY contains invalid characters.');
        }

        $model = $this->environment('GEMINI_MODEL') ?? self::DEFAULT_MODEL;

        if (preg_match('/^[A-Za-z0-9._-]+$/', $model) !== 1) {
            throw new \RuntimeException('GEMINI_MODEL contains invalid characters.');
        }

        $payload = $this->payload($diff);
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model,
        );
        $response = $this->request instanceof \Closure
            ? ($this->request)($url, $apiKey, $payload)
            : $this->send($url, $apiKey, $payload);

        return $this->message($response);
    }

    private function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function instruction(): string
    {
        $encoded = $this->environment('GITX_SYS_INSTRUCTION_B64');

        if ($encoded !== null) {
            $decoded = base64_decode($encoded, true);

            if (!is_string($decoded) || trim($decoded) === '') {
                throw new \RuntimeException('GITX_SYS_INSTRUCTION_B64 is not valid base64 text.');
            }

            return $decoded;
        }

        $instruction = file_get_contents(Paths::packageFile('resources/commit-message-instructions.md'));

        if (!is_string($instruction) || trim($instruction) === '') {
            throw new \RuntimeException('The bundled commit-message instruction is unavailable.');
        }

        return $instruction;
    }

    private function message(string $response): string
    {
        $decoded = $this->responseData($response);
        $this->throwApiError($decoded);
        $message = trim(implode("\n", $this->responseTexts($decoded)));
        $message = preg_replace('/\A```(?:text)?\s*\R?(.*?)\R?```\z/s', '$1', $message) ?? $message;
        $message = trim($message);

        if ($message === '' || str_contains($message, "\0")) {
            throw new \RuntimeException('Gemini did not return a usable commit message.');
        }

        if (strlen($message) > 12_000) {
            throw new \RuntimeException('Gemini returned an unexpectedly large commit message.');
        }

        return $message;
    }

    private function payload(string $diff): string
    {
        $truncated = strlen($diff) > self::MAX_DIFF_BYTES;
        $diff = substr($diff, 0, self::MAX_DIFF_BYTES);
        $requestText = 'Analyze the following staged git diff and generate a commit message.';

        if ($truncated) {
            $requestText .= ' The diff was truncated to its first 1,000,000 bytes.';
        }

        return json_encode([
            'system_instruction' => [
                'parts' => [['text' => $this->instruction()]],
            ],
            'contents' => [[
                'parts' => [
                    ['text' => $requestText],
                    [
                        'inline_data' => [
                            'mime_type' => 'text/plain',
                            'data' => base64_encode($diff),
                        ],
                    ],
                ],
            ]],
            'generation_config' => [
                'temperature' => 0.1,
                'topP' => 0.9,
                'topK' => 1,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(string $response): array
    {
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Gemini returned invalid JSON.', 0, $exception);
        }

        $data = ArrayShape::stringKeyed($decoded);

        if ($data === []) {
            throw new \RuntimeException('Gemini returned an invalid response.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function responseTexts(array $response): array
    {
        $candidates = $response['candidates'] ?? null;

        if (!is_array($candidates)) {
            return [];
        }

        $candidate = ArrayShape::stringKeyed($candidates[0] ?? null);
        $content = ArrayShape::stringKeyed($candidate['content'] ?? null);
        $parts = $content['parts'] ?? null;
        $texts = [];

        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }
        }

        return $texts;
    }

    private function send(string $url, string $apiKey, string $payload): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ],
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = sprintf('[PHP warning %d] %s', $severity, $message);

            return true;
        });

        try {
            $response = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if (!is_string($response)) {
            throw new \RuntimeException($warning ?? 'Gemini request failed.');
        }

        return $response;
    }

    /** @param array<string, mixed> $response */
    private function throwApiError(array $response): void
    {
        if (!array_key_exists('error', $response)) {
            return;
        }

        $error = ArrayShape::stringKeyed($response['error']);
        $status = is_string($error['status'] ?? null) ? $error['status'] : 'API_ERROR';
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Unknown Gemini API error.';

        throw new \RuntimeException(sprintf('Gemini API error [%s]: %s', $status, $message));
    }
}
