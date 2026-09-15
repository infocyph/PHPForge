<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Expands ranked graph seeds in relevance order under explicit query budgets.
 *
 * @phpstan-import-type KnowledgeNode from KnowledgeBase
 * @phpstan-import-type KnowledgeEdge from KnowledgeBase
 * @phpstan-import-type KnowledgeMatch from KnowledgeQuery
 * @phpstan-import-type KnowledgePath from KnowledgeQuery
 * @phpstan-type KnowledgeLink array{node:string,edge:KnowledgeEdge}
 * @phpstan-type TraversalItem array{node:string,seed:string,depth:int}
 * @phpstan-type TraversalState array{node_ids:array<string,true>,paths:array<string,KnowledgePath>,edges:list<KnowledgeEdge>,edge_keys:array<string,true>,frontier:list<TraversalItem>,offset:int,estimated_tokens:int,reasons:array<string,true>}
 * @phpstan-type TraversalResult array{nodes:list<KnowledgeNode>,edges:list<KnowledgeEdge>,paths:list<KnowledgePath>,node_ids:array<string,true>,estimated_tokens:int,reasons:array<string,true>}
 */
final class KnowledgeGraphTraversal
{
    public const int MAX_CONTEXT_EDGES = 50_000;

    public const int MAX_CONTEXT_NODES = 10_000;

    public const int MAX_TOKEN_BUDGET = 100_000;

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param list<KnowledgeEdge> $allEdges
     * @param list<KnowledgeMatch> $matches
     * @param array<string, int> $nodeScores
     * @param array<string, int> $communities
     * @param list<string> $relations
     * @param array<string, true> $reasons
     * @return TraversalResult
     */
    public function traverse(
        array $nodes,
        array $allEdges,
        array $matches,
        array $nodeScores,
        array $communities,
        array $relations,
        int $depth,
        int $budget,
        int $estimatedTokens,
        array $reasons,
        string $mode,
        string $direction,
    ): array {
        $budget = $budget === 0 ? self::MAX_TOKEN_BUDGET : $budget;
        $adjacency = $this->adjacency($allEdges, $relations, $direction);
        $state = $this->initialState($matches, $estimatedTokens, $reasons);
        $state = $this->expand($state, $nodes, $adjacency, $nodeScores, $communities, $depth, $budget, $mode);
        $state = $this->connectInternalEdges($state, $allEdges, $relations, $budget);

        return [
            'nodes' => array_map(static fn(string $id): array => $nodes[$id], array_keys($state['node_ids'])),
            'edges' => $state['edges'],
            'paths' => array_values($state['paths']),
            'node_ids' => $state['node_ids'],
            'estimated_tokens' => $state['estimated_tokens'],
            'reasons' => $state['reasons'],
        ];
    }

    /**
     * @param list<KnowledgeEdge> $edges
     * @param list<string> $relations
     * @return array<string, list<KnowledgeLink>>
     */
    private function adjacency(array $edges, array $relations, string $direction): array
    {
        $allowed = array_fill_keys($relations, true);
        $adjacency = [];

        foreach ($edges as $edge) {
            if ($allowed !== [] && !isset($allowed[$edge['relation']])) {
                continue;
            }

            if ($direction !== 'incoming') {
                $adjacency[$edge['source']][] = ['node' => $edge['target'], 'edge' => $edge];
            }

            if ($direction !== 'outgoing' && $edge['source'] !== $edge['target']) {
                $adjacency[$edge['target']][] = ['node' => $edge['source'], 'edge' => $edge];
            }
        }

        return $adjacency;
    }

    /**
     * @param KnowledgeLink $left
     * @param KnowledgeLink $right
     * @param array<string, int> $nodeScores
     * @param array<string, int> $communities
     */
    private function compareLinks(array $left, array $right, array $nodeScores, array $communities, string $current): int
    {
        $leftScore = $this->linkScore($left, $nodeScores, $communities, $current);
        $rightScore = $this->linkScore($right, $nodeScores, $communities, $current);

        return $rightScore <=> $leftScore
            ?: $left['edge']['relation'] <=> $right['edge']['relation']
            ?: $left['node'] <=> $right['node'];
    }

