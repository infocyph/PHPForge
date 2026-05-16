<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\CommunityTemplateCatalog;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\WorkflowWrapper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class InitCommand extends Command
{
    private const END_OF_LIFE_PHP_API = 'https://endoflife.date/api/php.json';

    /**
     * @var non-empty-array<string, string>|null
     */
    private ?array $phpVersionPresetsCache = null;

    public function __construct(string $name = 'ic:init')
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set up PHPForge project config, pre-commit hooks, and CI wrappers.')
            ->addOption('workflow', null, InputOption::VALUE_NONE, 'Copy the Security & Standards GitHub Actions workflow wrapper.')
            ->addOption('workflow-ref', null, InputOption::VALUE_REQUIRED, 'PHPForge Git ref used by generated workflow wrappers.', 'main')
            ->addOption('captainhook', null, InputOption::VALUE_NONE, 'Copy the default CaptainHook pre-commit configuration.')
            ->addOption('gitlab-ci', null, InputOption::VALUE_NONE, 'Copy a GitLab CI pipeline.')
            ->addOption('bitbucket-ci', null, InputOption::VALUE_NONE, 'Copy a Bitbucket Pipelines configuration.')
            ->addOption('forgejo-workflow', null, InputOption::VALUE_NONE, 'Copy a Forgejo Actions workflow.')
            ->addOption('community-templates', null, InputOption::VALUE_NONE, 'Copy generic contributing, issue, and pull request templates.')
            ->addOption('no-interaction-defaults', null, InputOption::VALUE_NONE, 'Use default init selections without prompting.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->defaultSettings($this->stringValue($input->getOption('workflow-ref'), 'main'));
        $settings['php_versions'] = $this->phpVersionPresets()['supported'];
        $selection = $this->resolvedSelection($input, $output, $settings);
        $settings = $selection['settings'];
        $flags = $selection['flags'];

        $force = (bool) $input->getOption('force');
        $copied = $this->copySelectedTargets($flags, $settings, $force, $output);

        $output->writeln(sprintf('<info>PHPForge init complete: %d file(s) copied.</info>', $copied));
        $output->writeln('<info>Next steps:</info>');
        $this->renderNextSteps($flags, $output);

        $output->writeln('  - Run composer ic:ci to validate setup');

        return 0;
    }

    /**
     * @param array<string, bool|string> $settings
     * @return array<string, bool|string>
     */
    private function ask(InputInterface $input, OutputInterface $output, array $settings): array
    {
        $helper = $this->getHelper('question');

        if (!$helper instanceof QuestionHelper) {
            return $settings;
        }

        $settings = $this->askInstallTargets($helper, $input, $output, $settings);

        if (!$settings['workflow']) {
            return $settings;
        }

        $settings['workflow_ref'] = $this->askWorkflowRef($helper, $input, $output, (string) $settings['workflow_ref']);
        $settings['php_versions'] = $this->askPhpVersions($helper, $input, $output, (string) $settings['php_versions']);
        $settings['dependency_versions'] = $this->askDependencyVersions($helper, $input, $output, (string) $settings['dependency_versions']);
        $settings['php_extensions'] = $this->askPhpExtensions($helper, $input, $output, (string) $settings['php_extensions']);
        $settings['composer_flags'] = $this->askComposerFlags($helper, $input, $output, (string) $settings['composer_flags']);
        $settings['phpstan_memory_limit'] = $this->askPhpstanMemoryLimit($helper, $input, $output, (string) $settings['phpstan_memory_limit']);
        $settings['psalm_threads'] = $this->askPsalmThreads($helper, $input, $output, (string) $settings['psalm_threads']);
        $settings['run_analysis'] = $this->askRunAnalysis($helper, $input, $output, (bool) $settings['run_analysis']);
        $settings['run_svg_report'] = $this->askRunSvgReport($helper, $input, $output, (bool) $settings['run_svg_report']);
        $settings = $this->askServiceToggles($helper, $input, $output, $settings);

        $settings['service_db_name'] = $this->askServiceDbName($helper, $input, $output, (string) $settings['service_db_name']);
        $settings['service_db_user'] = $this->askServiceDbUser($helper, $input, $output, (string) $settings['service_db_user']);
        $settings['service_db_password'] = $this->askServiceDbPassword($helper, $input, $output, (string) $settings['service_db_password']);

        return $settings;
    }

    /**
     * @param non-empty-list<string> $choices
     */
    private function askChoiceWithCustom(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $prompt,
        array $choices,
        string $default,
        string $customPrompt,
    ): string {
        $selection = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion($prompt, [...$choices, 'custom'], $default)), $default);

        return $selection === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question($customPrompt, $default)), $default)
            : $selection;
    }

    private function askComposerFlags(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultComposerFlags,
    ): string {
        return $this->askMappedChoice($helper, $input, $output, [
            'prompt' => 'Extra Composer flags',
            'default_choice' => 'none (no extra Composer flags)',
            'custom_prompt' => 'Custom Composer flags: ',
            'custom_default' => $defaultComposerFlags,
            'resolved_label' => 'Resolved Composer flags',
        ], [
            'none' => '',
            'with-all-dependencies' => '--with-all-dependencies',
            'ignore-ext-redis' => '--ignore-platform-req=ext-redis',
        ], [
            'none (no extra Composer flags)' => 'none',
            'with-all-dependencies (--with-all-dependencies; update transitive deps as needed)' => 'with-all-dependencies',
            'ignore-ext-redis (--ignore-platform-req=ext-redis; ignore ext-redis platform check)' => 'ignore-ext-redis',
            'custom (enter custom Composer flags)' => 'custom',
        ]);
    }

    private function askDependencyVersions(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultDependencyVersions,
    ): string {
        $presets = [
            'full' => '["prefer-lowest","prefer-stable"]',
            'stable' => '["prefer-stable"]',
        ];

        return $this->askMappedChoice($helper, $input, $output, [
            'prompt' => 'Dependency matrix',
            'default_choice' => sprintf('full (%s)', $presets['full']),
            'custom_prompt' => 'Custom dependency versions JSON: ',
            'custom_default' => $defaultDependencyVersions,
            'resolved_label' => 'Resolved dependency matrix',
        ], $presets, [
            sprintf('full (%s)', $presets['full']) => 'full',
            sprintf('stable (%s)', $presets['stable']) => 'stable',
            'custom (enter JSON array string)' => 'custom',
        ]);
    }

    private function askEnableWorkflowService(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $serviceName,
        bool $defaultEnabled,
    ): bool {
        return (bool) $helper->ask($input, $output, new ConfirmationQuestion(sprintf('Enable %s service container in workflow run job? [y/N] ', $serviceName), $defaultEnabled));
    }

    /**
     * @param array<string, bool|string> $settings
     * @return array<string, bool|string>
     */
    private function askInstallTargets(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        array $settings,
    ): array {
        $settings['captainhook'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install CaptainHook pre-commit config (validate, audit, parallel CI)? [Y/n] ', true));
        $settings['workflow'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install GitHub Actions workflow wrapper (parallel CI, SARIF, SVG report)? [Y/n] ', true));
        $settings['gitlab_ci'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install GitLab CI pipeline (.gitlab-ci.yml)? [y/N] ', false));
        $settings['bitbucket_ci'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install Bitbucket pipeline (bitbucket-pipelines.yml)? [y/N] ', false));
        $settings['forgejo_workflow'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install Forgejo workflow (.forgejo/workflows/security-standards.yml)? [y/N] ', false));
        $settings['community_templates'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install generic contributing, issue, and pull request templates? [y/N] ', false));

        return $settings;
    }

    /**
     * @param array{prompt:string,default_choice:string,custom_prompt:string,custom_default:string,resolved_label:string} $settings
     * @param non-empty-array<string, string> $presets
     * @param array<string, string> $choiceMap
     */
    private function askMappedChoice(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        array $settings,
        array $presets,
        array $choiceMap,
    ): string {
        $choice = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            $settings['prompt'],
            array_keys($choiceMap),
            $settings['default_choice'],
        )), $settings['default_choice']);

        $defaultPreset = array_key_first($presets);
        $preset = $choiceMap[$choice] ?? $defaultPreset;
        $resolved = $preset === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question($settings['custom_prompt'], $settings['custom_default'])), $settings['custom_default'])
            : ($presets[$preset] ?? $settings['custom_default']);

        $output->writeln(sprintf(
            '<comment>%s: %s</comment>',
            $settings['resolved_label'],
            $resolved !== '' ? $resolved : '(none)',
        ));

        return $resolved;
    }

    private function askPhpExtensions(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultExtensions,
    ): string {
        $phpExtensionPresets = [
            'none' => '',
            'detected' => $this->detectedPhpExtensions(),
            'common' => 'mbstring, intl, bcmath',
            'mysql' => 'mbstring, intl, bcmath, pdo_mysql',
            'pgsql' => 'mbstring, intl, bcmath, pdo_pgsql',
            'mysql+pgsql' => 'mbstring, intl, bcmath, pdo_mysql, pdo_pgsql',
        ];

        $detectedExtensionsLabel = $phpExtensionPresets['detected'] !== ''
            ? $phpExtensionPresets['detected']
            : 'none detected';
        $defaultChoice = $phpExtensionPresets['detected'] !== ''
            ? sprintf('detected (from composer ext-* require/require-dev/suggest: %s)', $detectedExtensionsLabel)
            : 'none (no extra extensions)';

        $extensionChoiceMap = [
            'none (no extra extensions)' => 'none',
            sprintf('detected (from composer ext-* require/require-dev/suggest: %s)', $detectedExtensionsLabel) => 'detected',
            'common (mbstring, intl, bcmath)' => 'common',
            'mysql (mbstring, intl, bcmath, pdo_mysql)' => 'mysql',
            'pgsql (mbstring, intl, bcmath, pdo_pgsql)' => 'pgsql',
            'mysql+pgsql (mbstring, intl, bcmath, pdo_mysql, pdo_pgsql)' => 'mysql+pgsql',
            'custom (enter comma-separated extension names)' => 'custom',
        ];

        return $this->askMappedChoice($helper, $input, $output, [
            'prompt' => 'PHP extensions',
            'default_choice' => $defaultChoice,
            'custom_prompt' => 'Custom PHP extensions, comma-separated: ',
            'custom_default' => $defaultExtensions,
            'resolved_label' => 'Resolved PHP extensions',
        ], $phpExtensionPresets, $extensionChoiceMap);
    }

    private function askPhpstanMemoryLimit(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultPhpstanMemoryLimit,
    ): string {
        return $this->askChoiceWithCustom(
            $helper,
            $input,
            $output,
            'PHPStan memory limit',
            ['1G', '2G', '4G'],
            $defaultPhpstanMemoryLimit,
            'Custom PHPStan memory limit: ',
        );
    }

    private function askPhpVersions(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultPhpVersions,
    ): string {
        $phpVersionPresets = $this->phpVersionPresets();
        $phpVersionChoiceMap = [
            sprintf('supported (%s)', $phpVersionPresets['supported'] ?? $defaultPhpVersions) => 'supported',
            sprintf('current (%s)', $phpVersionPresets['current'] ?? $defaultPhpVersions) => 'current',
            sprintf('stable (%s)', $phpVersionPresets['stable'] ?? $defaultPhpVersions) => 'stable',
            'custom (enter JSON array string)' => 'custom',
        ];

        $defaultChoice = sprintf('supported (%s)', $phpVersionPresets['supported'] ?? $defaultPhpVersions);

        return $this->askMappedChoice($helper, $input, $output, [
            'prompt' => 'PHP version matrix',
            'default_choice' => $defaultChoice,
            'custom_prompt' => 'Custom PHP versions JSON: ',
            'custom_default' => $defaultPhpVersions,
            'resolved_label' => 'Resolved PHP versions',
        ], $phpVersionPresets, $phpVersionChoiceMap);
    }

    private function askPsalmThreads(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultPsalmThreads,
    ): string {
        return $this->askChoiceWithCustom(
            $helper,
            $input,
            $output,
            'Psalm threads',
            ['1', '2', '4'],
            $defaultPsalmThreads,
            'Custom Psalm thread count: ',
        );
    }

    private function askRunAnalysis(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        bool $defaultRunAnalysis,
    ): bool {
        return (bool) $helper->ask($input, $output, new ConfirmationQuestion('Enable SARIF code-scanning analysis job? [Y/n] ', $defaultRunAnalysis));
    }

    private function askRunSvgReport(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        bool $defaultRunSvgReport,
    ): bool {
        return (bool) $helper->ask($input, $output, new ConfirmationQuestion('Generate SVG security and quality report artifacts? [Y/n] ', $defaultRunSvgReport));
    }

    private function askServiceDbName(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultName,
    ): string {
        return $this->stringValue($helper->ask($input, $output, new Question('Shared service database name: ', $defaultName)), $defaultName);
    }

    private function askServiceDbPassword(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultPassword,
    ): string {
        return $this->stringValue($helper->ask($input, $output, new Question('Shared service password: ', $defaultPassword)), $defaultPassword);
    }

    private function askServiceDbUser(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultUser,
    ): string {
        return $this->stringValue($helper->ask($input, $output, new Question('Shared service username: ', $defaultUser)), $defaultUser);
    }

    /**
     * @param array<string, bool|string> $settings
     * @return array<string, bool|string>
     */
    private function askServiceToggles(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        array $settings,
    ): array {
        $services = [
            'enable_redis_service' => 'Redis',
            'enable_valkey_service' => 'Valkey',
            'enable_memcached_service' => 'Memcached',
            'enable_postgres_service' => 'PostgreSQL',
            'enable_mysql_service' => 'MySQL',
            'enable_scylladb_service' => 'ScyllaDB Alternator',
            'enable_elasticsearch_service' => 'Elasticsearch',
            'enable_mongodb_service' => 'MongoDB',
        ];

        foreach ($services as $setting => $serviceName) {
            $settings[$setting] = $this->askEnableWorkflowService($helper, $input, $output, $serviceName, (bool) $settings[$setting]);
        }

        return $settings;
    }

    private function askWorkflowRef(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultWorkflowRef,
    ): string {
        $workflowRefChoices = [
            'main' => 'main',
        ];

        if (!in_array($defaultWorkflowRef, $workflowRefChoices, true)) {
            $workflowRefChoices['configured'] = $defaultWorkflowRef;
        }

        $workflowRefChoices['custom'] = 'custom';

        $workflowRef = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            'PHPForge workflow ref',
            $workflowRefChoices,
            array_search($defaultWorkflowRef, $workflowRefChoices, true) ?: 'main',
        )), $defaultWorkflowRef);

        if ($workflowRef !== 'custom') {
            return $workflowRef;
        }

        return $this->stringValue($helper->ask($input, $output, new Question('Custom PHPForge workflow ref: ', $defaultWorkflowRef)), $defaultWorkflowRef);
    }

    /**
     * @param array<string, bool|string> $settings
     */
    private function boolWorkflowSetting(array $settings, string $key): string
    {
        return (bool) ($settings[$key] ?? false) ? 'true' : 'false';
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

    private function copyCommunityTemplates(bool $force, OutputInterface $output): int
    {
        $copied = 0;

        foreach (CommunityTemplateCatalog::publishPairs() as $pair) {
            $copied += $this->copy($pair['source'], $pair['target'], $force, $output);
        }

        return $copied;
    }

    /**
     * @param array<string, bool> $flags
     * @param array<string, bool|string> $settings
     */
    private function copySelectedTargets(array $flags, array $settings, bool $force, OutputInterface $output): int
    {
        $copied = 0;

        if ($flags['workflow']) {
            $copied += $this->copyWorkflow(
                Paths::packageFile('resources/workflows/security-standards.yml'),
                Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'security-standards.yml',
                $settings,
                $force,
                $output,
            );
        }

        foreach (['captainhook', 'gitlab_ci', 'bitbucket_ci', 'forgejo_workflow'] as $flag) {
            if ($flags[$flag]) {
                [$source, $target] = $this->target($flag);
                $copied += $this->copy($source, $target, $force, $output);
            }
        }

        if ($flags['community_templates']) {
            $copied += $this->copyCommunityTemplates($force, $output);
        }

        return $copied;
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

        $workflowSettings = $this->normalizedWorkflowSettings($settings, $output);

        if (!is_array($workflowSettings)) {
            return 0;
        }

        $contents = WorkflowWrapper::update(
            $contents,
            $workflowSettings['workflow_ref'],
            $this->workflowWrapperValues($workflowSettings, $settings),
        );

        if (!is_string($contents)) {
            $output->writeln('<error>Unable to patch workflow template: missing or invalid "with" block.</error>');

            return 0;
        }

        return $this->write($contents, $target, $force, $output);
    }

    /**
     * @return array{
     *     workflow: bool,
     *     captainhook: bool,
     *     gitlab_ci: bool,
     *     bitbucket_ci: bool,
     *     forgejo_workflow: bool,
     *     community_templates: bool,
     *     workflow_ref: string,
     *     php_versions: string,
     *     dependency_versions: string,
     *     php_extensions: string,
     *     composer_flags: string,
     *     phpstan_memory_limit: string,
     *     psalm_threads: string,
     *     run_analysis: bool,
     *     run_svg_report: bool,
     *     fail_on_skipped_tests: bool,
     *     enable_redis_service: bool,
     *     enable_valkey_service: bool,
     *     enable_memcached_service: bool,
     *     enable_postgres_service: bool,
     *     enable_mysql_service: bool,
     *     enable_scylladb_service: bool,
     *     enable_elasticsearch_service: bool,
     *     enable_mongodb_service: bool,
     *     service_db_name: string,
     *     service_db_user: string,
     *     service_db_password: string
     * }
     */
    private function defaultSettings(string $workflowRef): array
    {
        return [
            'workflow' => true,
            'captainhook' => true,
            'gitlab_ci' => false,
            'bitbucket_ci' => false,
            'forgejo_workflow' => false,
            'community_templates' => false,
            'workflow_ref' => $workflowRef,
            'php_versions' => '["8.2","8.3","8.4","8.5"]',
            'dependency_versions' => '["prefer-lowest","prefer-stable"]',
            'php_extensions' => $this->detectedPhpExtensions(),
            'composer_flags' => '',
            'phpstan_memory_limit' => '1G',
            'psalm_threads' => '1',
            'run_analysis' => true,
            'run_svg_report' => true,
            'fail_on_skipped_tests' => false,
            'enable_redis_service' => false,
            'enable_valkey_service' => false,
            'enable_memcached_service' => false,
            'enable_postgres_service' => false,
            'enable_mysql_service' => false,
            'enable_scylladb_service' => false,
            'enable_elasticsearch_service' => false,
            'enable_mongodb_service' => false,
            'service_db_name' => 'phpforge',
            'service_db_user' => 'phpforge',
            'service_db_password' => 'phpforge',
        ];
    }

    private function detectedPhpExtensions(): string
    {
        $composerJson = Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson) || !is_readable($composerJson)) {
            return '';
        }

        $contents = file_get_contents($composerJson);

        if (!is_string($contents) || $contents === '') {
            return '';
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return '';
        }

        $extensions = [];

        foreach (['require', 'require-dev', 'suggest'] as $section) {
            $packages = $data[$section] ?? null;

            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $package => $constraint) {
                unset($constraint);

                if (!is_string($package) || !str_starts_with($package, 'ext-')) {
                    continue;
                }

                $extension = substr($package, 4);

                if ($extension === '') {
                    continue;
                }

                $extensions[] = str_replace('-', '_', $extension);
            }
        }

        $extensions = array_values(array_unique($extensions));
        sort($extensions);

        return implode(', ', $extensions);
    }

    private function isCycleSupported(mixed $eol, \DateTimeImmutable $today): bool
    {
        if ($eol === false) {
            return true;
        }

        $eolDate = $this->parseDate($eol);

        if (!$eolDate instanceof \DateTimeImmutable) {
            return false;
        }

        return $eolDate >= $today;
    }

    private function normalizedJsonStringList(string $value, string $name, OutputInterface $output): ?string
    {
        $decoded = json_decode($value, true);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            $output->writeln(sprintf('<error>Invalid %s value. Expected a JSON array string.</error>', $name));

            return null;
        }

        foreach ($decoded as $item) {
            if (!is_string($item) || str_contains($item, "'") || str_contains($item, "\n") || str_contains($item, "\r")) {
                $output->writeln(sprintf('<error>Invalid %s entry. Values must be single-line strings without single quotes.</error>', $name));

                return null;
            }
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            $output->writeln(sprintf('<error>Invalid %s value. Could not encode JSON.</error>', $name));

            return null;
        }

        return $encoded;
    }

    /**
     * @param array<string, bool|string> $settings
     * @return array{
     *     workflow_ref: string,
     *     php_versions: string,
     *     dependency_versions: string,
     *     php_extensions: string,
     *     composer_flags: string,
     *     phpstan_memory_limit: string,
     *     psalm_threads: string,
     *     service_db_name: string,
     *     service_db_user: string,
     *     service_db_password: string
     * }|null
     */
    private function normalizedWorkflowSettings(array $settings, OutputInterface $output): ?array
    {
        $workflowRef = $this->validatedWorkflowRef((string) $settings['workflow_ref'], $output);
        $phpVersions = $this->normalizedJsonStringList((string) $settings['php_versions'], 'php_versions', $output);
        $dependencyVersions = $this->normalizedJsonStringList((string) $settings['dependency_versions'], 'dependency_versions', $output);
        $phpExtensions = $this->singleLineValue((string) $settings['php_extensions'], 'php_extensions', $output);
        $composerFlags = $this->singleLineValue((string) $settings['composer_flags'], 'composer_flags', $output);
        $phpstanMemoryLimit = $this->singleLineValue((string) $settings['phpstan_memory_limit'], 'phpstan_memory_limit', $output);
        $psalmThreads = $this->singleLineValue((string) $settings['psalm_threads'], 'psalm_threads', $output);
        $serviceDbName = $this->singleLineValue((string) $settings['service_db_name'], 'service_db_name', $output);
        $serviceDbUser = $this->singleLineValue((string) $settings['service_db_user'], 'service_db_user', $output);
        $serviceDbPassword = $this->singleLineValue((string) $settings['service_db_password'], 'service_db_password', $output);

        if (
            !is_string($workflowRef)
            || !is_string($phpVersions)
            || !is_string($dependencyVersions)
            || !is_string($phpExtensions)
            || !is_string($composerFlags)
            || !is_string($phpstanMemoryLimit)
            || !is_string($psalmThreads)
            || !is_string($serviceDbName)
            || !is_string($serviceDbUser)
            || !is_string($serviceDbPassword)
        ) {
            return null;
        }

        return [
            'workflow_ref' => $workflowRef,
            'php_versions' => $phpVersions,
            'dependency_versions' => $dependencyVersions,
            'php_extensions' => $phpExtensions,
            'composer_flags' => $composerFlags,
            'phpstan_memory_limit' => $phpstanMemoryLimit,
            'psalm_threads' => $psalmThreads,
            'service_db_name' => $serviceDbName,
            'service_db_user' => $serviceDbUser,
            'service_db_password' => $serviceDbPassword,
        ];
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    /**
     * @return non-empty-array<string, string>
     */
    private function phpVersionPresets(): array
    {
        if (is_array($this->phpVersionPresetsCache)) {
            return $this->phpVersionPresetsCache;
        }

        $supported = $this->supportedPhpVersionsFromApi();

        if ($supported === []) {
            $supported = ['8.2', '8.3', '8.4', '8.5'];
        }

        $current = array_slice($supported, -2);

        if ($current === []) {
            $current = $supported;
        }

        $stable = array_slice($supported, -1);

        if ($stable === []) {
            $stable = $supported;
        }

        $this->phpVersionPresetsCache = [
            'supported' => (string) json_encode($supported, JSON_UNESCAPED_SLASHES),
            'current' => (string) json_encode($current, JSON_UNESCAPED_SLASHES),
            'stable' => (string) json_encode($stable, JSON_UNESCAPED_SLASHES),
        ];

        return $this->phpVersionPresetsCache;
    }

    /**
     * @param array<string, bool> $flags
     */
    private function renderNextSteps(array $flags, OutputInterface $output): void
    {
        $messages = [
            'workflow' => '  - Review and commit .github/workflows/security-standards.yml (parallel CI, SARIF, SVG settings)',
            'gitlab_ci' => '  - Review and commit .gitlab-ci.yml',
            'bitbucket_ci' => '  - Review and commit bitbucket-pipelines.yml',
            'forgejo_workflow' => '  - Review and commit .forgejo/workflows/security-standards.yml',
            'community_templates' => '  - Review and commit CONTRIBUTING.md and .github issue/PR templates',
        ];

        foreach ($messages as $key => $message) {
            if ($flags[$key]) {
                $output->writeln($message);
            }
        }

        if ($flags['captainhook']) {
            $output->writeln('  - Hooks auto-install on the next composer install/update');
            $output->writeln('  - Optional now: composer ic:hooks (install/update immediately)');
        }
    }

    /**
     * @param array<string, bool|string> $settings
     *
     * @return array{
     *     flags: array<string, bool>,
     *     settings: array<string, bool|string>
     * }
     */
    private function resolvedSelection(InputInterface $input, OutputInterface $output, array $settings): array
    {
        $flags = [
            'workflow' => (bool) $input->getOption('workflow'),
            'captainhook' => (bool) $input->getOption('captainhook'),
            'gitlab_ci' => (bool) $input->getOption('gitlab-ci'),
            'bitbucket_ci' => (bool) $input->getOption('bitbucket-ci'),
            'forgejo_workflow' => (bool) $input->getOption('forgejo-workflow'),
            'community_templates' => (bool) $input->getOption('community-templates'),
        ];
        $explicit = in_array(true, $flags, true) || (bool) $input->getOption('no-interaction-defaults');

        if (!$explicit && $input->isInteractive()) {
            $settings = $this->ask($input, $output, $settings);

            foreach (array_keys($flags) as $key) {
                $flags[$key] = (bool) $settings[$key];
            }
        } elseif (!in_array(true, $flags, true)) {
            $flags['workflow'] = true;
            $flags['captainhook'] = true;
        }

        return ['flags' => $flags, 'settings' => $settings];
    }

    private function singleLineValue(string $value, string $name, OutputInterface $output): ?string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            $output->writeln(sprintf('<error>Invalid %s value: newlines are not allowed.</error>', $name));

            return null;
        }

        return $value;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function supportedPhpVersionsFromApi(): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2.0,
                'ignore_errors' => true,
                'user_agent' => 'infocyph/phpforge ic:init',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        set_error_handler(static fn(): bool => true);

        try {
            $payload = file_get_contents(self::END_OF_LIFE_PHP_API, false, $context);
        } finally {
            restore_error_handler();
        }

        if (!is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return [];
        }

        $today = new \DateTimeImmutable('today');
        $versions = [];

        foreach ($decoded as $release) {
            if (!is_array($release)) {
                continue;
            }

            $cycle = $release['cycle'] ?? null;

            if (!is_string($cycle) || preg_match('/^\d+\.\d+$/', $cycle) !== 1) {
                continue;
            }

            if (version_compare($cycle, '8.2', '<')) {
                continue;
            }

            $releaseDate = $this->parseDate($release['releaseDate'] ?? null);

            if ($releaseDate instanceof \DateTimeImmutable && $releaseDate > $today) {
                continue;
            }

            if (!$this->isCycleSupported($release['eol'] ?? null, $today)) {
                continue;
            }

            $versions[] = $cycle;
        }

        $versions = array_values(array_unique($versions));
        usort($versions, static function (string $a, string $b): int {
            if (version_compare($a, $b, '<')) {
                return -1;
            }

            return version_compare($a, $b, '>') ? 1 : 0;
        });

        return $versions;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function target(string $flag): array
    {
        return match ($flag) {
            'captainhook' => [Paths::bundledConfigFile('captainhook.json'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'captainhook.json'],
            'gitlab_ci' => [Paths::packageFile('resources/ci/gitlab-ci.yml'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.gitlab-ci.yml'],
            'bitbucket_ci' => [Paths::packageFile('resources/ci/bitbucket-pipelines.yml'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'bitbucket-pipelines.yml'],
            'forgejo_workflow' => [Paths::packageFile('resources/ci/forgejo-security-standards.yml'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.forgejo' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'security-standards.yml'],
            default => throw new \InvalidArgumentException(sprintf('Unknown init target "%s".', $flag)),
        };
    }

    private function validatedWorkflowRef(string $value, OutputInterface $output): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9._\/-]+$/', $value) !== 1) {
            $output->writeln(sprintf('<error>Invalid workflow_ref "%s". Use a plain git ref (letters, numbers, ".", "_", "-", "/").</error>', $value));

            return null;
        }

        return $value;
    }

    /**
     * @param array{
     *     workflow_ref: string,
     *     php_versions: string,
     *     dependency_versions: string,
     *     php_extensions: string,
     *     composer_flags: string,
     *     phpstan_memory_limit: string,
     *     psalm_threads: string,
     *     service_db_name: string,
     *     service_db_user: string,
     *     service_db_password: string
     * } $workflowSettings
     * @param array<string, bool|string> $settings
     * @return array<string, string>
     */
    private function workflowWrapperValues(array $workflowSettings, array $settings): array
    {
        return [
            'php_versions' => WorkflowWrapper::yamlSingleQuoted($workflowSettings['php_versions']),
            'dependency_versions' => WorkflowWrapper::yamlSingleQuoted($workflowSettings['dependency_versions']),
            'php_extensions' => WorkflowWrapper::yamlDoubleQuoted($workflowSettings['php_extensions']),
            'composer_flags' => WorkflowWrapper::yamlDoubleQuoted($workflowSettings['composer_flags']),
            'phpstan_memory_limit' => WorkflowWrapper::yamlDoubleQuoted($workflowSettings['phpstan_memory_limit']),
            'psalm_threads' => WorkflowWrapper::yamlDoubleQuoted($workflowSettings['psalm_threads']),
            'run_analysis' => $this->boolWorkflowSetting($settings, 'run_analysis'),
            'run_svg_report' => $this->boolWorkflowSetting($settings, 'run_svg_report'),
            'fail_on_skipped_tests' => $this->boolWorkflowSetting($settings, 'fail_on_skipped_tests'),
            'enable_redis_service' => $this->boolWorkflowSetting($settings, 'enable_redis_service'),
            'enable_valkey_service' => $this->boolWorkflowSetting($settings, 'enable_valkey_service'),
            'enable_memcached_service' => $this->boolWorkflowSetting($settings, 'enable_memcached_service'),
            'enable_postgres_service' => $this->boolWorkflowSetting($settings, 'enable_postgres_service'),
            'enable_mysql_service' => $this->boolWorkflowSetting($settings, 'enable_mysql_service'),
            'enable_scylladb_service' => $this->boolWorkflowSetting($settings, 'enable_scylladb_service'),
            'enable_elasticsearch_service' => $this->boolWorkflowSetting($settings, 'enable_elasticsearch_service'),
            'enable_mongodb_service' => $this->boolWorkflowSetting($settings, 'enable_mongodb_service'),
            'service_db_name' => WorkflowWrapper::yamlDoubleQuoted($workflowSettings['service_db_name']),
            'service_db_user' => WorkflowWrapper::yamlDoubleQuoted($workflowSettings['service_db_user']),
            'service_db_password' => WorkflowWrapper::yamlDoubleQuoted($workflowSettings['service_db_password']),
        ];
    }

    private function write(string $contents, string $target, bool $force, OutputInterface $output): int
    {
        if (is_file($target) && !$force) {
            $output->writeln(sprintf('<comment>Skipped existing file: %s</comment>', $target));

            return 0;
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
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
