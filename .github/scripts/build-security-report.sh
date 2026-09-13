#!/usr/bin/env bash
set -euo pipefail

mkdir -p .phpforge-report/out

run_result="${RUN_RESULT:-missing}"
analyze_result="${ANALYZE_RESULT:-missing}"
benchmark_job_result="${BENCHMARK_JOB_RESULT:-missing}"
run_qa_enabled="${RUN_QA_ENABLED:-true}"
run_analysis_enabled="${RUN_ANALYSIS_ENABLED:-true}"
run_benchmark_enabled="${RUN_BENCHMARK_ENABLED:-true}"
generated_at="$(date -u +"%Y-%m-%d %H:%M UTC")"
jobs_api_url="${GITHUB_API_URL}/repos/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}/jobs?per_page=100"

overall_state="failing"

if [ "$run_qa_enabled" != "true" ] && [ "$run_analysis_enabled" != "true" ]; then
  overall_state="skipped"
elif { [ "$run_qa_enabled" != "true" ] || [ "$run_result" = "success" ]; } \
  && { [ "$run_analysis_enabled" != "true" ] || [ "$analyze_result" = "success" ]; }; then
  overall_state="passing"
elif { [ "$run_qa_enabled" != "true" ] || [ "$run_result" = "success" ]; } \
  && [ "$analyze_result" = "skipped" ]; then
  overall_state="partial"
fi

php_versions_input="${PHP_VERSIONS_INPUT:-[]}"

if ! jq -e 'type == "array"' >/dev/null 2>&1 <<< "$php_versions_input"; then
  php_versions_input='[]'
fi

jobs_json="$(curl -fsSL \
  -H "Authorization: Bearer ${GH_TOKEN}" \
  -H "Accept: application/vnd.github+json" \
  "$jobs_api_url" || echo '{"jobs":[]}'
)"

version_matrix_file=".phpforge-report/out/version-matrix.ndjson"
: > "$version_matrix_file"

job_conclusion() {
  local job_name="$1"

  jq -r --arg name "$job_name" '
    .jobs // []
    | map(select(.name == $name or (.name | endswith(" / " + $name))))
    | first
    | .conclusion // "missing"
  ' <<< "$jobs_json"
}

while IFS= read -r php_version; do
  [ -z "$php_version" ] && continue

  code_analysis_prefer_lowest="$(job_conclusion "QA - PHP ${php_version} - prefer-lowest")"
  code_analysis_prefer_stable="$(job_conclusion "QA - PHP ${php_version} - prefer-stable")"
  security_analysis="$(job_conclusion "Analysis - PHP ${php_version}")"

  if [ "$run_result" = "skipped" ] && [ "$code_analysis_prefer_lowest" = "missing" ]; then
    code_analysis_prefer_lowest="skipped"
  fi

  if [ "$run_result" = "skipped" ] && [ "$code_analysis_prefer_stable" = "missing" ]; then
    code_analysis_prefer_stable="skipped"
  fi

  if [ "$analyze_result" = "skipped" ] && [ "$security_analysis" = "missing" ]; then
    security_analysis="skipped"
  fi

  jq -nc \
    --arg php_version "$php_version" \
    --arg code_analysis_prefer_lowest "$code_analysis_prefer_lowest" \
    --arg code_analysis_prefer_stable "$code_analysis_prefer_stable" \
    --arg security_analysis "$security_analysis" \
    '{
      php_version: $php_version,
      code_analysis_prefer_lowest: $code_analysis_prefer_lowest,
      code_analysis_prefer_stable: $code_analysis_prefer_stable,
      security_analysis: $security_analysis
    }' >> "$version_matrix_file"
done < <(jq -r '.[]' <<< "$php_versions_input")

