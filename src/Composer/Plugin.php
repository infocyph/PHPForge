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

    public function activate(Composer $composer, IOInterface $io): void
    {
        unset($composer);

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
        try {
            $configPath = $this->ensureProjectCaptainHookConfig();

            if (!is_string($configPath)) {
                return;
            }

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

    private function ensureProjectCaptainHookConfig(): ?string
    {
        $projectConfig = Paths::projectRootPath() . DIRECTORY_SEPARATOR . 'captainhook.json';

        if (is_file($projectConfig)) {
            return $projectConfig;
        }

        $bundledConfig = Paths::bundledConfigFileOrNull('captainhook.json');

        if (!is_string($bundledConfig) || !is_file($bundledConfig)) {
            return null;
        }

        if (!copy($bundledConfig, $projectConfig)) {
            throw new \RuntimeException(sprintf(
                'Failed to copy bundled CaptainHook config from "%s" to "%s".',
                $bundledConfig,
                $projectConfig,
            ));
        }

        return $projectConfig;
    }

    private function reportMissingAllowPlugins(): void
    {
        $composerJson = (getcwd() ?: '') . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJson) || !is_readable($composerJson)) {
            return;
        }

        $contents = file_get_contents($composerJson);

        if (!is_string($contents) || $contents === '') {
            return;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return;
        }

        $config = $data['config'] ?? [];

        if (!is_array($config)) {
            $config = [];
        }

        $allowPlugins = $config['allow-plugins'] ?? [];

        if ($allowPlugins === true) {
            return;
        }

        if (!is_array($allowPlugins)) {
            $allowPlugins = [];
        }

        $wanted = [
            'infocyph/phpforge',
            'pestphp/pest-plugin',
        ];

        $missing = [];

        foreach ($wanted as $package) {
            if (($allowPlugins[$package] ?? null) !== true) {
                $missing[] = $package;
            }
        }

        if ($missing === [] || !$this->io instanceof IOInterface) {
            return;
        }

        $this->io->writeError('<info>PHPForge recommends enabling these Composer plugins:</info>');

        foreach ($missing as $package) {
            $this->io->writeError(sprintf('  composer config allow-plugins.%s true', $package));
        }
    }
}
