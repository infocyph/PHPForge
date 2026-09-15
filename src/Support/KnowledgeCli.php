<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * Owns the command-line contract for building and querying project knowledge.
 *
 * @phpstan-import-type QueryResult from KnowledgeQuery
 * @phpstan-import-type Explanation from KnowledgeExplainer
 * @phpstan-type KnowledgeQueryOptions array{question:string,graph:?string,limit:int,depth:int,budget:int,mode:string,direction:string,context:string,relations:list<string>,json:bool,explain:bool,provider:string,model:?string}
 */
final readonly class KnowledgeCli
{
    /** @param null|\Closure(string):void $stdout
     * @param null|\Closure(string):void $stderr
     */
    public function __construct(
        private ?\Closure $stdout = null,
        private ?\Closure $stderr = null,
    ) {}

    /** @param list<string> $args */
    public function build(array $args): int
    {
        try {
            $options = $this->buildOptions($args);
            $result = new KnowledgeBase()->build($options['paths'], $options['output'], $options['force']);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->error('Knowledge build failed: ' . $exception->getMessage() . PHP_EOL);

            return 2;
        }

        $document = $result['document'];
        $files = $document['files'];
        $label = $result['reused'] ? 'reused' : 'built';
        $this->output(sprintf('Knowledge base %s: %s%s', $label, $this->displayPath($result['path']), PHP_EOL));
        $this->output(sprintf(
            'Files: structural=%d content-indexed=%d skipped=%d unsupported=%d%s',
            count($files['structural']),
            count($files['content_indexed']),
            count($files['skipped']),
            count($files['unsupported']),
            PHP_EOL,
        ));
        $this->output(sprintf(
            'Graph: nodes=%d edges=%d fingerprint=%s%s',
            count($document['nodes']),
            count($document['edges']),
            $document['fingerprint'],
            PHP_EOL,
        ));
        $this->output('Artifacts: ' . implode(', ', array_map($this->displayPath(...), $result['artifacts'])) . PHP_EOL);

        return 0;
    }

    /** @param list<string> $args */
    public function query(array $args): int
    {
        try {
            $options = $this->queryOptions($args);
            $result = new KnowledgeQuery()->query(
                question: $options['question'],
                graph: $options['graph'],
                limit: $options['limit'],
                depth: $options['depth'],
                budget: $options['budget'],
                mode: $options['mode'],
                direction: $options['direction'],
                context: $options['context'],
                relations: $options['relations'],
            );
            [$explanation, $explanationError] = $this->explanation($result, $options);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->error('Knowledge query failed: ' . $exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($options['json']) {
            $payload = [...$result, 'explanation' => $explanation, 'explanation_error' => $explanationError];
            $this->output(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        } else {
            $this->writeQuery($result, $explanation, $explanationError);
        }

        return 0;
    }

    /** @param array{name:string,value:string} $option
     * @param KnowledgeQueryOptions $options
     * @return KnowledgeQueryOptions
     */
    private function applyQueryOption(array $option, array $options): array
    {
        $name = $option['name'];

        if (in_array($name, ['limit', 'depth', 'budget'], true)) {
            $parsed = filter_var($option['value'], FILTER_VALIDATE_INT);

            if (!is_int($parsed)) {
                throw new \InvalidArgumentException(sprintf('--%s requires an integer.', $name));
            }

            return match ($name) {
                'limit' => [...$options, 'limit' => $parsed],
                'depth' => [...$options, 'depth' => $parsed],
                default => [...$options, 'budget' => $parsed],
            };
        }

        return match ($name) {
            'graph' => [...$options, 'graph' => $option['value']],
            'direction' => [...$options, 'direction' => $option['value']],
            'context' => [...$options, 'context' => $option['value']],
            'relation' => [...$options, 'relations' => [...$options['relations'], $option['value']]],
            'provider' => [...$options, 'provider' => $option['value'], 'explain' => true],
            default => [...$options, 'model' => $option['value'], 'explain' => true],
        };
    }

    /** @param list<string> $args
     * @return array{paths:list<string>,output:?string,force:bool}
     */
    private function buildOptions(array $args): array
    {
        $paths = [];
        $output = null;
        $force = false;

        for ($index = 0, $count = count($args); $index < $count; $index++) {
            $arg = $args[$index];

            if ($arg === '--force') {
                $force = true;
            } elseif ($arg === '--output') {
                $output = $args[++$index] ?? throw new \InvalidArgumentException('--output requires a path.');
            } elseif (str_starts_with($arg, '--output=')) {
                $output = substr($arg, strlen('--output='));
            } elseif (str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException(sprintf('Unknown knowledge build option: %s', $arg));
            } else {
                $paths[] = $arg;
            }
        }

        if (is_string($output) && trim($output) === '') {
            throw new \InvalidArgumentException('--output requires a path.');
        }

        return ['paths' => $paths, 'output' => $output, 'force' => $force];
    }

    private function displayPath(string $path): string
    {
        $root = rtrim(Paths::projectRootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? str_replace('\\', '/', substr($path, strlen($root))) : $path;
    }

    private function error(string $message): void
    {
        if ($this->stderr instanceof \Closure) {
            ($this->stderr)($message);

            return;
        }

        fwrite(STDERR, $message);
    }

    /** @param array<string, mixed> $result
     * @param KnowledgeQueryOptions $options
     * @return array{Explanation|null,string|null}
     */
    private function explanation(array $result, array $options): array
    {
        /** @var QueryResult $result */
        if (!$options['explain']) {
            return [null, null];
        }

        try {
            return [new KnowledgeExplainer()->explain($result, $options['provider'], $options['model']), null];
        } catch (\RuntimeException $exception) {
            return [null, $exception->getMessage()];
        }
    }

    private function output(string $message): void
    {
        if ($this->stdout instanceof \Closure) {
            ($this->stdout)($message);

            return;
        }

        fwrite(STDOUT, $message);
    }

    /** @param list<string> $args
     * @return KnowledgeQueryOptions
     */
    private function queryOptions(array $args): array
    {
        /** @var KnowledgeQueryOptions $options */
        $options = [
            'question' => '',
            'graph' => null,
            'limit' => 20,
            'depth' => 2,
            'budget' => 4_000,
            'mode' => 'bfs',
            'direction' => 'both',
            'context' => 'auto',
            'relations' => [],
            'json' => false,
            'explain' => false,
            'provider' => 'auto',
            'model' => null,
        ];
        $question = [];

        for ($index = 0, $count = count($args); $index < $count; $index++) {
            $arg = $args[$index];

            if (in_array($arg, ['--json', '--explain', '--dfs'], true)) {
                $options['json'] = $options['json'] || $arg === '--json';
                $options['explain'] = $options['explain'] || $arg === '--explain';
                $options['mode'] = $arg === '--dfs' ? 'dfs' : $options['mode'];

                continue;
            }

            $option = $this->valueOption($args, $index, $arg);

            if (is_array($option)) {
                $options = $this->applyQueryOption($option, $options);

                continue;
            }

            if (str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException(sprintf('Unknown knowledge query option: %s', $arg));
            }

            $question[] = $arg;
        }

        $questionText = trim(implode(' ', $question));

        if ($questionText === '') {
            throw new \InvalidArgumentException('Usage: phpforge kb:query <question> [--depth=2] [--context=auto] [--json] [--explain].');
        }

        $options['question'] = $questionText;

        return $options;
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) <= $length ? $value : substr($value, 0, $length - 1) . '…';
    }

    /** @param list<string> $args
     * @return array{name:string,value:string}|null
     */
    private function valueOption(array $args, int &$index, string $arg): ?array
    {
        foreach (['graph', 'limit', 'depth', 'budget', 'direction', 'context', 'relation', 'provider', 'model'] as $name) {
            $prefix = '--' . $name . '=';
            $value = null;

            if ($arg === '--' . $name) {
                $value = $args[++$index] ?? throw new \InvalidArgumentException(sprintf('--%s requires a value.', $name));
            } elseif (str_starts_with($arg, $prefix)) {
                $value = substr($arg, strlen($prefix));
            }

            if ($value === null) {
                continue;
            }

            if ($value === '') {
                throw new \InvalidArgumentException(sprintf('--%s requires a value.', $name));
            }

            return ['name' => $name, 'value' => $value];
        }

        return null;
    }

    /** @param array<string, mixed> $result
     * @param array<string, mixed>|null $explanation
     */
    private function writeQuery(array $result, ?array $explanation, ?string $explanationError): void
    {
        /** @var QueryResult $result */
        /** @var Explanation|null $explanation */
        $this->output('Knowledge query' . PHP_EOL);
        $this->output('Question: ' . $result['question'] . PHP_EOL . PHP_EOL);
        $this->output(sprintf('%-6s %-18s %-56s %s%s', 'SCORE', 'TYPE', 'NAME', 'LOCATION', PHP_EOL));

        foreach ($result['matches'] as $match) {
            $node = $match['node'];
            $this->output(sprintf(
                '%-6d %-18s %-56s %s:%d%s',
                $match['score'],
                $node['type'],
                $this->truncate($node['name'], 56),
                $node['file'] !== '' ? $node['file'] : '(external)',
                $node['line'],
                PHP_EOL,
            ));
        }

        $retrieval = $result['retrieval'];
        $architecture = $result['architecture'];
        $this->output(PHP_EOL . sprintf(
            'Retrieved: matches=%d nodes=%d relationships=%d%s',
            count($result['matches']),
            count($result['context_nodes']),
            count($result['edges']),
            PHP_EOL,
        ));
        $this->output(sprintf(
            'Traversal: %s depth=%d direction=%s context=%s tokens~%d/%d%s',
            strtoupper($retrieval['mode']),
            $retrieval['depth'],
            $retrieval['direction'],
            $retrieval['context'],
            $retrieval['estimated_tokens'],
            $retrieval['token_budget'] === 0 ? $retrieval['safety_caps']['token_budget'] : $retrieval['token_budget'],
            PHP_EOL,
        ));
        $this->output(sprintf(
            'Architecture: communities=%d hubs=%d bridges=%d%s',
            count($architecture['communities']),
            count($architecture['god_nodes']),
            count($architecture['bridges']),
            PHP_EOL,
        ));

        if ($retrieval['truncated']) {
            $this->output('Truncated by: ' . implode(', ', $retrieval['truncation_reasons']) . PHP_EOL);
        }

        if (is_array($explanation)) {
            $this->output(PHP_EOL . sprintf('AI explanation [INFERRED — %s/%s]%s', $explanation['provider'], $explanation['model'], PHP_EOL));
            $this->output($explanation['summary'] . PHP_EOL);

            if ($explanation['uncertainty'] !== '') {
                $this->output('Uncertainty: ' . $explanation['uncertainty'] . PHP_EOL);
            }
        } elseif (is_string($explanationError)) {
            $this->error('AI explanation unavailable; graph results remain valid: ' . $explanationError . PHP_EOL);
        }
    }
}
