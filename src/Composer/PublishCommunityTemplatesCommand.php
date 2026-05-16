<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\CommunityTemplateCatalog;
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
        if (!is_file($source)) {
            $output->writeln(sprintf('<error>Missing bundled template: %s</error>', $targetRelative));

            return false;
        }

        if (is_file($target) && !$force) {
            $output->writeln(sprintf('<comment>Skipped existing template: %s</comment>', $targetRelative));

            return false;
        }

        $contents = file_get_contents($source);

        if (!is_string($contents)) {
            $output->writeln(sprintf('<error>Unable to read bundled template: %s</error>', $targetRelative));

            return false;
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Unable to create directory: %s</error>', $directory));

            return false;
        }

        if (file_put_contents($target, $contents) === false) {
            $output->writeln(sprintf('<error>Unable to write template: %s</error>', $targetRelative));

            return false;
        }

        $output->writeln(sprintf('<info>Published template: %s</info>', $targetRelative));

        return true;
    }
}
