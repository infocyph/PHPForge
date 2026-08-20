<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\ConfigInventory;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\ServiceCatalog;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @phpstan-type WorkflowDiagnostics array{path:string,exists:bool,ref:string,inputs:array<string,string>,warnings:list<string>}
 * @phpstan-type Diagnostics array{
 *     project_root:string,
 *     vendor_dir:string,
 *     configs:list<array{file:string,source:string,path:string}>,
 *     plugins:array<string,bool>,
 *     pre_commit_hook:bool,
 *     workflow:WorkflowDiagnostics,
 *     runtime:array{valid:bool,php_versions:list<string>},
 *     service_catalog_warnings:list<string>
 * }
 */
final class DoctorCommand extends Command
{
    private const array EXPECTED_WORKFLOW_INPUTS = [
        'integration_services',
        'service_topologies',
    ];

    private const array PLUGINS = [
        'infocyph/phpforge',
        'ergebnis/composer-normalize',
        'pestphp/pest-plugin',
    ];

    private const string WORKFLOW_PATH = '.github/workflows/security-standards.yml';

    public function __construct()
    {
        parent::__construct('ic:doctor');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show PHPForge environment, config, and hook diagnostics.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $diagnostics = $this->diagnostics();

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->renderDiagnostics($output, $diagnostics);

        return 0;
    }

    private function allowPlugins(): mixed
    {
        $composerJson = Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($composerJson), true);

        if (!is_array($data)) {
            return [];
        }

        $config = $data['config'] ?? [];

