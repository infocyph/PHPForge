<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Retrieves a deterministic, depth-aware graph context from persisted project knowledge.
 *
 * @phpstan-import-type KnowledgeNode from KnowledgeBase
 * @phpstan-import-type KnowledgeEdge from KnowledgeBase
 * @phpstan-import-type GraphNodeSummary from KnowledgeGraphAnalyzer
 * @phpstan-import-type GraphCommunity from KnowledgeGraphAnalyzer
 * @phpstan-import-type GraphBridge from KnowledgeGraphAnalyzer
 * @phpstan-import-type GraphAnalysis from KnowledgeGraphAnalyzer
 * @phpstan-type KnowledgeMatch array{score:int,node:KnowledgeNode}
 * @phpstan-type KnowledgePath array{node:string,seed:string,depth:int,via:string|null,relation:string|null}
 * @phpstan-type QueryArchitecture array{communities:list<GraphCommunity>,god_nodes:list<GraphNodeSummary>,bridges:list<GraphBridge>}
 * @phpstan-type QuerySafetyCaps array{seed_matches:int,depth:int,token_budget:int,context_nodes:int,context_edges:int}
 * @phpstan-type QueryRetrieval array{mode:string,depth:int,direction:string,context:string,relations:list<string>,token_budget:int,estimated_tokens:int,truncated:bool,truncation_reasons:list<string>,safety_caps:QuerySafetyCaps}
 * @phpstan-type QueryResult array{schema:string,schema_version:int,question:string,graph_fingerprint:string,matches:list<KnowledgeMatch>,context_nodes:list<KnowledgeNode>,edges:list<KnowledgeEdge>,paths:list<KnowledgePath>,architecture:QueryArchitecture,retrieval:QueryRetrieval}
 */
final class KnowledgeQuery
{
    /** @var list<string> */
    private const array ARCHITECTURE_RELATIONS = [
        'calls', 'extends', 'implements', 'imports', 'instantiates', 'references', 'uses_trait',
    ];

    /** @var list<string> */
    private const array CALL_RELATIONS = ['calls', 'instantiates', 'references'];

    private const int DEFAULT_DEPTH = 2;

    private const int DEFAULT_TOKEN_BUDGET = 4_000;

    /** @var list<string> */
    private const array INHERITANCE_RELATIONS = ['extends', 'implements', 'uses_trait'];

    private const int MAX_DEPTH = 12;

    private const int MAX_SEED_MATCHES = 2_000;

    /** @var array<string, true> */
    private const array STOP_TERMS = [
        'a' => true,
        'an' => true,
        'and' => true,
        'are' => true,
        'as' => true,
        'at' => true,
        'be' => true,
        'by' => true,
        'does' => true,
        'for' => true,
        'from' => true,
        'how' => true,
        'in' => true,
        'is' => true,
        'it' => true,
        'of' => true,
        'on' => true,
        'or' => true,
        'the' => true,
        'to' => true,
        'what' => true,
        'where' => true,
        'which' => true,
        'with' => true,
    ];

