<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\CaptainHook;
use Infocyph\PHPForge\Support\CommunityTemplateCatalog;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\ServiceCatalog;
use Infocyph\PHPForge\Support\WorkflowWrapper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Process;

final class InitCommand extends Command
{
    public function __construct()
    {
        parent::__construct('ic:init');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set up PHPForge hooks, CI, and selected integration services.')
            ->addOption('workflow', null, InputOption::VALUE_NONE, 'Copy the Security & Standards GitHub Actions workflow wrapper.')
            ->addOption('workflow-ref', null, InputOption::VALUE_REQUIRED, 'PHPForge Git ref used by generated workflow wrappers.', 'main')
            ->addOption('captainhook', null, InputOption::VALUE_NONE, 'Create the CaptainHook pre-commit configuration and install hooks.')
            ->addOption('services', null, InputOption::VALUE_REQUIRED, 'JSON string array of integration services.', '[]')
            ->addOption('service-topologies', null, InputOption::VALUE_REQUIRED, 'JSON service-to-topology object.', '{}')
            ->addOption('gitlab-ci', null, InputOption::VALUE_NONE, 'Copy a GitLab CI pipeline.')
            ->addOption('bitbucket-ci', null, InputOption::VALUE_NONE, 'Copy a Bitbucket Pipelines configuration.')
            ->addOption('forgejo-workflow', null, InputOption::VALUE_NONE, 'Copy a Forgejo Actions workflow.')
            ->addOption('community-templates', null, InputOption::VALUE_NONE, 'Copy generic contributing, issue, and pull request templates.')
            ->addOption('no-interaction-defaults', null, InputOption::VALUE_NONE, 'Use the default GitHub Actions and CaptainHook selections without prompting.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selection($input, $output);

        if (!is_array($selection)) {
            return 2;
        }

        $force = (bool) $input->getOption('force');
        $copied = 0;

        if ($selection['flags']['workflow']) {
            $copied += $this->copyWorkflow($selection, $force, $output);
        }

        foreach (['captainhook', 'gitlab_ci', 'bitbucket_ci', 'forgejo_workflow'] as $flag) {
            if ($selection['flags'][$flag]) {
                [$source, $target] = $this->target($flag);
                $copied += $this->copy($source, $target, $force, $output);
            }
        }

        if ($selection['flags']['community_templates']) {
            foreach (CommunityTemplateCatalog::publishPairs() as $pair) {
                $copied += $this->copy($pair['source'], $pair['target'], $force, $output);
            }
        }

        $output->writeln('Supported PHP: ' . implode(', ', $this->runtimeVersions()));

        if ($selection['flags']['captainhook'] && $this->installHooks($output) !== 0) {
            return 1;
        }

        $output->writeln(sprintf('<info>PHPForge init complete: %d file(s) copied.</info>', $copied));
        $output->writeln('Run composer ic:ci to validate setup.');

        return 0;
    }

    private function copy(string $source, string $target, bool $force, OutputInterface $output): int
    {
        $contents = is_file($source) ? file_get_contents($source) : false;

        if (!is_string($contents)) {
            $output->writeln(sprintf('<error>Unable to read template: %s</error>', $source));

            return 0;
        }

        return $this->write($contents, $target, $force, $output);
    }

    /** @param array{workflow_ref:string,services:list<string>,topologies:array<string, string>,flags:array<string, bool>} $selection */
    private function copyWorkflow(array $selection, bool $force, OutputInterface $output): int
    {
        $source = Paths::packageFile('resources/workflows/security-standards.yml');
        $contents = file_get_contents($source);

        if (!is_string($contents)) {
            $output->writeln(sprintf('<error>Unable to read template: %s</error>', $source));

            return 0;
        }

        $updated = WorkflowWrapper::update($contents, $selection['workflow_ref'], [
            'integration_services' => WorkflowWrapper::yamlSingleQuoted(json_encode($selection['services'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'service_topologies' => WorkflowWrapper::yamlSingleQuoted(json_encode($selection['topologies'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT)),
        ]);

        if (!is_string($updated)) {
            $output->writeln('<error>Unable to patch workflow template.</error>');

            return 0;
        }

        $target = Paths::projectRootPath() . '/.github/workflows/security-standards.yml';

        return $this->write($updated, $target, $force, $output);
    }

    private function installHooks(OutputInterface $output): int
    {
        $configuration = Paths::projectRootPath() . '/captainhook.json';

        if (!is_file($configuration)) {
            return 0;
        }

        $process = new Process(CaptainHook::installCommand($configuration), Paths::projectRootPath());
        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer, false, $type === Process::ERR ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
        });

        return $process->getExitCode() ?? 1;
    }

    /**
     * @param array<string, bool> $flags
     * @return array{workflow_ref:string,services:list<string>,topologies:array<string, string>,flags:array<string, bool>}|null
     */
    private function interactiveSelection(InputInterface $input, OutputInterface $output, array $flags, string $workflowRef): ?array
    {
        $helper = $this->getHelper('question');

        if (!$helper instanceof QuestionHelper) {
            return null;
        }

        $flags['workflow'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install GitHub Actions workflow? [Y/n] ', true));
        $flags['captainhook'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install CaptainHook? [Y/n] ', true));
        $question = new ChoiceQuestion('Select integration services (comma-separated indexes; press Enter for none)', ServiceCatalog::names());
        $question->setMultiselect(true);
        $validateChoice = $question->getValidator();

        if ($validateChoice !== null) {
            $question->setValidator(
                static fn(mixed $answer): mixed => $answer === null || $answer === '' ? [] : $validateChoice($answer),
            );
        }

        $answer = $helper->ask($input, $output, $question);
        $services = $this->uniqueStrings($answer);
        $topologies = [];
        $catalog = ServiceCatalog::all();

        foreach ($services as $service) {
            $choices = array_keys($catalog[$service]['topologies']);

            if (count($choices) < 2) {
                continue;
            }

            $topology = $helper->ask($input, $output, new ChoiceQuestion(sprintf('%s topology', $catalog[$service]['label']), $choices, 'standalone'));

            if (is_string($topology) && $topology !== 'standalone') {
                $topologies[$service] = $topology;
            }
        }

        return ['workflow_ref' => $workflowRef, 'services' => $services, 'topologies' => $topologies, 'flags' => $flags];
    }

    /** @return list<string> */
    private function jsonStringList(string $value, string $name, OutputInterface $output): ?array
    {
        $services = ServiceCatalog::servicesFromJson($value);

        if ($services === null) {
            $output->writeln(sprintf('<error>%s must be a JSON string array.</error>', $name));

            return null;
        }

        return $services;
    }

    /** @return array<string, string>|null */
    private function jsonStringMap(string $value, string $name, OutputInterface $output): ?array
    {
        $mapped = ServiceCatalog::topologiesFromJson($value);

        if ($mapped === null) {
            $output->writeln(sprintf('<error>%s must be a JSON object with string values.</error>', $name));

            return null;
        }

        return $mapped;
    }

    /** @return list<string> */
    private function runtimeVersions(): array
    {
        $runtime = require Paths::packageFile('resources/runtime.php');
        $values = is_array($runtime) ? ($runtime['php_versions'] ?? null) : null;

        return $this->uniqueStrings($values);
    }

    /** @return array{workflow_ref:string,services:list<string>,topologies:array<string, string>,flags:array<string, bool>}|null */
    private function selection(InputInterface $input, OutputInterface $output): ?array
    {
        $workflowRef = $input->getOption('workflow-ref');
        $workflowRef = is_string($workflowRef) ? $workflowRef : 'main';

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $workflowRef) !== 1) {
            $output->writeln('<error>Invalid workflow ref.</error>');

            return null;
        }

        $flags = [
            'workflow' => (bool) $input->getOption('workflow'),
            'captainhook' => (bool) $input->getOption('captainhook'),
            'gitlab_ci' => (bool) $input->getOption('gitlab-ci'),
            'bitbucket_ci' => (bool) $input->getOption('bitbucket-ci'),
            'forgejo_workflow' => (bool) $input->getOption('forgejo-workflow'),
            'community_templates' => (bool) $input->getOption('community-templates'),
        ];
        $explicitTarget = in_array(true, $flags, true);

        if ($input->isInteractive() && !$explicitTarget && !(bool) $input->getOption('no-interaction-defaults')) {
            return $this->interactiveSelection($input, $output, $flags, $workflowRef);
        }

        if (!$explicitTarget && (bool) $input->getOption('no-interaction-defaults')) {
            $flags['workflow'] = true;
            $flags['captainhook'] = true;
        }

        $servicesValue = $input->getOption('services');
        $topologiesValue = $input->getOption('service-topologies');
        $services = $this->jsonStringList(is_string($servicesValue) ? $servicesValue : '[]', 'services', $output);
        $topologies = $this->jsonStringMap(is_string($topologiesValue) ? $topologiesValue : '{}', 'service-topologies', $output);

        if (!is_array($services) || !is_array($topologies)) {
            return null;
        }

        $errors = ServiceCatalog::validate($services, $topologies);

        foreach ($errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        if ($errors !== []) {
            return null;
        }

        return ['workflow_ref' => $workflowRef, 'services' => $services, 'topologies' => $topologies, 'flags' => $flags];
    }

    /** @return array{string, string} */
    private function target(string $flag): array
    {
        return match ($flag) {
            'captainhook' => [Paths::packageFile('resources/captainhook.json'), Paths::projectRootPath() . '/captainhook.json'],
            'gitlab_ci' => [Paths::packageFile('resources/ci/gitlab-ci.yml'), Paths::projectRootPath() . '/.gitlab-ci.yml'],
            'bitbucket_ci' => [Paths::packageFile('resources/ci/bitbucket-pipelines.yml'), Paths::projectRootPath() . '/bitbucket-pipelines.yml'],
            'forgejo_workflow' => [Paths::packageFile('resources/ci/forgejo-security-standards.yml'), Paths::projectRootPath() . '/.forgejo/workflows/security-standards.yml'],
            default => throw new \InvalidArgumentException(sprintf('Unknown init target: %s', $flag)),
        };
    }

    /** @return list<string> */
    private function uniqueStrings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && !in_array($value, $strings, true)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    private function write(string $contents, string $target, bool $force, OutputInterface $output): int
    {
        if (is_file($target) && !$force) {
            $output->writeln(sprintf('<comment>Skipped existing: %s</comment>', $target));

            return 0;
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Unable to create directory: %s</error>', $directory));

            return 0;
        }

        if (file_put_contents($target, $contents) === false) {
            $output->writeln(sprintf('<error>Unable to write file: %s</error>', $target));

            return 0;
        }

        $output->writeln(sprintf('<info>Copied: %s</info>', $target));

        return 1;
    }
}
