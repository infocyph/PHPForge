<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use Symfony\Component\Console\Output\OutputInterface;

final class FilePublisher
{
    /**
     * @param array{
     *     missing: string,
     *     skipped: string,
     *     unreadable: string,
     *     unwritable: string,
     *     published: string
     * } $messages
     * @param null|callable(string): ?string $transform
     */
    public static function publish(
        string $source,
        string $target,
        string $label,
        bool $force,
        OutputInterface $output,
        array $messages,
        ?callable $transform = null,
    ): bool {
        if (!is_file($source)) {
            $output->writeln(sprintf($messages['missing'], $label));

            return false;
        }

        if (is_file($target) && !$force) {
            $output->writeln(sprintf($messages['skipped'], $label));

            return false;
        }

        $contents = file_get_contents($source);

        if (!is_string($contents)) {
            $output->writeln(sprintf($messages['unreadable'], $label));

            return false;
        }

        if (is_callable($transform)) {
            $contents = $transform($contents);

            if (!is_string($contents)) {
                return false;
            }
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Unable to create directory: %s</error>', $directory));

            return false;
        }

        if (file_put_contents($target, $contents) === false) {
            $output->writeln(sprintf($messages['unwritable'], $label));

            return false;
        }

        $output->writeln(sprintf($messages['published'], $label));

        return true;
    }
}
