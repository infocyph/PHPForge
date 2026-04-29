<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

use PhpParser\Node;
use PhpParser\ParserFactory;

final class DuplicateAstBlockIndex
{
    /**
     * @param list<array{value:string,exact:string,line:int,statement:int,shape:string}> $tokens
     *
     * @return list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}>
     */
    public function blocks(string $contents, string $file, array $tokens): array
    {
        try {
            $nodes = (new ParserFactory())->createForHostVersion()->parse($contents);
        } catch (\Throwable) {
            return [];
        }

        if ($nodes === null) {
            return [];
        }

        $lineTokenMap = $this->lineTokenMap($tokens);
        $blocks = [];

        foreach ($nodes as $node) {
            $this->collectBlocks($blocks, $node, $file, $lineTokenMap);
        }

        return $blocks;
    }

    /**
     * @param list<string> $shape
     */
    private function appendShape(array &$shape, mixed $value): void
    {
        if ($value instanceof Node) {
            $shape = [...$shape, ...$this->shape($value)];

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->appendShape($shape, $item);
            }

            return;
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            $shape[] = $this->scalarShape($value);
        }
    }

    /**
     * @param array<int, array{first:int,last:int}> $lineTokenMap
     *
     * @return array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}|null
     */
    private function block(Node $node, string $file, array $lineTokenMap): ?array
    {
        $type = $this->blockType($node);

        if ($type === '') {
            return null;
        }

        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();
        $tokenRange = $this->tokenRange($lineTokenMap, $startLine, $endLine);

        if ($tokenRange === null) {
            return null;
        }

        $statementHashes = $this->statementHashes($node);

        return [
            'id' => hash('sha256', $file . ':' . $startLine . ':' . $endLine . ':' . $node->getType()),
            'type' => $type,
            'file' => $file,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'token_start' => $tokenRange['start'],
            'token_end' => $tokenRange['end'],
            'statement_hashes' => $statementHashes,
            'shape' => $this->shape($node),
        ];
    }

    private function blockType(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\ClassMethod,
            $node instanceof Node\Stmt\Function_,
            $node instanceof Node\Expr\Closure,
            $node instanceof Node\Expr\ArrowFunction => 'function',
            $node instanceof Node\Stmt\For_,
            $node instanceof Node\Stmt\Foreach_,
            $node instanceof Node\Stmt\While_,
            $node instanceof Node\Stmt\Do_ => 'loop',
            $node instanceof Node\Stmt\If_,
            $node instanceof Node\Stmt\ElseIf_,
            $node instanceof Node\Stmt\Else_,
            $node instanceof Node\Stmt\Switch_,
            $node instanceof Node\Expr\Match_,
            $node instanceof Node\MatchArm => 'branch',
            $node instanceof Node\Stmt\TryCatch,
            $node instanceof Node\Stmt\Catch_,
            $node instanceof Node\Stmt\Finally_ => 'exception',
            default => '',
        };
    }

    /**
     * @param list<array{id:string,type:string,file:string,start_line:int,end_line:int,token_start:int,token_end:int,statement_hashes:list<string>,shape:list<string>}> $blocks
     * @param array<int, array{first:int,last:int}> $lineTokenMap
     */
    private function collectBlocks(array &$blocks, Node $node, string $file, array $lineTokenMap): void
    {
        $block = $this->block($node, $file, $lineTokenMap);

        if ($block !== null) {
            $blocks[] = $block;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->collectBlocks($blocks, $value, $file, $lineTokenMap);

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->collectBlocks($blocks, $item, $file, $lineTokenMap);
                    }
                }
            }
        }
    }

    /**
     * @param list<array{value:string,exact:string,line:int,statement:int,shape:string}> $tokens
     *
     * @return array<int, array{first:int,last:int}>
     */
    private function lineTokenMap(array $tokens): array
    {
        $map = [];

        foreach ($tokens as $index => $token) {
            $line = $token['line'];
            $map[$line]['first'] ??= $index;
            $map[$line]['last'] = $index;
        }

        return $map;
    }

    private function scalarShape(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'STR',
            is_int($value), is_float($value) => 'NUM',
            is_bool($value) => 'BOOL',
            $value === null => 'NULL',
            default => 'SCALAR',
        };
    }

    /**
     * @return list<string>
     */
    private function shape(Node $node): array
    {
        $shape = [$this->shapeName($node)];

        foreach ($node->getSubNodeNames() as $name) {
            $this->appendShape($shape, $node->{$name});
        }

        return $shape;
    }

    private function shapeName(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Expr\Variable => 'VAR',
            $node instanceof Node\Scalar\String_ => 'STR',
            $node instanceof Node\Scalar\LNumber,
            $node instanceof Node\Scalar\DNumber => 'NUM',
            $node instanceof Node\Name => 'NAME',
            $node instanceof Node\Identifier => 'IDENTIFIER',
            default => $node->getType(),
        };
    }

    /**
     * @return list<string>
     */
    private function statementHashes(Node $node): array
    {
        $statements = $this->statements($node);

        if ($statements === []) {
            return [hash('sha256', implode("\0", $this->shape($node)))];
        }

        $hashes = [];

        foreach ($statements as $statement) {
            $hashes[] = hash('sha256', implode("\0", $this->shape($statement)));
        }

        return $hashes;
    }

    /**
     * @return list<Node>
     */
    private function statements(Node $node): array
    {
        $statements = [];

        foreach (['stmts', 'elseifs', 'uses', 'catches', 'arms'] as $name) {
            $value = $node->{$name} ?? null;

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if ($item instanceof Node) {
                    $statements[] = $item;
                }
            }
        }

        if ($node instanceof Node\Expr\ArrowFunction) {
            $statements[] = $node->expr;
        }

        return $statements;
    }

    /**
     * @param array<int, array{first:int,last:int}> $lineTokenMap
     *
     * @return array{start:int,end:int}|null
     */
    private function tokenRange(array $lineTokenMap, int $startLine, int $endLine): ?array
    {
        $start = null;
        $end = null;

        for ($line = $startLine; $line <= $endLine; $line++) {
            if (!isset($lineTokenMap[$line])) {
                continue;
            }

            $start ??= $lineTokenMap[$line]['first'];
            $end = $lineTokenMap[$line]['last'];
        }

        return is_int($start) && is_int($end) ? ['start' => $start, 'end' => $end] : null;
    }
}