        return is_array($config) ? ($config['allow-plugins'] ?? []) : [];
    }

    /** @return Diagnostics */
    private function diagnostics(): array
    {
        $configs = [];

        foreach (ConfigInventory::files() as $file) {
            $configs[] = [
                'file' => $file,
                'source' => ConfigInventory::source($file),
                'path' => ConfigInventory::resolvedPath($file),
            ];
        }

        $allowPlugins = $this->allowPlugins();
        $plugins = [];

        foreach (self::PLUGINS as $plugin) {
            $plugins[$plugin] = $allowPlugins === true || (is_array($allowPlugins) && ($allowPlugins[$plugin] ?? false) === true);
        }

        return [
            'project_root' => Paths::projectRootPath(),
            'vendor_dir' => Paths::vendorDir(),
            'configs' => $configs,
            'plugins' => $plugins,
            'pre_commit_hook' => is_file(Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . 'pre-commit'),
            'workflow' => $this->workflowDiagnostics(),
            'runtime' => $this->runtimeDiagnostics(),
            'service_catalog_warnings' => ServiceCatalog::validateDefinitions(),
        ];
    }

    private function dockerComposeAvailable(): bool
    {
        $path = getenv('PATH');

        if (!is_string($path)) {
            return false;
        }

        return array_any(explode(PATH_SEPARATOR, $path), fn($directory) => is_executable($directory . DIRECTORY_SEPARATOR . 'docker'));
    }

    /** @param list<string> $services */
    private function needsCompose(array $services): bool
    {
        $catalog = ServiceCatalog::all();

        return array_any($services, fn($service) => ($catalog[$service]['external'] ?? false) === true);
    }

    private function normalizeYamlScalar(string $value): string
    {
        $trimmed = trim($value);

        if (
            strlen($trimmed) >= 2
            && (($trimmed[0] === '"' && $trimmed[strlen($trimmed) - 1] === '"') || ($trimmed[0] === '\'' && $trimmed[strlen($trimmed) - 1] === '\''))
        ) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }

    /**
     * @return array{ref: string, inputs: array<string, string>}
     */
    private function parseWorkflowWrapper(string $contents): array
    {
        $lines = preg_split('/\R/', $contents);

        if (!is_array($lines)) {
            return ['ref' => '', 'inputs' => []];
        }

        $ref = '';
        $inputs = [];
        $collectInputs = false;
        $withIndent = -1;

        foreach ($lines as $line) {
            $ref = $ref !== '' ? $ref : $this->workflowRefFromLine($line);

            if (preg_match('/^(\s*)with:\s*$/', $line, $withMatches) === 1) {
                $collectInputs = true;
                $withIndent = strlen($withMatches[1]);

                continue;
            }

            if (!$collectInputs) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $input = $this->workflowInputFromLine($line, $withIndent);

            if ($input === null) {
                $collectInputs = strlen($line) - strlen(ltrim($line, ' ')) > $withIndent;

                continue;
            }

            $inputs[$input['key']] = $input['value'];
        }

        return ['ref' => $ref, 'inputs' => $inputs];
    }

    /** @param Diagnostics $diagnostics */
    private function renderDiagnostics(OutputInterface $output, array $diagnostics): void
    {
        $output->writeln('<info>PHPForge Doctor</info>');
        $output->writeln('===============');
        $output->writeln('Project root: ' . $diagnostics['project_root']);
        $output->writeln('Vendor dir:   ' . $diagnostics['vendor_dir']);
        $output->writeln('');
        $output->writeln(sprintf('<info>Config files (%d)</info>', count($diagnostics['configs'])));

        foreach ($diagnostics['configs'] as $config) {
            $available = $config['source'] !== 'missing';
            $output->writeln(sprintf(
                '  %s %-18s %s',
                $available ? '<info>[OK]</info>' : '<comment>[WARN]</comment>',
                $config['file'],
                $config['source'],
            ));
        }

        $output->writeln('');
        $output->writeln('<info>Composer plugins</info>');

        foreach ($diagnostics['plugins'] as $plugin => $enabled) {
            $output->writeln(sprintf(
                '  %s %-28s %s',
                $enabled ? '<info>[OK]</info>' : '<comment>[WARN]</comment>',
                $plugin,
                $enabled ? 'enabled' : 'not enabled',
            ));
        }

        $this->renderWorkflowDiagnostics($output, $diagnostics);
        $output->writeln(sprintf(
            'Runtime matrix: %s',
            $diagnostics['runtime']['valid'] ? implode(', ', $diagnostics['runtime']['php_versions']) : '<comment>[WARN] invalid</comment>',
        ));

        foreach ($diagnostics['service_catalog_warnings'] as $warning) {
            $output->writeln('  <comment>[WARN]</comment> ' . $warning);
        }
        $this->renderHealthSummary($output, $diagnostics);
    }

    /** @param Diagnostics $diagnostics */
    private function renderHealthSummary(OutputInterface $output, array $diagnostics): void
    {
        [$warningCount, $hasMissingConfig] = $this->warningSummary($diagnostics);

        $output->writeln('');

        if ($warningCount === 0) {
            $output->writeln('<info>Result: healthy</info>');

            return;
        }

        $output->writeln(sprintf('<comment>Result: %d warning(s) need attention</comment>', $warningCount));
        $output->writeln('<info>Suggested actions</info>');

        if ($hasMissingConfig) {
            $output->writeln('  composer install');
        }

        foreach ($diagnostics['plugins'] as $plugin => $enabled) {
            if (!$enabled) {
                $output->writeln(sprintf('  composer config allow-plugins.%s true', $plugin));
            }
        }

        if ($diagnostics['workflow']['warnings'] !== []) {
            $output->writeln('  composer ic:init --workflow --force');
        }
    }

    /** @param Diagnostics $diagnostics */
    private function renderWorkflowDiagnostics(OutputInterface $output, array $diagnostics): void
    {
        $workflow = $diagnostics['workflow'];

        $output->writeln('');
        $output->writeln('<info>Integrations</info>');
        $output->writeln(sprintf(
            '  %s Pre-commit hook  %s',
            $diagnostics['pre_commit_hook'] ? '<info>[OK]</info>' : '[--]',
            $diagnostics['pre_commit_hook'] ? 'installed' : 'not configured (optional)',
        ));
        $output->writeln(sprintf(
            '  %s Workflow wrapper %s',
            $workflow['exists'] ? '<info>[OK]</info>' : '[--]',
            $workflow['exists'] ? 'found' : 'not configured (optional)',
        ));
        $output->writeln('Path:   ' . $workflow['path']);

        if (!$workflow['exists']) {
            return;
        }

        $output->writeln('Ref:    ' . ($workflow['ref'] !== '' ? $workflow['ref'] : '(unknown)'));

        if ($workflow['warnings'] === []) {
            $output->writeln('Validation: OK');

            return;
        }

        $output->writeln('Validation warnings:');

        foreach ($workflow['warnings'] as $warning) {
            $output->writeln('  <comment>[WARN]</comment> ' . $warning);
        }
    }

    /** @return array{valid:bool,php_versions:list<string>} */
    private function runtimeDiagnostics(): array
    {
        $manifest = require Paths::packageFile('resources/runtime.php');
        $versions = is_array($manifest) ? ($manifest['php_versions'] ?? null) : null;

        if (!is_array($versions) || !array_is_list($versions)) {
            return ['valid' => false, 'php_versions' => []];
        }

        $resolved = [];

        foreach ($versions as $version) {
            if (!is_string($version) || preg_match('/^\d+\.\d+$/', $version) !== 1 || in_array($version, $resolved, true)) {
                return ['valid' => false, 'php_versions' => []];
            }

            $resolved[] = $version;
        }

        return ['valid' => $resolved !== [], 'php_versions' => $resolved];
    }

    /** @param WorkflowDiagnostics $result */
    private function validateWorkflowServices(array &$result): void
    {
        $servicesValue = $result['inputs']['integration_services'] ?? null;
        $topologiesValue = $result['inputs']['service_topologies'] ?? null;

        $services = is_string($servicesValue) ? ServiceCatalog::servicesFromJson($servicesValue) : null;

        if ($services === null) {
            $result['warnings'][] = 'integration_services must be a JSON string array.';

            return;
        }

        $topologies = is_string($topologiesValue) ? ServiceCatalog::topologiesFromJson($topologiesValue) : null;

        if ($topologies === null) {
            $result['warnings'][] = 'service_topologies must be a JSON object with string values.';

            return;
        }

        foreach (ServiceCatalog::validate($services, $topologies) as $warning) {
            $result['warnings'][] = $warning;
        }

        foreach (ServiceCatalog::extensions($services) as $extension) {
            if (!extension_loaded($extension)) {
                $result['warnings'][] = sprintf('Required PHP extension is not loaded: %s', $extension);
            }
        }

        if ($this->needsCompose($services) && !$this->dockerComposeAvailable()) {
            $result['warnings'][] = 'Docker Compose is required by the selected external services but is unavailable.';
        }
    }

    /**
     * @param Diagnostics $diagnostics
     * @return array{int,bool}
     */
    private function warningSummary(array $diagnostics): array
    {
        $warningCount = count($diagnostics['workflow']['warnings'])
            + ($diagnostics['runtime']['valid'] ? 0 : 1)
            + count($diagnostics['service_catalog_warnings']);
        $hasMissingConfig = false;

        foreach ($diagnostics['configs'] as $config) {
            if ($config['source'] === 'missing') {
                $warningCount++;
                $hasMissingConfig = true;
            }
        }

        foreach ($diagnostics['plugins'] as $enabled) {
            $warningCount += $enabled ? 0 : 1;
        }

        return [$warningCount, $hasMissingConfig];
    }

    /** @return WorkflowDiagnostics */
    private function workflowDiagnostics(): array
    {
        $workflowPath = Paths::projectRootPath() . DIRECTORY_SEPARATOR . self::WORKFLOW_PATH;
        $result = [
            'path' => $workflowPath,
            'exists' => is_file($workflowPath),
            'ref' => '',
            'inputs' => [],
            'warnings' => [],
        ];

        if (!$result['exists']) {
            return $result;
        }

        $contents = file_get_contents($workflowPath);

        if (!is_string($contents) || $contents === '') {
            $result['warnings'][] = 'Workflow file is empty or unreadable.';

            return $result;
        }

        if (preg_match('/^\s*workflow_call:\s*(?:#.*)?$/m', $contents) === 1) {
            $result['ref'] = 'local';

            return $result;
        }

        $parsed = $this->parseWorkflowWrapper($contents);
        $result['ref'] = $parsed['ref'];
        $result['inputs'] = $parsed['inputs'];

        if ($result['ref'] === '') {
            $result['warnings'][] = 'Unable to detect PHPForge workflow reference in uses: infocyph/phpforge/.github/workflows/security-standards.yml@...';

            return $result;
        }

        $missingInputs = array_values(array_diff(self::EXPECTED_WORKFLOW_INPUTS, array_keys($result['inputs'])));

        if ($missingInputs !== []) {
            $result['warnings'][] = 'Missing workflow inputs: ' . implode(', ', $missingInputs);
        }

        $this->validateWorkflowServices($result);

        return $result;
    }

    /**
     * @return array{key: string, value: string}|null
     */
    private function workflowInputFromLine(string $line, int $withIndent): ?array
    {
        if (preg_match('/^(\s*)([a-z_][a-z0-9_]*):\s*(.*?)\s*$/i', $line, $matches) !== 1) {
            return null;
        }

        if (strlen($matches[1]) <= $withIndent) {
            return null;
        }

        return [
            'key' => $matches[2],
            'value' => $this->normalizeYamlScalar($matches[3]),
        ];
    }

    private function workflowRefFromLine(string $line): string
    {
        if (preg_match('/^\s*uses:\s*infocyph\/phpforge\/\.github\/workflows\/security-standards\.yml@([^\s#]+)\s*$/', $line, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }
}
