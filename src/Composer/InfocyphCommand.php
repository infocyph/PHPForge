<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\ParallelRunner;
use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InfocyphCommand extends Command
{
    /**
     * @param list<list<string>> $tasks
     * @param list<list<string>> $preflightTasks
     */
    public function __construct(
        string $commandName,
        private readonly string $commandDescription,
        private readonly array $tasks,
        private readonly bool $parallel = false,
        private readonly array $preflightTasks = [],
    ) {
        parent::__construct($commandName);
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->commandDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($input);

        if ($this->parallel) {
            return (new ParallelRunner($output))->run($this->preflightTasks, $this->tasks);
        }

        return (new Runner($output))->run($this->tasks);
    }
}
