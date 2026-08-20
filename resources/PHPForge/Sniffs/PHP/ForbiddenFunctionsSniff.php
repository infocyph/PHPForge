<?php

declare(strict_types=1);

namespace PHPForge\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Generic\Sniffs\PHP\ForbiddenFunctionsSniff as GenericForbiddenFunctionsSniff;

final class ForbiddenFunctionsSniff extends GenericForbiddenFunctionsSniff
{
    public function process(File $phpcsFile, int $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$stackPtr]['code'] === T_EXIT && str_starts_with($tokens[0]['content'], '#!/usr/bin/env php')) {
            return;
        }

        parent::process($phpcsFile, $stackPtr);
    }
}
