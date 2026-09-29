<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function releaseGit(string $root, string ...$arguments): void
{
    (new Process(['git', ...$arguments], $root))->mustRun();
}

function releaseCommit(string $root, string $contents, string $message): void
{
    file_put_contents($root . '/library.php', $contents);
    releaseGit($root, 'add', 'library.php');
    releaseGit($root, 'commit', '-qm', $message);
}

beforeEach(function (): void {
    $this->releaseRoot = sys_get_temp_dir() . '/phpforge-release-test-' . uniqid('', true);
    mkdir($this->releaseRoot . '/bin', 0755, true);
    mkdir($this->releaseRoot . '/scratch');
    releaseGit($this->releaseRoot, 'init', '-q');
    releaseGit($this->releaseRoot, 'config', 'user.name', 'Release Test');
    releaseGit($this->releaseRoot, 'config', 'user.email', 'release@example.test');
    file_put_contents($this->releaseRoot . '/instructions.md', 'Write library release notes.');
    file_put_contents($this->releaseRoot . '/bin/gh', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$1" == api ]]; then
  case "${TEST_API_MODE:-missing}" in
    exists) echo '{"tag_name":"existing"}'; exit 0 ;;
    error) echo 'gh: Forbidden (HTTP 403)' >&2; exit 1 ;;
    *) echo 'gh: Not Found (HTTP 404)' >&2; exit 1 ;;
  esac
fi
printf '%s\n' "$@" > "$TEST_ROOT/publish-args.txt"
while (( $# > 0 )); do
  if [[ "$1" == --notes-file ]]; then
    cp "$2" "$TEST_ROOT/published-notes.md"
    break
  fi
  shift
done
BASH);
    file_put_contents($this->releaseRoot . '/bin/copilot', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
cat > "$TEST_ROOT/prompt.md"
printf '%s\n' "$@" > "$TEST_ROOT/copilot-args.txt"
if [[ -n "${GH_TOKEN:-}${GITHUB_TOKEN:-}" ]]; then
  echo 'Publishing token reached Copilot' >&2
  exit 1
fi
case "${TEST_COPILOT_MODE:-ok}" in
  error) exit 1 ;;
  empty) printf ' \n'; exit 0 ;;
  *) printf '## Improvements\n\n- Preserve literal `API` and $values.\n' ;;
esac
BASH);
    chmod($this->releaseRoot . '/bin/gh', 0755);
    chmod($this->releaseRoot . '/bin/copilot', 0755);
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->releaseRoot]))->mustRun();
});

/** @param array<string, string> $overrides */
function runReleaseScript(string $root, string $tag, array $overrides = []): Process
{
    $process = new Process(
        ['bash', dirname(__DIR__, 2) . '/.github/scripts/create-release.sh'],
        $root,
        array_merge([
            'PATH' => $root . '/bin:' . getenv('PATH'),
            'TEST_ROOT' => $root,
            'RUNNER_TEMP' => $root . '/scratch',
            'RELEASE_TAG' => $tag,
            'RELEASE_INSTRUCTIONS_FILE' => $root . '/instructions.md',
            'GITHUB_REPOSITORY' => 'example/library',
            'COPILOT_GITHUB_TOKEN' => 'test-copilot-token',
            'GH_TOKEN' => 'test-publishing-token',
            'GITHUB_TOKEN' => 'test-publishing-token',
        ], $overrides),
    );
    $process->run();

    return $process;
}

it('generates notes from the tagged diff and passes literal markdown through a file', function (): void {
    releaseCommit($this->releaseRoot, "<?php\nfunction oldApi() {}\n", 'Initial API');
    releaseGit($this->releaseRoot, 'tag', '-a', 'v1.0.0', '-m', 'Version 1');
    releaseCommit($this->releaseRoot, "<?php\nfunction newApi() {}\n", 'Add API $(touch injected) `touch injected`');
    releaseGit($this->releaseRoot, 'tag', 'v1.1.0');

    $process = runReleaseScript($this->releaseRoot, 'v1.1.0');

    expect($process->isSuccessful())->toBeTrue()
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->toContain('Previous version: v1.0.0', '+function newApi()', '-function oldApi()', '$(touch injected)')
        ->and(file_get_contents($this->releaseRoot . '/published-notes.md'))->toContain('`API` and $values.', 'https://github.com/example/library/compare/v1.0.0...v1.1.0')
        ->and(file_get_contents($this->releaseRoot . '/publish-args.txt'))->toContain('--verify-tag', '--notes-file')
        ->and(file_get_contents($this->releaseRoot . '/publish-args.txt'))->not->toContain('--prerelease')
        ->and(is_file($this->releaseRoot . '/injected'))->toBeFalse()
        ->and(glob($this->releaseRoot . '/scratch/*'))->toBe([]);
});

