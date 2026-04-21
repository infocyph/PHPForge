<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InitCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('ic:init')
            ->setDescription('Copy optional PHPForge project files into the current project.')
            ->addOption('workflow', null, InputOption::VALUE_NONE, 'Copy the Security & Standards GitHub Actions workflow.')
            ->addOption('captainhook', null, InputOption::VALUE_NONE, 'Copy the default CaptainHook configuration.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $copyWorkflow = (bool) $input->getOption('workflow');
        $copyCaptainHook = (bool) $input->getOption('captainhook');

        if (! $copyWorkflow && ! $copyCaptainHook) {
            $copyWorkflow = true;
            $copyCaptainHook = true;
        }

        $force = (bool) $input->getOption('force');
        $copied = 0;

        if ($copyWorkflow) {
            $copied += $this->copy(
                Paths::packageFile('resources/workflows/security-standards.yml'),
                Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'security-standards.yml',
                $force,
                $output,
            );
        }

        if ($copyCaptainHook) {
            $copied += $this->copy(
                Paths::packageFile('captainhook.json'),
                Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'captainhook.json',
                $force,
                $output,
            );
        }

        $output->writeln(sprintf('<info>PHPForge init complete: %d file(s) copied.</info>', $copied));

        return 0;
    }

    private function copy(string $source, string $target, bool $force, OutputInterface $output): int
    {
        if (! is_file($source)) {
            $output->writeln(sprintf('<error>Missing template: %s</error>', $source));

            return 0;
        }

        if (is_file($target) && ! $force) {
            $output->writeln(sprintf('<comment>Skipped existing file: %s</comment>', $target));

            return 0;
        }

        $directory = dirname($target);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        copy($source, $target);
        $output->writeln(sprintf('<info>Copied: %s</info>', $target));

        return 1;
    }
}
