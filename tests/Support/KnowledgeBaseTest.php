<?php

declare(strict_types=1);

use Infocyph\PHPForge\Support\KnowledgeBase;
use Infocyph\PHPForge\Support\KnowledgeCli;
use Infocyph\PHPForge\Support\KnowledgeExplainer;
use Infocyph\PHPForge\Support\KnowledgeQuery;
use Infocyph\PHPForge\Composer\CommandProvider;
use Symfony\Component\Process\Process;

function removeKnowledgeFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

function makeKnowledgeFixture(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-knowledge-' . uniqid('', true);
    mkdir($root . DIRECTORY_SEPARATOR . 'src', 0755, true);
    mkdir($root . DIRECTORY_SEPARATOR . 'assets', 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/knowledge"}');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'phpprobe.json', '{"preset":"standard"}');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'README.md', "# Example\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . '.env', "SECRET=do-not-index\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . '.env.production', "SECRET=do-not-index-either\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'assets/app.js', "export const ready = true;\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'assets/logo.png', "\x89PNG\r\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Example;

final class Service
{
    public function run(): void
    {
        helper();
    }
}

function helper(): void {}
PHP);
    (new Process(['git', 'init', '--quiet'], $root))->mustRun();
    (new Process(['git', 'add', '--', '.'], $root))->mustRun();

    return $root;
}

