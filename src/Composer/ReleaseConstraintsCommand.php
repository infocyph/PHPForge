<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\StableRuntimeConstraints;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ReleaseConstraintsCommand extends Command
{
    public function __construct(string $name = 'ic:release:constraints')
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Reject non-stable runtime dependency constraints before release.');
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- Inherited command signature.
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composerFile = Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'composer.json';
        $violations = (new StableRuntimeConstraints())->violations($composerFile);

        if ($violations === []) {
            $output->writeln('<info>Stable runtime constraint guard passed.</info>');

            return 0;
        }

        $output->writeln('<error>Stable runtime constraint guard failed:</error>');

        foreach ($violations as $violation) {
            $output->writeln('  - ' . $violation);
        }

        return 1;
    }
}
