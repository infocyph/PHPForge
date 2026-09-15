<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Coordinates deterministic architecture analysis of extracted project facts.
 *
 * @phpstan-import-type KnowledgeDocument from KnowledgeBase
 * @phpstan-type GraphNodeSummary array{id:string,type:string,name:string,file:string,degree:int,degree_centrality:float,community:int,communities_reached:int}
 * @phpstan-type GraphCommunity array{id:int,label:string,node_count:int,file_count:int,internal_edges:int,external_edges:int,cohesion:float,files:list<string>,representative_nodes:list<string>}
 * @phpstan-type GraphBridge array{source:string,target:string,source_name:string,target_name:string,relation:string,source_community:int,target_community:int,score:int,certainty:string,file:string,line:int,why:string}
 * @phpstan-type GraphHealth array{connected_components:int,isolated_nodes:int,self_loops:int,dangling_edges:int,density:float}
 * @phpstan-type GraphAnalysis array{node_communities:array<string,int>,communities:list<GraphCommunity>,god_nodes:list<GraphNodeSummary>,surprising_connections:list<GraphBridge>,suggested_questions:list<array{type:string,question:string,why:string}>,graph_health:GraphHealth,certainties:array<string,int>,resolutions:array<string,int>}
 */
final readonly class KnowledgeGraphAnalyzer
{
    /** @param array<string, mixed> $document
     * @phpstan-param KnowledgeDocument $document
     * @return array<string, mixed>
     * @phpstan-return GraphAnalysis
     */
    public function analyze(array $document): array
    {
        $nodes = [];

        foreach ($document['nodes'] as $node) {
            $nodes[$node['id']] = $node;
        }

        $topology = new KnowledgeGraphTopology();
        $facts = $topology->facts($nodes, $document['edges']);
        $nodeCommunities = $topology->nodeCommunities($nodes, $topology->fileCommunities($nodes, $document['edges']));
        $insights = new KnowledgeGraphInsights($topology);
        $communities = $insights->communities($nodes, $facts['edges'], $facts['degrees'], $nodeCommunities);
        $godNodes = $insights->godNodes($nodes, $facts['adjacency'], $facts['degrees'], $nodeCommunities);
        $bridges = $insights->bridges($nodes, $facts['edges'], $facts['degrees'], $nodeCommunities, $communities);

        return [
            'node_communities' => $nodeCommunities,
            'communities' => $communities,
            'god_nodes' => $godNodes,
            'surprising_connections' => $bridges,
            'suggested_questions' => $insights->suggestedQuestions($godNodes, $bridges, $communities),
            'graph_health' => $facts['health'],
            'certainties' => $facts['certainties'],
            'resolutions' => $facts['resolutions'],
        ];
    }
}