    /**
     * @param TraversalState $state
     * @param list<KnowledgeEdge> $allEdges
     * @param list<string> $relations
     * @return TraversalState
     */
    private function connectInternalEdges(array $state, array $allEdges, array $relations, int $budget): array
    {
        foreach ($allEdges as $edge) {
            $key = $this->edgeKey($edge);

            if (isset($state['edge_keys'][$key]) || !isset($state['node_ids'][$edge['source']], $state['node_ids'][$edge['target']])) {
                continue;
            }

            if ($relations !== [] && !in_array($edge['relation'], $relations, true)) {
                continue;
            }

            if (count($state['edges']) >= self::MAX_CONTEXT_EDGES) {
                $state['reasons']['context_edges'] = true;

                break;
            }

            $state = $this->withEdge($state, $edge, $budget);
        }

        return $state;
    }

    /** @param array<string, mixed> $edge
     * @phpstan-param KnowledgeEdge $edge
     */
    private function edgeKey(array $edge): string
    {
        return implode("\0", [$edge['source'], $edge['target'], $edge['relation'], $edge['file'], (string) $edge['line']]);
    }

    /**
     * @param TraversalState $state
     * @param array<string, KnowledgeNode> $nodes
     * @param array<string, list<KnowledgeLink>> $adjacency
     * @param array<string, int> $nodeScores
     * @param array<string, int> $communities
     * @return TraversalState
     */
    private function expand(
        array $state,
        array $nodes,
        array $adjacency,
        array $nodeScores,
        array $communities,
        int $depth,
        int $budget,
        string $mode,
    ): array {
        while (($current = $this->next($state, $mode)) !== null) {
            $state = $current['state'];

            if ($current['item']['depth'] < $depth) {
                $state = $this->expandItem(
                    $state,
                    $current['item'],
                    $nodes,
                    $adjacency[$current['item']['node']] ?? [],
                    $nodeScores,
                    $communities,
                    $budget,
                    $mode,
                );
            }
        }

        return $state;
    }

    /**
     * @param TraversalState $state
     * @param TraversalItem $item
     * @param array<string, KnowledgeNode> $nodes
     * @param list<KnowledgeLink> $links
     * @param array<string, int> $nodeScores
     * @param array<string, int> $communities
     * @return TraversalState
     */
    private function expandItem(
        array $state,
        array $item,
        array $nodes,
        array $links,
        array $nodeScores,
        array $communities,
        int $budget,
        string $mode,
    ): array {
        usort($links, fn(array $left, array $right): int => $this->compareLinks($left, $right, $nodeScores, $communities, $item['node']));
        $links = $mode === 'dfs' ? array_reverse($links) : $links;

        foreach ($links as $link) {
            $neighbor = $link['node'];

            if (isset($state['node_ids'][$neighbor]) || !isset($nodes[$neighbor])) {
                continue;
            }

            if (count($state['node_ids']) >= self::MAX_CONTEXT_NODES) {
                $state['reasons']['context_nodes'] = true;

                break;
            }

            $state = $this->withNeighbor($state, $item, $neighbor, $nodes[$neighbor], $link['edge'], $budget);
        }

        return $state;
    }