    /**
     * Zero for limit or budget requests the applicable safety maximum.
     *
     * @param list<string> $relations
     * @return QueryResult
     */
    public function query(
        string $question,
        ?string $graph = null,
        int $limit = 20,
        int $depth = self::DEFAULT_DEPTH,
        int $budget = self::DEFAULT_TOKEN_BUDGET,
        string $mode = 'bfs',
        string $direction = 'both',
        string $context = 'auto',
        array $relations = [],
    ): array {
        $question = trim($question);
        $this->validate($question, $limit, $depth, $budget, $mode, $direction, $context);

        $base = new KnowledgeBase();
        $document = $base->read($base->path($graph));
        $nodes = $this->nodesById($document['nodes']);
        $analysis = new KnowledgeGraphAnalyzer()->analyze($document);
        $resolvedContext = $this->resolveContext($question, $context);
        $activeRelations = $this->activeRelations($document['edges'], $resolvedContext, $relations);
        [$matches, $nodeScores, $seedCapReached] = $this->matches(
            $document['nodes'],
            $question,
            $limit,
            $resolvedContext,
            $analysis,
        );
        [$matches, $estimatedTokens, $budgetReached] = $this->budgetMatches($matches, $budget);
        $reasons = [];

        if ($seedCapReached) {
            $reasons['seed_matches'] = true;
        }

        if ($budgetReached) {
            $reasons['token_budget'] = true;
        }

        $traversal = new KnowledgeGraphTraversal()->traverse(
            $nodes,
            $document['edges'],
            $matches,
            $nodeScores,
            $analysis['node_communities'],
            $activeRelations,
            $depth,
            $budget,
            $estimatedTokens,
            $reasons,
            $mode,
            $direction,
        );

        return [
            'schema' => 'phpforge.knowledge-query',
            'schema_version' => 2,
            'question' => $question,
            'graph_fingerprint' => $document['fingerprint'],
            'matches' => $matches,
            'context_nodes' => $traversal['nodes'],
            'edges' => $traversal['edges'],
            'paths' => $traversal['paths'],
            'architecture' => $this->architecture($analysis, $traversal['node_ids']),
            'retrieval' => [
                'mode' => $mode,
                'depth' => $depth,
                'direction' => $direction,
                'context' => $resolvedContext,
                'relations' => $activeRelations,
                'token_budget' => $budget,
                'estimated_tokens' => $traversal['estimated_tokens'],
                'truncated' => $traversal['reasons'] !== [],
                'truncation_reasons' => array_keys($traversal['reasons']),
                'safety_caps' => [
                    'seed_matches' => self::MAX_SEED_MATCHES,
                    'depth' => self::MAX_DEPTH,
                    'token_budget' => KnowledgeGraphTraversal::MAX_TOKEN_BUDGET,
                    'context_nodes' => KnowledgeGraphTraversal::MAX_CONTEXT_NODES,
                    'context_edges' => KnowledgeGraphTraversal::MAX_CONTEXT_EDGES,
                ],
            ],
        ];
    }

    /**
     * @param list<KnowledgeEdge> $edges
     * @param list<string> $requested
     * @return list<string>
     */
    private function activeRelations(array $edges, string $context, array $requested): array
    {
        $available = [];

        foreach ($edges as $edge) {
            $available[$edge['relation']] = true;
        }

        $requested = $this->normalizeRelations($requested);

        foreach ($requested as $relation) {
            if (!isset($available[$relation])) {
                $names = array_keys($available);
                sort($names);

                throw new \InvalidArgumentException(sprintf(
                    'Unknown knowledge relation: %s. Available relations: %s.',
                    $relation,
                    implode(', ', $names),
                ));
            }
        }

        if ($requested !== []) {
            return $requested;
        }

        $relations = match ($context) {
            'architecture' => self::ARCHITECTURE_RELATIONS,
            'calls' => self::CALL_RELATIONS,
            'inheritance' => self::INHERITANCE_RELATIONS,
            'content' => ['contains'],
            default => [],
        };

        return array_values(array_filter($relations, static fn(string $relation): bool => isset($available[$relation])));
    }