matrix_results_json="$(jq -sc '.' "$version_matrix_file")"
check_results_json="$(jq -c '
  [ .[] as $row |
    {
      test: "code_analysis",
      dependency_mode: "prefer-lowest",
      php_version: $row.php_version,
      status: $row.code_analysis_prefer_lowest,
      source_job: ("QA - PHP " + $row.php_version + " - prefer-lowest"),
      generated_by: "run"
    },
    {
      test: "code_analysis",
      dependency_mode: "prefer-stable",
      php_version: $row.php_version,
      status: $row.code_analysis_prefer_stable,
      source_job: ("QA - PHP " + $row.php_version + " - prefer-stable"),
      generated_by: "run"
    },
    {
      test: "security_analysis",
      dependency_mode: null,
      php_version: $row.php_version,
      status: $row.security_analysis,
      source_job: ("Analysis - PHP " + $row.php_version),
      generated_by: "analyze"
    }
  ]
' <<< "$matrix_results_json")"

benchmark_command="none"

if composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:bench:quick$'; then
  benchmark_command="ic:bench:quick"
elif composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:test:bench$'; then
  benchmark_command="ic:test:bench"
elif composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:benchmark$'; then
  benchmark_command="ic:benchmark"
elif composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:bench:run$'; then
  benchmark_command="ic:bench:run"
fi

benchmark_rows_file=".phpforge-report/out/benchmark-results.ndjson"
: > "$benchmark_rows_file"
benchmark_artifact_dir=".phpforge-report/in/benchmark"

