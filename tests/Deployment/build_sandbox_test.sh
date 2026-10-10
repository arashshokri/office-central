#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo 'Run this isolated CI test as root.' >&2; exit 1; }
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
docker buildx version
work="$(mktemp -d /var/lib/office-build-test.XXXXXXXX)"
cleanup() {
    case "$work" in /var/lib/office-build-test.*) rm -rf -- "$work";; esac
}
trap cleanup EXIT
cd "$repo_root/agent"
CGO_ENABLED=0 go test -c -o "$work/office-helper.test"
systemd-run --quiet --wait --pipe --collect \
    --unit="office-build-test-${work##*.}" \
    --property=ProtectHome=true --property=ProtectSystem=full \
    --property=NoNewPrivileges=true --property=UMask=0077 \
    --setenv="OFFICE_BUILDX_SANDBOX_ROOT=$work" \
    --setenv=DOCKER_CONFIG=/root/.docker \
    --setenv=BUILDX_CONFIG=/root/.docker/buildx \
    "$work/office-helper.test" -test.run='^Test(BuildOfficeImage|OfficeSourceWebRuntime)InServiceSandbox$' -test.v -test.timeout=6m
