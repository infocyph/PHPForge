<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\CommunityTemplateCatalog;
use Infocyph\PHPForge\Support\FilePublisher;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PublishCommunityTemplatesCommand extends Command
{
    public function __construct(string $name = 'ic:community')
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Publish generic CONTRIBUTING, issue, and pull request templates into the project.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing project files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = 0;
        $force = (bool) $input->getOption('force');

        foreach (CommunityTemplateCatalog::publishPairs() as $pair) {
            $published += $this->publish($pair['source'], $pair['target'], $pair['target_relative'], $force, $output) ? 1 : 0;
        }

        $output->writeln(sprintf('<info>Published %d community template file(s).</info>', $published));

        return 0;
    }

    private function publish(string $source, string $target, string $targetRelative, bool $force, OutputInterface $output): bool
    {
        return FilePublisher::publish($source, $target, $targetRelative, $force, $output, [
            'missing' => '<error>Missing bundled template: %s</error>',
            'skipped' => '<comment>Skipped existing template: %s</comment>',
            'unreadable' => '<error>Unable to read bundled template: %s</error>',
            'unwritable' => '<error>Unable to write template: %s</error>',
            'published' => '<info>Published template: %s</info>',
        ]);
    }
}
