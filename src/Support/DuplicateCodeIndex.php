<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class DuplicateCodeIndex
{
    /**
     * @param list<string> $files
     * @param array{normalize:bool,fuzzy:bool} $options
     * @return array{streams: array<string, list<array{value:string,exact:string,line:int,statement:int,shape:string}>>, blocks: array<string, list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>>, total_lines: int}
     */
    public function build(array $files, array $options): array
    {
        $streams = [];
        $blocks = [];
        $totalLines = 0;
        $ast = new DuplicateAstBlockIndex();

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if (!is_string($contents)) {
                continue;
            }

            $totalLines += $this->lineCount($contents);
            $tokens = $this->tokenize($contents, $options['normalize'], $options['fuzzy']);
            $streams[$file] = $tokens;
            $blocks[$file] = $ast->blocks($contents, $file, $tokens);
        }

        return ['streams' => $streams, 'blocks' => $blocks, 'total_lines' => $totalLines];
    }

    private function isIdentifierToken(int $id): bool
    {
        static $ids = null;

        if (!is_array($ids)) {
            $ids = [T_STRING];

            foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $tokenName) {
                if (defined($tokenName)) {
                    $ids[] = (int) constant($tokenName);
                }
            }
        }

        return in_array($id, $ids, true);
    }

    private function lineCount(string $contents): int
    {
        return $contents === '' ? 0 : substr_count($contents, "\n") + 1;
    }

    /**
     * @param array{0:int,1:string,2:int}|string $rawToken
     * @return array{value:string,exact:string,line:int,statement:int,shape:string}|null
     */
    private function normalizedToken(array|string $rawToken, int &$currentLine, bool $normalize, bool $fuzzy): ?array
    {
        if (is_string($rawToken)) {
            return $this->symbolToken($rawToken, $currentLine);
        }

        [$id, $text, $line] = $rawToken;
        $currentLine = $line + substr_count($text, "\n");

        if (in_array($id, [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return null;
        }

        return [
            'value' => $normalize ? $this->normalizeToken($id, $text, $fuzzy) : token_name($id) . ':' . $text,
            'exact' => token_name($id) . ':' . $text,
            'line' => $line,
            'statement' => 0,
            'shape' => '',
        ];
    }

    private function normalizeToken(int $id, string $text, bool $fuzzy): string
    {
        if ($this->isIdentifierToken($id)) {
            return $fuzzy ? 'ID' : token_name($id) . ':' . strtolower($text);
        }

        return match ($id) {
            T_VARIABLE => 'VAR',
            T_LNUMBER, T_DNUMBER => 'NUM',
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => 'STR',
            default => token_name($id) . ':' . strtolower($text),
        };
    }

    /**
     * @return array{value:string,exact:string,line:int,statement:int,shape:string}
     */
    private function symbolToken(string $symbol, int &$currentLine): array
    {
        $token = [
            'value' => $symbol,
            'exact' => $symbol,
            'line' => $currentLine,
            'statement' => 0,
            'shape' => '',
        ];

        $currentLine += substr_count($symbol, "\n");

        return $token;
    }

    /**
     * @return list<array{value:string,exact:string,line:int,statement:int,shape:string}>
     */
    private function tokenize(string $contents, bool $normalize, bool $fuzzy): array
    {
        $tokens = [];
        $statement = 0;
        $currentLine = 1;

        foreach (token_get_all($contents) as $rawToken) {
            $token = $this->normalizedToken($rawToken, $currentLine, $normalize, $fuzzy);

            if ($token === null) {
                continue;
            }

            $token['statement'] = $statement;
            $tokens[] = $token;

            if (in_array($token['exact'], [';', '{', '}'], true)) {
                $statement++;
            }
        }

        return $tokens;
    }
}
