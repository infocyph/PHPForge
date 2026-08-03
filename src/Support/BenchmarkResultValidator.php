<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class BenchmarkResultValidator
{
    private const array LATENCY_FIELDS = ['minimum', 'average', 'p50', 'p95', 'p99', 'maximum'];

    private const array RESOURCE_FIELDS = [
        'cpu' => ['average_percent', 'peak_percent'],
        'memory' => ['average_mb', 'peak_mb', 'growth_mb'],
    ];

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function errors(array $document): array
    {
        $errors = [];

        if (($document['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be 1.';
        }

        if (!$this->isDateTime($document['generated_at'] ?? null)) {
            $errors[] = 'generated_at must be an RFC 3339 date-time string.';
        }

        return [
            ...$errors,
            ...$this->environmentErrors($document['environment'] ?? null),
            ...$this->workloadsErrors($document['workloads'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function environmentErrors(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return ['environment must be an object.'];
        }

        $environment = ArrayShape::stringKeyed($value);
        $errors = $this->requiredErrors(
            'environment',
            $environment,
            ['stable', 'fingerprint', 'php_version', 'php_sapi', 'operating_system', 'cpu_model', 'memory_limit', 'opcache', 'jit', 'xdebug', 'extensions', 'runner'],
        );

        if (!is_bool($environment['stable'] ?? null)) {
            $errors[] = 'environment.stable must be boolean.';
        }

        foreach (['fingerprint', 'php_version', 'php_sapi', 'operating_system', 'cpu_model', 'runner'] as $field) {
            if (!is_string($environment[$field] ?? null) || $environment[$field] === '') {
                $errors[] = sprintf('environment.%s must be a non-empty string.', $field);
            }
        }

        return [...$errors, ...$this->environmentValueErrors($environment)];
    }

    /**
     * @param array<string, mixed> $environment
     * @return list<string>
     */
    private function environmentValueErrors(array $environment): array
    {
        $errors = [];
        $extensions = $environment['extensions'] ?? null;

        if (!is_array($extensions) || !array_is_list($extensions) || array_filter($extensions, is_string(...)) !== $extensions) {
            $errors[] = 'environment.extensions must be a list of strings.';
        }

        if (!is_string($environment['memory_limit'] ?? null) && !is_int($environment['memory_limit'] ?? null)) {
            $errors[] = 'environment.memory_limit must be a string or integer.';
        }

        foreach (['opcache', 'jit', 'xdebug'] as $field) {
            if (!is_bool($environment[$field] ?? null) && !is_string($environment[$field] ?? null)) {
                $errors[] = sprintf('environment.%s must be boolean or string.', $field);
            }
        }

        return $errors;
    }

    private function errorRateError(string $path, mixed $attempted, mixed $failed, ?float $errorRate): ?string
    {
        if ($errorRate === null) {
            return null;
        }

        if ($errorRate > 1) {
            return $path . '.error_rate must be between 0 and 1.';
        }

        if (!is_int($attempted) || !is_int($failed)) {
            return null;
        }

        $expectedErrorRate = $attempted === 0 ? 0.0 : $failed / $attempted;

        return abs($errorRate - $expectedErrorRate) > 0.0000001
            ? $path . '.error_rate must equal failed_operations divided by attempted_operations.'
            : null;
    }

    private function isDateTime(mixed $value): bool
    {
        if (
            !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function isNonNegativeNumber(mixed $value): bool
    {
        $number = $this->number($value);

        return $number !== null && $number >= 0;
    }

    private function isOptionalNonNegativeNumber(mixed $value): bool
    {
        return $value === null || $this->isNonNegativeNumber($value);
    }

    /**
     * @return list<string>
     */
    private function latencyErrors(string $path, mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [$path . ' must be an object.'];
        }

        $latency = ArrayShape::stringKeyed($value);
        $errors = $this->requiredErrors($path, $latency, self::LATENCY_FIELDS);

        foreach (self::LATENCY_FIELDS as $field) {
            if (!$this->isOptionalNonNegativeNumber($latency[$field] ?? null)) {
                $errors[] = sprintf('%s.%s must be null or a non-negative finite number.', $path, $field);
            }
        }

        $p50 = $this->number($latency['p50'] ?? null);
        $p95 = $this->number($latency['p95'] ?? null);
        $p99 = $this->number($latency['p99'] ?? null);

        if ($p50 !== null && $p95 !== null && $p99 !== null && ($p50 > $p95 || $p95 > $p99)) {
            $errors[] = $path . ' must satisfy p50 <= p95 <= p99.';
        }

        return $errors;
    }

    private function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function operationConsistencyErrors(string $path, array $result): array
    {
        $errors = [];
        $attempted = $result['attempted_operations'] ?? null;
        $successful = $result['successful_operations'] ?? null;
        $failed = $result['failed_operations'] ?? null;
        $timeouts = $result['timeouts'] ?? null;
        $errorRate = $this->number($result['error_rate'] ?? null);

        $errorRateError = $this->errorRateError($path, $attempted, $failed, $errorRate);

        if ($errorRateError !== null) {
            $errors[] = $errorRateError;
        }

        if (is_int($attempted) && is_int($successful) && is_int($failed) && $attempted !== $successful + $failed) {
            $errors[] = $path . ' attempted operations must equal successful plus failed operations.';
        }

        if (is_int($failed) && is_int($timeouts) && $timeouts > $failed) {
            $errors[] = $path . '.timeouts cannot exceed failed_operations.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function operationErrors(string $path, array $result): array
    {
        $errors = [];

        foreach (['attempted_operations', 'successful_operations', 'failed_operations', 'timeouts'] as $field) {
            if (!is_int($result[$field] ?? null) || $result[$field] < 0) {
                $errors[] = sprintf('%s.%s must be a non-negative integer.', $path, $field);
            }
        }

        foreach (['successful_rpm', 'error_rate'] as $field) {
            if (!$this->isNonNegativeNumber($result[$field] ?? null)) {
                $errors[] = sprintf('%s.%s must be a non-negative finite number.', $path, $field);
            }
        }

        return [...$errors, ...$this->operationConsistencyErrors($path, $result)];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $fields
     * @return list<string>
     */
    private function requiredErrors(string $path, array $data, array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[] = sprintf('%s.%s is required.', $path, $field);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function resourceErrors(string $path, array $result): array
    {
        $errors = [];

        foreach (self::RESOURCE_FIELDS as $resource => $fields) {
            $errors = [...$errors, ...$this->resourceGroupErrors($path . '.' . $resource, $result[$resource] ?? null, $fields)];
        }

        return $errors;
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function resourceGroupErrors(string $path, mixed $value, array $fields): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [$path . ' must be an object.'];
        }

        $resource = ArrayShape::stringKeyed($value);
        $errors = $this->requiredErrors($path, $resource, $fields);

        foreach ($fields as $field) {
            if (!$this->isOptionalNonNegativeNumber($resource[$field] ?? null)) {
                $errors[] = sprintf('%s.%s must be null or a non-negative finite number.', $path, $field);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function resultErrors(string $path, mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [$path . ' must be an object.'];
        }

        $result = ArrayShape::stringKeyed($value);

        return [
            ...$this->requiredErrors(
                $path,
                $result,
                ['attempted_operations', 'successful_operations', 'failed_operations', 'timeouts', 'successful_rpm', 'error_rate', 'latency_ms', 'cpu', 'memory', 'stability'],
            ),
            ...$this->operationErrors($path, $result),
            ...$this->latencyErrors($path . '.latency_ms', $result['latency_ms'] ?? null),
            ...$this->resourceErrors($path, $result),
            ...$this->stabilityErrors($path . '.stability', $result['stability'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function stabilityErrors(string $path, mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [$path . ' must be an object.'];
        }

        $stability = ArrayShape::stringKeyed($value);
        $errors = $this->requiredErrors($path, $stability, ['status', 'spread_percent']);

        if (!in_array($stability['status'] ?? null, ['stable', 'unstable', 'unverified'], true)) {
            $errors[] = $path . '.status is invalid.';
        }

        if (!$this->isNonNegativeNumber($stability['spread_percent'] ?? null)) {
            $errors[] = $path . '.spread_percent must be a non-negative finite number.';
        }

        return $errors;
    }

    /**
     * @param array<string, true> $seen
     * @return list<string>
     */
    private function workloadErrors(string $path, mixed $value, array &$seen): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [$path . ' must be an object.'];
        }

        $workload = ArrayShape::stringKeyed($value);
        $errors = $this->requiredErrors(
            $path,
            $workload,
            ['name', 'type', 'metadata', 'repetitions', 'warmup_operations', 'duration_seconds', 'concurrency', 'result'],
        );

        return [
            ...$errors,
            ...$this->workloadIdentityErrors($path, $workload, $seen),
            ...$this->workloadExecutionErrors($path, $workload),
            ...$this->resultErrors($path . '.result', $workload['result'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $workload
     * @return list<string>
     */
    private function workloadExecutionErrors(string $path, array $workload): array
    {
        $errors = [];

        if (!in_array($workload['type'] ?? null, ['component', 'http', 'persistent-worker', 'queue-worker', 'custom'], true)) {
            $errors[] = $path . '.type is invalid.';
        }

        $metadata = $workload['metadata'] ?? null;

        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            $errors[] = $path . '.metadata must be an object.';
        }

        foreach (['repetitions' => 1, 'warmup_operations' => 0, 'concurrency' => 1] as $field => $minimum) {
            $value = $workload[$field] ?? null;

            if (!is_int($value) || $value < $minimum) {
                $errors[] = sprintf('%s.%s must be a %s integer.', $path, $field, $minimum === 0 ? 'non-negative' : 'positive');
            }
        }

        if (!$this->isNonNegativeNumber($workload['duration_seconds'] ?? null)) {
            $errors[] = $path . '.duration_seconds must be a non-negative finite number.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $workload
     * @param array<string, true> $seen
     * @return list<string>
     */
    private function workloadIdentityErrors(string $path, array $workload, array &$seen): array
    {
        $name = $workload['name'] ?? null;

        if (!is_string($name) || $name === '') {
            return [$path . '.name must be a non-empty string.'];
        }

        if (isset($seen[$name])) {
            return [sprintf('Workload name "%s" is duplicated.', $name)];
        }

        $seen[$name] = true;

        return [];
    }

    /**
     * @return list<string>
     */
    private function workloadsErrors(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return ['workloads must be a non-empty list.'];
        }

        $errors = [];
        $seen = [];

        foreach ($value as $index => $workload) {
            $errors = [
                ...$errors,
                ...$this->workloadErrors(sprintf('workloads.%d', $index), $workload, $seen),
            ];
        }

        return $errors;
    }
}
