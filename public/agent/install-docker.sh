#!/usr/bin/env bash
# Docker's signed official apt repository; never remove an existing engine.
set -euo pipefail
if docker compose version >/dev/null 2>&1; then exit 0; fi
if command -v docker >/dev/null; then
    echo 'Docker is installed but Compose v2 is missing. Install the Compose plugin for your existing Docker distribution, then rerun.' >&2
    exit 1
fi
. /etc/os-release
case "$ID" in ubuntu|debian) ;; *) echo 'Install Docker Engine and Compose v2 manually on this distribution.' >&2; exit 1;; esac
apt-get update
apt-get install -y ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl --fail --silent --show-error --proto '=https' --tlsv1.2 "https://download.docker.com/linux/$ID/gpg" -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/office-docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/$ID
Suites: $VERSION_CODENAME
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker
docker compose version
