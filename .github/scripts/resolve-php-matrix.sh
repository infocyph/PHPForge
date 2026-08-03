#!/usr/bin/env bash

set -euo pipefail

: "${GITHUB_OUTPUT:?GITHUB_OUTPUT must be set}"

requested_versions="${INPUT_PHP_VERSIONS:-[]}"
supported_versions="${SUPPORTED_PHP_VERSIONS:-[]}"

filtered_versions="$(
  jq -cer \
    --argjson supported "$supported_versions" \
    '
      if type != "array" then
        error("php_versions must be an array")
      elif ($supported | type) != "array" or any($supported[]; type != "string") then
        error("supported PHP versions must be a string array")
      else
        reduce .[] as $requested (
          [];
          if
            ($requested | type) == "string"
            and any(
              $supported[];
              . as $supported_cycle
              | $requested == $supported_cycle
                or ($requested | startswith($supported_cycle + "."))
            )
            and (index($requested) == null)
          then
            . + [$requested]
          else
            .
          end
        )
      end
    ' <<< "$requested_versions"
)"

clean_install_php_version="$(jq -r 'if length > 0 then .[-1] else "" end' <<< "$filtered_versions")"
has_supported_php_versions="$(jq -r 'length > 0' <<< "$filtered_versions")"

{
  echo "php_versions=${filtered_versions}"
  echo "clean_install_php_version=${clean_install_php_version}"
  echo "has_supported_php_versions=${has_supported_php_versions}"
} >> "$GITHUB_OUTPUT"
