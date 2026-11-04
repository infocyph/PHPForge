<?php

declare(strict_types=1);

use Composer\InstalledVersions;

$phpParserRoot = InstalledVersions::getInstallPath('nikic/php-parser');

if (!is_string($phpParserRoot)) {
    return;
}

$phpParserSymbols = [
    \PhpParser\Node::class => 'interface',
    \PhpParser\NodeAbstract::class => 'class',
    \PhpParser\Node\Expr::class => 'class',
    \PhpParser\Node\Expr\Cast::class => 'class',
    \PhpParser\Node\Expr\Cast\Bool_::class => 'class',
    \PhpParser\Node\Expr\Cast\Double::class => 'class',
    \PhpParser\Node\Expr\Cast\Int_::class => 'class',
    \PhpParser\Node\Expr\Cast\String_::class => 'class',
];

foreach ($phpParserSymbols as $symbol => $type) {
    if ($type === 'interface' && interface_exists($symbol)) {
        continue;
    }

    class_exists($symbol);
}
