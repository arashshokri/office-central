#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo 'Run this isolated permission test as root.' >&2; exit 1; }
repo="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
chmod 0755 "$work"
umask 077
app_root="$work/app"
mkdir -p "$app_root/bootstrap" "$app_root/public/agent"
printf '<?php // protected checkout fixture\n' > "$app_root/bootstrap/app.php"
printf 'public installer\n' > "$app_root/public/agent/install.sh"
printf 'private fixture\n' > "$work/environment"
ln -s "$work/environment" "$app_root/public/storage"
if runuser -u www-data -- test -r "$app_root/bootstrap/app.php"; then
    echo 'Fixture did not reproduce the restricted checkout.' >&2; exit 1
fi
bash "$repo/docker/php/prepare-runtime.sh" "$app_root"
runuser -u www-data -- cat "$app_root/bootstrap/app.php" > "$work/read-result"
cmp "$app_root/bootstrap/app.php" "$work/read-result"
if runuser -u www-data -- test -w "$app_root/bootstrap/app.php"; then
    echo 'Runtime can modify root-owned code.' >&2; exit 1
fi
[[ $(stat -c %a "$work/environment") == 600 ]]
chmod 0700 "$app_root/public" "$app_root/public/agent"
chmod 0600 "$app_root/public/agent/install.sh"
bash "$repo/scripts/prepare-public.sh" "$app_root/public"
runuser -u www-data -- cat "$app_root/public/agent/install.sh" > "$work/public-result"
cmp "$app_root/public/agent/install.sh" "$work/public-result"
[[ $(stat -c %a "$work/environment") == 600 ]]
[[ -L "$app_root/public/storage" ]]
printf 'PASS: PHP and Nginx can read a restricted checkout; code remains root-owned and private symlink targets stay private.\n'
