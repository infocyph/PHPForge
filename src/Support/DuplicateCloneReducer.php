<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class DuplicateCloneReducer
{
    /**
     * @param list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $clones
     *
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    public function group(array $clones): array
    {
        $groups = [];

        foreach ($clones as $clone) {
            $key = $this->groupKey($clone);
            $groups[$key] = isset($groups[$key]) ? $this->mergeClone($groups[$key], $clone) : $clone;
        }

        return array_values($groups);
    }

    /**
     * @param list<array{file:string,start_line:int,end_line:int,lines:int,context:string}> $occurrences
     *
     * @return array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}
     */
    public function makeClone(string $source, array $occurrences, int $tokens, int $statements, float $similarity): array
    {
        $occurrences = $this->uniqueOccurrences($occurrences);
        $lines = $this->representativeLines($occurrences);
        $blockType = $this->blockType($occurrences);

        return [
            'fingerprint' => $this->fingerprint($source, $occurrences, $similarity),
            'source' => $source,
            'score' => $this->score($source, $occurrences, $tokens, $statements, $similarity, $blockType),
            'similarity' => $similarity,
            'tokens' => $tokens,
            'lines' => $lines,
            'statements' => $statements,
            'block_type' => $blockType,
            'occurrences' => $occurrences,
        ];
    }

    /**
     * @param list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $clones
     *
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    public function pruneContained(array $clones): array
    {
        $selected = [];

        foreach ($this->rank($clones) as $clone) {
            if (!$this->isContainedInAny($clone, $selected)) {
                $selected[] = $clone;
            }
        }

        return $selected;
    }

    /**
     * @param list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $clones
     *
     * @return list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>
     */
    public function rank(array $clones): array
    {
        usort($clones, static fn(array $left, array $right): int => [$right['score'], $right['lines'], $right['similarity']] <=> [$left['score'], $left['lines'], $left['similarity']]);

        return $clones;
    }

    /**
     * @param list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $clones
     */
    public function uniqueDuplicatedLines(array $clones): int
    {
        $lines = [];

        foreach ($clones as $clone) {
            foreach ($clone['occurrences'] as $occurrence) {
                for ($line = $occurrence['start_line']; $line <= $occurrence['end_line']; $line++) {
                    $lines[$occurrence['file'] . ':' . $line] = true;
                }
            }
        }

        return count($lines);
    }

    /**
     * @param list<array{file:string,start_line:int,end_line:int,lines:int,context:string}> $occurrences
     */
    private function blockType(array $occurrences): string
    {
        $types = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence['context'] !== '') {
                $types[$occurrence['context']] = true;
            }
        }

        return count($types) === 1 ? array_key_first($types) : 'mixed';
    }

    /**
     * @param array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>} $left
     * @param array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>} $right
     */
    private function cloneContainedBy(array $left, array $right): bool
    {
        foreach ($left['occurrences'] as $occurrence) {
            if (!$this->occurrenceContainedByAny($occurrence, $right['occurrences'])) {
                return false;
            }
        }

        return $left['score'] <= $right['score'];
    }

    /**
     * @param list<array{file:string,start_line:int,end_line:int,lines:int,context:string}> $occurrences
     */
    private function fingerprint(string $source, array $occurrences, float $similarity): string
    {
        $parts = [$source, sprintf('%.3f', $similarity)];

        foreach ($occurrences as $occurrence) {
            $parts[] = $this->occurrenceKey($occurrence);
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>} $clone
     */
    private function groupKey(array $clone): string
    {
        if ($clone['source'] !== 'near_miss') {
            return $clone['fingerprint'];
        }

        $keys = array_map($this->occurrenceKey(...), $clone['occurrences']);
        sort($keys);

        return hash('sha256', implode('|', $keys));
    }

    /**
     * @param array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>} $clone
     * @param list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}> $selected
     */
    private function isContainedInAny(array $clone, array $selected): bool
    {
        foreach ($selected as $candidate) {
            if ($this->cloneContainedBy($clone, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>} $left
     * @param array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>} $right
     *
     * @return array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}
     */
    private function mergeClone(array $left, array $right): array
    {
        return $this->makeClone(
            $left['score'] >= $right['score'] ? $left['source'] : $right['source'],
            [...$left['occurrences'], ...$right['occurrences']],
            max($left['tokens'], $right['tokens']),
            max($left['statements'], $right['statements']),
            max($left['similarity'], $right['similarity']),
        );
    }

    /**
     * @param array{file:string,start_line:int,end_line:int,lines:int,context:string} $occurrence
     * @param list<array{file:string,start_line:int,end_line:int,lines:int,context:string}> $candidates
     */
    private function occurrenceContainedByAny(array $occurrence, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate['file'] === $occurrence['file'] && $candidate['start_line'] <= $occurrence['start_line'] && $candidate['end_line'] >= $occurrence['end_line']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{file:string,start_line:int,end_line:int,lines:int,context:string} $occurrence
     */
    private function occurrenceKey(array $occurrence): string
    {
        return $occurrence['file'] . ':' . $occurrence['start_line'] . '-' . $occurrence['end_line'];
    }

    /**
     * @param list<array{file:string,start_line:int,end_line:int,lines:int,context:string}> $occurrences
     */
    private function representativeLines(array $occurrences): int
    {
        $lines = array_map(static fn(array $occurrence): int => $occurrence['lines'], $occurrences);

        return $lines === [] ? 0 : min($lines);
    }

    /**
     * @param list<array{file:string,start_line:int,end_line:int,lines:int,context:string}> $occurrences
     */
    private function score(string $source, array $occurrences, int $tokens, int $statements, float $similarity, string $blockType): float
    {
        $score = ($this->representativeLines($occurrences) * 2.0)
            + ($tokens / 5.0)
            + ($statements * 3.0)
            + (count($occurrences) * 10.0)
            + ($similarity * 50.0);

        $score += $blockType !== 'mixed' ? 18.0 : 0.0;
        $score += $source === 'near_miss' ? 8.0 : 0.0;
        $score -= $tokens < 90 && $statements < 6 ? 18.0 : 0.0;

        return round(max(0.0, $score), 2);
    }

    /**
     * @param list<array{file:string,start_line:int,end_line:int,lines:int,context:string}> $occurrences
     *
     * @return list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>
     */
    private function uniqueOccurrences(array $occurrences): array
    {
        $unique = [];

        foreach ($occurrences as $occurrence) {
            $unique[$this->occurrenceKey($occurrence)] = $occurrence;
        }

        $occurrences = array_values($unique);
        usort($occurrences, static fn(array $left, array $right): int => [$left['file'], $left['start_line'], $left['end_line']] <=> [$right['file'], $right['start_line'], $right['end_line']]);

        return $occurrences;
    }
}
