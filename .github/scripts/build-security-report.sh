#!/usr/bin/env bash
set -euo pipefail

mkdir -p .phpforge-report/out

run_result="${RUN_RESULT:-missing}"
analyze_result="${ANALYZE_RESULT:-missing}"
benchmark_job_result="${BENCHMARK_JOB_RESULT:-missing}"
generated_at="$(date -u +"%Y-%m-%d %H:%M UTC")"
jobs_api_url="${GITHUB_API_URL}/repos/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}/jobs?per_page=100"
short_sha="${GITHUB_SHA::7}"

overall_state="failing"

if [ "$run_result" = "success" ] && [ "$analyze_result" = "success" ]; then
  overall_state="passing"
elif [ "$run_result" = "success" ] && [ "$analyze_result" = "skipped" ]; then
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

  code_analysis_prefer_lowest="$(job_conclusion "Code Analysis - PHP ${php_version} - prefer-lowest")"
  code_analysis_prefer_stable="$(job_conclusion "Code Analysis - PHP ${php_version} - prefer-stable")"
  security_analysis="$(job_conclusion "Security Analysis - PHP ${php_version}")"

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
      source_job: ("Code Analysis - PHP " + $row.php_version + " - prefer-lowest"),
      generated_by: "run"
    },
    {
      test: "code_analysis",
      dependency_mode: "prefer-stable",
      php_version: $row.php_version,
      status: $row.code_analysis_prefer_stable,
      source_job: ("Code Analysis - PHP " + $row.php_version + " - prefer-stable"),
      generated_by: "run"
    },
    {
      test: "security_analysis",
      dependency_mode: null,
      php_version: $row.php_version,
      status: $row.security_analysis,
      source_job: ("Security Analysis - PHP " + $row.php_version),
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
      def normalize_line:
        if startswith("[") then .
        else ((capture("^(?<prefix>.*)(?<json>\\[\\{.*\\}\\])$") | .json)? // .)
        end;
      def extract_json_array:
        split("\n")
        | map(gsub("\r"; ""))
        | map(sub("[[:space:]]+$"; ""))
        | map(normalize_line)
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
  before_count="$(wc -l < "$benchmark_rows_file")"

  if curl -fsSL \
    -H "Authorization: Bearer ${GH_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    -L "${GITHUB_API_URL}/repos/${GITHUB_REPOSITORY}/actions/jobs/${benchmark_job_id}/logs" > "$benchmark_job_log" 2>/dev/null; then
    parse_benchmark_json_rows "$benchmark_job_log" "$benchmark_php_version" "$benchmark_status" "$benchmark_job_name" >> "$benchmark_rows_file"
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
  | map(select(.name | test("(^| / )Benchmark - PHP [0-9]+(\\.[0-9]+)*$")))
  | sort_by((.name | capture("Benchmark - PHP (?<v>[0-9]+(\\.[0-9]+)*)").v | split(".") | map(tonumber)))
  | .[]
  | [
      (.id | tostring),
      .name,
      (.conclusion // "missing"),
      (.name | capture("Benchmark - PHP (?<v>[0-9]+(\\.[0-9]+)*)").v)
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
  --arg psalm "$(package_version 'vimeo/psalm')" \
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
    {name: "Psalm", package: "vimeo/psalm", version: $psalm},
    {name: "Rector", package: "rector/rector", version: $rector},
    {name: "PHPBench", package: "phpbench/phpbench", version: $phpbench}
  ]'
)"

echo "$tools_json" > .phpforge-report/out/tool-versions.json

jq -n \
  --arg generated_at "$generated_at" \
  --arg overall_state "$overall_state" \
  --arg run_result "$run_result" \
  --arg analyze_result "$analyze_result" \
  --arg benchmark_job_result "$benchmark_job_result" \
  --argjson tested_php_versions "$php_versions_input" \
  --argjson matrix_results "$matrix_results_json" \
  --arg benchmark_command "$benchmark_command" \
  --arg code_lowest_rollup "$code_lowest_rollup" \
  --arg code_stable_rollup "$code_stable_rollup" \
  --arg security_rollup "$security_rollup" \
  --arg benchmark_rollup "$benchmark_rollup" \
  --argjson tools "$tools_json" \
  --argjson check_results "$check_results_json" \
  --argjson benchmark_results "$benchmark_results_json" \
  '{
    schema: "phpforge-security-summary-v2",
    generated_at: $generated_at,
    overall_state: $overall_state,
    run_result: $run_result,
    analyze_result: $analyze_result,
    benchmark_job_result: $benchmark_job_result,
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

status_label() {
  case "$1" in
    passing) echo "PASSING" ;;
    failing) echo "FAILING" ;;
    partial) echo "PARTIAL" ;;
    success) echo "PASS" ;;
    failure) echo "FAIL" ;;
    cancelled) echo "CANCELLED" ;;
    skipped) echo "SKIPPED" ;;
    *) echo "${1^^}" ;;
  esac
}

