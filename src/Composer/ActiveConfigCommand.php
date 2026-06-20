<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\ActiveConfigFormatter;
use Infocyph\PHPForge\Support\ActiveConfigInspector;
use Infocyph\PHPForge\Support\ConfigFileSelection;
use Infocyph\PHPForge\Support\ConfigInventory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ActiveConfigCommand extends Command
{
    public function __construct()
    {
        parent::__construct('ic:active-config');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show active configs for supported tools.')
            ->addArgument('files', InputArgument::IS_ARRAY, 'Specific config files to inspect.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Inspect all active tool configs.')
            ->addOption('parameter', null, InputOption::VALUE_REQUIRED, 'Output one config/effective parameter by key or dot path.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selectedFiles = $this->resolveFiles($input);
        $validatedFiles = $this->validatedFiles($selectedFiles, $output);

        if (!is_array($validatedFiles)) {
            return 1;
        }

        $activeConfig = (new ActiveConfigInspector())->inspect(
            $validatedFiles,
            $this->optionString($input, 'parameter'),
        );

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($activeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $output->writeln((new ActiveConfigFormatter())->text($activeConfig));

        return 0;
    }

    private function optionString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(InputInterface $input): array
    {
        $files = $input->getArgument('files');

        if (!is_array($files) || $files === [] || (bool) $input->getOption('all')) {
            return [];
        }

        return ConfigFileSelection::normalize(array_values($files), ConfigInventory::activeFiles());
    }

    /**
     * @param list<string> $files
     * @return list<string>|null
     */
    private function validatedFiles(array $files, OutputInterface $output): ?array
    {
        return ConfigFileSelection::validatedOrWriteError(
            $files,
            ConfigInventory::activeFiles(),
            'Invalid active config selection',
            $output,
        );
    }
}
