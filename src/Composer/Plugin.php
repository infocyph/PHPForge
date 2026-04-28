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
        $configPath = Paths::config('captainhook.json');

        if (!is_file($configPath)) {
            return;
        }

        $process = new Process(CaptainHook::installCommand($configPath), getcwd() ?: null);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'CaptainHook install failed.';

            if (getenv('IC_HOOKS_STRICT') !== '0') {
                throw new \RuntimeException($message);
            }

            $event->getIO()->writeError('<warning>PHPForge could not install CaptainHook hooks; continuing because IC_HOOKS_STRICT=0.</warning>');
            $event->getIO()->writeError($message);
        }
    }

    public function uninstall(Composer $composer, IOInterface $io): void {}

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
