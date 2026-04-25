<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\ConfigInventory;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    private const EXPECTED_WORKFLOW_INPUTS = [
        'php_versions',
        'dependency_versions',
        'php_extensions',
        'coverage',
        'composer_flags',
        'phpstan_memory_limit',
        'psalm_threads',
        'run_analysis',
    ];

    private const PLUGINS = [
        'infocyph/phpforge',
        'pestphp/pest-plugin',
    ];

    private const WORKFLOW_PATH = '.github/workflows/security-standards.yml';

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

    /**
     * @return array{
     *     project_root: string,
     *     vendor_dir: string,
     *     configs: list<array{file: string, source: string, path: string}>,
     *     plugins: array<string, bool>,
     *     pre_commit_hook: bool,
     *     workflow: array{
     *         path: string,
     *         exists: bool,
     *         ref: string,
     *         inputs: array<string, string>,
     *         warnings: list<string>
     *     }
     * }
     */
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
        ];
    }

    private function isValidJsonStringArray(string $value): bool
    {
        $decoded = json_decode($value, true);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return false;
        }

        foreach ($decoded as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
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
            if (
                $ref === ''
                && preg_match('/^\s*uses:\s*infocyph\/phpforge\/\.github\/workflows\/security-standards\.yml@([^\s#]+)\s*$/', $line, $matches) === 1
            ) {
                $ref = $matches[1];
            }

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

            if (preg_match('/^(\s*)([a-z_][a-z0-9_]*):\s*(.*?)\s*$/i', $line, $matches) !== 1) {
                $indent = strlen($line) - strlen(ltrim($line, ' '));

                if ($indent <= $withIndent) {
                    $collectInputs = false;
                }

                continue;
            }

            $indent = strlen($matches[1]);

            if ($indent <= $withIndent) {
                $collectInputs = false;

                continue;
            }

            $key = $matches[2];
            $value = $this->normalizeYamlScalar($matches[3]);
            $inputs[$key] = $value;
        }

        return ['ref' => $ref, 'inputs' => $inputs];
    }

    /**
     * @param array{
     *     project_root: string,
     *     vendor_dir: string,
     *     configs: list<array{file: string, source: string, path: string}>,
     *     plugins: array<string, bool>,
     *     pre_commit_hook: bool,
     *     workflow: array{
     *         path: string,
     *         exists: bool,
     *         ref: string,
     *         inputs: array<string, string>,
     *         warnings: list<string>
     *     }
     * } $diagnostics
     */
    private function renderDiagnostics(OutputInterface $output, array $diagnostics): void
    {
        $output->writeln('<info>PHPForge Doctor</info>');
        $output->writeln('Project root: ' . $diagnostics['project_root']);
        $output->writeln('Vendor dir:   ' . $diagnostics['vendor_dir']);
        $output->writeln('');
        $output->writeln('<info>Config files</info>');

        foreach ($diagnostics['configs'] as $config) {
            $output->writeln(sprintf('  %-18s %s', $config['file'], $config['source']));
        }

        $output->writeln('');
        $output->writeln('<info>Composer plugins</info>');

        foreach ($diagnostics['plugins'] as $plugin => $enabled) {
            $output->writeln(sprintf('  %-24s %s', $plugin, $enabled ? 'enabled' : 'not enabled'));
        }

        $this->renderWorkflowDiagnostics($output, $diagnostics);
    }

    /**
     * @param array{
     *     pre_commit_hook: bool,
     *     workflow: array{
     *         path: string,
     *         exists: bool,
     *         ref: string,
     *         inputs: array<string, string>,
     *         warnings: list<string>
     *     }
     * } $diagnostics
     */
    private function renderWorkflowDiagnostics(OutputInterface $output, array $diagnostics): void
    {
        $workflow = $diagnostics['workflow'];

        $output->writeln('');
        $output->writeln('Pre-commit hook: ' . ($diagnostics['pre_commit_hook'] ? 'installed' : 'not installed'));
        $output->writeln('');
        $output->writeln('<info>Workflow wrapper</info>');
        $output->writeln('Path:   ' . $workflow['path']);
        $output->writeln('Status: ' . ($workflow['exists'] ? 'found' : 'not found'));

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
            $output->writeln('  - ' . $warning);
        }
    }

    private function validateComposerFlags(string $value): ?string
    {
        $flags = trim($value);

        if ($flags === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', $flags);

        if (!is_array($tokens)) {
            return null;
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (!str_starts_with($token, '--')) {
                return sprintf(
                    'composer_flags should be empty or a space-separated list of --flags, got: %s',
                    $value,
                );
            }
        }

        return null;
    }

    /**
     * @return array{
     *     path: string,
     *     exists: bool,
     *     ref: string,
     *     inputs: array<string, string>,
     *     warnings: list<string>
     * }
     */
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

        foreach (['php_versions', 'dependency_versions'] as $matrixInput) {
            if (!array_key_exists($matrixInput, $result['inputs'])) {
                continue;
            }

            if (!$this->isValidJsonStringArray($result['inputs'][$matrixInput])) {
                $result['warnings'][] = sprintf(
                    '%s must be a JSON array string, got: %s',
                    $matrixInput,
                    $result['inputs'][$matrixInput],
                );
            }
        }

        if (array_key_exists('composer_flags', $result['inputs'])) {
            $flagsWarning = $this->validateComposerFlags($result['inputs']['composer_flags']);

            if ($flagsWarning !== null) {
                $result['warnings'][] = $flagsWarning;
            }
        }

        return $result;
    }
}
