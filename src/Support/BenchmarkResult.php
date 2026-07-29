<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class BenchmarkResult
{
    /**
     * @return array{
     *     status: 'failed'|'passed'|'skipped',
     *     messages: list<string>,
     *     comparisons: list<array{workload:string,baseline_rpm:float,candidate_rpm:float,regression_percent:float}>
     * }
     */
    public function compare(
        string $baselineFile,
        string $candidateFile,
        float $maximumRegressionPercent,
        bool $stableEnvironment,
    ): array {
        $baseline = $this->load($baselineFile);
        $candidate = $this->load($candidateFile);
        $messages = [
            ...array_map(static fn(string $error): string => 'Baseline: ' . $error, $baseline['errors']),
            ...array_map(static fn(string $error): string => 'Candidate: ' . $error, $candidate['errors']),
        ];

        if ($messages !== []) {
            return ['status' => 'failed', 'messages' => $messages, 'comparisons' => []];
        }

        if (!$stableEnvironment) {
            return [
                'status' => 'skipped',
                'messages' => ['Regression budget skipped because a stable benchmark environment was not asserted.'],
                'comparisons' => [],
            ];
        }

        if (!$this->sameStableEnvironment($baseline['data'], $candidate['data'])) {
            return [
                'status' => 'failed',
                'messages' => ['Regression budget requires both documents to declare the same stable environment fingerprint and runtime metadata.'],
                'comparisons' => [],
            ];
        }

        return $this->compareWorkloads(
            $this->workloadsByName($baseline['data']),
            $this->workloadsByName($candidate['data']),
            $maximumRegressionPercent,
        );
    }

    /**
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function load(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return ['data' => [], 'errors' => [sprintf('Benchmark result is not readable: %s', $file)]];
        }

        $contents = file_get_contents($file);

        if (!is_string($contents) || $contents === '') {
            return ['data' => [], 'errors' => [sprintf('Benchmark result is empty or unreadable: %s', $file)]];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            return ['data' => [], 'errors' => ['Benchmark result must be a JSON object.']];
        }

        $data = ArrayShape::stringKeyed($decoded);

        return ['data' => $data, 'errors' => (new BenchmarkResultValidator())->errors($data)];
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     * @return array{
     *     messages: list<string>,
     *     comparison: array{workload:string,baseline_rpm:float,candidate_rpm:float,regression_percent:float}|null
     * }
     */
    private function compareMetrics(string $name, array $baseline, array $candidate, float $maximumRegressionPercent): array
    {
        $baselineRpm = $this->number($baseline['successful_rpm'] ?? null);
        $candidateRpm = $this->number($candidate['successful_rpm'] ?? null);

        if ($baselineRpm === null || $baselineRpm <= 0 || $candidateRpm === null) {
            return ['messages' => [sprintf('Workload "%s" contains invalid successful RPM values.', $name)], 'comparison' => null];
        }

        $regression = (($baselineRpm - $candidateRpm) / $baselineRpm) * 100;
        $messages = $this->metricRegressionErrors($name, $regression, $maximumRegressionPercent, $baseline, $candidate);

        return [
            'messages' => $messages,
            'comparison' => [
                'workload' => $name,
                'baseline_rpm' => $baselineRpm,
                'candidate_rpm' => $candidateRpm,
                'regression_percent' => round($regression, 5),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed>|null $candidate
     * @return array{
     *     messages: list<string>,
     *     comparison: array{workload:string,baseline_rpm:float,candidate_rpm:float,regression_percent:float}|null
     * }
     */
    private function compareWorkload(string $name, array $baseline, ?array $candidate, float $maximumRegressionPercent): array
    {
        if ($candidate === null) {
            return ['messages' => [sprintf('Candidate result is missing baseline workload "%s".', $name)], 'comparison' => null];
        }

        if (!$this->sameWorkload($baseline, $candidate)) {
            return ['messages' => [sprintf('Workload "%s" has different execution settings or metadata.', $name)], 'comparison' => null];
        }

        $baselineMetrics = ArrayShape::stringKeyed($baseline['result'] ?? null);
        $candidateMetrics = ArrayShape::stringKeyed($candidate['result'] ?? null);

        if (!$this->isStable($baselineMetrics) || !$this->isStable($candidateMetrics)) {
            return ['messages' => [sprintf('Workload "%s" cannot be gated because both samples must be stable.', $name)], 'comparison' => null];
        }

        return $this->compareMetrics($name, $baselineMetrics, $candidateMetrics, $maximumRegressionPercent);
    }

    /**
     * @param array<string, array<string, mixed>> $baseline
     * @param array<string, array<string, mixed>> $candidate
     * @return array{
     *     status: 'failed'|'passed',
     *     messages: list<string>,
     *     comparisons: list<array{workload:string,baseline_rpm:float,candidate_rpm:float,regression_percent:float}>
     * }
     */
    private function compareWorkloads(array $baseline, array $candidate, float $maximumRegressionPercent): array
    {
        $messages = [];
        $comparisons = [];

        foreach ($baseline as $name => $baselineWorkload) {
            $comparison = $this->compareWorkload(
                $name,
                $baselineWorkload,
                $candidate[$name] ?? null,
                $maximumRegressionPercent,
            );
            $messages = [...$messages, ...$comparison['messages']];

            if ($comparison['comparison'] !== null) {
                $comparisons[] = $comparison['comparison'];
            }
        }

        return [
            'status' => $messages === [] ? 'passed' : 'failed',
            'messages' => $messages,
            'comparisons' => $comparisons,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function isStable(array $metrics): bool
    {
        $stability = ArrayShape::stringKeyed($metrics['stability'] ?? null);

        return ($stability['status'] ?? null) === 'stable';
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     * @return list<string>
     */
    private function metricRegressionErrors(
        string $name,
        float $regression,
        float $maximumRegressionPercent,
        array $baseline,
        array $candidate,
    ): array {
        $messages = [];

        if ($regression > $maximumRegressionPercent) {
            $messages[] = sprintf(
                'Workload "%s" successful RPM regressed by %.2f%% (budget %.2f%%).',
                $name,
                $regression,
                $maximumRegressionPercent,
            );
        }

        $baselineErrorRate = $this->number($baseline['error_rate'] ?? null);
        $candidateErrorRate = $this->number($candidate['error_rate'] ?? null);

        if ($baselineErrorRate !== null && $candidateErrorRate !== null && $candidateErrorRate > $baselineErrorRate) {
            $messages[] = sprintf(
                'Workload "%s" error rate increased from %.5f to %.5f.',
                $name,
                $baselineErrorRate,
                $candidateErrorRate,
            );
        }

        return $messages;
    }

    private function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     */
    private function sameStableEnvironment(array $baseline, array $candidate): bool
    {
        $baselineEnvironment = ArrayShape::stringKeyed($baseline['environment'] ?? null);
        $candidateEnvironment = ArrayShape::stringKeyed($candidate['environment'] ?? null);

        if (
            ($baselineEnvironment['stable'] ?? null) !== true
            || ($candidateEnvironment['stable'] ?? null) !== true
            || ($baselineEnvironment['fingerprint'] ?? null) !== ($candidateEnvironment['fingerprint'] ?? null)
        ) {
            return false;
        }

        unset($baselineEnvironment['release'], $candidateEnvironment['release']);

        return $baselineEnvironment === $candidateEnvironment;
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     */
    private function sameWorkload(array $baseline, array $candidate): bool
    {
        unset($baseline['result'], $candidate['result']);

        return $baseline === $candidate;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    private function workloadsByName(array $data): array
    {
        $indexed = [];
        $workloads = $data['workloads'] ?? null;

        if (!is_array($workloads)) {
            return [];
        }

        foreach ($workloads as $value) {
            $workload = ArrayShape::stringKeyed($value);
            $name = $workload['name'] ?? null;

            if (is_string($name)) {
                $indexed[$name] = $workload;
            }
        }

        return $indexed;
    }
}
