<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Calculates graph integrity and deterministic file-level communities.
 *
 * @phpstan-import-type KnowledgeNode from KnowledgeBase
 * @phpstan-import-type KnowledgeEdge from KnowledgeBase
 * @phpstan-import-type GraphHealth from KnowledgeGraphAnalyzer
 * @phpstan-type GraphFacts array{degrees:array<string,int>,adjacency:array<string,array<string,true>>,edges:list<KnowledgeEdge>,health:GraphHealth,certainties:array<string,int>,resolutions:array<string,int>}
 */
final class KnowledgeGraphTopology
{
    private const int MAX_COMMUNITY_ITERATIONS = 30;

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param list<KnowledgeEdge> $edges
     * @return GraphFacts
     */
    public function facts(array $nodes, array $edges): array
    {
        $facts = new KnowledgeGraphFacts($nodes);

        foreach ($edges as $edge) {
            $facts->add($edge);
        }

        return $facts->result();
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param list<KnowledgeEdge> $edges
     * @return array<string, int>
     */
    public function fileCommunities(array $nodes, array $edges): array
    {
        $files = $this->projectFiles($nodes);
        $weights = $this->fileWeights($nodes, $edges);
        $labels = array_combine($files, $files);
        $degrees = [];

        foreach ($files as $file) {
            $degrees[$file] = array_sum($weights[$file] ?? []);
        }

        return $this->normalizeLabels($this->optimizeLabels($files, $weights, $labels, $degrees));
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param array<string, int> $fileCommunities
     * @return array<string, int>
     */
    public function nodeCommunities(array $nodes, array $fileCommunities): array
    {
        $communities = [];

        foreach ($nodes as $id => $node) {
            if ($node['file'] !== '' && isset($fileCommunities[$node['file']])) {
                $communities[$id] = $fileCommunities[$node['file']];
            }
        }

        ksort($communities);

        return $communities;
    }

    public function relationWeight(string $relation): int
    {
        return match ($relation) {
            'extends', 'implements', 'uses_trait' => 5,
            'calls', 'instantiates' => 3,
            'references', 'imports' => 2,
            default => 1,
        };
    }

    /**
     * @param array<string, int> $neighborWeights
     * @param array<string, int> $totals
     */
    private function bestLabel(string $current, array $neighborWeights, array $totals, int $degree, int $twiceWeight): string
    {
        $candidates = array_values(array_unique([...array_keys($neighborWeights), $current]));
        sort($candidates);
        $best = $current;
        $bestGain = $this->gain($current, $neighborWeights, $totals, $degree, $twiceWeight);

        foreach ($candidates as $candidate) {
            $gain = $this->gain($candidate, $neighborWeights, $totals, $degree, $twiceWeight);

            if ($gain > $bestGain + 1.0e-9 || (abs($gain - $bestGain) <= 1.0e-9 && $candidate < $best)) {
                $best = $candidate;
                $bestGain = $gain;
            }
        }

        return $best;
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param list<KnowledgeEdge> $edges
     * @return array<string, array<string, int>>
     */
    private function fileWeights(array $nodes, array $edges): array
    {
        $weights = [];

        foreach ($edges as $edge) {
            $source = $nodes[$edge['source']] ?? null;
            $target = $nodes[$edge['target']] ?? null;

            if (!is_array($source) || !is_array($target) || !$source['defined'] || !$target['defined']) {
                continue;
            }

            if ($source['file'] === '' || $target['file'] === '' || $source['file'] === $target['file']) {
                continue;
            }

            $weight = $this->relationWeight($edge['relation']);
            $weights[$source['file']][$target['file']] = ($weights[$source['file']][$target['file']] ?? 0) + $weight;
            $weights[$target['file']][$source['file']] = ($weights[$target['file']][$source['file']] ?? 0) + $weight;
        }

        return $weights;
    }

    /** @param array<string, int> $neighborWeights
     * @param array<string, int> $totals
     */
    private function gain(string $label, array $neighborWeights, array $totals, int $degree, int $twiceWeight): float
    {
        return ($neighborWeights[$label] ?? 0) - ($degree * ($totals[$label] ?? 0) / $twiceWeight);
    }

    /**
     * @param array<string, array<string, int>> $weights
     * @param array<string, string> $labels
     * @param array<string, int> $degrees
     * @param array<string, int> $totals
     */
    private function moveFile(string $file, array $weights, array &$labels, array $degrees, array &$totals, int $twiceWeight): bool
    {
        $current = $labels[$file];
        $degree = $degrees[$file];
        $totals[$current] -= $degree;
        $neighborWeights = [];

        foreach ($weights[$file] ?? [] as $neighbor => $weight) {
            $label = $labels[$neighbor];
            $neighborWeights[$label] = ($neighborWeights[$label] ?? 0) + $weight;
        }

        $best = $this->bestLabel($current, $neighborWeights, $totals, $degree, $twiceWeight);
        $labels[$file] = $best;
        $totals[$best] = ($totals[$best] ?? 0) + $degree;

        return $best !== $current;
    }

    /**
     * @param array<string, string> $labels
     * @return array<string, int>
     */
    private function normalizeLabels(array $labels): array
    {
        $groups = [];

        foreach ($labels as $file => $label) {
            $groups[$label][] = $file;
        }

        foreach ($groups as &$group) {
            sort($group);
        }

        unset($group);
        uasort($groups, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $result = [];

        foreach (array_values($groups) as $community => $group) {
            foreach ($group as $file) {
                $result[$file] = $community;
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * @param list<string> $files
     * @param array<string, array<string, int>> $weights
     * @param array<string, string> $labels
     * @param array<string, int> $degrees
     * @return array<string, string>
     */
    private function optimizeLabels(array $files, array $weights, array $labels, array $degrees): array
    {
        $twiceWeight = array_sum($degrees);

        if ($twiceWeight === 0) {
            return $labels;
        }

        $totals = $degrees;

        for ($iteration = 0; $iteration < self::MAX_COMMUNITY_ITERATIONS; $iteration++) {
            $moved = false;

            foreach ($files as $file) {
                $moved = $this->moveFile($file, $weights, $labels, $degrees, $totals, $twiceWeight) || $moved;
            }

            if (!$moved) {
                break;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @return list<string>
     */
    private function projectFiles(array $nodes): array
    {
        $files = [];

        foreach ($nodes as $node) {
            if ($node['file'] !== '' && $node['defined']) {
                $files[$node['file']] = true;
            }
        }

        $result = array_keys($files);
        sort($result);

        return $result;
    }
}