status_class() {
  case "$1" in
    passing|success) echo "ok" ;;
    failing|failure|cancelled|timed_out|action_required) echo "fail" ;;
    partial|skipped|missing) echo "warn" ;;
    info) echo "info" ;;
    *) echo "warn" ;;
  esac
}

status_fill() {
  case "$1" in
    passing|success) echo "#22c55e" ;;
    failing|failure|cancelled|timed_out|action_required) echo "#f87171" ;;
    skipped|partial) echo "#facc15" ;;
    missing) echo "#fb923c" ;;
    *) echo "#94a3b8" ;;
  esac
}

xml_escape() {
  sed \
    -e 's/&/\&amp;/g' \
    -e 's/</\&lt;/g' \
    -e 's/>/\&gt;/g' \
    -e 's/"/\&quot;/g' \
    -e "s/'/\&apos;/g" <<< "$1"
}

combined_status() {
  local first="$1"
  local second="$2"

  if [ "$first" = "success" ] && [ "$second" = "success" ]; then
    echo "success"
  elif [ "$first" = "failure" ] || [ "$second" = "failure" ]; then
    echo "failure"
  elif [ "$first" = "cancelled" ] || [ "$second" = "cancelled" ]; then
    echo "cancelled"
  elif [ "$first" = "missing" ] || [ "$second" = "missing" ]; then
    echo "missing"
  elif [ "$first" = "skipped" ] || [ "$second" = "skipped" ]; then
    echo "skipped"
  else
    echo "partial"
  fi
}

status_icon_svg() {
  local status="$1"
  local x="$2"
  local y="$3"
  local fill
  local glyph

  fill="$(status_fill "$status")"

  if [ "$status" = "success" ]; then
    printf '<circle cx="%s" cy="%s" r="8" fill="%s"/><path d="M%s %s l4 4 l8 -9" fill="none" stroke="#062214" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>' \
      "$x" "$y" "$fill" "$((x - 5))" "$((y - 1))"
  else
    case "$status" in
      failure|cancelled|timed_out|action_required) glyph="!" ;;
      skipped) glyph="-" ;;
      *) glyph="?" ;;
    esac

    printf '<circle cx="%s" cy="%s" r="8" fill="%s"/><text x="%s" y="%s" text-anchor="middle" class="icon-glyph">%s</text>' \
      "$x" "$y" "$fill" "$x" "$((y + 4))" "$glyph"
  fi
}

matrix_cards_svg=""
card_y=324

append_matrix_line() {
  local status="$1"
  local label="$2"
  local icon_x="$3"
  local line_y="$4"
  local escaped_label
  local text_x
  local text_y

  escaped_label="$(xml_escape "$label")"
  text_x=$((icon_x + 20))
  text_y=$((line_y + 5))
  matrix_cards_svg="${matrix_cards_svg}    $(status_icon_svg "$status" "$icon_x" "$line_y")"$'\n'
  matrix_cards_svg="${matrix_cards_svg}    <text x=\"${text_x}\" y=\"${text_y}\" class=\"matrix-line small-text\">${escaped_label}</text>"$'\n'
}