parse_benchmark_results_file() {
  local results_file="$1"
  local php_version="$2"
  local benchmark_status="$3"
  local source_job="$4"

  jq -c \
    --arg php_version "$php_version" \
    --arg status "$benchmark_status" \
    --arg source_job "$source_job" '
      def flatten_rows:
        if type == "array" then .[] | flatten_rows else . end;
      ((. // []) | flatten_rows)
      | select(type == "object")
      | {
          test: "benchmark",
          php_version: $php_version,
          status: $status,
          source_job: $source_job,
          generated_by: "benchmark",
          benchmark: (.benchmark // .benchmark_name // null),
          subject: (.subject // .subject_name // null),
          set: (if (.set // "") == "" then null else (.set | tostring) end),
          revs: ((.revs // null) | if . == null then null else (tonumber? // null) end),
          its: ((.its // null) | if . == null then null else (tonumber? // null) end),
          mem_peak: (
            .mem_peak // .memory_peak // .memory // null
            | if . == null then null else tostring end
          ),
          mode: (
            .mode // .time_avg // .mean // null
            | if . == null then null else tostring end
          ),
          rstdev: (
            .rstdev // null
            | if . == null then null else tostring end
          )
        }
      | select(.subject != null and .mode != null)
    ' < "$results_file"
}

parse_benchmark_json_rows() {
  local log_file="$1"
  local php_version="$2"
  local benchmark_status="$3"
  local source_job="$4"

  jq -R -s -c \
    --arg php_version "$php_version" \
    --arg status "$benchmark_status" \
    --arg source_job "$source_job" '
      def flatten_rows:
        if type == "array" then .[] | flatten_rows else . end;
      def extract_json_array:
        (gsub("\r"; "") | gsub("\u001b\\[[0-9;]*[A-Za-z]"; "")) as $clean
        | [
            $clean
            | match("\\[\\{.*?\\}\\]"; "gm")
            | .string
          ]
        | map(fromjson? | select(type == "array"))
        | if length == 0 then null else .[-1] end;
      ((extract_json_array // []) | flatten_rows)
      | select(type == "object")
      | {
          test: "benchmark",
          php_version: $php_version,
          status: $status,
          source_job: $source_job,
          generated_by: "benchmark",
          benchmark: (.benchmark // .benchmark_name // null),
          subject: (.subject // .subject_name // null),
          set: (if (.set // "") == "" then null else (.set | tostring) end),
          revs: ((.revs // null) | if . == null then null else (tonumber? // null) end),
          its: ((.its // null) | if . == null then null else (tonumber? // null) end),
          mem_peak: (
            .mem_peak // .memory_peak // .memory // null
            | if . == null then null else tostring end
          ),
          mode: (
            .mode // .time_avg // .mean // null
            | if . == null then null else tostring end
          ),
          rstdev: (
            .rstdev // null
            | if . == null then null else tostring end
          )
        }
      | select(.subject != null and .mode != null)
    ' < "$log_file"
}

while IFS=$'\t' read -r benchmark_job_id benchmark_job_name benchmark_job_conclusion benchmark_php_version; do
  [ -z "$benchmark_job_id" ] && continue

  benchmark_status="$benchmark_job_conclusion"
  if [ -z "$benchmark_status" ] || [ "$benchmark_status" = "null" ]; then
    benchmark_status="missing"
  fi

  benchmark_job_log=".phpforge-report/out/benchmark-job-${benchmark_job_id}.log"
  benchmark_job_rows=".phpforge-report/out/benchmark-job-${benchmark_job_id}.rows.ndjson"
  benchmark_artifact_file="${benchmark_artifact_dir}/benchmark-results-php-${benchmark_php_version}.json"
  before_count="$(wc -l < "$benchmark_rows_file")"

  if [ -f "$benchmark_artifact_file" ]; then
    parse_benchmark_results_file "$benchmark_artifact_file" "$benchmark_php_version" "$benchmark_status" "$benchmark_job_name" > "$benchmark_job_rows"
    parsed_row_count="$(wc -l < "$benchmark_job_rows")"
    raw_row_count="$(jq -r 'if type == "array" then length else -1 end' "$benchmark_artifact_file" 2>/dev/null || echo "-1")"
    if [ "$parsed_row_count" -gt 0 ]; then
      cat "$benchmark_job_rows" >> "$benchmark_rows_file"
    else
      if [ "$raw_row_count" = "0" ]; then
        benchmark_status="skipped"
        echo "::warning::Benchmark artifact for PHP ${benchmark_php_version} was empty (no benchmark subjects). Marking as skipped."
      else
        echo "::warning::Benchmark artifact for PHP ${benchmark_php_version} had no parseable rows."
      fi
    fi
  elif curl -fsSL \
    -H "Authorization: Bearer ${GH_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    -L "${GITHUB_API_URL}/repos/${GITHUB_REPOSITORY}/actions/jobs/${benchmark_job_id}/logs" > "$benchmark_job_log" 2>/dev/null; then
    parse_benchmark_json_rows "$benchmark_job_log" "$benchmark_php_version" "$benchmark_status" "$benchmark_job_name" > "$benchmark_job_rows"
    parsed_row_count="$(wc -l < "$benchmark_job_rows")"
    if [ "$parsed_row_count" -gt 0 ]; then
      cat "$benchmark_job_rows" >> "$benchmark_rows_file"
    else
      sample_json_line="$(grep -Eo '\[\{.*\}\]' "$benchmark_job_log" | head -n 1 || true)"
      if [ -n "$sample_json_line" ]; then
        echo "::warning::Benchmark log ${benchmark_job_id} contained JSON-like payload, but parser produced zero rows."
      else
        echo "::warning::Benchmark log ${benchmark_job_id} did not contain detectable JSON payload."
      fi
    fi
  else
    echo "::warning::No benchmark artifact for PHP ${benchmark_php_version}, and unable to download logs for job ${benchmark_job_id} (${benchmark_job_name})."
  fi

  after_count="$(wc -l < "$benchmark_rows_file")"
  if [ "$after_count" -eq "$before_count" ]; then
    jq -nc \
      --arg php_version "$benchmark_php_version" \
      --arg status "$benchmark_status" \
      --arg source_job "$benchmark_job_name" \
      '{
        test: "benchmark",
        php_version: $php_version,
        status: $status,
        source_job: $source_job,
        generated_by: "benchmark",
        benchmark: null,
        subject: null,
        set: null,
        revs: null,
        its: null,
        mem_peak: null,
        mode: null,
        rstdev: null
      }' >> "$benchmark_rows_file"
  fi
done < <(jq -r '
  .jobs // []
  | map(
      . as $job
      | (
          [
            $job.steps[]?
            | .name // ""
            | capture("^Setup benchmark - PHP (?<v>[0-9]+(\\.[0-9]+)*)$")?
            | .v
          ]
          | .[0]
        ) as $php_version
      | select($php_version != null)
      | {
          id: $job.id,
          name: ("Benchmark - PHP " + $php_version),
          conclusion: ($job.conclusion // "missing"),
          php_version: $php_version
        }
    )
  | sort_by((.php_version | split(".") | map(tonumber)))
  | .[]
  | [
      (.id | tostring),
      .name,
      .conclusion,
      .php_version
    ]
  | @tsv
' <<< "$jobs_json")

benchmark_results_json="$(jq -sc '
  map(
    if type == "array" then .[]
    else .
    end
  )
  | map(select(type == "object"))
  | sort_by(
      (if type == "object" then (.php_version | split(".") | map(tonumber)) else [0] end),
      (if type == "object" then (.benchmark // "") else "" end),
      (if type == "object" then (.subject // "") else "" end)
    )
' "$benchmark_rows_file")"

if jq -e '
  ([ .[] | select(.test == "benchmark") | .status ] | unique) as $statuses
  | (([ .[] | select(.test == "benchmark" and .subject != null and .mode != null) ] | length) == 0) as $no_rows
  | ($statuses | index("success") != null) and $no_rows
' >/dev/null <<< "$benchmark_results_json"; then
  echo "::error::Benchmark job succeeded, but no benchmark rows were parsed from PHPBench JSON output."
  exit 1
fi

aggregate_matrix_field() {
  local key="$1"
  local statuses

  statuses="$(jq -r --arg key "$key" '[.[] | .[$key]] | map(select(type == "string")) | unique | join(" ")' <<< "$matrix_results_json")"

  if [[ " ${statuses} " == *" failure "* || " ${statuses} " == *" cancelled "* || " ${statuses} " == *" timed_out "* || " ${statuses} " == *" action_required "* ]]; then
    echo "failure"
  elif [[ " ${statuses} " == *" missing "* ]]; then
    echo "missing"
  elif [[ " ${statuses} " == *" success "* && " ${statuses} " == *" skipped "* ]]; then
    echo "partial"
  elif [[ " ${statuses} " == *" skipped "* ]]; then
    echo "skipped"
  elif [[ " ${statuses} " == *" success "* ]]; then
    echo "success"
  else
    echo "partial"
  fi
}

code_lowest_rollup="$(aggregate_matrix_field "code_analysis_prefer_lowest")"
code_stable_rollup="$(aggregate_matrix_field "code_analysis_prefer_stable")"
security_rollup="$(aggregate_matrix_field "security_analysis")"
benchmark_rollup="$(jq -r '
  if length == 0 then "skipped"
  else
    ([.[].status] | unique | join(" ")) as $statuses
    | if ($statuses | contains("failure")) or ($statuses | contains("cancelled")) or ($statuses | contains("timed_out")) or ($statuses | contains("action_required")) then
        "failure"
      elif ($statuses | contains("missing")) then
        "missing"
      elif ($statuses | contains("success")) and ($statuses | contains("skipped")) then
        "partial"
      elif ($statuses | contains("skipped")) then
        "skipped"
      elif ($statuses | contains("success")) then
        "success"
      else
        "partial"
      end
  end
' <<< "$benchmark_results_json")"

package_version() {
  local package_name="$1"
  local version
  version="$(composer show "$package_name" --format=json 2>/dev/null | jq -r '.versions[0] // .version // empty' || true)"

  if [ -z "$version" ]; then
    version="not-installed"
  fi

  echo "$version"
}

tools_json="$(jq -nc \
  --arg captainhook "$(package_version 'captainhook/captainhook')" \
  --arg composer_normalize "$(package_version 'ergebnis/composer-normalize')" \
  --arg deptrac "$(package_version 'deptrac/deptrac')" \
  --arg phpprobe "$(package_version 'infocyph/phpprobe')" \
  --arg pest "$(package_version 'pestphp/pest')" \
  --arg pint "$(package_version 'laravel/pint')" \
  --arg phpcs "$(package_version 'squizlabs/php_codesniffer')" \
  --arg phpstan "$(package_version 'phpstan/phpstan')" \
  --arg psalm "$(package_version 'psalm/phar')" \
  --arg rector "$(package_version 'rector/rector')" \
  --arg phpbench "$(package_version 'phpbench/phpbench')" \
  '[
    {name: "CaptainHook", package: "captainhook/captainhook", version: $captainhook},
    {name: "Composer Normalize", package: "ergebnis/composer-normalize", version: $composer_normalize},
    {name: "Deptrac", package: "deptrac/deptrac", version: $deptrac},
    {name: "PHPProbe", package: "infocyph/phpprobe", version: $phpprobe},
    {name: "Pest", package: "pestphp/pest", version: $pest},
    {name: "Pint", package: "laravel/pint", version: $pint},
    {name: "PHP_CodeSniffer", package: "squizlabs/php_codesniffer", version: $phpcs},
    {name: "PHPStan", package: "phpstan/phpstan", version: $phpstan},
    {name: "Psalm", package: "psalm/phar", version: $psalm},
    {name: "Rector", package: "rector/rector", version: $rector},
    {name: "PHPBench", package: "phpbench/phpbench", version: $phpbench}
  ]'
)"

echo "$tools_json" > .phpforge-report/out/tool-versions.json

printf '%s\n' \
  "$php_versions_input" \
  "$matrix_results_json" \
  "$tools_json" \
  "$check_results_json" \
  "$benchmark_results_json" \
| jq -n \
  --arg generated_at "$generated_at" \
  --arg overall_state "$overall_state" \
  --arg run_result "$run_result" \
  --arg analyze_result "$analyze_result" \
  --arg benchmark_job_result "$benchmark_job_result" \
  --arg run_qa_enabled "$run_qa_enabled" \
  --arg run_analysis_enabled "$run_analysis_enabled" \
  --arg run_benchmark_enabled "$run_benchmark_enabled" \
  --arg benchmark_command "$benchmark_command" \
  --arg code_lowest_rollup "$code_lowest_rollup" \
  --arg code_stable_rollup "$code_stable_rollup" \
  --arg security_rollup "$security_rollup" \
  --arg benchmark_rollup "$benchmark_rollup" \
  'input as $tested_php_versions
  | input as $matrix_results
  | input as $tools
  | input as $check_results
  | input as $benchmark_results
  | {
    schema: "phpforge-security-summary-v2",
    generated_at: $generated_at,
    overall_state: $overall_state,
    run_result: $run_result,
    analyze_result: $analyze_result,
    benchmark_job_result: $benchmark_job_result,
    enabled: {
      qa: ($run_qa_enabled == "true"),
      analysis: ($run_analysis_enabled == "true"),
      benchmark: ($run_benchmark_enabled == "true")
    },
    tested_php_versions: $tested_php_versions,
    matrix_results: $matrix_results,
    check_results: $check_results,
    benchmark_results: $benchmark_results,
    rollup: {
      code_analysis_prefer_lowest: $code_lowest_rollup,
      code_analysis_prefer_stable: $code_stable_rollup,
      security_analysis: $security_rollup,
      benchmark: $benchmark_rollup
    },
    benchmark_command: $benchmark_command,
    tools: $tools
  }' > .phpforge-report/out/security-summary.json

display_status() {
  case "$1" in
    passing|success) printf 'PASS' ;;
    failing|failure) printf 'FAIL' ;;
    partial) printf 'PARTIAL' ;;
    cancelled) printf 'CANCELLED' ;;
    timed_out) printf 'TIMED OUT' ;;
    action_required) printf 'ACTION REQUIRED' ;;
    skipped) printf 'SKIPPED' ;;
    missing) printf 'MISSING' ;;
    *) printf '%s' "$1" | tr '[:lower:]' '[:upper:]' ;;
  esac
}

tested_versions="$(jq -r 'if length > 0 then join(", ") else "none" end' <<< "$php_versions_input")"
benchmark_entry_count="$(jq '[.[] | select(.subject != null and .mode != null)] | length' <<< "$benchmark_results_json")"
check_entry_count="$(jq 'length' <<< "$check_results_json")"

{
  echo "### Security Report Summary"
  echo ""
  echo "| Area | Result |"
  echo "| --- | --- |"
  printf '| Overall | %s |\n' "$(display_status "$overall_state")"
  printf '| QA matrix | %s |\n' "$(display_status "$run_result")"
  printf '| Analysis | %s |\n' "$(display_status "$analyze_result")"
  printf '| Benchmark | %s |\n' "$(display_status "$benchmark_rollup")"
  echo ""
  printf 'Tested PHP versions: %s  \n' "$tested_versions"
  printf 'Checks: %s · Benchmark entries: %s · Command: %s\n' \
    "$check_entry_count" "$benchmark_entry_count" "$benchmark_command"
  echo ""
  echo "#### PHP Matrix"
  echo ""
  echo "| PHP | Prefer lowest | Prefer stable | Analysis |"
  echo "| --- | --- | --- | --- |"

  while IFS=$'\t' read -r php_version lowest stable security; do
    [ -z "$php_version" ] && continue
    printf '| %s | %s | %s | %s |\n' \
      "$php_version" \
      "$(display_status "$lowest")" \
      "$(display_status "$stable")" \
      "$(display_status "$security")"
  done < <(jq -r '.[] | [.php_version, .code_analysis_prefer_lowest, .code_analysis_prefer_stable, .security_analysis] | @tsv' <<< "$matrix_results_json")

  echo ""
  echo "#### Benchmark Results"
  echo ""

  if [ "$benchmark_entry_count" -eq 0 ]; then
    echo "No benchmark rows were produced."
  else
    while IFS= read -r benchmark_php_version; do
      [ -z "$benchmark_php_version" ] && continue

      benchmark_version_count="$(jq -r --arg php_version "$benchmark_php_version" \
        '[.[] | select(.php_version == $php_version and .subject != null and .mode != null)] | length' \
        <<< "$benchmark_results_json")"
      benchmark_version_status="$(jq -r --arg php_version "$benchmark_php_version" '
        [.[] | select(.php_version == $php_version and .subject != null and .mode != null) | .status]
        | unique
        | if length == 1 then .[0] else join("/") end
      ' <<< "$benchmark_results_json")"

      echo "<details>"
      printf '<summary>PHP %s — %s benchmark result(s) — %s</summary>\n' \
        "$benchmark_php_version" \
        "$benchmark_version_count" \
        "$(display_status "$benchmark_version_status")"
      echo ""
      echo "| Benchmark | Subject | Set | Revs | Iterations | Memory peak | Mode | RSD |"
      echo "| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |"

      jq -r --arg php_version "$benchmark_php_version" '
        def value_or_na:
          if . == null or . == "" then "n/a" else tostring end;
        def markdown:
          value_or_na | gsub("\\r"; " ") | gsub("\\n"; "<br>") | gsub("\\|"; "\\|");
        def format_mode:
          if type == "number" then (((. * 1000) | round) / 1000 | tostring) + " μs"
          else value_or_na
          end;
        def format_rsd:
          if type == "number" then "± " + (((. * 100) | round) / 100 | tostring) + "%"
          elif type == "string" and test("%$") then .
          else value_or_na
          end;
        .[]
        | select(.php_version == $php_version and .subject != null and .mode != null)
        | "| \(.benchmark | markdown) | \(.subject | markdown) | \(.set | markdown) | \(.revs | markdown) | \(.its | markdown) | \(.mem_peak | markdown) | \(.mode | format_mode | markdown) | \(.rstdev | format_rsd | markdown) |"
      ' <<< "$benchmark_results_json"

      echo ""
      echo "</details>"
      echo ""
    done < <(jq -r '
      [.[] | select(.subject != null and .mode != null) | .php_version]
      | unique
      | sort_by(split(".") | map(tonumber))
      | .[]
    ' <<< "$benchmark_results_json")
  fi

  echo "#### Tool Versions"
  echo ""
  echo "<details>"
  echo "<summary>Show resolved tool versions</summary>"
  echo ""
  echo "| Tool | Version |"
  echo "| --- | --- |"

  jq -r '
    def markdown:
      tostring | gsub("\\r"; " ") | gsub("\\n"; "<br>") | gsub("\\|"; "\\|");
    .[] | "| \(.name | markdown) | \(.version | markdown) |"
  ' <<< "$tools_json"

  echo ""
  echo "</details>"
  echo ""
  echo "Artifact: security-report/security-summary.json"
} >> "$GITHUB_STEP_SUMMARY"
