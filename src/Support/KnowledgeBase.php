<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Composer\InstalledVersions;
use Infocyph\PHPProbe\Graph\CodeGraphExtractor;

/**
 * Builds and persists PHPForge's deterministic project knowledge index.
 *
 * @phpstan-import-type FileRecord from KnowledgeFileCatalog
 * @phpstan-type KnowledgeNode array{id:string,type:string,name:string,file:string,line:int,end_line:int,defined:bool,attributes:array<string, mixed>}
 * @phpstan-type KnowledgeEdge array{source:string,target:string,relation:string,file:string,line:int,certainty:string,resolution:string}
 * @phpstan-type KnowledgeDocument array{schema:string,schema_version:int,root:string,fingerprint:string,source_graph:array{schema:string,schema_version:int},extractors:list<array{name:string,version:string,capability:string}>,files:array{structural:list<string>,content_indexed:list<string>,skipped:list<string>,unsupported:list<string>},nodes:list<KnowledgeNode>,edges:list<KnowledgeEdge>}
 */
final class KnowledgeBase
{
    public const string DEFAULT_PATH = 'phpforge/knowledge.json';

    private const string INDEXER_VERSION = '1';

    /**
     * @param list<string> $paths
     * @return array{document:KnowledgeDocument,reused:bool,path:string,artifacts:list<string>}
     */
    public function build(array $paths = [], ?string $output = null, bool $force = false): array
    {
        $root = Paths::projectRootPath();
        $target = $this->path($output);
        $records = new KnowledgeFileCatalog()->records($root, $paths);
        $fingerprint = $this->fingerprint($records);
        $existing = $this->existing($target);
        $publisher = new KnowledgeArtifacts();
        $publisher->assertWritable($target);

        if (!$force && is_array($existing) && $existing['fingerprint'] === $fingerprint) {
            $artifacts = $publisher->publish($existing, $records, $target);

            return ['document' => $existing, 'reused' => true, 'path' => $target, 'artifacts' => $artifacts];
        }

        $files = ['structural' => [], 'content_indexed' => [], 'skipped' => [], 'unsupported' => []];
        $phpFiles = [];

        foreach ($records as $record) {
            $bucket = match ($record['category']) {
                'structural' => 'structural',
                'inventory' => 'content_indexed',
                'skipped' => 'skipped',
                default => 'unsupported',
            };
            $files[$bucket][] = $record['path'];

            if ($bucket === 'structural') {
                $phpFiles[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $record['path']);
            }
        }

        $phpGraph = new CodeGraphExtractor()->extract($phpFiles, $root);
        /** @var list<KnowledgeNode> $nodes */
        $nodes = $phpGraph['nodes'];
        /** @var list<KnowledgeEdge> $edges */
        $edges = $phpGraph['edges'];
        $nodeIds = array_fill_keys(array_column($nodes, 'id'), true);

        foreach ($records as $record) {
            $id = 'file:' . $record['path'];

            if ($record['category'] === 'inventory' && !isset($nodeIds[$id])) {
                $nodes[] = $this->inventoryNode($record, $root);
                [$contentNodes, $contentEdges] = $this->contentGraph($record, $root);
                $nodes = [...$nodes, ...$contentNodes];
                $edges = [...$edges, ...$contentEdges];
            }
        }

        usort($nodes, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);
        $document = [
            'schema' => 'phpforge.knowledge-base',
            'schema_version' => 1,
            'root' => '.',
            'fingerprint' => $fingerprint,
            'source_graph' => ['schema' => $phpGraph['schema'], 'schema_version' => $phpGraph['schema_version']],
            'extractors' => [
                [
                    'name' => 'PHPProbe',
                    'version' => InstalledVersions::getPrettyVersion('infocyph/phpprobe') ?? '1.2',
                    'capability' => 'php-ast',
                ],
                ['name' => 'PHPForge', 'version' => '1', 'capability' => 'bounded-text-content'],
            ],
            'files' => $files,
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        $this->write($target, $document);
        $artifacts = $publisher->publish($document, $records, $target);

        return ['document' => $document, 'reused' => false, 'path' => $target, 'artifacts' => $artifacts];
    }

    public function path(?string $path = null): string
    {
        $path ??= self::DEFAULT_PATH;

        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return Paths::projectRootPath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /** @return KnowledgeDocument */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Knowledge base not found: %s. Run composer ic:kb:build first.', $path));
        }

        $contents = file_get_contents($path);

        try {
            $document = is_string($contents) ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Knowledge base is invalid JSON: %s', $path), previous: $exception);
        }

        if (!is_array($document) || ($document['schema'] ?? null) !== 'phpforge.knowledge-base' || ($document['schema_version'] ?? null) !== 1) {
            throw new \RuntimeException(sprintf('Knowledge base has an unsupported schema: %s', $path));
        }

        /** @var KnowledgeDocument $document */
        return $document;
    }

