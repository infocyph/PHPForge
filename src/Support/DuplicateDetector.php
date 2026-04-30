<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

final class DuplicateDetector
{
    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        $options = $this->parseArgs($args);

        if ($options['help']) {
            return $this->help();
        }

        $result = (new DuplicateDetectionEngine())->analyze((new PhpFileFinder())->find($options['paths'], $options['excludes']), $options);

        if ($options['baseline'] !== '') {
            $result = $this->withoutBaselineClones($result, $options['baseline']);
        }

        if ($options['writeBaseline'] !== '') {
            $this->writeBaseline($result, $options['writeBaseline']);
        }

        $this->writeResult($result, $options);

        if ($options['writeBaseline'] !== '') {
            return 0;
        }

        return $result['clones'] === [] ? 0 : 1;
    }

    /**
     * @param list<string> $args
     */
    private function configPath(array $args, string $default): string
    {
        for ($index = 0; $index < count($args); $index++) {
            $config = $this->optionValue($args[$index], '--config');

            if ($config !== null) {
                return $config;
            }

            if ($args[$index] === '--config' && isset($args[$index + 1])) {
                return $args[$index + 1];
            }
        }

        return $default;
    }

    /**
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function defaultOptions(): array
    {
        return [
            'help' => false,
            'json' => false,
            'config' => Paths::config('phpforge.json'),
            'mode' => 'gate',
            'normalize' => true,
            'fuzzy' => false,
            'nearMiss' => false,
            'minLines' => 5,
            'minTokens' => 70,
            'minStatements' => 4,
            'minSimilarity' => 0.85,
            'baseline' => '',
            'writeBaseline' => '',
            'paths' => [],
            'excludes' => [],
        ];
    }

    private function help(): int
    {
        fwrite(STDOUT, implode(PHP_EOL, [
            'Usage: phpforge duplicates [options] [paths...]',
            '',
            'Options:',
            '  --mode=gate|audit              gate is deterministic; audit enables structural matching',
            '  --config=FILE                  read PHPForge native checker settings',
            '  --exclude=PATH                 skip a path (repeatable)',
            '  --min-lines=N                  minimum duplicated lines (default: 5)',
            '  --min-tokens=N                 token fingerprint window size (default: 140)',
            '  --min-statements=N             statement window size for audit mode (default: 4)',
            '  --min-similarity=N             near-miss threshold, 0.0-1.0 or 0-100 (default: 0.85)',
            '  --near-miss                    enable bounded statement/shape similarity matching',
            '  --exact                        do not normalize variables/literals',
            '  --fuzzy                        also normalize identifiers/calls',
            '  --baseline=FILE                suppress clone groups already in a baseline',
            '  --write-baseline[=FILE]        write current clone groups to a baseline and exit 0',
            '  --json                         output machine-readable JSON',
        ]) . PHP_EOL);

        return 0;
    }

    /**
     * @return array<string, true>
     */
    private function knownFingerprints(string $baselinePath): array
    {
        if (!is_file($baselinePath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($baselinePath), true);
        $clones = is_array($decoded) ? ($decoded['clones'] ?? []) : [];
        $known = [];

        if (!is_array($clones)) {
            return [];
        }

        foreach ($clones as $clone) {
            if (is_array($clone) && is_string($clone['fingerprint'] ?? null)) {
                $known[$clone['fingerprint']] = true;
            }
        }

        return $known;
    }

    /**
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function normalizeMode(array $options): array
    {
        $options['mode'] = in_array($options['mode'], ['gate', 'audit'], true) ? $options['mode'] : 'gate';

        if ($options['mode'] === 'audit') {
            $options['nearMiss'] = true;
        }

        return $options;
    }

    private function optionValue(string $arg, string $name): ?string
    {
        if (!str_starts_with($arg, $name . '=')) {
            return null;
        }

        return substr($arg, strlen($name) + 1);
    }

    /**
     * @param list<string> $args
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function parseArgs(array $args): array
    {
        $options = $this->defaultOptions();
        $options['config'] = $this->configPath($args, $options['config']);
        $options = PhpForgeConfig::fromFile($options['config'])->applyDuplicateOptions($options);
        $options = $this->normalizeMode($options);

        return $this->parseCliOptions($args, $options, $options['paths']);
    }

    /**
     * @param list<string> $args
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     * @param list<string> $configuredPaths
     * @return array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>}
     */
    private function parseCliOptions(array $args, array $options, array $configuredPaths): array
    {
        $options['paths'] = [];

        for ($index = 0; $index < count($args); $index++) {
            $arg = $args[$index];
            $value = $this->optionValue($arg, '--mode');

            if ($value !== null) {
                $options['mode'] = in_array($value, ['gate', 'audit'], true) ? $value : 'gate';
                $options['nearMiss'] = $options['mode'] === 'audit';

                continue;
            }

            if ($this->skipConfigOption($args, $index, $arg)) {
                continue;
            }

            if ($this->parseExcludeOption($args, $index, $options, $arg)) {
                continue;
            }

            if ($this->parseFlag($options, $arg) || $this->parseNumericOption($options, $arg) || $this->parseFileOption($options, $arg)) {
                continue;
            }

            $options['paths'][] = $arg;
        }

        if ($options['paths'] === []) {
            $options['paths'] = $configuredPaths;
        }

        return $options;
    }

    /**
     * @param list<string> $args
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseExcludeOption(array $args, int &$index, array &$options, string $arg): bool
    {
        $exclude = $this->optionValue($arg, '--exclude');

        if ($exclude !== null) {
            if ($exclude !== '') {
                $options['excludes'][] = $exclude;
            }

            $options['excludes'] = array_values(array_unique($options['excludes']));

            return true;
        }

        if ($arg !== '--exclude') {
            return false;
        }

        if (isset($args[$index + 1]) && $args[$index + 1] !== '') {
            $options['excludes'][] = $args[++$index];
            $options['excludes'] = array_values(array_unique($options['excludes']));
        }

        return true;
    }

    /**
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseFileOption(array &$options, string $arg): bool
    {
        $baseline = $this->optionValue($arg, '--baseline');

        if ($baseline !== null) {
            $options['baseline'] = $baseline;

            return true;
        }

        $writeBaseline = $this->optionValue($arg, '--write-baseline');

        if ($writeBaseline !== null) {
            $options['writeBaseline'] = $writeBaseline !== '' ? $writeBaseline : '.phpforge-duplicates-baseline.json';

            return true;
        }

        if ($arg === '--write-baseline') {
            $options['writeBaseline'] = '.phpforge-duplicates-baseline.json';

            return true;
        }

        return false;
    }

    /**
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseFlag(array &$options, string $arg): bool
    {
        $flagMap = [
            '--help' => 'help',
            '-h' => 'help',
            '--json' => 'json',
            '--fuzzy' => 'fuzzy',
            '--near-miss' => 'nearMiss',
        ];

        if (isset($flagMap[$arg])) {
            $options[$flagMap[$arg]] = true;

            return true;
        }

        if ($arg === '--exact') {
            $options['normalize'] = false;
            $options['fuzzy'] = false;

            return true;
        }

        if ($arg === '--no-fuzzy') {
            $options['fuzzy'] = false;

            return true;
        }

        return false;
    }

    /**
     * @param array{help:bool,json:bool,config:string,mode:string,normalize:bool,fuzzy:bool,nearMiss:bool,minLines:int,minTokens:int,minStatements:int,minSimilarity:float,baseline:string,writeBaseline:string,paths:list<string>,excludes:list<string>} $options
     */
    private function parseNumericOption(array &$options, string $arg): bool
    {
        foreach (['--min-lines' => 'minLines', '--min-tokens' => 'minTokens', '--min-statements' => 'minStatements'] as $name => $key) {
            $value = $this->optionValue($arg, $name);

            if ($value !== null) {
                $options[$key] = max(1, (int) $value);

                return true;
            }
        }

        $similarity = $this->optionValue($arg, '--min-similarity');

        if ($similarity === null) {
            return false;
        }

        $value = (float) $similarity;
        $options['minSimilarity'] = $value > 1.0 ? min(100.0, $value) / 100.0 : max(0.0, min(1.0, $value));

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function skipConfigOption(array $args, int &$index, string $arg): bool
    {
        if ($this->optionValue($arg, '--config') !== null) {
            return true;
        }

        if ($arg !== '--config') {
            return false;
        }

        if (isset($args[$index + 1])) {
            $index++;
        }

        return true;
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @return array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>}
     */
    private function withoutBaselineClones(array $result, string $baselinePath): array
    {
        $known = $this->knownFingerprints($baselinePath);

        if ($known === []) {
            return $result;
        }

        $result['clones'] = array_values(array_filter($result['clones'], static fn(array $clone): bool => !isset($known[$clone['fingerprint']])));
        $result['known_clones'] = count($known);
        $result['new_clones'] = count($result['clones']);

        return $result;
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function writeBaseline(array $result, string $path): void
    {
        $payload = [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'clones' => array_map(static fn(array $clone): array => [
                'fingerprint' => $clone['fingerprint'],
                'source' => $clone['source'],
                'score' => $clone['score'],
            ], $result['clones']),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     * @param array{json:bool,writeBaseline:string} $options
     */
    private function writeResult(array $result, array $options): void
    {
        if ($options['json']) {
            fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return;
        }

        if ($options['writeBaseline'] !== '') {
            fwrite(STDOUT, sprintf('Duplicate baseline written: %s', $options['writeBaseline']) . PHP_EOL);
        }

        if ($result['clones'] === []) {
            fwrite(STDOUT, sprintf('No new duplicated code found (%d PHP files, %d lines checked).', $result['files'], $result['total_lines']) . PHP_EOL);

            return;
        }

        $this->writeTextClones($result);
    }

    /**
     * @param array{files:int,total_lines:int,duplicated_lines:int,duplicate_percentage:float,known_clones:int,new_clones:int,clones:list<array{fingerprint:string,source:string,score:float,similarity:float,tokens:int,lines:int,statements:int,block_type:string,occurrences:list<array{file:string,start_line:int,end_line:int,lines:int,context:string}>}>} $result
     */
    private function writeTextClones(array $result): void
    {
        fwrite(STDERR, sprintf(
            'Found %d clone group(s) with %d duplicated lines in %d PHP files:',
            count($result['clones']),
            $result['duplicated_lines'],
            $result['files'],
        ) . PHP_EOL);

        foreach ($result['clones'] as $clone) {
            $first = $clone['occurrences'][0];
            fwrite(STDERR, sprintf(
                '  - %s:%d-%d (%d lines, %.0f%% similar, %s, score %.1f)',
                $first['file'],
                $first['start_line'],
                $first['end_line'],
                $clone['lines'],
                $clone['similarity'] * 100,
                $clone['source'],
                $clone['score'],
            ) . PHP_EOL);

            foreach (array_slice($clone['occurrences'], 1) as $occurrence) {
                fwrite(STDERR, sprintf('    %s:%d-%d', $occurrence['file'], $occurrence['start_line'], $occurrence['end_line']) . PHP_EOL);
            }
        }

        fwrite(STDERR, sprintf('%.2f%% duplicated lines.', $result['duplicate_percentage']) . PHP_EOL);
    }
}
