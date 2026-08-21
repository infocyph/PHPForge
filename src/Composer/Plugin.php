<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Infocyph\PHPForge\Support\CaptainHook;
use Infocyph\PHPForge\Support\Paths;
use Symfony\Component\Process\Process;

final class Plugin implements Capable, EventSubscriberInterface, PluginInterface
{
    /**
     * @var list<string>
     */
    private const array RECOMMENDED_PLUGINS = [
        'infocyph/phpforge',
        'ergebnis/composer-normalize',
        'pestphp/pest-plugin',
    ];

    private ?IOInterface $io = null;

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'installHooks',
        ];
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- Composer plugin contract.
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
        $this->reportMissingAllowPlugins();
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<class-string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    public function installHooks(Event $event): void
    {
        if (!$event->isDevMode() || !$this->isGitCheckout()) {
            return;
        }

        try {
            $configPath = Paths::config('captainhook.json');

            $process = new Process(CaptainHook::installCommand($configPath), getcwd() ?: null);
            $process->setTimeout(null);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }

            $message = (trim($process->getErrorOutput()) ?: trim($process->getOutput())) ?: 'CaptainHook install failed.';

            throw new \RuntimeException($message);
        } catch (\RuntimeException $exception) {
            if (getenv('IC_HOOKS_STRICT') !== '0') {
                throw $exception;
            }

            $event->getIO()->writeError('<warning>PHPForge could not install CaptainHook hooks; continuing because IC_HOOKS_STRICT=0.</warning>');
            $event->getIO()->writeError($exception->getMessage());
        }
    }

    public function uninstall(Composer $composer, IOInterface $io): void {}

    private function isGitCheckout(): bool
    {
        $gitPath = Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.git';

        return is_dir($gitPath) || is_file($gitPath);
    }

    /**
     * @param array<array-key, mixed> $allowPlugins
     * @return list<string>
     */
    private function missingAllowPlugins(array $allowPlugins): array
    {
        $missing = [];

        foreach (self::RECOMMENDED_PLUGINS as $package) {
            if (($allowPlugins[$package] ?? null) !== true) {
                $missing[] = $package;
            }
        }

        return $missing;
    }

    /**
     * @return array<array-key, mixed>|true|null
     */
    private function readAllowPlugins(): array|true|null
    {
        $data = $this->readComposerJson();

        if ($data === null) {
            return null;
        }

        $config = $data['config'] ?? [];

        if (!is_array($config)) {
            return [];
        }

        $allowPlugins = $config['allow-plugins'] ?? [];

        if ($allowPlugins === true) {
            return true;
        }

        return is_array($allowPlugins) ? $allowPlugins : [];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function readComposerJson(): ?array
    {
        $composerJson = (getcwd() ?: '') . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson) || !is_readable($composerJson)) {
            return null;
        }

        $contents = file_get_contents($composerJson);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    private function reportMissingAllowPlugins(): void
    {
        $io = $this->io;

        if (!$io instanceof IOInterface) {
            return;
        }

        $allowPlugins = $this->readAllowPlugins();

        if ($allowPlugins === null || $allowPlugins === true) {
            return;
        }

        $missing = $this->missingAllowPlugins($allowPlugins);

        if ($missing === []) {
            return;
        }

        $this->writeMissingAllowPlugins($io, $missing);
    }

    /**
     * @param list<string> $missing
     */
    private function writeMissingAllowPlugins(IOInterface $io, array $missing): void
    {
        $io->writeError('<info>PHPForge recommends enabling these Composer plugins:</info>');

        foreach ($missing as $package) {
            $io->writeError(sprintf('  composer config allow-plugins.%s true', $package));
        }
    }
}