    /** @return list<array{offset:int,content:string}> */
    private function contentChunks(string $contents): array
    {
        $chunks = [];
        $offset = 0;
        $total = strlen($contents);

        while ($offset < $total) {
            $length = min(12_000, $total - $offset);
            $content = substr($contents, $offset, $length);

            while ($length > 0 && preg_match('//u', $content) !== 1) {
                $length--;
                $content = substr($contents, $offset, $length);
            }

            if ($length === 0) {
                throw new \RuntimeException('Could not split UTF-8 knowledge content safely.');
            }

            $chunks[] = ['offset' => $offset, 'content' => $content];
            $offset += $length;
        }

        return $chunks;
    }

    /** @param array<string, mixed> $record
     * @return array{list<KnowledgeNode>,list<KnowledgeEdge>}
     */
    private function contentGraph(array $record, string $root): array
    {
        /** @var FileRecord $record */
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $record['path']);
        $contents = file_get_contents($absolute);

        if (!is_string($contents) || $contents === '') {
            return [[], []];
        }

        $fileId = 'file:' . $record['path'];
        $nodes = [];
        $edges = [];
        $line = 1;

        foreach ($this->contentChunks($contents) as $chunk) {
            $content = $chunk['content'];
            $id = sprintf('content:%s:%d', $record['path'], $chunk['offset']);
            $newlines = substr_count($content, "\n");
            $endLine = max($line, $line + $newlines - (str_ends_with($content, "\n") ? 1 : 0));
            $nodes[] = [
                'id' => $id,
                'type' => 'content',
                'name' => sprintf('%s:%d-%d', $record['path'], $line, $endLine),
                'file' => $record['path'],
                'line' => $line,
                'end_line' => $endLine,
                'defined' => true,
                'attributes' => [
                    'content' => $content,
                    'content_hash' => hash('sha256', $content),
                    'language' => $record['language'],
                    'extraction' => 'content',
                ],
            ];
            $edges[] = [
                'source' => $fileId,
                'target' => $id,
                'relation' => 'contains',
                'file' => $record['path'],
                'line' => $line,
                'certainty' => 'extracted',
                'resolution' => 'exact',
            ];
            $line += $newlines;
        }

        return [$nodes, $edges];
    }

    /** @return KnowledgeDocument|null */
    private function existing(string $path): ?array
    {
        return is_file($path) ? $this->read($path) : null;
    }

    /** @param list<FileRecord> $records */
    private function fingerprint(array $records): string
    {
        $context = hash_init('sha256');
        $probeVersion = InstalledVersions::getPrettyVersion('infocyph/phpprobe') ?? 'unknown';
        $probeReference = InstalledVersions::getReference('infocyph/phpprobe') ?? 'unknown';
        hash_update($context, implode("\0", [self::INDEXER_VERSION, $probeVersion, $probeReference]) . "\0");

        foreach ($records as $record) {
            hash_update($context, implode("\0", [
                $record['path'], $record['category'], $record['language'], (string) $record['size'], $record['content_hash'],
            ]) . "\0");
        }

        return hash_final($context);
    }

    /** @param array<string, mixed> $record
     * @return KnowledgeNode
     */
    private function inventoryNode(array $record, string $root): array
    {
        /** @var FileRecord $record */
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $record['path']);
        $contents = file_get_contents($absolute);

        return [
            'id' => 'file:' . $record['path'],
            'type' => 'file',
            'name' => $record['path'],
            'file' => $record['path'],
            'line' => 1,
            'end_line' => is_string($contents) ? max(1, substr_count($contents, "\n") + 1) : 1,
            'defined' => true,
            'attributes' => [
                'content_hash' => $record['content_hash'],
                'language' => $record['language'],
                'extraction' => 'content-index',
                'size' => $record['size'],
            ],
        ];
    }

    /** @param KnowledgeDocument $document */
    private function write(string $path, array $document): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create knowledge output directory: %s', $directory));
        }

        $temporary = tempnam($directory, '.phpforge-knowledge-');

        if (!is_string($temporary)) {
            throw new \RuntimeException(sprintf('Could not create a temporary knowledge file in: %s', $directory));
        }

        try {
            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Could not publish knowledge base: %s', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
