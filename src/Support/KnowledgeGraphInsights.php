<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Ranks project hubs and explains extracted cross-community relationships.
 *
 * @phpstan-import-type KnowledgeNode from KnowledgeBase
 * @phpstan-import-type KnowledgeEdge from KnowledgeBase
 * @phpstan-import-type GraphNodeSummary from KnowledgeGraphAnalyzer
 * @phpstan-import-type GraphCommunity from KnowledgeGraphAnalyzer
 * @phpstan-import-type GraphBridge from KnowledgeGraphAnalyzer
 * @phpstan-type CommunityBucket array{nodes:list<string>,files:array<string,true>,internal:int,external:int}
 */
final readonly class KnowledgeGraphInsights
{
    public function __construct(private KnowledgeGraphTopology $topology) {}

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param list<KnowledgeEdge> $edges
     * @param array<string, int> $degrees
     * @param array<string, int> $nodeCommunities
     * @param list<GraphCommunity> $communities
     * @return list<GraphBridge>
     */
    public function bridges(array $nodes, array $edges, array $degrees, array $nodeCommunities, array $communities): array
    {
        $labels = array_column($communities, 'label', 'id');
        $result = [];

        foreach ($edges as $edge) {
            $sourceCommunity = $nodeCommunities[$edge['source']] ?? null;
            $targetCommunity = $nodeCommunities[$edge['target']] ?? null;

            if ($sourceCommunity === null || $targetCommunity === null || $sourceCommunity === $targetCommunity) {
                continue;
            }

            if (!$this->bridgeCandidate($nodes[$edge['source']], $nodes[$edge['target']], $edge['relation'])) {
                continue;
            }

            $key = implode("\0", [$edge['source'], $edge['target'], $edge['relation']]);
            $result[$key] ??= $this->bridge($nodes, $edge, $degrees, $sourceCommunity, $targetCommunity, $labels);
        }

        $result = array_values($result);
        usort($result, static fn(array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['source'] <=> $right['source'] ?: $left['target'] <=> $right['target']);

        return array_slice($result, 0, 20);
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param list<KnowledgeEdge> $edges
     * @param array<string, int> $degrees
     * @param array<string, int> $nodeCommunities
     * @return list<GraphCommunity>
     */
    public function communities(array $nodes, array $edges, array $degrees, array $nodeCommunities): array
    {
        $buckets = $this->communityBuckets($nodes, $nodeCommunities);
        $buckets = $this->countCommunityEdges($buckets, $edges, $nodeCommunities);
        $result = [];

        foreach ($buckets as $id => $bucket) {
            $result[] = $this->community((int) $id, $bucket, $nodes, $degrees);
        }

        usort($result, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);

        return $result;
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param array<string, array<string, true>> $adjacency
     * @param array<string, int> $degrees
     * @param array<string, int> $nodeCommunities
     * @return list<GraphNodeSummary>
     */
    public function godNodes(array $nodes, array $adjacency, array $degrees, array $nodeCommunities): array
    {
        $result = [];

        foreach ($nodeCommunities as $nodeId => $community) {
            $node = $nodes[$nodeId];

            if (!$this->godCandidate($node)) {
                continue;
            }

            $result[] = $this->nodeSummary($node, $adjacency[$nodeId] ?? [], $degrees[$nodeId], $nodeCommunities, $community, count($nodes));
        }

        usort($result, static fn(array $left, array $right): int => $right['degree'] <=> $left['degree'] ?: $right['communities_reached'] <=> $left['communities_reached'] ?: $left['id'] <=> $right['id']);

        return array_slice($result, 0, 15);
    }

    /**
     * @param list<GraphNodeSummary> $gods
     * @param list<GraphBridge> $bridges
     * @param list<GraphCommunity> $communities
     * @return list<array{type:string,question:string,why:string}>
     */
    public function suggestedQuestions(array $gods, array $bridges, array $communities): array
    {
        $questions = [];
        $labels = array_column($communities, 'label', 'id');

        foreach (array_slice($gods, 0, 3) as $god) {
            $questions[] = [
                'type' => 'god_node',
                'question' => sprintf('Why is `%s` a central project abstraction?', $god['name']),
                'why' => sprintf('Degree %d across %d neighboring communities.', $god['degree'], $god['communities_reached']),
            ];
        }

        foreach (array_slice($bridges, 0, 3) as $bridge) {
            $questions[] = [
                'type' => 'community_bridge',
                'question' => sprintf('How does `%s` connect `%s` and `%s`?', $bridge['source_name'], $labels[$bridge['source_community']], $labels[$bridge['target_community']]),
                'why' => $bridge['why'],
            ];
        }

        return $questions;
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param array<string, mixed> $edge
     * @phpstan-param KnowledgeEdge $edge
     * @param array<string, int> $degrees
     * @param array<int, string> $labels
     * @return array<string, mixed>
     * @phpstan-return GraphBridge
     */
    private function bridge(array $nodes, array $edge, array $degrees, int $sourceCommunity, int $targetCommunity, array $labels): array
    {
        $source = $nodes[$edge['source']];
        $target = $nodes[$edge['target']];

        return [
            'source' => $edge['source'],
            'target' => $edge['target'],
            'source_name' => $source['name'],
            'target_name' => $target['name'],
            'relation' => $edge['relation'],
            'source_community' => $sourceCommunity,
            'target_community' => $targetCommunity,
            'score' => $degrees[$edge['source']] + $degrees[$edge['target']] + $this->topology->relationWeight($edge['relation']),
            'certainty' => $edge['certainty'],
            'file' => $edge['file'],
            'line' => $edge['line'],
            'why' => sprintf('The extracted %s relationship bridges %s and %s.', $edge['relation'], $labels[$sourceCommunity], $labels[$targetCommunity]),
        ];
    }

    /** @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @phpstan-param KnowledgeNode $source
     * @phpstan-param KnowledgeNode $target
     */
    private function bridgeCandidate(array $source, array $target, string $relation): bool
    {
        return $relation !== 'contains' && $this->godCandidate($source) && $this->godCandidate($target);
    }

    /**
     * @param CommunityBucket $bucket
     * @param array<string, KnowledgeNode> $nodes
     * @param array<string, int> $degrees
     * @return array<string, mixed>
     * @phpstan-return GraphCommunity
     */
    private function community(int $id, array $bucket, array $nodes, array $degrees): array
    {
        $memberIds = $bucket['nodes'];
        usort($memberIds, static fn(string $left, string $right): int => $degrees[$right] <=> $degrees[$left] ?: $left <=> $right);
        $representatives = array_slice(array_map(static fn(string $nodeId): string => $nodes[$nodeId]['name'], $memberIds), 0, 5);
        $files = array_keys($bucket['files']);
        sort($files);
        $edges = $bucket['internal'] + $bucket['external'];

        return [
            'id' => $id,
            'label' => $this->communityLabel($memberIds, $nodes, $files),
            'node_count' => count($memberIds),
            'file_count' => count($files),
            'internal_edges' => $bucket['internal'],
            'external_edges' => $bucket['external'],
            'cohesion' => $edges > 0 ? round($bucket['internal'] / $edges, 6) : 0.0,
            'files' => $files,
            'representative_nodes' => $representatives,
        ];
    }

    /**
     * @param array<string, KnowledgeNode> $nodes
     * @param array<string, int> $nodeCommunities
     * @return array<int, CommunityBucket>
     */
    private function communityBuckets(array $nodes, array $nodeCommunities): array
    {
        $buckets = [];

        foreach ($nodeCommunities as $nodeId => $community) {
            $node = $nodes[$nodeId];
            $buckets[$community] ??= ['nodes' => [], 'files' => [], 'internal' => 0, 'external' => 0];
            $buckets[$community]['nodes'][] = $nodeId;
            $buckets[$community]['files'][$node['file']] = true;
        }

        return $buckets;
    }

    /**
     * @param list<string> $memberIds
     * @param array<string, KnowledgeNode> $nodes
     * @param list<string> $files
     */
    private function communityLabel(array $memberIds, array $nodes, array $files): string
    {
        foreach (['class', 'interface', 'trait', 'enum', 'function', 'method'] as $type) {
            foreach ($memberIds as $nodeId) {
                if ($nodes[$nodeId]['type'] === $type) {
                    return $nodes[$nodeId]['name'];
                }
            }
        }

        return $memberIds !== [] ? $nodes[$memberIds[0]]['name'] : $files[0];
    }

    /**
     * @param array<int, CommunityBucket> $buckets
     * @param list<KnowledgeEdge> $edges
     * @param array<string, int> $nodeCommunities
     * @return array<int, CommunityBucket>
     */
    private function countCommunityEdges(array $buckets, array $edges, array $nodeCommunities): array
    {
        foreach ($edges as $edge) {
            $source = $nodeCommunities[$edge['source']] ?? null;
            $target = $nodeCommunities[$edge['target']] ?? null;

            if ($source !== null && $source === $target) {
                $bucket = $buckets[$source];
                $bucket['internal']++;
                $buckets[$source] = $bucket;
            } elseif ($source !== null || $target !== null) {
                $buckets = $this->countExternalEdge($buckets, $source, $target);
            }
        }

        return $buckets;
    }

    /**
     * @param array<int, CommunityBucket> $buckets
     * @return array<int, CommunityBucket>
     */
    private function countExternalEdge(array $buckets, ?int $source, ?int $target): array
    {
        if ($source !== null) {
            $bucket = $buckets[$source];
            $bucket['external']++;
            $buckets[$source] = $bucket;
        }

        if ($target !== null) {
            $bucket = $buckets[$target];
            $bucket['external']++;
            $buckets[$target] = $bucket;
        }

        return $buckets;
    }

    /** @param array<string, mixed> $node
     * @phpstan-param KnowledgeNode $node
     */
    private function godCandidate(array $node): bool
    {
        return $node['defined'] && in_array($node['type'], ['class', 'interface', 'trait', 'enum', 'function', 'method'], true);
    }

    /**
     * @param array<string, mixed> $node
     * @phpstan-param KnowledgeNode $node
     * @param array<string, true> $neighbors
     * @param array<string, int> $nodeCommunities
     * @return array<string, mixed>
     * @phpstan-return GraphNodeSummary
     */
    private function nodeSummary(array $node, array $neighbors, int $degree, array $nodeCommunities, int $community, int $nodeCount): array
    {
        $reached = [];

        foreach (array_keys($neighbors) as $neighbor) {
            if (isset($nodeCommunities[$neighbor])) {
                $reached[$nodeCommunities[$neighbor]] = true;
            }
        }

        return [
            'id' => $node['id'],
            'type' => $node['type'],
            'name' => $node['name'],
            'file' => $node['file'],
            'degree' => $degree,
            'degree_centrality' => $nodeCount > 1 ? round($degree / ($nodeCount - 1), 8) : 0.0,
            'community' => $community,
            'communities_reached' => count($reached),
        ];
    }
}