    /**
     * @param list<KnowledgeMatch> $matches
     * @param array<string, true> $reasons
     * @return TraversalState
     */
    private function initialState(array $matches, int $estimatedTokens, array $reasons): array
    {
        $nodeIds = [];
        $paths = [];
        $frontier = [];

        foreach ($matches as $match) {
            $id = $match['node']['id'];
            $nodeIds[$id] = true;
            $paths[$id] = ['node' => $id, 'seed' => $id, 'depth' => 0, 'via' => null, 'relation' => null];
            $frontier[] = ['node' => $id, 'seed' => $id, 'depth' => 0];
        }

        return [
            'node_ids' => $nodeIds,
            'paths' => $paths,
            'edges' => [],
            'edge_keys' => [],
            'frontier' => $frontier,
            'offset' => 0,
            'estimated_tokens' => $estimatedTokens,
            'reasons' => $reasons,
        ];
    }

    /** @param KnowledgeLink $link
     * @param array<string, int> $nodeScores
     * @param array<string, int> $communities
     */
    private function linkScore(array $link, array $nodeScores, array $communities, string $current): int
    {
        $edge = $link['edge'];
        $certainty = $edge['certainty'] === 'extracted' ? 8 : 0;
        $resolution = $edge['resolution'] === 'exact' ? 5 : 0;
        $crossCommunity = isset($communities[$current], $communities[$link['node']])
            && $communities[$current] !== $communities[$link['node']] ? 12 : 0;

        return ($nodeScores[$link['node']] ?? 0)
            + $this->relationWeight($edge['relation']) * 10
            + $certainty
            + $resolution
            + $crossCommunity;
    }

    /**
     * @param TraversalState $state
     * @return array{state:TraversalState,item:TraversalItem}|null
     */
    private function next(array $state, string $mode): ?array
    {
        if ($mode === 'dfs') {
            $item = array_pop($state['frontier']);

            return is_array($item) ? ['state' => $state, 'item' => $item] : null;
        }

        $item = $state['frontier'][$state['offset']] ?? null;

        if (!is_array($item)) {
            return null;
        }

        $state['offset']++;

        return ['state' => $state, 'item' => $item];
    }

    private function relationWeight(string $relation): int
    {
        return match ($relation) {
            'extends', 'implements', 'uses_trait' => 5,
            'calls', 'instantiates' => 3,
            'references', 'imports' => 2,
            default => 1,
        };
    }

    private function tokens(mixed $value): int
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return max(1, (int) ceil(strlen($json) / 4));
    }

    /**
     * @param TraversalState $state
     * @param array<string, mixed> $edge
     * @phpstan-param KnowledgeEdge $edge
     * @return TraversalState
     */
    private function withEdge(array $state, array $edge, int $budget): array
    {
        $cost = $this->tokens($edge);

        if ($state['estimated_tokens'] + $cost > $budget) {
            $state['reasons']['token_budget'] = true;

            return $state;
        }

        $state['edge_keys'][$this->edgeKey($edge)] = true;
        $state['edges'][] = $edge;
        $state['estimated_tokens'] += $cost;

        return $state;
    }

    /**
     * @param TraversalState $state
     * @param TraversalItem $item
     * @param array<string, mixed> $node
     * @param array<string, mixed> $edge
     * @phpstan-param KnowledgeNode $node
     * @phpstan-param KnowledgeEdge $edge
     * @return TraversalState
     */
    private function withNeighbor(array $state, array $item, string $neighbor, array $node, array $edge, int $budget): array
    {
        $cost = $this->tokens($node) + $this->tokens($edge);

        if ($state['estimated_tokens'] + $cost > $budget) {
            $state['reasons']['token_budget'] = true;

            return $state;
        }

        $state['node_ids'][$neighbor] = true;
        $state['edge_keys'][$this->edgeKey($edge)] = true;
        $state['edges'][] = $edge;
        $state['estimated_tokens'] += $cost;
        $state['paths'][$neighbor] = [
            'node' => $neighbor,
            'seed' => $item['seed'],
            'depth' => $item['depth'] + 1,
            'via' => $item['node'],
            'relation' => $edge['relation'],
        ];
        $state['frontier'][] = ['node' => $neighbor, 'seed' => $item['seed'], 'depth' => $item['depth'] + 1];

        return $state;
    }
}
