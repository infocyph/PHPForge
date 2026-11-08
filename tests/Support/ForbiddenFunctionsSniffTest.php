<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\PHPForge\Support\Paths;
use PHP_CodeSniffer\Files\File;
use PHPForge\Sniffs\PHP\ForbiddenFunctionsSniff;
use PhpParser\ParserFactory;

it('accepts successful exits without breaking the PHP parser', function (): void {
    // Initialize integer token polyfills before PHPCS installs its legacy token emulation.
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $installation = InstalledVersions::getInstallPath('squizlabs/php_codesniffer');

    if ($installation === null) {
        throw new RuntimeException('The PHPCS installation is required for sniff tests.');
    }

    $autoload = $installation . '/autoload.php';

    if (!is_file($autoload)) {
        throw new RuntimeException('The PHPCS autoloader is missing.');
    }

    require_once $autoload;
    Paths::requirePackageFile('resources/PHPForge/Sniffs/PHP/ForbiddenFunctionsSniff.php');

    $file = $this->createMock(File::class);
    $file->method('getTokens')->willReturn([
        ['code' => T_OPEN_TAG, 'type' => 'T_OPEN_TAG', 'content' => '<?php'],
        ['code' => T_EXIT, 'type' => 'T_EXIT', 'content' => 'exit'],
        ['code' => 'PHPCS_T_OPEN_PARENTHESIS', 'type' => 'T_OPEN_PARENTHESIS', 'content' => '(', 'parenthesis_closer' => 4],
        ['code' => T_LNUMBER, 'type' => 'T_LNUMBER', 'content' => '0'],
        ['code' => 'PHPCS_T_CLOSE_PARENTHESIS', 'type' => 'T_CLOSE_PARENTHESIS', 'content' => ')'],
    ]);
    $file->method('findNext')->willReturnOnConsecutiveCalls(2, 3, false);
    $file->expects($this->never())->method('addError');

    (new ForbiddenFunctionsSniff())->process($file, 1);

    expect($parser->parse('<?php return 1;'))->not->toBeEmpty();
});
