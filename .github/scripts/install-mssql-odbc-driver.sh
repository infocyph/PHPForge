#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
runtime_file="${PHPFORGE_RUNTIME_FILE:-${root}/resources/runtime.php}"
driver_major="$(php -r '$runtime = require $argv[1]; echo $runtime["service_client_versions"]["mssql_odbc"] ?? "";' "$runtime_file")"

if [[ ! "$driver_major" =~ ^[0-9]+$ ]]; then
  echo "::error::Invalid MSSQL ODBC driver version in ${runtime_file}."
  exit 1
fi

package="msodbcsql${driver_major}"
driver_name="ODBC Driver ${driver_major} for SQL Server"

if command -v odbcinst >/dev/null 2>&1 && odbcinst -q -d -n "$driver_name" >/dev/null 2>&1; then
  echo "${driver_name} ready"
  exit 0
fi

if [[ ! -r /etc/os-release ]]; then
  echo "::error::Cannot determine the Linux distribution required to install ${driver_name}."
  exit 1
fi

# shellcheck source=/dev/null
source /etc/os-release

case "${ID:-}" in
  ubuntu|debian)
    ;;
  *)
    echo "::error::Automatic ${driver_name} installation supports Ubuntu and Debian runners only."
    exit 1
    ;;
esac

if [[ -z "${VERSION_ID:-}" ]]; then
  echo "::error::Cannot determine the Linux release required to install ${driver_name}."
  exit 1
fi

sudo_command=()
if ((EUID != 0)); then
  sudo_command=(sudo)
fi

temporary_directory="$(mktemp -d)"
trap 'rm -rf -- "$temporary_directory"' EXIT
repository_package="${temporary_directory}/packages-microsoft-prod.deb"
repository_url="https://packages.microsoft.com/config/${ID}/${VERSION_ID}/packages-microsoft-prod.deb"

curl --fail --location --silent --show-error "$repository_url" --output "$repository_package"
"${sudo_command[@]}" dpkg -i "$repository_package"
"${sudo_command[@]}" apt-get update -qq
"${sudo_command[@]}" env ACCEPT_EULA=Y DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$package"

if ! command -v odbcinst >/dev/null 2>&1 || ! odbcinst -q -d -n "$driver_name" >/dev/null 2>&1; then
  echo "::error::${driver_name} was not registered after installing ${package}."
  exit 1
fi

echo "${driver_name} ready"