while IFS=$'\t' read -r php_version lowest stable security; do
  php_version="$(xml_escape "$php_version")"
  ci_status="$(combined_status "$lowest" "$stable")"
  php_title_y=$((card_y + 38))
  status_line_y=$((card_y + 32))

  matrix_cards_svg="${matrix_cards_svg}  <rect x=\"44\" y=\"${card_y}\" width=\"712\" height=\"62\" rx=\"12\" class=\"section-card\"/>"$'\n'
  matrix_cards_svg="${matrix_cards_svg}  <text x=\"64\" y=\"${php_title_y}\" class=\"php-title small-text\">PHP <tspan class=\"accent\">${php_version}</tspan></text>"$'\n'

  append_matrix_line "$ci_status" "CI" 178 "$status_line_y"
  append_matrix_line "$security" "Security" 286 "$status_line_y"
  append_matrix_line "$lowest" "Lowest" 430 "$status_line_y"
  append_matrix_line "$stable" "Stable" 572 "$status_line_y"

  card_y=$((card_y + 76))
done < <(jq -r '.[] | [.php_version, .code_analysis_prefer_lowest, .code_analysis_prefer_stable, .security_analysis] | @tsv' <<< "$matrix_results_json")

quality_title_y=$((card_y + 32))
chip_y=$((quality_title_y + 20))
chip_x=44
quality_chips_svg=""

