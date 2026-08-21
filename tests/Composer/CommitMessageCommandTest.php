<?php

declare(strict_types=1);

use Infocyph\PHPForge\Composer\CommandProvider;
use Infocyph\PHPForge\Composer\CommitMessageCommand;
use Infocyph\PHPForge\Support\GeminiCommitMessageGenerator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument as Argument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

function removeCommitMessageTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}

/** @return array{exit_code: int, output: string} */
function runCommitMessageCommand(CommitMessageCommand $command, string $messageFile): array
{
    $execute = new ReflectionMethod($command, 'execute');
    $input = new ArrayInput(
        ['message-file' => $messageFile],
        new InputDefinition([new Argument('message-file', Argument::REQUIRED)]),
    );
    $output = new BufferedOutput();

    return [
        'exit_code' => $execute->invoke($command, $input, $output),
        'output' => $output->fetch(),
    ];
}

/** @param list<string> $command */
function runCommitMessageGit(array $command, string $root): void
{
    (new Process($command, $root))->mustRun();
}

function restoreCommitMessageEnvironment(string $name, string|false $value): void
{
    putenv($value === false ? $name : $name . '=' . $value);
}

function geminiResponse(string $message): Closure
{
    return static function (string $url, string $apiKey, string $payload) use ($message): string {
        if ($url === '' || $apiKey === '' || $payload === '') {
            throw new RuntimeException('The Gemini request fixture is incomplete.');
        }

        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => $message]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    };
}

function failingGeminiResponse(string $message): Closure
{
    return static function (string $url, string $apiKey, string $payload) use ($message): never {
        if ($url === '' || $apiKey === '' || $payload === '') {
            throw new RuntimeException('The Gemini request fixture is incomplete.');
        }

        throw new RuntimeException($message);
    };
}

it('publishes the commit message command through the Composer plugin', function (): void {
    $commandNames = array_map(
        static fn(Symfony\Component\Console\Command\Command $command): ?string => $command->getName(),
        (new CommandProvider())->getCommands(),
    );

    expect($commandNames)->toContain('ic:commit-message')
        ->and(GeminiCommitMessageGenerator::DEFAULT_MODEL)->toBe('gemini-flash-lite-latest');
});

