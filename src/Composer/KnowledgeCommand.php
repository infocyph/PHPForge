<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\KnowledgeCli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class KnowledgeCommand extends Command
{
    public function __construct(private readonly string $action)
    {
        parent::__construct('ic:kb:' . $action);
    }

    protected function configure(): void
    {
        if ($this->action === 'build') {
            $this
                ->setDescription('Build or reuse the deterministic project knowledge base.')
                ->addArgument('paths', InputArgument::IS_ARRAY, 'Project paths to index.')
                ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Knowledge JSON output path.')
                ->addOption('force', null, InputOption::VALUE_NONE, 'Rebuild even when the input fingerprint is unchanged.');

            return;
        }

        $this
            ->setDescription('Query a depth-aware project subgraph and optionally explain it.')
            ->addArgument('question', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Natural-language or symbol query.')
            ->addOption('graph', null, InputOption::VALUE_REQUIRED, 'Knowledge JSON input path.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum lexical seed matches; zero requests the safety maximum.')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Traversal depth, from 0 to 12 (default: 2).')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Approximate context-token budget; zero requests the safety maximum.')
            ->addOption('direction', null, InputOption::VALUE_REQUIRED, 'Edge direction: both, incoming or outgoing.')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context profile: auto, all, architecture, calls, inheritance or content.')
            ->addOption('relation', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict traversal to an extracted relation; repeatable or comma-separated.')
            ->addOption('dfs', null, InputOption::VALUE_NONE, 'Use depth-first traversal instead of breadth-first traversal.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write the complete query result as JSON.')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Explain the retrieved graph slice with an available Ollama model.')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Explanation provider: ollama or gemini.')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Provider model override.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cli = new KnowledgeCli(
            static fn(string $message) => $output->write($message, false, OutputInterface::OUTPUT_RAW),
            static fn(string $message) => $output->write($message, false, OutputInterface::OUTPUT_RAW),
        );

        return $this->action === 'build'
            ? $cli->build($this->buildArguments($input))
            : $cli->query($this->queryArguments($input));
    }

    /** @return list<string> */
    private function buildArguments(InputInterface $input): array
    {
        $arguments = $this->strings($input->getArgument('paths'));
        $output = $input->getOption('output');

        if (is_string($output) && $output !== '') {
            $arguments[] = '--output=' . $output;
        }

        if ($input->getOption('force') === true) {
            $arguments[] = '--force';
        }

        return $arguments;
    }

    /** @return list<string> */
    private function queryArguments(InputInterface $input): array
    {
        $arguments = $this->strings($input->getArgument('question'));

        $arguments = $this->withValueOptions($arguments, $input);
        $arguments = $this->withRelationOptions($arguments, $input);

        return $this->withFlagOptions($arguments, $input);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function withFlagOptions(array $arguments, InputInterface $input): array
    {
        foreach (['json', 'explain', 'dfs'] as $name) {
            if ($input->getOption($name) === true) {
                $arguments[] = '--' . $name;
            }
        }

        return $arguments;
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function withRelationOptions(array $arguments, InputInterface $input): array
    {
        $relations = $input->getOption('relation');

        if (!is_array($relations)) {
            return $arguments;
        }

        foreach ($relations as $relation) {
            if (is_string($relation) && $relation !== '') {
                $arguments[] = '--relation=' . $relation;
            }
        }

        return $arguments;
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function withValueOptions(array $arguments, InputInterface $input): array
    {
        foreach (['graph', 'limit', 'depth', 'budget', 'direction', 'context', 'provider', 'model'] as $name) {
            $value = $input->getOption($name);

            if (is_string($value) && $value !== '') {
                $arguments[] = '--' . $name . '=' . $value;
            }
        }

        return $arguments;
    }
}