it('includes the full initial history and tree when no previous version exists', function (): void {
    releaseCommit($this->releaseRoot, "<?php\nfunction firstApi() {}\n", 'Initial library');
    releaseCommit($this->releaseRoot, "<?php\nfunction firstApi() {}\nfunction secondApi() {}\n", 'Add second API');
    releaseGit($this->releaseRoot, 'tag', '1.0');

    expect(runReleaseScript($this->releaseRoot, '1.0')->isSuccessful())->toBeTrue()
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->toContain('none (initial release)', 'Initial library', 'Add second API', '+function firstApi()')
        ->and(file_get_contents($this->releaseRoot . '/published-notes.md'))->not->toContain('Full Changelog');
});

it('ignores prereleases and non-version tags when choosing a stable baseline', function (): void {
    releaseCommit($this->releaseRoot, 'stable', 'Stable version');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.0');
    releaseCommit($this->releaseRoot, 'candidate', 'Candidate API');
    releaseGit($this->releaseRoot, 'tag', 'v1.1.0-rc.1');
    releaseGit($this->releaseRoot, 'tag', 'checkpoint');
    releaseCommit($this->releaseRoot, 'final', 'Finalize API');
    releaseGit($this->releaseRoot, 'tag', 'v1.1.0');

    expect(runReleaseScript($this->releaseRoot, 'v1.1.0')->isSuccessful())->toBeTrue()
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->toContain('Previous version: v1.0.0', 'Candidate API');
});

it('compares successive prereleases and prevents them becoming latest', function (): void {
    releaseCommit($this->releaseRoot, 'candidate one', 'Candidate one');
    releaseGit($this->releaseRoot, 'tag', 'v2.0.0-rc.1');
    releaseCommit($this->releaseRoot, 'candidate two', 'Candidate two');
    releaseGit($this->releaseRoot, 'tag', 'v2.0.0-rc.2');

    expect(runReleaseScript($this->releaseRoot, 'v2.0.0-rc.2')->isSuccessful())->toBeTrue()
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->toContain('Previous version: v2.0.0-rc.1')
        ->and(file_get_contents($this->releaseRoot . '/publish-args.txt'))->toContain('--prerelease', '--latest=false');
});

it('handles tags sharing a commit without treating the release as an initial release', function (): void {
    releaseCommit($this->releaseRoot, 'same tree', 'Library');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.0');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.1');

    expect(runReleaseScript($this->releaseRoot, 'v1.0.1')->isSuccessful())->toBeTrue()
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->toContain('Previous version: v1.0.0');
});

it('preserves existing releases without requiring Copilot credentials', function (): void {
    releaseCommit($this->releaseRoot, 'library', 'Library');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.0');

    $process = runReleaseScript($this->releaseRoot, 'v1.0.0', ['TEST_API_MODE' => 'exists', 'COPILOT_GITHUB_TOKEN' => '']);

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('already exists')
        ->and(is_file($this->releaseRoot . '/prompt.md'))->toBeFalse()
        ->and(is_file($this->releaseRoot . '/publish-args.txt'))->toBeFalse();
});

it('fails without publishing when authentication or note generation fails', function (array $overrides): void {
    releaseCommit($this->releaseRoot, 'library', 'Library');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.0');

    expect(runReleaseScript($this->releaseRoot, 'v1.0.0', $overrides)->isSuccessful())->toBeFalse()
        ->and(is_file($this->releaseRoot . '/publish-args.txt'))->toBeFalse();
})->with([
    'API failure' => [['TEST_API_MODE' => 'error']],
    'missing Copilot secret' => [['COPILOT_GITHUB_TOKEN' => '']],
    'Copilot failure' => [['TEST_COPILOT_MODE' => 'error']],
    'empty notes' => [['TEST_COPILOT_MODE' => 'empty']],
]);

