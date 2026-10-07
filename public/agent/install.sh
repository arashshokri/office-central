#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo 'Run as root.' >&2; exit 1; }
command -v curl >/dev/null && command -v python3 >/dev/null || { echo 'Install curl, python3 and CA certificates first.' >&2; exit 1; }
case "$(uname -m)" in x86_64) arch=amd64;; aarch64) arch=arm64;; *) echo 'Unsupported architecture.' >&2; exit 1;; esac
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
endpoint=https://update.ponet.ir
curl --fail --silent --show-error --proto '=https' --tlsv1.2 "$endpoint/agent/bootstrap.json" -o "$work/bootstrap.json"
name="office-agent-linux-$arch"
checksum="$(python3 - "$work/bootstrap.json" "$name" <<'PY'
import json, re, sys
value=json.load(open(sys.argv[1]))["files"][sys.argv[2]]
assert re.fullmatch(r"[a-f0-9]{64}",value)
print(value)
PY
)"
curl --fail --show-error --proto '=https' --tlsv1.2 "$endpoint/agent/$name" -o "$work/office-agent"
printf '%s  %s\n' "$checksum" "$work/office-agent" | sha256sum --check --status
chmod 0755 "$work/office-agent"
if ! docker compose version >/dev/null 2>&1; then
    checksum="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["files"]["install-docker.sh"])' "$work/bootstrap.json")"
    curl --fail --show-error --proto '=https' --tlsv1.2 "$endpoint/agent/install-docker.sh" -o "$work/install-docker.sh"
    printf '%s  %s\n' "$checksum" "$work/install-docker.sh" | sha256sum --check --status
    bash "$work/install-docker.sh"
fi
"$work/office-agent" install "$@"
