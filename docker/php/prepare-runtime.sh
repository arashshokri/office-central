#!/usr/bin/env bash
set -euo pipefail
# Docker COPY preserves checkout modes. A checkout under umask 077 must
# still be readable by the unprivileged PHP runtime; code stays root-owned.
app_root="${1:-/var/www/html}"
[[ -d "$app_root" && ! -L "$app_root" ]] || { echo 'Invalid application directory.' >&2; exit 1; }
find "$app_root" -type d -exec chmod 0755 {} +
find "$app_root" -type f -exec chmod 0644 {} +
