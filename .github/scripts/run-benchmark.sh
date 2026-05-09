#!/usr/bin/env bash
set -euo pipefail

benchmark_command="none"
benchmark_log=".phpforge-report/benchmark-output.log"
benchmark_metric_ns="0"
benchmark_metric_source="elapsed"

mkdir -p .phpforge-report

if composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:bench:quick$'; then
  benchmark_command="ic:bench:quick"
elif composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:test:bench$'; then
  benchmark_command="ic:test:bench"
elif composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:benchmark$'; then
  benchmark_command="ic:benchmark"
elif composer list --raw 2>/dev/null | awk '{print $1}' | grep -q '^ic:bench:run$'; then
  benchmark_command="ic:bench:run"
fi

if [ "$benchmark_command" = "none" ]; then
  echo "No benchmark command found (expected one of: ic:bench:quick, ic:test:bench, ic:benchmark, ic:bench:run)."
  echo "benchmark_command=none" >> "$GITHUB_OUTPUT"
  echo "duration_ms=0" >> "$GITHUB_OUTPUT"
  echo "benchmark_metric_ns=0" >> "$GITHUB_OUTPUT"
  echo "benchmark_metric_source=none" >> "$GITHUB_OUTPUT"
  echo "benchmark_status=skipped" >> "$GITHUB_OUTPUT"
  exit 0
fi

if [ ! -f "vendor/bin/phpbench" ]; then
  echo "PHPBench binary not found at vendor/bin/phpbench; skipping benchmark run."
  echo "benchmark_command=${benchmark_command}" >> "$GITHUB_OUTPUT"
  echo "duration_ms=0" >> "$GITHUB_OUTPUT"
  echo "benchmark_metric_ns=0" >> "$GITHUB_OUTPUT"
  echo "benchmark_metric_source=none" >> "$GITHUB_OUTPUT"
  echo "benchmark_status=skipped" >> "$GITHUB_OUTPUT"
  exit 0
fi

phpbench_bin="vendor/bin/phpbench"
config_path=""

for candidate in \
  "phpbench.json" \
  "phpbench.json.dist" \
  "resources/phpbench.json" \
  "vendor/infocyph/phpforge/resources/phpbench.json"; do
  if [ -f "$candidate" ]; then
    config_path="$candidate"
    break
  fi
done

phpbench_args=(run --report=aggregate --output=json --progress=none --bootstrap=vendor/autoload.php)

if [ -n "$config_path" ]; then
  phpbench_args+=("--config=${config_path}")
fi

if [ "$benchmark_command" = "ic:bench:quick" ]; then
  phpbench_args+=(--revs=10 --iterations=3 --warmup=1)
fi

for bench_path in \
  "benchmarks" \
  "tests/Bench" \
  "tests/Benchmark" \
  "tests/Benchmarks"; do
  if [ -d "$bench_path" ]; then
    phpbench_args+=("$bench_path")
    break
  fi
done

echo "Running PHPBench JSON command: ${phpbench_bin} ${phpbench_args[*]}"

start_ms="$(date +%s%3N)"
set +e
"$phpbench_bin" "${phpbench_args[@]}" 2>&1 | tee "$benchmark_log"
command_exit_code="${PIPESTATUS[0]}"
set -e
end_ms="$(date +%s%3N)"
duration_ms=$((end_ms - start_ms))

if [ "$command_exit_code" -eq 0 ] && [ -f "$benchmark_log" ]; then
  parsed_metric_ns="$(jq -R -s -r '
    def maybe_json_line:
      if startswith("[") then .
      else (capture("^(?<prefix>.*)(?<json>\\[\\{.*\\}\\])$")?.json // .)
      end;
    def extract_json_array:
      split("\n")
      | map(gsub("\r"; ""))
      | map(sub("[[:space:]]+$"; ""))
      | map(maybe_json_line)
      | map(fromjson? | select(type == "array"))
      | if length == 0 then null else .[-1] end;
    def to_ns:
      if type == "number" then (. * 1000)
      elif type == "string" then
        (gsub("μs"; "us") | gsub("µs"; "us")
        | capture("^(?<value>[0-9]+(?:\\.[0-9]+)?)\\s*(?<unit>ns|us|ms|s)$")?) as $m
        | if $m == null then null
          else
            ($m.value | tonumber) *
            (if $m.unit == "ns" then 1
             elif $m.unit == "us" then 1000
             elif $m.unit == "ms" then 1000000
             else 1000000000
             end)
          end
      else null
      end;
    (extract_json_array) as $rows
    | if $rows == null then ""
      else
        [
          $rows[]
          | (.mode? // .mean? // .time_avg? // empty)
          | to_ns
        ]
        | map(select(. != null))
        | if length == 0 then "" else (add / length | round | tostring) end
      end
  ' < "$benchmark_log")"

  if [ -n "$parsed_metric_ns" ] && [ "$parsed_metric_ns" != "null" ]; then
    benchmark_metric_ns="$parsed_metric_ns"
    benchmark_metric_source="phpbench_json"
  else
    benchmark_metric_ns=$((duration_ms * 1000000))
    benchmark_metric_source="elapsed"
  fi
else
  benchmark_metric_ns=$((duration_ms * 1000000))
  benchmark_metric_source="elapsed"
fi

echo "benchmark_command=${benchmark_command}" >> "$GITHUB_OUTPUT"
echo "duration_ms=${duration_ms}" >> "$GITHUB_OUTPUT"
echo "benchmark_metric_ns=${benchmark_metric_ns}" >> "$GITHUB_OUTPUT"
echo "benchmark_metric_source=${benchmark_metric_source}" >> "$GITHUB_OUTPUT"

if [ "$command_exit_code" -eq 0 ]; then
  echo "benchmark_status=success" >> "$GITHUB_OUTPUT"
else
  echo "benchmark_status=failure" >> "$GITHUB_OUTPUT"
  exit "$command_exit_code"
fi
