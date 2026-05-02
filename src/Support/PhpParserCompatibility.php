<?php

declare(strict_types=1);

use Composer\InstalledVersions;

$phpParserRoot = InstalledVersions::getInstallPath('nikic/php-parser');

if (!is_string($phpParserRoot)) {
    return;
}

$phpParserFiles = [
    [\PhpParser\Node::class, $phpParserRoot . '/lib/PhpParser/Node.php', 'interface'],
    [\PhpParser\NodeAbstract::class, $phpParserRoot . '/lib/PhpParser/NodeAbstract.php', 'class'],
    [\PhpParser\Node\Expr::class, $phpParserRoot . '/lib/PhpParser/Node/Expr.php', 'class'],
    [\PhpParser\Node\Expr\Cast::class, $phpParserRoot . '/lib/PhpParser/Node/Expr/Cast.php', 'class'],
    [\PhpParser\Node\Expr\Cast\Bool_::class, $phpParserRoot . '/lib/PhpParser/Node/Expr/Cast/Bool_.php', 'class'],
    [\PhpParser\Node\Expr\Cast\Double::class, $phpParserRoot . '/lib/PhpParser/Node/Expr/Cast/Double.php', 'class'],
    [\PhpParser\Node\Expr\Cast\Int_::class, $phpParserRoot . '/lib/PhpParser/Node/Expr/Cast/Int_.php', 'class'],
    [\PhpParser\Node\Expr\Cast\String_::class, $phpParserRoot . '/lib/PhpParser/Node/Expr/Cast/String_.php', 'class'],
];

foreach ($phpParserFiles as [$symbol, $file, $type]) {
    $loaded = $type === 'interface'
        ? interface_exists($symbol, false)
        : class_exists($symbol, false);

    if (!$loaded && is_file($file)) {
        require_once $file;
    }
}
