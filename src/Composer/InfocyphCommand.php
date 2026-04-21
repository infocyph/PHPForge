<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand;
use Infocyph\PHPForge\Support\Runner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InfocyphCommand extends BaseCommand
{
    /**
     * @param list<list<string>> $tasks
     */
    public function __construct(
        private readonly string $commandName,
        private readonly string $commandDescription,
        private readonly array $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName($this->commandName)
            ->setDescription($this->commandDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new Runner($output))->run($this->tasks);
    }
}
