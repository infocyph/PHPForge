<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class InitCommand extends Command
{
    public function __construct()
    {
        parent::__construct('ic:init');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Copy optional PHPForge project files into the current project.')
            ->addOption('workflow', null, InputOption::VALUE_NONE, 'Copy the Security & Standards GitHub Actions workflow.')
            ->addOption('workflow-ref', null, InputOption::VALUE_REQUIRED, 'PHPForge Git ref used by generated workflow wrappers.', 'main')
            ->addOption('captainhook', null, InputOption::VALUE_NONE, 'Copy the default CaptainHook configuration.')
            ->addOption('no-interaction-defaults', null, InputOption::VALUE_NONE, 'Use default init selections without prompting.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $copyWorkflow = (bool) $input->getOption('workflow');
        $copyCaptainHook = (bool) $input->getOption('captainhook');
        $explicit = $copyWorkflow || $copyCaptainHook || (bool) $input->getOption('no-interaction-defaults');
        $settings = $this->defaultSettings((string) $input->getOption('workflow-ref'));

        if (!$explicit && $input->isInteractive()) {
            $settings = $this->ask($input, $output, $settings);
            $copyWorkflow = $settings['workflow'];
            $copyCaptainHook = $settings['captainhook'];
        } elseif (!$copyWorkflow && !$copyCaptainHook) {
            $copyWorkflow = true;
            $copyCaptainHook = true;
        }

        $force = (bool) $input->getOption('force');
        $copied = 0;

        if ($copyWorkflow) {
            $copied += $this->copyWorkflow(
                Paths::packageFile('resources/workflows/security-standards.yml'),
                Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'security-standards.yml',
                $settings,
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

    /**
     * @return array{
     *     workflow: bool,
     *     captainhook: bool,
     *     workflow_ref: string,
     *     php_versions: string,
     *     dependency_versions: string,
     *     php_extensions: string,
     *     coverage: string,
     *     composer_flags: string,
     *     phpstan_memory_limit: string,
     *     psalm_threads: string,
     *     run_analysis: bool
     * }
     */
    private function ask(InputInterface $input, OutputInterface $output, array $settings): array
    {
        $helper = $this->getHelper('question');

        if (!$helper instanceof QuestionHelper) {
            return $settings;
        }

        $settings['captainhook'] = $helper->ask($input, $output, new ConfirmationQuestion('Install CaptainHook config? [Y/n] ', true));
        $settings['workflow'] = $helper->ask($input, $output, new ConfirmationQuestion('Install GitHub Actions workflow wrapper? [Y/n] ', true));

        if (!$settings['workflow']) {
            return $settings;
        }

        $workflowRefChoices = [
            'main' => 'main',
        ];

        if (!in_array($settings['workflow_ref'], $workflowRefChoices, true)) {
            $workflowRefChoices['configured'] = $settings['workflow_ref'];
        }

        $workflowRefChoices['custom'] = 'custom';

        $workflowRef = $helper->ask($input, $output, new ChoiceQuestion(
            'PHPForge workflow ref',
            $workflowRefChoices,
            array_search($settings['workflow_ref'], $workflowRefChoices, true) ?: 'main',
        ));

        $settings['workflow_ref'] = $workflowRef === 'custom'
            ? (string) $helper->ask($input, $output, new Question('Custom PHPForge workflow ref: ', $settings['workflow_ref']))
            : (string) $workflowRef;

        $phpPreset = $helper->ask($input, $output, new ChoiceQuestion(
            'PHP version matrix',
            [
                'supported' => '["8.2","8.3","8.4","8.5"]',
                'current' => '["8.4","8.5"]',
                'stable' => '["8.5"]',
                'custom' => 'custom',
            ],
            'supported',
        ));

        $settings['php_versions'] = $phpPreset === 'custom'
            ? (string) $helper->ask($input, $output, new Question('Custom PHP versions JSON: ', $settings['php_versions']))
            : (string) $phpPreset;

        $dependencyPreset = $helper->ask($input, $output, new ChoiceQuestion(
            'Dependency matrix',
            [
                'full' => '["prefer-lowest","prefer-stable"]',
                'stable' => '["prefer-stable"]',
                'custom' => 'custom',
            ],
            'full',
        ));

        $settings['dependency_versions'] = $dependencyPreset === 'custom'
            ? (string) $helper->ask($input, $output, new Question('Custom dependency versions JSON: ', $settings['dependency_versions']))
            : (string) $dependencyPreset;

        $extensionPreset = $helper->ask($input, $output, new ChoiceQuestion(
            'PHP extensions',
            [
                'none' => '',
                'common' => 'mbstring, intl, bcmath',
                'mysql' => 'mbstring, intl, bcmath, pdo_mysql',
                'pgsql' => 'mbstring, intl, bcmath, pdo_pgsql',
                'mysql+pgsql' => 'mbstring, intl, bcmath, pdo_mysql, pdo_pgsql',
                'custom' => 'custom',
            ],
            'none',
        ));

        $settings['php_extensions'] = $extensionPreset === 'custom'
            ? (string) $helper->ask($input, $output, new Question('Custom PHP extensions, comma-separated: ', $settings['php_extensions']))
            : (string) $extensionPreset;

        $settings['coverage'] = (string) $helper->ask($input, $output, new ChoiceQuestion('Coverage driver', ['none', 'xdebug', 'pcov'], $settings['coverage']));

        $composerFlags = $helper->ask($input, $output, new ChoiceQuestion(
            'Extra Composer flags',
            [
                'none' => '',
                'with-all-dependencies' => '--with-all-dependencies',
                'ignore-ext-redis' => '--ignore-platform-req=ext-redis',
                'custom' => 'custom',
            ],
            'none',
        ));

        $settings['composer_flags'] = $composerFlags === 'custom'
            ? (string) $helper->ask($input, $output, new Question('Custom Composer flags: ', $settings['composer_flags']))
            : (string) $composerFlags;

        $phpstanMemoryLimit = $helper->ask($input, $output, new ChoiceQuestion(
            'PHPStan memory limit',
            [
                '1G',
                '2G',
                '4G',
                'custom',
            ],
            $settings['phpstan_memory_limit'],
        ));

        $settings['phpstan_memory_limit'] = $phpstanMemoryLimit === 'custom'
            ? (string) $helper->ask($input, $output, new Question('Custom PHPStan memory limit: ', $settings['phpstan_memory_limit']))
            : (string) $phpstanMemoryLimit;

        $psalmThreads = $helper->ask($input, $output, new ChoiceQuestion(
            'Psalm threads',
            [
                '1',
                '2',
                '4',
                'custom',
            ],
            $settings['psalm_threads'],
        ));

        $settings['psalm_threads'] = $psalmThreads === 'custom'
            ? (string) $helper->ask($input, $output, new Question('Custom Psalm thread count: ', $settings['psalm_threads']))
            : (string) $psalmThreads;

        $settings['run_analysis'] = $helper->ask($input, $output, new ConfirmationQuestion('Enable SARIF code-scanning analysis? [Y/n] ', $settings['run_analysis']));

        return $settings;
    }

    private function copy(string $source, string $target, bool $force, OutputInterface $output): int
    {
        if (!is_file($source)) {
            $output->writeln(sprintf('<error>Missing template: %s</error>', $source));

            return 0;
        }

        $contents = file_get_contents($source);

        if (!is_string($contents)) {
            $output->writeln(sprintf('<error>Unable to read template: %s</error>', $source));

            return 0;
        }

        return $this->write($contents, $target, $force, $output);
    }

    /**
     * @param array<string, bool|string> $settings
     */
    private function copyWorkflow(string $source, string $target, array $settings, bool $force, OutputInterface $output): int
    {
        if (!is_file($source)) {
            $output->writeln(sprintf('<error>Missing template: %s</error>', $source));

            return 0;
        }

        $contents = file_get_contents($source);

        if (!is_string($contents)) {
            $output->writeln(sprintf('<error>Unable to read template: %s</error>', $source));

            return 0;
        }

        $contents = str_replace('@main', '@' . $settings['workflow_ref'], $contents);
        $contents = str_replace('php_versions: \'["8.2","8.3","8.4","8.5"]\'', "php_versions: '" . $settings['php_versions'] . "'", $contents);
        $contents = str_replace('dependency_versions: \'["prefer-lowest","prefer-stable"]\'', "dependency_versions: '" . $settings['dependency_versions'] . "'", $contents);
        $contents = str_replace('php_extensions: ""', 'php_extensions: "' . $settings['php_extensions'] . '"', $contents);
        $contents = str_replace('coverage: "none"', 'coverage: "' . $settings['coverage'] . '"', $contents);
        $contents = str_replace('composer_flags: ""', 'composer_flags: "' . $settings['composer_flags'] . '"', $contents);
        $contents = str_replace('phpstan_memory_limit: "1G"', 'phpstan_memory_limit: "' . $settings['phpstan_memory_limit'] . '"', $contents);
        $contents = str_replace('psalm_threads: "1"', 'psalm_threads: "' . $settings['psalm_threads'] . '"', $contents);
        $contents = str_replace('run_analysis: true', 'run_analysis: ' . ($settings['run_analysis'] ? 'true' : 'false'), $contents);

        return $this->write($contents, $target, $force, $output);
    }

    /**
     * @return array{
     *     workflow: bool,
     *     captainhook: bool,
     *     workflow_ref: string,
     *     php_versions: string,
     *     dependency_versions: string,
     *     php_extensions: string,
     *     coverage: string,
     *     composer_flags: string,
     *     phpstan_memory_limit: string,
     *     psalm_threads: string,
     *     run_analysis: bool
     * }
     */
    private function defaultSettings(string $workflowRef): array
    {
        return [
            'workflow' => true,
            'captainhook' => true,
            'workflow_ref' => $workflowRef,
            'php_versions' => '["8.2","8.3","8.4","8.5"]',
            'dependency_versions' => '["prefer-lowest","prefer-stable"]',
            'php_extensions' => '',
            'coverage' => 'none',
            'composer_flags' => '',
            'phpstan_memory_limit' => '1G',
            'psalm_threads' => '1',
            'run_analysis' => true,
        ];
    }

    private function write(string $contents, string $target, bool $force, OutputInterface $output): int
    {
        if (is_file($target) && !$force) {
            $output->writeln(sprintf('<comment>Skipped existing file: %s</comment>', $target));

            return 0;
        }

        $directory = dirname($target);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($target, $contents);
        $output->writeln(sprintf('<info>Copied: %s</info>', $target));

        return 1;
    }
}