append_chip() {
  local label="$1"
  local status="$2"
  local width
  local fill
  local escaped_label
  local dot_x
  local dot_y
  local text_x
  local text_y

  width=$(((${#label} * 8) + 62))

  if [ $((chip_x + width)) -gt 756 ]; then
    chip_x=44
    chip_y=$((chip_y + 44))
  fi

  fill="$(status_fill "$status")"
  escaped_label="$(xml_escape "$label")"
  dot_x=$((chip_x + 22))
  dot_y=$((chip_y + 15))
  text_x=$((chip_x + 42))
  text_y=$((chip_y + 20))
  quality_chips_svg="${quality_chips_svg}  <rect x=\"${chip_x}\" y=\"${chip_y}\" width=\"${width}\" height=\"30\" rx=\"15\" class=\"chip\"/>"$'\n'
  quality_chips_svg="${quality_chips_svg}  <circle cx=\"${dot_x}\" cy=\"${dot_y}\" r=\"8\" fill=\"${fill}\"/>"$'\n'
  quality_chips_svg="${quality_chips_svg}  <text x=\"${text_x}\" y=\"${text_y}\" class=\"chip-text small-text\">${escaped_label}</text>"$'\n'
  chip_x=$((chip_x + width + 14))
}

append_chip "Code Lowest" "$code_lowest_rollup"
append_chip "Code Stable" "$code_stable_rollup"
append_chip "Security" "$security_rollup"
append_chip "Benchmark" "$benchmark_rollup"

benchmark_title_y=$((chip_y + 54))
benchmark_card_y=$((benchmark_title_y + 16))
benchmark_chart_svg=""
benchmark_row_y=$((benchmark_card_y + 32))
benchmark_row_count=0
benchmark_display_rows="$(jq -r '
  .[]
  | select(.subject != null and .mode != null)
  | [.php_version, .status, .subject, .mode, (.rstdev // "n/a")]
  | @tsv
' <<< "$benchmark_results_json")"

if [ -z "$benchmark_display_rows" ]; then
  benchmark_chart_svg="${benchmark_chart_svg}  <rect x=\"44\" y=\"${benchmark_card_y}\" width=\"712\" height=\"54\" rx=\"12\" class=\"section-card\"/>"$'\n'
  benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"64\" y=\"$((benchmark_card_y + 34))\" class=\"matrix-line small-text\">No ic:bench:quick benchmark rows detected.</text>"$'\n'
  benchmark_row_count=1
else
  benchmark_chart_svg="${benchmark_chart_svg}  <rect x=\"44\" y=\"$((benchmark_card_y - 2))\" width=\"712\" height=\"24\" rx=\"8\" class=\"section-card\"/>"$'\n'
  benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"64\" y=\"$((benchmark_card_y + 14))\" class=\"matrix-line small-text\">PHP</text>"$'\n'
  benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"154\" y=\"$((benchmark_card_y + 14))\" class=\"matrix-line small-text\">Subject</text>"$'\n'
  benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"612\" y=\"$((benchmark_card_y + 14))\" text-anchor=\"end\" class=\"matrix-line small-text\">Mode</text>"$'\n'
  benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"742\" y=\"$((benchmark_card_y + 14))\" text-anchor=\"end\" class=\"matrix-line small-text\">RSD</text>"$'\n'

  while IFS=$'\t' read -r bench_php bench_status bench_subject bench_mode bench_rstdev; do
    [ -z "$bench_subject" ] && continue
    benchmark_row_count=$((benchmark_row_count + 1))
    row_height=32
    row_y=$((benchmark_row_y + (benchmark_row_count - 1) * row_height))
    row_card_y=$((row_y - 16))
    label_y=$((row_y + 3))
    benchmark_chart_svg="${benchmark_chart_svg}  <rect x=\"44\" y=\"${row_card_y}\" width=\"712\" height=\"30\" rx=\"10\" class=\"section-card\"/>"$'\n'
    benchmark_chart_svg="${benchmark_chart_svg}  <circle cx=\"54\" cy=\"${label_y}\" r=\"5\" fill=\"$(status_fill "$bench_status")\"/>"$'\n'
    benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"64\" y=\"${label_y}\" class=\"matrix-line small-text\">$(xml_escape "$bench_php")</text>"$'\n'
    benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"154\" y=\"${label_y}\" class=\"matrix-line small-text\">$(xml_escape "$bench_subject")</text>"$'\n'
    benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"612\" y=\"${label_y}\" text-anchor=\"end\" class=\"matrix-line small-text\">$(xml_escape "$bench_mode")</text>"$'\n'
    benchmark_chart_svg="${benchmark_chart_svg}  <text x=\"742\" y=\"${label_y}\" text-anchor=\"end\" class=\"matrix-line small-text\">$(xml_escape "$bench_rstdev")</text>"$'\n'
  done <<< "$benchmark_display_rows"
fi

benchmark_section_height=$((benchmark_row_count * 32 + 54))
footer_y=$((benchmark_card_y + benchmark_section_height + 18))
tools_svg=""
tool_line=""
tool_line_y=$((footer_y + 34))

while IFS=$'\t' read -r tool_name tool_version; do
  tool_item="$(xml_escape "${tool_name} ${tool_version}")"

  if [ -z "$tool_line" ]; then
    tool_line="$tool_item"
  elif [ $((${#tool_line} + ${#tool_item})) -gt 86 ]; then
    tools_svg="${tools_svg}  <text x=\"92\" y=\"${tool_line_y}\" class=\"tools-value small-text\">${tool_line}</text>"$'\n'
    tool_line="$tool_item"
    tool_line_y=$((tool_line_y + 22))
  else
    tool_line="${tool_line} &#183; ${tool_item}"
  fi
done < <(jq -r '.[] | [.name, .version] | @tsv' <<< "$tools_json")

if [ -n "$tool_line" ]; then
  tools_svg="${tools_svg}  <text x=\"92\" y=\"${tool_line_y}\" class=\"tools-value small-text\">${tool_line}</text>"$'\n'
fi

total_height=$((tool_line_y + 40))
panel_height=$((total_height - 26))
tools_label_y=$((footer_y + 34))
repository_label="$(xml_escape "Project: ${GITHUB_REPOSITORY}")"
run_meta="$(xml_escape "Generated: ${generated_at} | Commit: ${short_sha} | Run: #${GITHUB_RUN_NUMBER} | Worker: ${GITHUB_RUN_ID}")"
overall_status_label="$(status_label "$overall_state")"

if [ "$overall_state" = "passing" ]; then
  security_status="Security Status: Protected"
elif [ "$overall_state" = "partial" ]; then
  security_status="Security Status: Partially Protected"
else
  security_status="Security Status: Attention Required"
fi

cat > .phpforge-report/out/security-report.svg <<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 800 ${total_height}" preserveAspectRatio="xMidYMin meet" role="img" aria-label="Security and standards report summary">
  <defs>
    <radialGradient id="panelGlow" cx="48%" cy="24%" r="72%">
      <stop offset="0%" stop-color="#17345a" stop-opacity="0.72"/>
      <stop offset="52%" stop-color="#071827" stop-opacity="0.98"/>
      <stop offset="100%" stop-color="#030b13" stop-opacity="1"/>
    </radialGradient>
    <linearGradient id="shieldGradient" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#22c55e"/>
      <stop offset="100%" stop-color="#86efac"/>
    </linearGradient>
    <filter id="softShadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="14" stdDeviation="18" flood-color="#000814" flood-opacity="0.48"/>
    </filter>
  </defs>
  <style>
    :root { max-width: 800px; height: auto; background: transparent; }
    .panel { fill: url(#panelGlow); fill-opacity: 0.86; stroke: #385274; stroke-width: 1.1; }
    .divider { stroke: #475569; stroke-width: 1; opacity: 0.9; }
    .text { paint-order: stroke fill; stroke: #020617; stroke-width: 3px; stroke-linejoin: round; }
    .small-text { paint-order: stroke fill; stroke: #020617; stroke-width: 2px; stroke-linejoin: round; }
    .title { font: 700 26px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #ffffff; }
    .subtitle { font: 700 18px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #22c55e; }
    .repo { font: 700 14px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #e2e8f0; }
    .section-title { font: 700 15px "Segoe UI", "Helvetica Neue", Arial, sans-serif; letter-spacing: 2px; fill: #dbeafe; }
    .section-card { fill: #0f172a; fill-opacity: 0.72; stroke: #334862; stroke-width: 1; }
    .tile-label { font: 700 18px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #ffffff; }
    .tile-value { font: 800 34px "Segoe UI", "Helvetica Neue", Arial, sans-serif; }
    .php-title { font: 700 19px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #ffffff; }
    .accent { fill: #4ade80; }
    .matrix-line { font: 16px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #ffffff; }
    .icon-glyph { font: 800 12px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #08111f; }
    .chip { fill: #0f172a; fill-opacity: 0.72; stroke: #334862; stroke-width: 1; }
    .chip-text { font: 700 15px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #ffffff; }
    .badge { fill: #166534; fill-opacity: 0.48; stroke: #22c55e; stroke-width: 1.2; }
    .badge-text { font: 700 15px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #ffffff; }
    .tools-label { font: 15px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #dbeafe; }
    .tools-value { font: 700 15px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #22c55e; }
    .meta { font: 13px "Segoe UI", "Helvetica Neue", Arial, sans-serif; fill: #dbeafe; }
    .ok { fill: #22c55e; }
    .warn { fill: #facc15; }
    .fail { fill: #f87171; }
    .critical { fill: #f87171; }
    .high { fill: #fb923c; }
    .medium { fill: #facc15; }
    .low { fill: #22c55e; }
  </style>
  <rect x="20" y="14" width="760" height="${panel_height}" rx="16" class="panel" filter="url(#softShadow)"/>

  <path d="M64 48 L92 38 L120 48 L118 80 C115 104 98 118 92 121 C86 118 69 104 66 80 Z" fill="#22c55e" fill-opacity="0.16" stroke="url(#shieldGradient)" stroke-width="2.6"/>
  <path d="M78 79 l11 11 l22 -25" fill="none" stroke="#86efac" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/>
  <text x="140" y="70" class="title text">Security &amp; Standards Report</text>
  <text x="140" y="101" class="subtitle text">${security_status}</text>
  <text x="140" y="123" class="repo small-text">${repository_label}</text>
  <text x="140" y="143" class="meta small-text">${run_meta}</text>
  <rect x="620" y="46" width="126" height="34" rx="17" class="badge"/>
  <circle cx="642" cy="63" r="8" fill="#22c55e"/>
  <path d="M638 62 l3 3 l6 -7" fill="none" stroke="#062214" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  <text x="660" y="69" class="badge-text small-text">${overall_status_label}</text>
  <line x1="44" y1="158" x2="756" y2="158" class="divider"/>

  <text x="44" y="194" class="section-title small-text">VULNERABILITY SUMMARY</text>
  <rect x="44" y="210" width="167" height="62" rx="10" class="section-card"/>
  <text x="62" y="249" class="tile-label small-text">Critical</text>
  <text x="184" y="254" text-anchor="end" class="tile-value critical">0</text>
  <rect x="226" y="210" width="167" height="62" rx="10" class="section-card"/>
  <text x="244" y="249" class="tile-label small-text">High</text>
  <text x="366" y="254" text-anchor="end" class="tile-value high">0</text>
  <rect x="408" y="210" width="167" height="62" rx="10" class="section-card"/>
  <text x="426" y="249" class="tile-label small-text">Medium</text>
  <text x="548" y="254" text-anchor="end" class="tile-value medium">0</text>
  <rect x="590" y="210" width="167" height="62" rx="10" class="section-card"/>
  <text x="608" y="249" class="tile-label small-text">Low</text>
  <text x="730" y="254" text-anchor="end" class="tile-value low">0</text>

  <text x="44" y="306" class="section-title small-text">PHP MATRIX</text>
${matrix_cards_svg}
  <text x="44" y="${quality_title_y}" class="section-title small-text">QUALITY GATES</text>
${quality_chips_svg}
  <text x="44" y="${benchmark_title_y}" class="section-title small-text">BENCHMARK RESULTS (IC:BENCH:QUICK)</text>
${benchmark_chart_svg}
  <line x1="44" y1="${footer_y}" x2="756" y2="${footer_y}" class="divider"/>
  <text x="44" y="${tools_label_y}" class="tools-label small-text">Tools:</text>
${tools_svg}
</svg>
SVG

python3 - <<'PY'
import xml.etree.ElementTree as ET

ET.parse(".phpforge-report/out/security-report.svg")
PY

{
  tested_versions="$(jq -r 'if length > 0 then join(", ") else "none" end' <<< "$php_versions_input")"
  tool_versions="$(jq -r 'map("\(.name) (\(.version))") | join(", ")' <<< "$tools_json")"
  echo "### Security SVG Report"
  echo ""
  echo "- Overall: \`${overall_state}\`"
  echo "- Run matrix: \`${run_result}\`"
  echo "- Analysis job: \`${analyze_result}\`"
  echo "- Tested PHP versions: \`${tested_versions}\`"
  echo "- Benchmark job: \`${benchmark_job_result}\` (command: ${benchmark_command})"
  echo "- Benchmark entries: \`$(jq 'length' <<< "$benchmark_results_json")\`"
  echo "- Check entries: \`$(jq 'length' <<< "$check_results_json")\`"
  echo "- Tools: ${tool_versions}"
  echo "- Artifacts: \`security-report.svg\`, \`security-summary.json\`"
} >> "$GITHUB_STEP_SUMMARY"