it('fails when the referenced PHPForge instructions are unavailable', function (): void {
    releaseCommit($this->releaseRoot, 'library', 'Library');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.0');

    $process = runReleaseScript($this->releaseRoot, 'v1.0.0', [
        'RELEASE_INSTRUCTIONS_FILE' => $this->releaseRoot . '/missing.md',
    ]);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('Release instructions are missing or empty.')
        ->and(is_file($this->releaseRoot . '/publish-args.txt'))->toBeFalse();
});

it('passes complete diffs larger than one megabyte without shell argument limits', function (): void {
    $contents = str_repeat("public function documentedApi() {}\n", 40000) . 'END_OF_COMPLETE_DIFF';
    releaseCommit($this->releaseRoot, $contents, 'Add full library');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.0');

    expect(runReleaseScript($this->releaseRoot, 'v1.0.0')->isSuccessful())->toBeTrue()
        ->and(filesize($this->releaseRoot . '/prompt.md'))->toBeGreaterThan(1000000)
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->toContain('END_OF_COMPLETE_DIFF');
});

it('rejects invalid tags and a checkout different from the pushed tag', function (string $tag): void {
    releaseCommit($this->releaseRoot, 'old', 'Old library');
    releaseGit($this->releaseRoot, 'tag', $tag);
    releaseCommit($this->releaseRoot, 'new', 'New library');

    expect(runReleaseScript($this->releaseRoot, $tag)->isSuccessful())->toBeFalse()
        ->and(is_file($this->releaseRoot . '/publish-args.txt'))->toBeFalse();
})->with(['v1.0.0', 'checkpoint']);

it('separates tag releases from regular QA with explicit secret forwarding', function (): void {
    $wrapper = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/workflows/security-standards.yml');
    $projectWrapper = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/phpforge.yml');
    $release = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/release.yml');
    $steps = array_column($release['jobs']['release']['steps'], null, 'name');

    expect($wrapper['on']['push']['tags'])->toBe(['v*', '[0-9]*'])
        ->and($wrapper['jobs']['phpforge']['if'])->toBe("github.event_name != 'push' || !startsWith(github.ref, 'refs/tags/')")
        ->and($wrapper['jobs']['release']['needs'] ?? null)->toBeNull()
        ->and($wrapper['jobs']['release']['if'])->toContain("startsWith(github.ref, 'refs/tags/')")
        ->and($projectWrapper['jobs']['security-standards']['if'])->toBe("github.event_name != 'push' || !startsWith(github.ref, 'refs/tags/')")
        ->and($projectWrapper['jobs']['release']['needs'] ?? null)->toBeNull()
        ->and($wrapper['jobs']['release']['permissions'])->toBe(['contents' => 'write'])
        ->and($wrapper['jobs']['release']['secrets']['COPILOT_GITHUB_TOKEN'])->toBe('${{ secrets.COPILOT_GITHUB_TOKEN }}')
        ->and($release['on']['workflow_call']['secrets']['COPILOT_GITHUB_TOKEN']['required'])->toBeTrue()
        ->and($release['on']['workflow_call']['inputs']['instructions_file'] ?? null)->toBeNull()
        ->and($steps['Checkout release tooling']['with']['sparse-checkout'])->toContain('resources/release-notes-instructions.md')
        ->and($steps['Generate notes and create release']['env']['RELEASE_INSTRUCTIONS_FILE'])
        ->toBe('${{ github.workspace }}/.phpforge-release/resources/release-notes-instructions.md')
        ->and($release['concurrency']['cancel-in-progress'])->toBeFalse();
});

it('excludes newer version tags on unrelated branches from the baseline', function (): void {
    releaseCommit($this->releaseRoot, 'stable', 'Stable version');
    releaseGit($this->releaseRoot, 'tag', 'v1.0.0');
    releaseGit($this->releaseRoot, 'checkout', '-qb', 'unrelated');
    releaseCommit($this->releaseRoot, 'unrelated', 'Unrelated feature');
    releaseGit($this->releaseRoot, 'tag', 'v9.0.0');
    releaseGit($this->releaseRoot, 'checkout', '--detach', 'v1.0.0');
    releaseCommit($this->releaseRoot, 'release', 'Release feature');
    releaseGit($this->releaseRoot, 'tag', 'v1.1.0');

    expect(runReleaseScript($this->releaseRoot, 'v1.1.0')->isSuccessful())->toBeTrue()
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->toContain('Previous version: v1.0.0')
        ->and(file_get_contents($this->releaseRoot . '/prompt.md'))->not->toContain('Unrelated feature', 'v9.0.0');
});
