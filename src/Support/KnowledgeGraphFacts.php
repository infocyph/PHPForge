<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Accumulates graph integrity facts without conflating them with clustering.
 *
 * @phpstan-import-type KnowledgeNode from KnowledgeBase
 * @phpstan-import-type KnowledgeEdge from KnowledgeBase
 * @phpstan-import-type GraphFacts from KnowledgeGraphTopology
 * @phpstan-import-type GraphHealth from KnowledgeGraphAnalyzer
 */
final class KnowledgeGraphFacts
{
    /** @var array<string, array<string, true>> */
    private array $adjacency;

    /** @var array<string, int> */
    private array $certainties = [];

    private int $dangling = 0;

    /** @var array<string, int> */
    private array $degrees;

    /** @var list<KnowledgeEdge> */
    private array $edges = [];

    /** @var array<string, true> */
    private array $pairs = [];

    /** @var array<string, int> */
    private array $resolutions = [];

    private int $selfLoops = 0;

    /** @param array<string, KnowledgeNode> $nodes */
    public function __construct(private readonly array $nodes)
    {
        $this->degrees = array_fill_keys(array_keys($nodes), 0);
        $this->adjacency = array_fill_keys(array_keys($nodes), []);
    }

    /** @param array<string, mixed> $edge
     * @phpstan-param KnowledgeEdge $edge
     */
    public function add(array $edge): void
    {
        $source = $edge['source'];
        $target = $edge['target'];

        if (!isset($this->nodes[$source], $this->nodes[$target])) {
            $this->dangling++;

            return;
        }

        $this->edges[] = $edge;
        $this->certainties[$edge['certainty']] = ($this->certainties[$edge['certainty']] ?? 0) + 1;
        $this->resolutions[$edge['resolution']] = ($this->resolutions[$edge['resolution']] ?? 0) + 1;
        $this->degrees[$source]++;
        $this->degrees[$target]++;

        if ($source === $target) {
            $this->selfLoops++;

            return;
        }

        $this->adjacency[$source][$target] = true;
        $this->adjacency[$target][$source] = true;
        $this->pairs[$this->pair($source, $target)] = true;
    }

    /** @return array<string, mixed>
     * @phpstan-return GraphFacts
     */
    public function result(): array
    {
        ksort($this->certainties);
        ksort($this->resolutions);

        return [
            'degrees' => $this->degrees,
            'adjacency' => $this->adjacency,
            'edges' => $this->edges,
            'health' => $this->health(),
            'certainties' => $this->certainties,
            'resolutions' => $this->resolutions,
        ];
    }

    private function connectedComponents(): int
    {
        $seen = [];
        $components = 0;

        foreach (array_keys($this->adjacency) as $start) {
            if (isset($seen[$start])) {
                continue;
            }

            $components++;
            $pending = [$start];
            $seen[$start] = true;

            while ($pending !== []) {
                $node = array_pop($pending);

                foreach (array_keys($this->adjacency[$node]) as $neighbor) {
                    if (!isset($seen[$neighbor])) {
                        $seen[$neighbor] = true;
                        $pending[] = $neighbor;
                    }
                }
            }
        }

        return $components;
    }

    /** @return array<string, int|float>
     * @phpstan-return GraphHealth
     */
    private function health(): array
    {
        $nodeCount = count($this->nodes);
        $possibleEdges = $nodeCount > 1 ? ($nodeCount * ($nodeCount - 1)) / 2 : 0;

        return [
            'connected_components' => $this->connectedComponents(),
            'isolated_nodes' => count(array_filter($this->degrees, static fn(int $degree): bool => $degree === 0)),
            'self_loops' => $this->selfLoops,
            'dangling_edges' => $this->dangling,
            'density' => $possibleEdges > 0 ? round(count($this->pairs) / $possibleEdges, 8) : 0.0,
        ];
    }

    private function pair(string $source, string $target): string
    {
        $pair = [$source, $target];
        sort($pair);

        return implode("\0", $pair);
    }
}