    /**
     * @phpstan-param GraphAnalysis $analysis
     * @param array<string, true> $nodeIds
     * @return QueryArchitecture
     */
    private function architecture(array $analysis, array $nodeIds): array
    {
        $communityIds = [];

        foreach (array_keys($nodeIds) as $nodeId) {
            $community = $analysis['node_communities'][$nodeId] ?? null;

            if (is_int($community)) {
                $communityIds[$community] = true;
            }
        }

        return [
            'communities' => array_values(array_filter(
                $analysis['communities'],
                static fn(array $community): bool => isset($communityIds[$community['id']]),
            )),
            'god_nodes' => array_values(array_filter(
                $analysis['god_nodes'],
                static fn(array $node): bool => isset($nodeIds[$node['id']]),
            )),
            'bridges' => array_values(array_filter(
                $analysis['surprising_connections'],
                static fn(array $bridge): bool => isset($nodeIds[$bridge['source']], $nodeIds[$bridge['target']]),
            )),
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function attributeText(array $attributes): array
    {
        $text = [];

        foreach ($attributes as $value) {
            if (is_scalar($value)) {
                $text[] = (string) $value;
            }
        }

        return $text;
    }

    /**
     * @param list<KnowledgeMatch> $matches
     * @return array{list<KnowledgeMatch>,int,bool}
     */
    private function budgetMatches(array $matches, int $budget): array
    {
        $budget = $budget === 0 ? KnowledgeGraphTraversal::MAX_TOKEN_BUDGET : $budget;
        $selected = [];
        $tokens = 0;
        $truncated = false;

        foreach ($matches as $match) {
            $cost = $this->tokens($match['node']);

            if ($selected !== [] && $tokens + $cost > $budget) {
                $truncated = true;

                continue;
            }

            $selected[] = $match;
            $tokens += $cost;
        }

        return [$selected, $tokens, $truncated];
    }

    /** @param array<string, mixed> $node
     * @phpstan-param KnowledgeNode $node
     */
    private function contextScore(array $node, string $context): int
    {
        $structural = in_array($node['type'], ['class', 'enum', 'function', 'interface', 'method', 'trait'], true);

        return match ($context) {
            'architecture', 'calls', 'inheritance' => $structural ? 30 : 0,
            'content' => in_array($node['type'], ['content', 'file'], true) ? 30 : 0,
            default => 0,
        };
    }

    /** @param array<string, mixed> $hub
     * @phpstan-param GraphNodeSummary $hub
     */
    private function hubScore(array $hub): int
    {
        return 40 + min(30, $hub['degree'] + $hub['communities_reached'] * 3);
    }

    /**
     * @param list<KnowledgeNode> $nodes
     * @phpstan-param GraphAnalysis $analysis
     * @return array{list<KnowledgeMatch>,array<string,int>,bool}
     */
    private function matches(array $nodes, string $question, int $limit, string $context, array $analysis): array
    {
        $terms = $this->terms($question);
        $ranked = [];
        $scores = [];

        foreach ($nodes as $node) {
            $score = $this->score($node, $question, $terms) + $this->contextScore($node, $context);
            $scores[$node['id']] = $score;

            if ($score > 0) {
                $ranked[] = ['score' => $score, 'node' => $node];
            }
        }

        $requested = $limit === 0 ? self::MAX_SEED_MATCHES : $limit;

        if ($context === 'architecture') {
            [$ranked, $scores] = $this->withArchitectureSeeds($ranked, $nodes, $scores, $analysis);
        }

        usort($ranked, static fn(array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['node']['id'] <=> $right['node']['id']);
        $seedCapReached = $limit === 0 && count($ranked) > self::MAX_SEED_MATCHES;

        $ranked = array_slice($ranked, 0, $requested);

        return [$ranked, $scores, $seedCapReached];
    }

    /** @param list<KnowledgeNode> $nodes
     * @return array<string, KnowledgeNode>
     */
    private function nodesById(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $result[$node['id']] = $node;
        }

        return $result;
    }

    /** @param list<string> $relations
     * @return list<string>
     */
    private function normalizeRelations(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $value) {
            foreach (preg_split('/\s*,\s*/', strtolower(trim($value))) ?: [] as $relation) {
                if ($relation !== '') {
                    $normalized[$relation] = true;
                }
            }
        }

        $result = array_keys($normalized);
        sort($result);

        return $result;
    }

    private function resolveContext(string $question, string $context): string
    {
        if ($context !== 'auto') {
            return $context;
        }

        $terms = array_fill_keys($this->terms($question), true);

        foreach ([
            'inheritance' => ['extends', 'implements', 'inheritance', 'interface', 'trait'],
            'calls' => ['call', 'called', 'caller', 'calls', 'flow', 'invokes', 'pipeline', 'trace'],
            'architecture' => ['architecture', 'architectural', 'community', 'coupling', 'dependencies', 'dependency', 'design', 'layer', 'module', 'structure', 'topology'],
            'content' => ['content', 'documentation', 'readme', 'text'],
        ] as $candidate => $needles) {
            foreach ($needles as $needle) {
                if (isset($terms[$needle])) {
                    return $candidate;
                }
            }
        }

        return 'all';
    }

    /** @param array<string, mixed> $node
     * @phpstan-param KnowledgeNode $node
     * @param list<string> $terms
     */
    private function score(array $node, string $question, array $terms): int
    {
        $name = strtolower($node['name']);
        $needle = strtolower($question);
        $haystack = strtolower(implode(' ', [$node['id'], $node['type'], $node['name'], $node['file'], ...$this->attributeText($node['attributes'])]));
        $score = $name === $needle ? 200 : (str_contains($name, $needle) ? 80 : 0);

        foreach ($terms as $term) {
            $score += $name === $term ? 50 : (str_contains($name, $term) ? 20 : 0);
            $score += str_contains($haystack, $term) ? 5 : 0;
        }

        return $score;
    }

    /** @return list<string> */
    private function terms(string $question): array
    {
        $normalized = preg_replace('/[^a-z0-9_\\\\:-]+/i', ' ', strtolower($question));
        $parts = preg_split('/\s+/', trim((string) $normalized)) ?: [];
        $terms = [];

        foreach ($parts as $term) {
            if (strlen($term) >= 2 && !isset(self::STOP_TERMS[$term])) {
                $terms[$term] = true;
            }
        }

        return array_keys($terms);
    }

    private function tokens(mixed $value): int
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return max(1, (int) ceil(strlen($json) / 4));
    }

