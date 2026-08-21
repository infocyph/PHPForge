<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\GeminiCommitMessageGenerator;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final class CommitMessageCommand extends Command
{
    private readonly GeminiCommitMessageGenerator $generator;

    public function __construct(
        string $name = 'ic:commit-message',
        ?GeminiCommitMessageGenerator $generator = null,
    ) {
        parent::__construct($name);
        $this->generator = $generator ?? new GeminiCommitMessageGenerator();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate an empty Git commit message from the staged diff with Gemini.')
            ->addArgument('message-file', InputArgument::REQUIRED, 'Git commit message file to populate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $messageFile = $input->getArgument('message-file');

        if (!is_string($messageFile) || !is_file($messageFile) || !is_readable($messageFile) || !is_writable($messageFile)) {
            $output->writeln('<error>The Git commit message file is not readable and writable.</error>');

            return 1;
        }

        $original = file_get_contents($messageFile);

        if (!is_string($original)) {
            $output->writeln('<error>Unable to read the Git commit message file.</error>');

            return 1;
        }

        if ($this->hasMessage($original)) {
            $output->writeln('<comment>Commit message already supplied; Gemini generation skipped.</comment>');

            return 0;
        }

        if (!$this->generator->configured()) {
            $output->writeln('<comment>GEMINI_API_KEY is not set; commit message generation skipped.</comment>');

            return 0;
        }

        try {
            $diff = $this->stagedDiff();

            if (trim($diff) === '') {
                $output->writeln('<comment>No staged diff found; commit message generation skipped.</comment>');

                return 0;
            }

            $message = $this->generator->generate($diff);
            $contents = $message . PHP_EOL;

            if (trim($original) !== '') {
                $contents .= PHP_EOL . ltrim($original);
            }

            if (file_put_contents($messageFile, $contents) === false) {
                $output->writeln('<error>Unable to write the generated Git commit message.</error>');

                return 1;
            }

            $output->writeln(sprintf(
                '<info>Generated commit message with Gemini (%s).</info>',
                getenv('GEMINI_MODEL') ?: GeminiCommitMessageGenerator::DEFAULT_MODEL,
            ));
        } catch (\RuntimeException $exception) {
            $output->writeln(sprintf(
                '<comment>Gemini commit message generation skipped: %s</comment>',
                $exception->getMessage(),
            ));
        }

        return 0;
    }

    private function hasMessage(string $contents): bool
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '#')) {
                return true;
            }
        }

        return false;
    }

    private function stagedDiff(): string
    {
        $process = new Process(
            ['git', 'diff', '--cached', '--no-color', '--no-ext-diff'],
            Paths::projectRootPath(),
        );
        $process->setTimeout(30);

        try {
            $exitCode = $process->run();
        } catch (ProcessStartFailedException $exception) {
            throw new \RuntimeException('Unable to start Git while reading staged changes.', 0, $exception);
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Git could not read staged changes.');
        }

        return $process->getOutput();
    }
}
