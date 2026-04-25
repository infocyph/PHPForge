<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

final class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [
            new InfocyphCommand('ic:tests', 'Run the full Infocyph quality suite.', TaskCatalog::testAll()),
            new InfocyphCommand('ic:tests:all', 'Run the full Infocyph quality suite.', TaskCatalog::testAll()),
            new InfocyphCommand('ic:tests:details', 'Run the detailed Infocyph quality suite.', TaskCatalog::testDetails()),
            new InfocyphCommand('ic:test:syntax', 'Check PHP syntax in project paths.', TaskCatalog::syntax()),
            new InfocyphCommand('ic:test:code', 'Run Pest tests.', TaskCatalog::testCode()),
            new InfocyphCommand('ic:test:lint', 'Run Pint in check mode.', TaskCatalog::lintCheck()),
            new InfocyphCommand('ic:test:sniff', 'Run PHP_CodeSniffer.', TaskCatalog::sniff()),
            new InfocyphCommand('ic:test:static', 'Run PHPStan.', TaskCatalog::staticAnalysis()),
            new InfocyphCommand('ic:test:security', 'Run Psalm security analysis.', TaskCatalog::security()),
            new InfocyphCommand('ic:test:refactor', 'Run Rector in dry-run mode.', TaskCatalog::refactorCheck()),
            new InfocyphCommand('ic:test:bench', 'Run PHPBench aggregate benchmarks.', TaskCatalog::benchRun()),
            new CiCommand(),
            new InitCommand(),
            new DoctorCommand(),
            new ListConfigCommand(),
            new PublishConfigCommand(),
            new CleanCommand(),
            new VersionCommand(),
            new InfocyphCommand('ic:process', 'Run Composer Normalize, Rector, Pint, and PHPCBF fixes.', TaskCatalog::processAll()),
            new InfocyphCommand('ic:process:all', 'Run Composer Normalize, Rector, Pint, and PHPCBF fixes.', TaskCatalog::processAll()),
            new InfocyphCommand('ic:process:lint', 'Run Pint fixes.', TaskCatalog::lintFix()),
            new InfocyphCommand('ic:process:sniff', 'Run PHPCBF fixes.', TaskCatalog::sniffFix()),
            new InfocyphCommand('ic:process:sniff:fix', 'Run PHPCBF fixes.', TaskCatalog::sniffFix()),
            new InfocyphCommand('ic:process:refactor', 'Run Rector fixes.', TaskCatalog::refactorFix()),
            new InfocyphCommand('ic:benchmark', 'Run PHPBench aggregate benchmarks.', TaskCatalog::benchRun()),
            new InfocyphCommand('ic:bench:run', 'Run PHPBench aggregate benchmarks.', TaskCatalog::benchRun()),
            new InfocyphCommand('ic:bench:quick', 'Run a quick PHPBench aggregate pass.', TaskCatalog::benchQuick()),
            new InfocyphCommand('ic:bench:chart', 'Run PHPBench chart report.', TaskCatalog::benchChart()),
            new PhpstanSarifCommand(),
            new InfocyphCommand('ic:release:audit', 'Run Composer audit guard.', TaskCatalog::releaseAudit()),
            new InfocyphCommand('ic:release:guard', 'Run Composer validation, audit, and quality suite.', TaskCatalog::releaseGuard()),
            new InfocyphCommand('ic:hooks', 'Install enabled CaptainHook hooks.', TaskCatalog::hooks()),
        ];
    }
}
