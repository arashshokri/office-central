#!/usr/bin/env bash
set -euo pipefail
# Standalone Office build: no Office Central checkout or PHP dependencies needed.
root="$(cd "$(dirname "$0")/.." && pwd)"
output="$root/dist/office-agent"
mkdir -p "$output"
cd "$root/agent"
go test ./...
go vet ./...
for arch in amd64 arm64; do
    CGO_ENABLED=0 GOOS=linux GOARCH="$arch" go build -trimpath -ldflags="-s -w" -o "$output/office-agent-linux-$arch" .
done
cp "$root/office-install.sh" "$output/install.sh"
cp "$root/agent/install-docker.sh" "$output/install-docker.sh"
cd "$output"
chmod 0755 office-agent-linux-* install.sh install-docker.sh
sha256sum office-agent-linux-* install.sh install-docker.sh > checksums.txt
printf 'Office agent artifacts: %s\n' "$output"