it('uses the gitx instruction and staged diff in the Gemini request', function (): void {
    $originalKey = getenv('GEMINI_API_KEY');
    $originalModel = getenv('GEMINI_MODEL');
    $captured = [];
    putenv('GEMINI_API_KEY=test-api-key');
    putenv('GEMINI_MODEL=test-model');

    try {
        $generator = new GeminiCommitMessageGenerator(
            static function (string $url, string $apiKey, string $payload) use (&$captured): string {
                $captured = [
                    'url' => $url,
                    'apiKey' => $apiKey,
                    'payload' => $payload,
                ];

                return '{"candidates":[{"content":{"parts":[{"text":"```text\\n:sparkles: feat(hooks): generate commit messages\\n```"}]}}]}';
            },
        );
        $message = $generator->generate("diff --git a/a.php b/a.php\n+new line\n");
        $payload = json_decode($captured['payload'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $encodedDiff = $payload['contents'][0]['parts'][1]['inline_data']['data'] ?? null;
        $instruction = $payload['system_instruction']['parts'][0]['text'] ?? null;

        expect($message)->toBe(':sparkles: feat(hooks): generate commit messages')
            ->and($captured['url'] ?? null)->toContain('/test-model:generateContent')
            ->and($captured['apiKey'] ?? null)->toBe('test-api-key')
            ->and(is_string($encodedDiff) ? base64_decode($encodedDiff, true) : null)->toContain('diff --git')
            ->and($instruction)->toContain('You are a commit message generator.');
    } finally {
        restoreCommitMessageEnvironment('GEMINI_API_KEY', $originalKey);
        restoreCommitMessageEnvironment('GEMINI_MODEL', $originalModel);
    }
});

it('preserves an existing commit message without calling Gemini', function (): void {
    $originalKey = getenv('GEMINI_API_KEY');
    $messageFile = tempnam(sys_get_temp_dir(), 'phpforge-message-');
    putenv('GEMINI_API_KEY=test-api-key');

    expect($messageFile)->toBeString();
    file_put_contents($messageFile, "fix(core): keep this message\n");

    try {
        $generator = new GeminiCommitMessageGenerator(
            failingGeminiResponse('Gemini must not be called.'),
        );
        $result = runCommitMessageCommand(new CommitMessageCommand(generator: $generator), $messageFile);

        expect($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('already supplied')
            ->and(file_get_contents($messageFile))->toBe("fix(core): keep this message\n");
    } finally {
        restoreCommitMessageEnvironment('GEMINI_API_KEY', $originalKey);
        unlink($messageFile);
    }
});

it('writes a generated message before Git comment lines', function (): void {
    $originalCwd = getcwd();
    $originalKey = getenv('GEMINI_API_KEY');
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-commit-message-' . uniqid('', true);
    mkdir($root, 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'Example.php', "<?php\n");
    runCommitMessageGit(['git', 'init', '--quiet'], $root);
    runCommitMessageGit(['git', 'add', '--', 'Example.php'], $root);
    $messageFile = $root . DIRECTORY_SEPARATOR . 'COMMIT_EDITMSG';
    file_put_contents($messageFile, "\n# Changes to be committed:\n");
    chdir($root);
    putenv('GEMINI_API_KEY=test-api-key');

    try {
        $generator = new GeminiCommitMessageGenerator(
            geminiResponse(':sparkles: feat(hooks): generate commit messages'),
        );
        $result = runCommitMessageCommand(new CommitMessageCommand(generator: $generator), $messageFile);
        $message = file_get_contents($messageFile);

        expect($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('Generated commit message with Gemini')
            ->and($message)->toStartWith(":sparkles: feat(hooks): generate commit messages\n\n")
            ->and($message)->toContain('# Changes to be committed:');
    } finally {
        restoreCommitMessageEnvironment('GEMINI_API_KEY', $originalKey);

        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeCommitMessageTree($root);
    }
});

it('keeps commits usable when Gemini is unavailable', function (): void {
    $originalCwd = getcwd();
    $originalKey = getenv('GEMINI_API_KEY');
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpforge-commit-message-' . uniqid('', true);
    mkdir($root, 0755, true);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/project"}');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'Example.php', "<?php\n");
    runCommitMessageGit(['git', 'init', '--quiet'], $root);
    runCommitMessageGit(['git', 'add', '--', 'Example.php'], $root);
    $messageFile = $root . DIRECTORY_SEPARATOR . 'COMMIT_EDITMSG';
    file_put_contents($messageFile, '');
    chdir($root);
    putenv('GEMINI_API_KEY=test-api-key');

    try {
        $generator = new GeminiCommitMessageGenerator(
            failingGeminiResponse('network unavailable'),
        );
        $result = runCommitMessageCommand(new CommitMessageCommand(generator: $generator), $messageFile);

        expect($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('generation skipped: network unavailable')
            ->and(file_get_contents($messageFile))->toBe('');
    } finally {
        restoreCommitMessageEnvironment('GEMINI_API_KEY', $originalKey);

        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }

        removeCommitMessageTree($root);
    }
});

it('enables the Gemini generator in both CaptainHook configurations', function (): void {
    foreach (['captainhook.json', 'resources/captainhook.json'] as $file) {
        $config = json_decode((string) file_get_contents(__DIR__ . '/../../' . $file), true, 512, JSON_THROW_ON_ERROR);
        $hook = $config['prepare-commit-msg'] ?? null;

        expect($hook['enabled'] ?? null)->toBeTrue()
            ->and($hook['actions'][0]['action'] ?? null)->toBe('composer ic:commit-message "{$ARG|value-of:message-file}"');
    }
});
