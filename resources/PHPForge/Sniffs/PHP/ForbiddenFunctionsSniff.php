<?php

declare(strict_types=1);

namespace PHPForge\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Generic\Sniffs\PHP\ForbiddenFunctionsSniff as GenericForbiddenFunctionsSniff;
use PHP_CodeSniffer\Util\Tokens;

final class ForbiddenFunctionsSniff extends GenericForbiddenFunctionsSniff
{
    #[\Override]
    public function process(File $phpcsFile, int $stackPtr): void
    {
        if ($this->isAllowedExit($phpcsFile, $stackPtr)) {
            return;
        }

        parent::process($phpcsFile, $stackPtr);
    }

    private function isAllowedExit(File $phpcsFile, int $stackPtr): bool
    {
        /** @var array<int, array{code: int|string, type: string, content: string, parenthesis_closer?: mixed}> $tokens */
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$stackPtr]['code'] !== T_EXIT || strtolower($tokens[$stackPtr]['content']) !== 'exit') {
            return false;
        }

        if (str_starts_with($tokens[0]['content'], '#!/usr/bin/env php')) {
            return true;
        }

        $openParenthesis = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, $stackPtr + 1, null, true);

        if ($openParenthesis === false || $tokens[$openParenthesis]['type'] !== 'T_OPEN_PARENTHESIS') {
            return false;
        }

        $openingToken = $tokens[$openParenthesis];

        if (!isset($openingToken['parenthesis_closer'])) {
            return false;
        }

        $closeParenthesis = $openingToken['parenthesis_closer'];

        if (!is_int($closeParenthesis)) {
            return false;
        }

        $argument = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, $openParenthesis + 1, $closeParenthesis, true);

        return $argument !== false
            && $tokens[$argument]['code'] === T_LNUMBER
            && $tokens[$argument]['content'] === '0'
            && $phpcsFile->findNext(Tokens::EMPTY_TOKENS, $argument + 1, $closeParenthesis, true) === false;
    }
}
