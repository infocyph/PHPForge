<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Explains a bounded knowledge-query result without changing extracted facts.
 *
 * @phpstan-type Explanation array{provider:string,model:string,certainty:string,summary:string,evidence:list<string>,uncertainty:string}
 */
final readonly class KnowledgeExplainer
{
    public const string DEFAULT_GEMINI_MODEL = 'gemini-flash-lite-latest';

    private const int MAX_CONTEXT_BYTES = 500_000;

    private const int MAX_RESPONSE_BYTES = 100_000;

    /** @param null|\Closure(string, list<string>, string): string $request */
    public function __construct(private ?\Closure $request = null) {}

    /**
     * @param array<string, mixed> $query
     * @return Explanation
     */
    public function explain(array $query, string $provider = 'auto', ?string $model = null): array
    {
        $provider = strtolower(trim($provider));

        if (!in_array($provider, ['auto', 'ollama', 'gemini'], true)) {
            throw new \InvalidArgumentException('Knowledge provider must be auto, ollama or gemini.');
        }

        $context = json_encode($query, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($context) > self::MAX_CONTEXT_BYTES) {
            throw new \RuntimeException(sprintf('Knowledge query context exceeds %d bytes.', self::MAX_CONTEXT_BYTES));
        }

        [$resolvedProvider, $response] = $this->providerResponse($context, $provider, $model);
        $explanation = $this->decodeExplanation($response, $resolvedProvider);
        $allowedEvidence = $this->allowedEvidence($query);

        foreach ($explanation['evidence'] as $nodeId) {
            if (!isset($allowedEvidence[$nodeId])) {
                throw new \RuntimeException(sprintf('%s explanation cited evidence outside the retrieved graph slice: %s', ucfirst($resolvedProvider), $nodeId));
            }
        }

        return $explanation;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, true>
     */
    private function allowedEvidence(array $query): array
    {
        $allowed = [];

        foreach (['matches', 'context_nodes'] as $section) {
            $items = $query[$section] ?? [];

            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $node = $section === 'matches' && is_array($item) ? ($item['node'] ?? null) : $item;

                if (is_array($node) && is_string($node['id'] ?? null)) {
                    $allowed[$node['id']] = true;
                }
            }
        }

        return $allowed;
    }

    /** @return array<string, mixed> */
    private function decodedObject(string $response, string $provider): array
    {
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException($provider . ' returned invalid response JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException($provider . ' returned an invalid response.');
        }

        $decoded = ArrayShape::stringKeyed($decoded);

        $error = $decoded['error'] ?? null;

        if (is_string($error) && $error !== '') {
            throw new \RuntimeException($provider . ' API error: ' . $error);
        }

        if (is_array($error)) {
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';

            throw new \RuntimeException($provider . ' API error: ' . $message);
        }

        return $decoded;
    }

    /**
     * @return Explanation
     */
    private function decodeExplanation(string $response, string $provider): array
    {
        if (strlen($response) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException(sprintf('%s returned an unexpectedly large response.', ucfirst($provider)));
        }

        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('%s returned invalid JSON.', ucfirst($provider)), previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('%s returned an invalid explanation.', ucfirst($provider)));
        }

        $summary = trim(is_string($decoded['summary'] ?? null) ? $decoded['summary'] : '');
        $uncertainty = trim(is_string($decoded['uncertainty'] ?? null) ? $decoded['uncertainty'] : '');
        $evidence = $decoded['evidence'] ?? null;

        if ($summary === '' || !is_array($evidence) || !array_is_list($evidence)) {
            throw new \RuntimeException(sprintf('%s returned an incomplete explanation.', ucfirst($provider)));
        }

        foreach ($evidence as $nodeId) {
            if (!is_string($nodeId) || trim($nodeId) === '') {
                throw new \RuntimeException(sprintf('%s returned invalid evidence identifiers.', ucfirst($provider)));
            }
        }

        $model = is_string($decoded['_model'] ?? null) ? $decoded['_model'] : '';

        return [
            'provider' => $provider,
            'model' => $model,
            'certainty' => 'inferred',
            'summary' => $summary,
            'evidence' => $evidence,
            'uncertainty' => $uncertainty,
        ];
    }

    private function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function gemini(string $context, ?string $model): string
    {
        $key = $this->environment('GEMINI_API_KEY');

        if ($key === null) {
            throw new \RuntimeException('GEMINI_API_KEY is not set.');
        }

        if (str_contains($key, "\r") || str_contains($key, "\n")) {
            throw new \RuntimeException('GEMINI_API_KEY contains invalid characters.');
        }

        $model = $this->validatedModel($model ?? $this->environment('PHPFORGE_KNOWLEDGE_GEMINI_MODEL') ?? self::DEFAULT_GEMINI_MODEL);
        $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $model);
        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => $this->instruction()]]],
            'contents' => [['parts' => [['text' => $context]]]],
            'generation_config' => [
                'temperature' => 0.1,
                'response_mime_type' => 'application/json',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = $this->send($url, ['Content-Type: application/json', 'x-goog-api-key: ' . $key], $payload);
        $body = $this->decodedObject($response, 'Gemini');
        $candidate = ArrayShape::stringKeyed(is_array($body['candidates'] ?? null) ? ($body['candidates'][0] ?? null) : null);
        $content = ArrayShape::stringKeyed($candidate['content'] ?? null);
        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];
        $text = ArrayShape::stringKeyed($parts[0] ?? null)['text'] ?? null;

        if (!is_string($text)) {
            throw new \RuntimeException('Gemini returned no explanation.');
        }

        return $this->withModel($text, $model);
    }

    private function instruction(): string
    {
        return implode(' ', [
            'Explain the retrieved PHPForge knowledge-graph slice.',
            'Use only the supplied nodes and edges; never invent symbols or relationships.',
            'Return one JSON object with summary (string), evidence (array of exact node IDs), and uncertainty (string).',
            'State uncertainty when the extracted graph cannot answer the question.',
        ]);
    }

    private function ollama(string $context, ?string $model): string
    {
        $host = rtrim($this->environment('OLLAMA_HOST') ?? 'http://127.0.0.1:11434', '/');

        if (filter_var($host, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $host)) {
            throw new \RuntimeException('OLLAMA_HOST must be an HTTP or HTTPS URL.');
        }

        $configuredModel = $model ?? $this->environment('OLLAMA_MODEL');
        $model = is_string($configuredModel)
            ? $this->validatedModel($configuredModel, true)
            : $this->ollamaModel($host);

        $payload = json_encode([
            'model' => $model,
            'stream' => false,
            'format' => 'json',
            'messages' => [
                ['role' => 'system', 'content' => $this->instruction()],
                ['role' => 'user', 'content' => $context],
            ],
            'options' => ['temperature' => 0.1],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = $this->decodedObject($this->send($host . '/api/chat', ['Content-Type: application/json'], $payload), 'Ollama');
        $message = ArrayShape::stringKeyed($body['message'] ?? null);
        $content = $message['content'] ?? null;

        if (!is_string($content)) {
            throw new \RuntimeException('Ollama returned no explanation.');
        }

        return $this->withModel($content, $model);
    }

    private function ollamaModel(string $host): string
    {
        $lastError = null;

        foreach (['/api/ps', '/api/tags'] as $path) {
            try {
                $body = $this->decodedObject(
                    $this->send($host . $path, ['Accept: application/json'], '', 'GET', 5),
                    'Ollama',
                );
            } catch (\RuntimeException $exception) {
                $lastError = $exception;

                continue;
            }

            $model = $this->ollamaModelFrom($body);

            if (is_string($model)) {
                return $this->validatedModel($model, true);
            }
        }

        $detail = $lastError instanceof \RuntimeException ? ' ' . $lastError->getMessage() : '';

        throw new \RuntimeException('No Ollama model is available. Start or install a chat-capable model, or set OLLAMA_MODEL.' . $detail);
    }

    /** @param array<string, mixed> $body */
    private function ollamaModelFrom(array $body): ?string
    {
        $models = $body['models'] ?? null;

        if (!is_array($models)) {
            return null;
        }

        foreach ($models as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $name = $candidate['model'] ?? $candidate['name'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    /** @return array{string,string} */
    private function providerResponse(string $context, string $provider, ?string $model): array
    {
        if ($provider === 'ollama') {
            return ['ollama', $this->ollama($context, $model)];
        }

        if ($provider === 'gemini') {
            return ['gemini', $this->gemini($context, $model)];
        }

        try {
            return ['ollama', $this->ollama($context, $model)];
        } catch (\RuntimeException $ollamaException) {
            if ($this->environment('GEMINI_API_KEY') === null) {
                throw $ollamaException;
            }

            try {
                return ['gemini', $this->gemini($context, null)];
            } catch (\RuntimeException $geminiException) {
                throw new \RuntimeException(sprintf(
                    'Local Ollama explanation failed (%s); Gemini fallback failed (%s).',
                    $ollamaException->getMessage(),
                    $geminiException->getMessage(),
                ), previous: $geminiException);
            }
        }
    }

    /** @param list<string> $headers */
    private function send(string $url, array $headers, string $payload, string $method = 'POST', int $timeout = 30): string
    {
        if ($this->request instanceof \Closure) {
            return ($this->request)($url, $headers, $payload);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headers,
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => $timeout,
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
            throw new \RuntimeException($warning ?? 'Knowledge provider request failed.');
        }

        return $response;
    }

    private function validatedModel(string $model, bool $ollama = false): string
    {
        $pattern = $ollama
            ? '#^[A-Za-z0-9][A-Za-z0-9._:/@-]{0,254}$#'
            : '/^[A-Za-z0-9._-]+$/';

        if (preg_match($pattern, $model) !== 1) {
            throw new \RuntimeException('Knowledge model name contains invalid characters.');
        }

        return $model;
    }

    private function withModel(string $explanation, string $model): string
    {
        try {
            $decoded = json_decode($explanation, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Knowledge provider returned invalid explanation JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Knowledge provider returned an invalid explanation.');
        }

        $decoded['_model'] = $model;

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