    private function validate(
        string $question,
        int $limit,
        int $depth,
        int $budget,
        string $mode,
        string $direction,
        string $context,
    ): void {
        if ($question === '') {
            throw new \InvalidArgumentException('Knowledge query cannot be empty.');
        }

        if ($limit < 0 || $limit > self::MAX_SEED_MATCHES) {
            throw new \InvalidArgumentException(sprintf('Knowledge query limit must be between 0 and %d; zero requests the safety maximum.', self::MAX_SEED_MATCHES));
        }

        if ($depth < 0 || $depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(sprintf('Knowledge query depth must be between 0 and %d.', self::MAX_DEPTH));
        }

        if ($budget < 0 || $budget > KnowledgeGraphTraversal::MAX_TOKEN_BUDGET) {
            throw new \InvalidArgumentException(sprintf('Knowledge query token budget must be between 0 and %d; zero requests the safety maximum.', KnowledgeGraphTraversal::MAX_TOKEN_BUDGET));
        }

        if (!in_array($mode, ['bfs', 'dfs'], true)) {
            throw new \InvalidArgumentException('Knowledge query mode must be bfs or dfs.');
        }

        if (!in_array($direction, ['both', 'incoming', 'outgoing'], true)) {
            throw new \InvalidArgumentException('Knowledge query direction must be both, incoming or outgoing.');
        }

        if (!in_array($context, ['auto', 'all', 'architecture', 'calls', 'content', 'inheritance'], true)) {
            throw new \InvalidArgumentException('Knowledge query context must be auto, all, architecture, calls, content or inheritance.');
        }
    }

    /**
     * @param list<KnowledgeMatch> $ranked
     * @param list<KnowledgeNode> $nodes
     * @param array<string, int> $scores
     * @phpstan-param GraphAnalysis $analysis
     * @return array{list<KnowledgeMatch>,array<string,int>}
     */
    private function withArchitectureSeeds(array $ranked, array $nodes, array $scores, array $analysis): array
    {
        $byId = $this->nodesById($nodes);
        $hubs = array_column($analysis['god_nodes'], null, 'id');
        $selected = [];
        $result = [];

        foreach ($ranked as $match) {
            $id = $match['node']['id'];
            $hub = $hubs[$id] ?? null;

            if (is_array($hub)) {
                $match = ['score' => max($match['score'], $this->hubScore($hub)), 'node' => $match['node']];
                $scores[$id] = $match['score'];
            }

            $result[] = $match;
            $selected[$id] = true;
        }

        foreach ($analysis['god_nodes'] as $hub) {
            if (isset($selected[$hub['id']]) || !isset($byId[$hub['id']])) {
                continue;
            }

            $score = $this->hubScore($hub);
            $scores[$hub['id']] = max($scores[$hub['id']] ?? 0, $score);
            $result[] = ['score' => $score, 'node' => $byId[$hub['id']]];
        }

        return [$result, $scores];
    }
}