function writeTraversalKnowledgeFixture(string $root): string
{
    $document = new KnowledgeBase()->build()['document'];
    $node = static fn(string $id, string $name, string $file): array => [
        'id' => $id,
        'type' => 'class',
        'name' => $name,
        'file' => $file,
        'line' => 1,
        'end_line' => 20,
        'defined' => true,
        'attributes' => ['extraction' => 'php-ast'],
    ];
    $edge = static fn(string $source, string $target, string $relation, string $file): array => [
        'source' => $source,
        'target' => $target,
        'relation' => $relation,
        'file' => $file,
        'line' => 10,
        'certainty' => 'extracted',
        'resolution' => 'exact',
    ];
    $document['nodes'] = [
        $node('class-like:Example\\EntryPoint', 'Example\\EntryPoint', 'src/EntryPoint.php'),
        $node('class-like:Example\\Coordinator', 'Example\\Coordinator', 'src/Coordinator.php'),
        $node('class-like:Example\\Repository', 'Example\\Repository', 'src/Repository.php'),
        $node('class-like:Example\\Storage', 'Example\\Storage', 'src/Storage.php'),
    ];
    $document['edges'] = [
        $edge('class-like:Example\\EntryPoint', 'class-like:Example\\Coordinator', 'calls', 'src/EntryPoint.php'),
        $edge('class-like:Example\\Coordinator', 'class-like:Example\\Repository', 'instantiates', 'src/Coordinator.php'),
        $edge('class-like:Example\\Repository', 'class-like:Example\\Storage', 'references', 'src/Repository.php'),
    ];
    $path = $root . DIRECTORY_SEPARATOR . 'phpforge' . DIRECTORY_SEPARATOR . 'traversal.json';
    file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

it('builds and reuses a deterministic mixed-file knowledge base', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);

    try {
        $first = new KnowledgeBase()->build();
        $firstAnalysis = file_get_contents($root . '/phpforge/analysis.json');
        $second = new KnowledgeBase()->build();
        $document = $first['document'];
        $nodes = array_column($document['nodes'], null, 'id');
        $manifest = json_decode((string) file_get_contents($root . '/phpforge/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $analysis = json_decode((string) file_get_contents($root . '/phpforge/analysis.json'), true, 64, JSON_THROW_ON_ERROR);
        $report = file_get_contents($root . '/phpforge/GRAPH_REPORT.md');
        $html = file_get_contents($root . '/phpforge/graph.html');

        expect($first['reused'])->toBeFalse()
            ->and($second['reused'])->toBeTrue()
            ->and(array_map('basename', $first['artifacts']))->toBe([
                'knowledge.json', 'manifest.json', 'analysis.json', 'GRAPH_REPORT.md', 'graph.html',
            ])
            ->and($document['schema'])->toBe('phpforge.knowledge-base')
            ->and($document['schema_version'])->toBe(1)
            ->and($document['source_graph'])->toBe(['schema' => 'phpprobe.code-graph', 'schema_version' => 1])
            ->and($document['files']['structural'])->toContain('src/Service.php')
            ->and($document['files']['content_indexed'])->toContain('README.md', 'assets/app.js', 'composer.json')
            ->and($document['files']['skipped'])->toContain('.env', '.env.production', 'assets/logo.png')
            ->and($nodes)->toHaveKeys(['class-like:Example\\Service', 'method:Example\\Service::run', 'file:README.md', 'content:README.md:0'])
            ->and($nodes)->not->toHaveKey('file:.env')
            ->and($nodes['file:README.md']['attributes']['extraction'])->toBe('content-index')
            ->and($nodes['content:README.md:0']['attributes']['content'])->toBe("# Example\n")
            ->and($manifest['schema'])->toBe('phpforge.knowledge-manifest')
            ->and($manifest['fingerprint'])->toBe($document['fingerprint'])
            ->and($analysis['schema'])->toBe('phpforge.knowledge-analysis')
            ->and($analysis['schema_version'])->toBe(2)
            ->and($analysis['node_count'])->toBe(count($document['nodes']))
            ->and($analysis['central_nodes'])->not->toBe([])
            ->and($analysis['communities'])->not->toBe([])
            ->and($analysis['god_nodes'])->not->toBe([])
            ->and($analysis['graph_health']['dangling_edges'])->toBe(0)
            ->and($analysis['certainties']['extracted'])->toBeGreaterThan(0)
            ->and($report)->toContain('# PHPForge Knowledge Report', '## Community Hubs', '## God Nodes', '## Surprising Connections', '## Suggested Questions')
            ->and($html)->toContain('<meta name="generator" content="PHPForge knowledgebase">', 'PHPForge Knowledge Graph', 'All communities')
            ->and(file_get_contents($root . '/phpforge/analysis.json'))->toBe($firstAnalysis)
            ->and($first['document'])->toBe($second['document']);
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('refuses to overwrite an unrelated companion artifact', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);
    mkdir($root . DIRECTORY_SEPARATOR . 'phpforge');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'phpforge/manifest.json', '{"application":"mine"}');

    try {
        expect(fn(): array => new KnowledgeBase()->build())
            ->toThrow(RuntimeException::class, 'Refusing to overwrite non-PHPForge artifact')
            ->and(file_get_contents($root . DIRECTORY_SEPARATOR . 'phpforge/manifest.json'))->toBe('{"application":"mine"}')
            ->and(file_exists($root . DIRECTORY_SEPARATOR . 'phpforge/knowledge.json'))->toBeFalse();
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('refuses to overwrite an unrelated file even when force is requested', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'existing.json', '{"unrelated":true}');

    try {
        expect(fn(): array => new KnowledgeBase()->build(output: 'existing.json', force: true))
            ->toThrow(RuntimeException::class, 'unsupported schema')
            ->and(file_get_contents($root . DIRECTORY_SEPARATOR . 'existing.json'))->toBe('{"unrelated":true}');
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('invalidates the persisted knowledge base after source changes', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);

    try {
        $first = new KnowledgeBase()->build();
        file_put_contents($root . DIRECTORY_SEPARATOR . 'src/Service.php', "<?php\n\nfinal class Changed {}\n");
        $second = new KnowledgeBase()->build();

        expect($second['reused'])->toBeFalse()
            ->and($second['document']['fingerprint'])->not->toBe($first['document']['fingerprint']);
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('retrieves matching symbols and their related context', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);

    try {
        new KnowledgeBase()->build();
        $result = new KnowledgeQuery()->query('Example Service run', limit: 5);
        $javascript = new KnowledgeQuery()->query('export ready', limit: 5);
        $matchIds = array_map(static fn(array $match): string => $match['node']['id'], $result['matches']);
        $javascriptIds = array_map(static fn(array $match): string => $match['node']['id'], $javascript['matches']);

        expect($result['schema'])->toBe('phpforge.knowledge-query')
            ->and($matchIds)->toContain('class-like:Example\\Service', 'method:Example\\Service::run')
            ->and($javascriptIds)->toContain('content:assets/app.js:0')
            ->and($result['edges'])->not->toBe([])
            ->and(count($result['matches']))->toBeLessThanOrEqual(5);
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('retrieves depth-aware architectural paths with relation and direction filters', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);

    try {
        $graph = writeTraversalKnowledgeFixture($root);
        $depthTwo = new KnowledgeQuery()->query(
            'EntryPoint architecture',
            graph: $graph,
            limit: 1,
            depth: 2,
            budget: 0,
            direction: 'outgoing',
        );
        $depthThree = new KnowledgeQuery()->query(
            'EntryPoint',
            graph: $graph,
            limit: 1,
            depth: 3,
            budget: 0,
            direction: 'outgoing',
            context: 'architecture',
        );
        $incoming = new KnowledgeQuery()->query(
            'Repository',
            graph: $graph,
            limit: 1,
            depth: 2,
            budget: 0,
            direction: 'incoming',
            context: 'architecture',
        );
        $callsOnly = new KnowledgeQuery()->query(
            'EntryPoint',
            graph: $graph,
            limit: 1,
            depth: 3,
            budget: 0,
            direction: 'outgoing',
            relations: ['calls'],
        );
        $depthTwoIds = array_column($depthTwo['context_nodes'], 'id');
        $incomingIds = array_column($incoming['context_nodes'], 'id');

        expect($depthTwo['schema_version'])->toBe(2)
            ->and($depthTwo['retrieval']['context'])->toBe('architecture')
            ->and($depthTwo['retrieval']['mode'])->toBe('bfs')
            ->and($depthTwo['retrieval']['truncated'])->toBeFalse()
            ->and($depthTwoIds)->toContain(
                'class-like:Example\\EntryPoint',
                'class-like:Example\\Coordinator',
                'class-like:Example\\Repository',
            )->not->toContain('class-like:Example\\Storage')
            ->and(array_column($depthThree['context_nodes'], 'id'))->toContain('class-like:Example\\Storage')
            ->and($incomingIds)->toContain(
                'class-like:Example\\Repository',
                'class-like:Example\\Coordinator',
                'class-like:Example\\EntryPoint',
            )->not->toContain('class-like:Example\\Storage')
            ->and(array_column($callsOnly['context_nodes'], 'id'))->toBe([
                'class-like:Example\\EntryPoint',
                'class-like:Example\\Coordinator',
            ])
            ->and($callsOnly['retrieval']['relations'])->toBe(['calls'])
            ->and($depthThree['architecture']['communities'])->not->toBe([])
            ->and($depthThree['architecture']['god_nodes'])->not->toBe([]);
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('reports token-budget truncation and supports depth-first traversal', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);

    try {
        $graph = writeTraversalKnowledgeFixture($root);
        $result = new KnowledgeQuery()->query(
            'EntryPoint',
            graph: $graph,
            limit: 1,
            depth: 3,
            budget: 80,
            mode: 'dfs',
            direction: 'outgoing',
            context: 'architecture',
        );

        expect($result['retrieval']['mode'])->toBe('dfs')
            ->and($result['retrieval']['truncated'])->toBeTrue()
            ->and($result['retrieval']['truncation_reasons'])->toContain('token_budget')
            ->and($result['context_nodes'])->toHaveCount(1);
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('accepts depth-aware traversal controls through the knowledge CLI', function (): void {
    $original = getcwd();
    $root = makeKnowledgeFixture();
    chdir($root);
    $output = '';

    try {
        $graph = writeTraversalKnowledgeFixture($root);
        $cli = new KnowledgeCli(static function (string $message) use (&$output): void {
            $output .= $message;
        });
        $exitCode = $cli->query([
            'EntryPoint',
            '--graph=' . $graph,
            '--depth=3',
            '--budget=0',
            '--direction=outgoing',
            '--context=architecture',
            '--relation=calls,instantiates,references',
            '--dfs',
            '--json',
        ]);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($result['retrieval']['mode'])->toBe('dfs')
            ->and($result['retrieval']['depth'])->toBe(3)
            ->and($result['retrieval']['direction'])->toBe('outgoing')
            ->and($result['retrieval']['context'])->toBe('architecture')
            ->and($result['retrieval']['relations'])->toBe(['calls', 'instantiates', 'references'])
            ->and(array_column($result['context_nodes'], 'id'))->toContain('class-like:Example\\Storage');
    } finally {
        if (is_string($original)) {
            chdir($original);
        }

        removeKnowledgeFixture($root);
    }
});

it('publishes knowledge commands through the Composer plugin', function (): void {
    $names = array_map(
        static fn(Symfony\Component\Console\Command\Command $command): ?string => $command->getName(),
        new CommandProvider()->getCommands(),
    );

    expect($names)->toContain('ic:kb:build', 'ic:kb:query');
});

it('requires explicit Gemini credentials for remote explanations', function (): void {
    $original = getenv('GEMINI_API_KEY');
    putenv('GEMINI_API_KEY');

    try {
        expect(fn(): array => new KnowledgeExplainer()->explain([
            'matches' => [],
            'context_nodes' => [],
            'edges' => [],
        ], 'gemini'))->toThrow(RuntimeException::class, 'GEMINI_API_KEY is not set');
    } finally {
        putenv($original === false ? 'GEMINI_API_KEY' : 'GEMINI_API_KEY=' . $original);
    }
});

it('discovers the running Ollama model and labels model output as inferred', function (): void {
    $originalModel = getenv('OLLAMA_MODEL');
    putenv('OLLAMA_MODEL');
    $requests = [];
    $query = [
        'question' => 'What calls run?',
        'matches' => [[
            'score' => 100,
            'node' => ['id' => 'method:Example\\Service::run'],
        ]],
        'context_nodes' => [['id' => 'method:Example\\Service::run']],
        'edges' => [],
    ];
    $explainer = new KnowledgeExplainer(
        static function (string $url, array $headers, string $payload) use (&$requests): string {
            $requests[] = [
                'url' => $url,
                'headers' => $headers,
                'payload' => $payload,
            ];

            if (str_ends_with($url, '/api/ps')) {
                return '{"models":[{"model":"hf.co/example/codestral:latest"}]}';
            }

            return json_encode([
                'message' => [
                    'content' => json_encode([
                        'summary' => 'The retrieved method is the primary match.',
                        'evidence' => ['method:Example\\Service::run'],
                        'uncertainty' => '',
                    ], JSON_THROW_ON_ERROR),
                ],
            ], JSON_THROW_ON_ERROR);
        },
    );
    try {
        $result = $explainer->explain($query);
        $payload = json_decode($requests[1]['payload'], true, 64, JSON_THROW_ON_ERROR);

        expect($requests[0]['url'])->toBe('http://127.0.0.1:11434/api/ps')
            ->and($requests[1]['url'])->toBe('http://127.0.0.1:11434/api/chat')
            ->and($payload['model'])->toBe('hf.co/example/codestral:latest')
            ->and($result['certainty'])->toBe('inferred')
            ->and($result['provider'])->toBe('ollama')
            ->and($result['model'])->toBe('hf.co/example/codestral:latest')
            ->and($result['evidence'])->toBe(['method:Example\\Service::run']);
    } finally {
        putenv($originalModel === false ? 'OLLAMA_MODEL' : 'OLLAMA_MODEL=' . $originalModel);
    }
});

it('falls back to the first installed Ollama model when none is running', function (): void {
    $originalModel = getenv('OLLAMA_MODEL');
    putenv('OLLAMA_MODEL');
    $requests = [];
    $explainer = new KnowledgeExplainer(
        static function (string $url, array $headers, string $payload) use (&$requests): string {
            $requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];

            if (str_ends_with($url, '/api/ps')) {
                return '{"models":[]}';
            }

            if (str_ends_with($url, '/api/tags')) {
                return '{"models":[{"name":"llama3.2:latest"}]}';
            }

            return '{"message":{"content":"{\\"summary\\":\\"Found\\",\\"evidence\\":[],\\"uncertainty\\":\\"\\"}"}}';
        },
    );

    try {
        $result = $explainer->explain(['matches' => [], 'context_nodes' => [], 'edges' => []]);
        $urls = array_column($requests, 'url');

        expect($urls)->toBe([
            'http://127.0.0.1:11434/api/ps',
            'http://127.0.0.1:11434/api/tags',
            'http://127.0.0.1:11434/api/chat',
        ])->and($result['model'])->toBe('llama3.2:latest');
    } finally {
        putenv($originalModel === false ? 'OLLAMA_MODEL' : 'OLLAMA_MODEL=' . $originalModel);
    }
});

it('falls back to Gemini when no local Ollama model is available and its key is set', function (): void {
    $originalKey = getenv('GEMINI_API_KEY');
    $originalModel = getenv('OLLAMA_MODEL');
    putenv('GEMINI_API_KEY=test-api-key');
    putenv('OLLAMA_MODEL');
    $requests = [];
    $explainer = new KnowledgeExplainer(
        static function (string $url, array $headers, string $payload) use (&$requests): string {
            $requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];

            if (str_starts_with($url, 'http://127.0.0.1:11434/')) {
                throw new RuntimeException('Ollama is unavailable.');
            }

            return json_encode([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"summary":"Gemini fallback","evidence":[],"uncertainty":""}',
                        ]],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR);
        },
    );

    try {
        $result = $explainer->explain(['matches' => [], 'context_nodes' => [], 'edges' => []]);

        expect(array_column($requests, 'url'))->toBe([
            'http://127.0.0.1:11434/api/ps',
            'http://127.0.0.1:11434/api/tags',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent',
        ])->and($requests[2]['headers'])->toContain('x-goog-api-key: test-api-key')
            ->and($result['provider'])->toBe('gemini')
            ->and($result['model'])->toBe('gemini-flash-lite-latest')
            ->and($result['summary'])->toBe('Gemini fallback');
    } finally {
        putenv($originalKey === false ? 'GEMINI_API_KEY' : 'GEMINI_API_KEY=' . $originalKey);
        putenv($originalModel === false ? 'OLLAMA_MODEL' : 'OLLAMA_MODEL=' . $originalModel);
    }
});

it('keeps an explicitly selected Ollama provider local only', function (): void {
    $originalKey = getenv('GEMINI_API_KEY');
    $originalModel = getenv('OLLAMA_MODEL');
    putenv('GEMINI_API_KEY=test-api-key');
    putenv('OLLAMA_MODEL');
    $requests = [];
    $explainer = new KnowledgeExplainer(
        static function (string $url, array $headers, string $payload) use (&$requests): string {
            $requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];

            throw new RuntimeException('Ollama is unavailable.');
        },
    );

    try {
        expect(fn(): array => $explainer->explain(
            ['matches' => [], 'context_nodes' => [], 'edges' => []],
            'ollama',
        ))->toThrow(RuntimeException::class, 'No Ollama model is available')
            ->and(array_column($requests, 'url'))->toBe([
                'http://127.0.0.1:11434/api/ps',
                'http://127.0.0.1:11434/api/tags',
            ]);
    } finally {
        putenv($originalKey === false ? 'GEMINI_API_KEY' : 'GEMINI_API_KEY=' . $originalKey);
        putenv($originalModel === false ? 'OLLAMA_MODEL' : 'OLLAMA_MODEL=' . $originalModel);
    }
});

it('rejects AI evidence outside the retrieved graph slice', function (): void {
    $captured = [];
    $explainer = new KnowledgeExplainer(
        static function (string $url, array $headers, string $payload) use (&$captured): string {
            $captured = [
                'url' => $url,
                'headers' => $headers,
                'payload' => $payload,
            ];

            return json_encode([
                'message' => [
                    'content' => '{"summary":"Invented","evidence":["class-like:Invented"],"uncertainty":""}',
                ],
            ], JSON_THROW_ON_ERROR);
        },
    );

    expect(fn(): array => $explainer->explain([
        'matches' => [],
        'context_nodes' => [],
        'edges' => [],
    ], model: 'fixture-model'))->toThrow(RuntimeException::class, 'outside the retrieved graph slice')
        ->and($captured['url'])->toBe('http://127.0.0.1:11434/api/chat')
        ->and($captured['headers'])->toContain('Content-Type: application/json')
        ->and($captured['payload'])->not->toBeEmpty();
});
