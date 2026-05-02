<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

final class CommandProvider implements CommandProviderCapability
{
    private const COMMAND_ROWS = <<<'COMMANDS'
quality|ic:tests|Run the full Infocyph quality suite.|testAll
quality|ic:tests:all|Run the full Infocyph quality suite.|testAll
quality|ic:tests:details|Run the detailed Infocyph quality suite.|testDetails
quality|ic:test:syntax|Check PHP syntax in project paths.|syntax
quality|ic:test:code|Run Pest tests.|testCode
quality|ic:test:lint|Run Pint in check mode.|lintCheck
quality|ic:test:sniff|Run PHP_CodeSniffer.|sniff
quality|ic:test:duplicates|Detect duplicated PHP code.|duplicates
quality|ic:test:architecture|Run Deptrac architecture checks.|architecture
quality|ic:test:static|Run PHPStan.|staticAnalysis
quality|ic:test:security|Run Psalm security analysis.|security
quality|ic:test:refactor|Run Rector in dry-run mode.|refactorCheck
quality|ic:test:bench|Run PHPBench aggregate benchmarks.|benchRun
process|ic:process|Run Composer Normalize, Rector, Pint, and PHPCBF fixes.|processAll
process|ic:process:all|Run Composer Normalize, Rector, Pint, and PHPCBF fixes.|processAll
process|ic:process:lint|Run Pint fixes.|lintFix
process|ic:process:sniff|Run PHPCBF fixes.|sniffFix
process|ic:process:sniff:fix|Run PHPCBF fixes.|sniffFix
process|ic:process:refactor|Run Rector fixes.|refactorFix
process|ic:benchmark|Run PHPBench aggregate benchmarks.|benchRun
process|ic:bench:run|Run PHPBench aggregate benchmarks.|benchRun
process|ic:bench:quick|Run a quick PHPBench aggregate pass.|benchQuick
process|ic:bench:chart|Run PHPBench chart report.|benchChart
release|ic:release:audit|Run Composer audit guard.|releaseAudit
release|ic:release:guard|Run Composer validation, audit, and quality suite.|releaseGuard
release|ic:hooks|Install enabled CaptainHook hooks.|hooks
COMMANDS;

    public function getCommands(): array
    {
        return [
            ...$this->infocyphCommands('quality'),
            new InfocyphCommand('ic:tests:parallel', 'Run the full Infocyph quality suite with bounded parallel checks.', TaskCatalog::testParallel(), true, TaskCatalog::syntax()),
            new CiCommand(),
            new InitCommand(),
            new DoctorCommand(),
            new ListConfigCommand(),
            new PublishConfigCommand(),
            new CleanCommand(),
            new VersionCommand(),
            ...$this->infocyphCommands('process'),
            new PhpstanSarifCommand(),
            ...$this->infocyphCommands('release'),
        ];
    }

    /**
     * @return list<array{string, string, string, string}>
     */
    private function commandRows(): array
    {
        $rows = [];

        foreach (explode("\n", trim(self::COMMAND_ROWS)) as $line) {
            $parts = explode('|', $line, 4);

            if (count($parts) === 4) {
                $rows[] = $parts;
            }
        }

        return $rows;
    }

    /**
     * @return list<InfocyphCommand>
     */
    private function infocyphCommands(string $group): array
    {
        $commands = [];

        foreach ($this->commandRows() as [$rowGroup, $name, $description, $taskMethod]) {
            if ($rowGroup === $group) {
                $commands[] = new InfocyphCommand($name, $description, $this->tasks($taskMethod));
            }
        }

        return $commands;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return list<list<string>>
     */
    private function taskList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $tasks = [];

        foreach ($value as $command) {
            $args = $this->stringList($command);

            if ($args !== []) {
                $tasks[] = $args;
            }
        }

        return $tasks;
    }

    /**
     * @return list<list<string>>
     */
    private function tasks(string $method): array
    {
        if (!method_exists(TaskCatalog::class, $method)) {
            return [];
        }

        $reflection = new \ReflectionMethod(TaskCatalog::class, $method);

        if (!$reflection->isPublic() || !$reflection->isStatic()) {
            return [];
        }

        return $this->taskList($reflection->invoke(null));
    }
}
