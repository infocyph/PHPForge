<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\ProcessResult;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final class StageCommand extends Command
{
    private const array COMPOSER_FILES = ['composer.json', 'composer.lock'];

    public function __construct(string $name = 'ic:stage')
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Normalize Composer files, syntax-check selected PHP changes, and stage them.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Files or directories to stage.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paths = $this->stringArguments($input->getArgument('paths'));
        $root = Paths::projectRootPath();

        if ($paths === []) {
            $output->writeln('<error>Provide at least one file or directory to stage.</error>');

            return 2;
        }

        try {
            $this->assertGitRepository($root);
            $before = $this->composerContents($root);
            $normalize = $this->runProcess(['composer', 'normalize'], $root);
            $this->writeProcessOutput($normalize, $output);

            if (!$normalize->successful()) {
                $output->writeln('<error>Composer normalization failed; nothing was staged.</error>');

                return 1;
            }

            $paths = $this->uniquePaths([...$paths, ...$this->changedComposerFiles($root, $before)]);
            $phpFiles = $this->phpFilesToStage($root, $paths);

            foreach ($phpFiles as [$file, $source]) {
                $syntax = $this->runProcess([PHP_BINARY, '-l'], $root, $source);

                if (!$syntax->successful()) {
                    $output->writeln(sprintf('<error>Syntax check failed: %s</error>', $file));
                    $this->writeProcessOutput($syntax, $output);
                    $output->writeln('<error>Nothing was staged.</error>');

                    return 1;
                }
            }

            $stage = $this->runProcess(['git', 'add', '--', ...$paths], $root);

            if (!$stage->successful()) {
                $this->writeProcessOutput($stage, $output);
                $output->writeln('<error>Git could not stage the selected paths.</error>');

                return 1;
            }

            $output->writeln(sprintf('<info>Syntax OK: %d PHP file(s).</info>', count($phpFiles)));
            $output->writeln(sprintf('<info>Staged %d requested/generated path(s).</info>', count($paths)));

            return 0;
        } catch (\RuntimeException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return 1;
        }
    }

    private function assertGitRepository(string $root): void
    {
        $result = $this->runProcess(['git', 'rev-parse', '--is-inside-work-tree'], $root);

        if (!$result->successful() || trim($result->stdout) !== 'true') {
            throw new \RuntimeException('The project root is not inside a Git work tree.');
        }
    }

    /**
     * @param array<string, string|null> $before
     * @return list<string>
     */
    private function changedComposerFiles(string $root, array $before): array
    {
        $changed = [];

        foreach ($this->composerContents($root) as $file => $contents) {
            if (($before[$file] ?? null) !== $contents) {
                $changed[] = $file;
            }
        }

        return $changed;
    }

    /** @return array<string, string|null> */
    private function composerContents(string $root): array
    {
        $contents = [];

        foreach (self::COMPOSER_FILES as $file) {
            $value = is_file($root . DIRECTORY_SEPARATOR . $file)
                ? file_get_contents($root . DIRECTORY_SEPARATOR . $file)
                : false;
            $contents[$file] = is_string($value) ? $value : null;
        }

        return $contents;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function gitFileList(array $command, string $root): array
    {
        $result = $this->runProcess($command, $root);

        if (!$result->successful()) {
            throw new \RuntimeException(trim($result->stderr) ?: 'Git could not inspect the selected paths.');
        }

        return array_values(array_filter(explode("\0", $result->stdout), static fn(string $file): bool => $file !== ''));
    }

    private function isPhpFile(string $file): bool
    {
        return str_ends_with(strtolower($file), '.php');
    }

    /**
     * @param list<string> $paths
     * @return list<array{string, string}>
     */
    private function phpFilesToStage(string $root, array $paths): array
    {
        $worktreeFiles = $this->gitFileList(
            ['git', 'ls-files', '--modified', '--others', '--exclude-standard', '-z', '--', ...$paths],
            $root,
        );
        $stagedFiles = $this->gitFileList(
            ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z', '--', ...$paths],
            $root,
        );
        $sources = [];

        foreach ($stagedFiles as $file) {
            if ($this->isPhpFile($file)) {
                $blob = $this->runProcess(['git', 'cat-file', 'blob', ':./' . $file], $root);

                if (!$blob->successful()) {
                    throw new \RuntimeException(sprintf('Unable to read staged PHP file: %s', $file));
                }

                $sources[$file] = $blob->stdout;
            }
        }

        foreach ($worktreeFiles as $file) {
            if (!$this->isPhpFile($file)) {
                continue;
            }

            $contents = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));

            if (!is_string($contents)) {
                throw new \RuntimeException(sprintf('Unable to read PHP file before staging: %s', $file));
            }

            $sources[$file] = $contents;
        }

        ksort($sources);

        return array_map(
            static fn(string $file, string $source): array => [$file, $source],
            array_keys($sources),
            array_values($sources),
        );
    }

    /** @param list<string> $command */
    private function runProcess(array $command, string $root, string $stdin = ''): ProcessResult
    {
        $process = new Process($command, $root);
        $process->setInput($stdin);

        try {
            $exitCode = $process->run();
        } catch (ProcessStartFailedException $exception) {
            throw new \RuntimeException(sprintf('Unable to start command: %s', $exception->getMessage()), 0, $exception);
        }

        return new ProcessResult($exitCode, $process->getOutput(), $process->getErrorOutput());
    }

    /** @return list<string> */
    private function stringArguments(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function uniquePaths(array $paths): array
    {
        $unique = [];

        foreach ($paths as $path) {
            $unique[$path] = true;
        }

        return array_keys($unique);
    }

    private function writeProcessOutput(ProcessResult $result, OutputInterface $output): void
    {
        if ($result->stdout !== '') {
            $output->write($result->stdout, false, OutputInterface::OUTPUT_RAW);
        }

        if ($result->stderr !== '') {
            $output->write($result->stderr, false, OutputInterface::OUTPUT_RAW);
        }
    }
}
