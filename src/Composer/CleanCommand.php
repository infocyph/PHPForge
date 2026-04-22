<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanCommand extends Command
{
    private const PATHS = [
        '.phpunit.cache',
        '.psalm-cache',
        'phpstan-results.json',
        'phpstan-results.sarif',
        'psalm-results.sarif',
    ];

    public function __construct()
    {
        parent::__construct('ic:clean');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove known PHPForge tool output files and directories.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($input);

        $removed = 0;

        foreach (self::PATHS as $path) {
            $target = Paths::projectRootPath() . DIRECTORY_SEPARATOR . $path;

            if (!file_exists($target)) {
                continue;
            }

            $this->remove($target);
            $removed++;
            $output->writeln(sprintf('<info>Removed: %s</info>', $path));
        }

        $output->writeln(sprintf('<info>Clean complete: %d path(s) removed.</info>', $removed));

        return 0;
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }

                $this->remove($path . DIRECTORY_SEPARATOR . $child);
            }

            rmdir($path);

            return;
        }

        unlink($path);
    }
}
