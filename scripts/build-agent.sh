#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root/agent"
go test ./...
for arch in amd64 arm64; do
    CGO_ENABLED=0 GOOS=linux GOARCH="$arch" go build -trimpath -ldflags="-s -w" -o "$root/public/agent/office-agent-linux-$arch" .
done
cd "$root/public/agent"
chmod 0755 "$root/public" "$root/public/agent"
chmod 0755 office-agent-linux-*
sha256sum office-agent-linux-* > checksums.txt
