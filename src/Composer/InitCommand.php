<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class InitCommand extends Command
{
    private const string END_OF_LIFE_PHP_API = 'https://endoflife.date/api/php.json';

    /**
     * @var array<string, string>|null
     */
    private ?array $phpVersionPresetsCache = null;

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
            ->addOption('phpforge', null, InputOption::VALUE_NONE, 'Copy the default PHPForge native checker configuration.')
            ->addOption('gitlab-ci', null, InputOption::VALUE_NONE, 'Copy a GitLab CI pipeline.')
            ->addOption('bitbucket-ci', null, InputOption::VALUE_NONE, 'Copy a Bitbucket Pipelines configuration.')
            ->addOption('forgejo-workflow', null, InputOption::VALUE_NONE, 'Copy a Forgejo Actions workflow.')
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

        $output->writeln('  - Run composer ic:tests to validate setup');

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
        $settings['coverage'] = $this->askCoverageDriver($helper, $input, $output, (string) $settings['coverage']);
        $settings['composer_flags'] = $this->askComposerFlags($helper, $input, $output, (string) $settings['composer_flags']);
        $settings['phpstan_memory_limit'] = $this->askPhpstanMemoryLimit($helper, $input, $output, (string) $settings['phpstan_memory_limit']);
        $settings['psalm_threads'] = $this->askPsalmThreads($helper, $input, $output, (string) $settings['psalm_threads']);
        $settings['run_analysis'] = $this->askRunAnalysis($helper, $input, $output, (bool) $settings['run_analysis']);
        $settings['run_svg_report'] = $this->askRunSvgReport($helper, $input, $output, (bool) $settings['run_svg_report']);

        return $settings;
    }

    private function askComposerFlags(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultComposerFlags,
    ): string {
        $composerFlagPresets = [
            'none' => '',
            'with-all-dependencies' => '--with-all-dependencies',
            'ignore-ext-redis' => '--ignore-platform-req=ext-redis',
        ];

        $composerFlagChoiceMap = [
            'none (no extra Composer flags)' => 'none',
            'with-all-dependencies (--with-all-dependencies; update transitive deps as needed)' => 'with-all-dependencies',
            'ignore-ext-redis (--ignore-platform-req=ext-redis; ignore ext-redis platform check)' => 'ignore-ext-redis',
            'custom (enter custom Composer flags)' => 'custom',
        ];

        $composerFlagChoice = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            'Extra Composer flags',
            array_keys($composerFlagChoiceMap),
            'none (no extra Composer flags)',
        )), 'none (no extra Composer flags)');

        $composerFlags = $composerFlagChoiceMap[$composerFlagChoice] ?? 'none';

        $resolvedComposerFlags = $composerFlags === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question('Custom Composer flags: ', $defaultComposerFlags)), $defaultComposerFlags)
            : $composerFlagPresets[$composerFlags];

        $output->writeln(sprintf(
            '<comment>Resolved Composer flags: %s</comment>',
            $resolvedComposerFlags !== '' ? $resolvedComposerFlags : '(none)',
        ));

        return $resolvedComposerFlags;
    }

    private function askCoverageDriver(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultCoverage,
    ): string {
        return $this->stringValue($helper->ask($input, $output, new ChoiceQuestion('Coverage driver', ['none', 'xdebug', 'pcov'], $defaultCoverage)), $defaultCoverage);
    }

    private function askDependencyVersions(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultDependencyVersions,
    ): string {
        $dependencyVersionPresets = [
            'full' => '["prefer-lowest","prefer-stable"]',
            'stable' => '["prefer-stable"]',
        ];

        $dependencyChoiceMap = [
            sprintf('full (%s)', $dependencyVersionPresets['full']) => 'full',
            sprintf('stable (%s)', $dependencyVersionPresets['stable']) => 'stable',
            'custom (enter JSON array string)' => 'custom',
        ];

        $dependencyChoice = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            'Dependency matrix',
            array_keys($dependencyChoiceMap),
            sprintf('full (%s)', $dependencyVersionPresets['full']),
        )), sprintf('full (%s)', $dependencyVersionPresets['full']));

        $dependencyPreset = $dependencyChoiceMap[$dependencyChoice] ?? 'full';

        $resolvedDependencyVersions = $dependencyPreset === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question('Custom dependency versions JSON: ', $defaultDependencyVersions)), $defaultDependencyVersions)
            : $dependencyVersionPresets[$dependencyPreset];

        $output->writeln(sprintf('<comment>Resolved dependency matrix: %s</comment>', $resolvedDependencyVersions));

        return $resolvedDependencyVersions;
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
        $settings['captainhook'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install CaptainHook config? [Y/n] ', true));
        $settings['workflow'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install GitHub Actions workflow wrapper? [Y/n] ', true));
        $settings['phpforge'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install PHPForge native checker config (phpforge.json)? [Y/n] ', true));
        $settings['gitlab_ci'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install GitLab CI pipeline (.gitlab-ci.yml)? [y/N] ', false));
        $settings['bitbucket_ci'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install Bitbucket pipeline (bitbucket-pipelines.yml)? [y/N] ', false));
        $settings['forgejo_workflow'] = (bool) $helper->ask($input, $output, new ConfirmationQuestion('Install Forgejo workflow (.forgejo/workflows/security-standards.yml)? [y/N] ', false));

        return $settings;
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

        $extensionChoiceMap = [
            'none (no extra extensions)' => 'none',
            sprintf('detected (from composer ext-* require/require-dev/suggest: %s)', $detectedExtensionsLabel) => 'detected',
            'common (mbstring, intl, bcmath)' => 'common',
            'mysql (mbstring, intl, bcmath, pdo_mysql)' => 'mysql',
            'pgsql (mbstring, intl, bcmath, pdo_pgsql)' => 'pgsql',
            'mysql+pgsql (mbstring, intl, bcmath, pdo_mysql, pdo_pgsql)' => 'mysql+pgsql',
            'custom (enter comma-separated extension names)' => 'custom',
        ];

        $extensionChoice = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            'PHP extensions',
            array_keys($extensionChoiceMap),
            'none (no extra extensions)',
        )), 'none (no extra extensions)');

        $extensionPreset = $extensionChoiceMap[$extensionChoice] ?? 'none';

        $resolvedExtensions = $extensionPreset === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question('Custom PHP extensions, comma-separated: ', $defaultExtensions)), $defaultExtensions)
            : $phpExtensionPresets[$extensionPreset];

        $output->writeln(sprintf(
            '<comment>Resolved PHP extensions: %s</comment>',
            $resolvedExtensions !== '' ? $resolvedExtensions : '(none)',
        ));

        return $resolvedExtensions;
    }

    private function askPhpstanMemoryLimit(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultPhpstanMemoryLimit,
    ): string {
        $phpstanMemoryLimit = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            'PHPStan memory limit',
            [
                '1G',
                '2G',
                '4G',
                'custom',
            ],
            $defaultPhpstanMemoryLimit,
        )), $defaultPhpstanMemoryLimit);

        return $phpstanMemoryLimit === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question('Custom PHPStan memory limit: ', $defaultPhpstanMemoryLimit)), $defaultPhpstanMemoryLimit)
            : $phpstanMemoryLimit;
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

        $phpVersionChoice = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            'PHP version matrix',
            array_keys($phpVersionChoiceMap),
            sprintf('supported (%s)', $phpVersionPresets['supported'] ?? $defaultPhpVersions),
        )), sprintf('supported (%s)', $phpVersionPresets['supported'] ?? $defaultPhpVersions));

        $phpPreset = $phpVersionChoiceMap[$phpVersionChoice] ?? 'supported';

        $resolvedPhpVersions = $phpPreset === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question('Custom PHP versions JSON: ', $defaultPhpVersions)), $defaultPhpVersions)
            : ($phpVersionPresets[$phpPreset] ?? $defaultPhpVersions);

        $output->writeln(sprintf('<comment>Resolved PHP versions: %s</comment>', $resolvedPhpVersions));

        return $resolvedPhpVersions;
    }

    private function askPsalmThreads(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        string $defaultPsalmThreads,
    ): string {
        $psalmThreads = $this->stringValue($helper->ask($input, $output, new ChoiceQuestion(
            'Psalm threads',
            [
                '1',
                '2',
                '4',
                'custom',
            ],
            $defaultPsalmThreads,
        )), $defaultPsalmThreads);

        return $psalmThreads === 'custom'
            ? $this->stringValue($helper->ask($input, $output, new Question('Custom Psalm thread count: ', $defaultPsalmThreads)), $defaultPsalmThreads)
            : $psalmThreads;
    }

    private function askRunAnalysis(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        bool $defaultRunAnalysis,
    ): bool {
        return (bool) $helper->ask($input, $output, new ConfirmationQuestion('Enable SARIF code-scanning analysis? [Y/n] ', $defaultRunAnalysis));
    }

    private function askRunSvgReport(
        QuestionHelper $helper,
        InputInterface $input,
        OutputInterface $output,
        bool $defaultRunSvgReport,
    ): bool {
        return (bool) $helper->ask($input, $output, new ConfirmationQuestion('Generate SVG security report artifacts? [Y/n] ', $defaultRunSvgReport));
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
     * @param array<string, bool> $flags
     * @param array<string, bool|string> $settings
     */
    private function copySelectedTargets(array $flags, array $settings, bool $force, OutputInterface $output): int
    {
        $targets = [
            'captainhook' => [Paths::bundledConfigFile('captainhook.json'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'captainhook.json'],
            'phpforge' => [Paths::bundledConfigFile('phpforge.json'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'phpforge.json'],
            'gitlab_ci' => [Paths::packageFile('resources/ci/gitlab-ci.yml'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.gitlab-ci.yml'],
            'bitbucket_ci' => [Paths::packageFile('resources/ci/bitbucket-pipelines.yml'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'bitbucket-pipelines.yml'],
            'forgejo_workflow' => [Paths::packageFile('resources/ci/forgejo-security-standards.yml'), Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.forgejo' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'security-standards.yml'],
        ];

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

        foreach ($targets as $flag => [$source, $target]) {
            if ($flags[$flag]) {
                $copied += $this->copy($source, $target, $force, $output);
            }
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

        $contents = str_replace('@main', '@' . $settings['workflow_ref'], $contents);
        $contents = str_replace('php_versions: \'["8.2","8.3","8.4","8.5"]\'', "php_versions: '" . $settings['php_versions'] . "'", $contents);
        $contents = str_replace('dependency_versions: \'["prefer-lowest","prefer-stable"]\'', "dependency_versions: '" . $settings['dependency_versions'] . "'", $contents);
        $contents = str_replace('php_extensions: ""', 'php_extensions: "' . $settings['php_extensions'] . '"', $contents);
        $contents = str_replace('coverage: "none"', 'coverage: "' . $settings['coverage'] . '"', $contents);
        $contents = str_replace('composer_flags: ""', 'composer_flags: "' . $settings['composer_flags'] . '"', $contents);
        $contents = str_replace('phpstan_memory_limit: "1G"', 'phpstan_memory_limit: "' . $settings['phpstan_memory_limit'] . '"', $contents);
        $contents = str_replace('psalm_threads: "1"', 'psalm_threads: "' . $settings['psalm_threads'] . '"', $contents);
        $contents = str_replace('run_analysis: true', 'run_analysis: ' . ($settings['run_analysis'] ? 'true' : 'false'), $contents);
        $contents = str_replace('run_svg_report: true', 'run_svg_report: ' . ($settings['run_svg_report'] ? 'true' : 'false'), $contents);

        return $this->write($contents, $target, $force, $output);
    }

    /**
     * @return array{
     *     workflow: bool,
     *     captainhook: bool,
     *     phpforge: bool,
     *     gitlab_ci: bool,
     *     bitbucket_ci: bool,
     *     forgejo_workflow: bool,
     *     workflow_ref: string,
     *     php_versions: string,
     *     dependency_versions: string,
     *     php_extensions: string,
     *     coverage: string,
     *     composer_flags: string,
     *     phpstan_memory_limit: string,
     *     psalm_threads: string,
     *     run_analysis: bool,
     *     run_svg_report: bool
     * }
     */
    private function defaultSettings(string $workflowRef): array
    {
        return [
            'workflow' => true,
            'captainhook' => true,
            'phpforge' => true,
            'gitlab_ci' => false,
            'bitbucket_ci' => false,
            'forgejo_workflow' => false,
            'workflow_ref' => $workflowRef,
            'php_versions' => '["8.2","8.3","8.4","8.5"]',
            'dependency_versions' => '["prefer-lowest","prefer-stable"]',
            'php_extensions' => '',
            'coverage' => 'none',
            'composer_flags' => '',
            'phpstan_memory_limit' => '1G',
            'psalm_threads' => '1',
            'run_analysis' => true,
            'run_svg_report' => true,
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

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    /**
     * @return array<string, string>
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
            'workflow' => '  - Review and commit .github/workflows/security-standards.yml',
            'phpforge' => '  - Review and commit phpforge.json (syntax/duplicate scan policy)',
            'gitlab_ci' => '  - Review and commit .gitlab-ci.yml',
            'bitbucket_ci' => '  - Review and commit bitbucket-pipelines.yml',
            'forgejo_workflow' => '  - Review and commit .forgejo/workflows/security-standards.yml',
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
            'phpforge' => (bool) $input->getOption('phpforge'),
            'gitlab_ci' => (bool) $input->getOption('gitlab-ci'),
            'bitbucket_ci' => (bool) $input->getOption('bitbucket-ci'),
            'forgejo_workflow' => (bool) $input->getOption('forgejo-workflow'),
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
            $flags['phpforge'] = true;
        }

        return ['flags' => $flags, 'settings' => $settings];
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
