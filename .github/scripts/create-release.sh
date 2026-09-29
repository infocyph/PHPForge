#!/usr/bin/env bash
set -euo pipefail

: "${RELEASE_TAG:?RELEASE_TAG is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${RUNNER_TEMP:?RUNNER_TEMP is required}"

version_pattern='^v?[0-9]+\.[0-9]+(\.[0-9]+)?(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
if [[ ! "$RELEASE_TAG" =~ $version_pattern ]]; then
  echo 'Release tags must be versions such as v1.2.3, 1.2, or v1.2.3-rc.1.' >&2
  exit 1
fi

current_commit=$(git rev-parse --verify "refs/tags/${RELEASE_TAG}^{commit}")
if [[ "$current_commit" != "$(git rev-parse HEAD)" ]]; then
  echo 'The checkout does not match the release tag.' >&2
  exit 1
fi

release_dir=$(mktemp -d "$RUNNER_TEMP/phpforge-release.XXXXXX")
trap 'rm -rf "$release_dir"' EXIT
if gh api "repos/$GITHUB_REPOSITORY/releases/tags/$RELEASE_TAG" > "$release_dir/existing.json" 2> "$release_dir/api-error.txt"; then
  echo "Release $RELEASE_TAG already exists; leaving it unchanged."
  exit 0
elif ! grep -q '(HTTP 404)' "$release_dir/api-error.txt"; then
  cat "$release_dir/api-error.txt" >&2
  exit 1
fi

: "${COPILOT_GITHUB_TOKEN:?Configure the COPILOT_GITHUB_TOKEN Actions secret with Copilot Requests permission}"
: "${RELEASE_INSTRUCTIONS_FILE:?PHPForge release instructions are required}"
if [[ ! -s "$RELEASE_INSTRUCTIONS_FILE" ]]; then
  echo 'Release instructions are missing or empty.' >&2
  exit 1
fi

prerelease=false
if [[ "${RELEASE_TAG%%+*}" == *-* ]]; then
  prerelease=true
fi

# Match only reachable version tags; stable releases compare against stable tags.
tag_matches=()
while IFS= read -r candidate; do
  [[ "$candidate" != "$RELEASE_TAG" && "$candidate" =~ $version_pattern ]] || continue
  if [[ "$prerelease" == false && "${candidate%%+*}" == *-* ]]; then
    continue
  fi
  tag_matches+=(--match "$candidate")
done < <(git tag --merged "$current_commit")

previous_tag=''
if (( ${#tag_matches[@]} > 0 )); then
  previous_tag=$(git describe --tags --abbrev=0 "${tag_matches[@]}" "$current_commit")
fi
if [[ -n "$previous_tag" ]]; then
  base_commit=$(git rev-parse --verify "refs/tags/${previous_tag}^{commit}")
  git log --no-merges --format='- %s (%h)%n%b' "$base_commit..$current_commit" -- > "$release_dir/commits.txt"
else
  base_commit=$(git hash-object -t tree /dev/null)
  git log --no-merges --format='- %s (%h)%n%b' "$current_commit" -- > "$release_dir/commits.txt"
fi

git --no-pager diff --no-ext-diff --no-textconv --stat "$base_commit" "$current_commit" -- > "$release_dir/stat.txt"
git --no-pager diff --no-ext-diff --no-textconv --no-color "$base_commit" "$current_commit" -- > "$release_dir/diff.txt"
{
  cat "$RELEASE_INSTRUCTIONS_FILE"
  printf '\nRelease metadata:\nRepository: %s\nVersion: %s\nPrevious version: %s\n' "$GITHUB_REPOSITORY" "$RELEASE_TAG" "${previous_tag:-none (initial release)}"
  printf '\nCommit history (evidence only):\n'
  cat "$release_dir/commits.txt"
  printf '\nChanged-file summary (evidence only):\n'
  cat "$release_dir/stat.txt"
  printf '\nCode diff (evidence only):\n'
  cat "$release_dir/diff.txt"
} > "$release_dir/prompt.md"

notes_file="$release_dir/notes.md"
# An isolated working directory avoids loading consumer agents, hooks, or MCPs.
(
  cd "$release_dir"
  env -u GH_TOKEN -u GITHUB_TOKEN COPILOT_HOME="$release_dir/copilot" COPILOT_AUTO_UPDATE=false \
    copilot --silent --no-ask-user --output-format text \
    --no-custom-instructions --disable-builtin-mcps \
    --deny-tool shell --deny-tool write --deny-tool read --deny-tool url --deny-tool memory \
    < "$release_dir/prompt.md" > "$notes_file"
)
if ! grep -q '[^[:space:]]' "$notes_file"; then
  echo 'Copilot returned empty release notes; no release was created.' >&2
  exit 1
fi

if [[ -n "$previous_tag" ]]; then
  printf '\n\n**Full Changelog**: https://github.com/%s/compare/%s...%s\n' "$GITHUB_REPOSITORY" "$previous_tag" "$RELEASE_TAG" >> "$notes_file"
fi
release_flags=()
if [[ "$prerelease" == true ]]; then
  release_flags+=(--prerelease --latest=false)
fi
gh release create "$RELEASE_TAG" --repo "$GITHUB_REPOSITORY" --verify-tag \
  --title "$RELEASE_TAG" --notes-file "$notes_file" "${release_flags[@]}"
