#!/usr/bin/env bash
set -euo pipefail
# Only this public bind mount is served by Nginx. Never follow storage
# symlinks or relax permissions on the host .env, backups or private keys.
root="$(cd "$(dirname "$0")/.." && pwd)"
public_dir="${1:-$root/public}"
[[ -d "$public_dir" && ! -L "$public_dir" ]] || { echo 'Invalid public directory.' >&2; exit 1; }
find "$public_dir" -type d -exec chmod 0755 {} +
find "$public_dir" -type f -exec chmod 0644 {} +
