#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
trap 'code=$?; printf "ERROR: command failed at line %s (exit %s).\n" "$LINENO" "$code" >&2; exit "$code"' ERR
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
BACKUP_DIR="${BACKUP_DIR:-$ROOT_DIR/backups}"
COMPOSE=(docker compose --project-name office-central --project-directory "$ROOT_DIR" -f "$ROOT_DIR/docker-compose.yml")
die(){ printf 'ERROR: %s\n' "$*" >&2; exit 1; }
need_root(){ [[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run with sudo.'; }
need(){ command -v "$1" >/dev/null || die "$1 is required."; }
env_value(){ sed -n "s/^$1=//p" "$ROOT_DIR/.env" | tail -n1; }
random_b64(){ openssl rand -base64 48 | tr -d '\n'; }
usage(){ echo 'Usage: centralctl.sh install|resume|start|stop|restart|status|logs|backup|restore <archive>|update <tag>|rollback [tag]|doctor'; }
install_cmd(){
  need_root; need docker; need openssl; docker compose version >/dev/null
  local domain='' email='' public_ip='' tls='letsencrypt'
  while (($#)); do case "$1" in --domain) domain="$2";shift 2;;--email) email="$2";shift 2;;--public-ip) public_ip="$2";shift 2;;--tls) tls="$2";shift 2;;*)die "Unknown option $1";;esac;done
  [[ -f "$ROOT_DIR/.env" ]] && die '.env already exists; it was not overwritten.'
  [[ -n "$domain" ]] || read -rp 'Central FQDN: ' domain; [[ "$domain" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || die 'Invalid FQDN.'
  [[ -n "$email" ]] || read -rp 'Admin email: ' email; [[ -n "$public_ip" ]] || read -rp 'Server public IP: ' public_ip
  local resolved; resolved="$(getent ahostsv4 "$domain" 2>/dev/null|awk 'NR==1{print $1}')" || true
  [[ "$resolved" == "$public_ip" ]] || printf 'WARNING: %s resolves to %s; expected %s. Fix DNS before TLS.\n' "$domain" "${resolved:-nothing}" "$public_ip"
  local appkey signing db_password redis_password env_tmp
  appkey="base64:$(openssl rand -base64 32)"
  db_password="$(random_b64)"; redis_password="$(random_b64)"
  signing="$(docker run --rm php:8.3-cli php -r '$p=sodium_crypto_sign_keypair();echo sodium_bin2base64(sodium_crypto_sign_secretkey($p),7)," ",sodium_bin2base64(sodium_crypto_sign_publickey($p),7);')"
  [[ "$signing" == *' '* ]] || die 'Ed25519 key generation failed.'
  env_tmp="$(mktemp "$ROOT_DIR/.env.installing.XXXXXX")"
  cp "$ROOT_DIR/.env.example" "$env_tmp"
  sed -i "s|^APP_URL=.*|APP_URL=https://$domain|;s|^CENTRAL_PUBLIC_URL=.*|CENTRAL_PUBLIC_URL=https://$domain|;s|^CENTRAL_FQDN=.*|CENTRAL_FQDN=$domain|;s|^ACME_EMAIL=.*|ACME_EMAIL=$email|;s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|;s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$redis_password|;s|^APP_KEY=.*|APP_KEY=$appkey|;s|^CENTRAL_SIGNING_PRIVATE_KEY=.*|CENTRAL_SIGNING_PRIVATE_KEY=${signing% *}|;s|^CENTRAL_SIGNING_PUBLIC_KEY=.*|CENTRAL_SIGNING_PUBLIC_KEY=${signing#* }|;s|^TLS_MODE=.*|TLS_MODE=$tls|" "$env_tmp"
  mv "$env_tmp" "$ROOT_DIR/.env"
  "${COMPOSE[@]}" up -d --build; "${COMPOSE[@]}" exec -T app php artisan migrate --force
  local admin_password; read -rsp 'Initial admin password (12+ characters): ' admin_password; echo
  "${COMPOSE[@]}" exec -T app php artisan office:create-admin --email="$email" --name=Administrator --password="$admin_password"; "${COMPOSE[@]}" exec -T app php artisan optimize
  echo "Installed on: http://127.0.0.1:80"
  echo "Point $domain to this server; the web service listens directly on port 80."
  echo "Preserve Host and forward X-Forwarded-For, X-Forwarded-Host and X-Forwarded-Proto."
}
resume_cmd(){
  need_root; need docker; [[ -f "$ROOT_DIR/.env" ]] || die '.env does not exist; run install instead.'
  "${COMPOSE[@]}" up -d --build
  "${COMPOSE[@]}" exec -T app php artisan migrate --force
  local email admin_password; email="$(env_value ACME_EMAIL)"; [[ -n "$email" ]] || read -rp 'Admin email: ' email
  read -rsp 'Initial admin password (12+ characters): ' admin_password; echo
  "${COMPOSE[@]}" exec -T app php artisan office:create-admin --email="$email" --name=Administrator --password="$admin_password"
  "${COMPOSE[@]}" exec -T app php artisan optimize
  "${COMPOSE[@]}" ps
  curl -fsS "http://127.0.0.1:80/health"; echo
}
backup_cmd(){
  need_root; mkdir -p "$BACKUP_DIR"; local stamp archive work; stamp="$(date -u +%Y%m%dT%H%M%SZ)"; archive="$BACKUP_DIR/office-central-$stamp.tar.gz"; work="$(mktemp -d)"; trap 'rm -rf -- "$work"' RETURN
  "${COMPOSE[@]}" exec -T postgres pg_dump -U "$(env_value DB_USERNAME)" -d "$(env_value DB_DATABASE)" -Fc > "$work/database.dump"; cp "$ROOT_DIR/.env" "$work/environment"
  docker run --rm -v office-central_package_data:/source:ro -v "$work:/backup" alpine tar czf /backup/packages.tar.gz -C /source .; tar czf "$archive" -C "$work" .; chmod 600 "$archive"; echo "$archive"
}
restore_cmd(){
  need_root; local archive="${1:-}"; [[ -f "$archive" ]] || die 'Backup archive is required.'; local confirm; read -rp 'Restore overwrites database and packages. Type RESTORE: ' confirm; [[ "$confirm" == RESTORE ]] || die 'Cancelled.'
  local work; work="$(mktemp -d)"; trap 'rm -rf -- "$work"' RETURN; tar xzf "$archive" -C "$work"; "${COMPOSE[@]}" stop app queue-worker scheduler
  "${COMPOSE[@]}" exec -T postgres dropdb -U "$(env_value DB_USERNAME)" --if-exists "$(env_value DB_DATABASE)"; "${COMPOSE[@]}" exec -T postgres createdb -U "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)"
  "${COMPOSE[@]}" exec -T postgres pg_restore -U "$(env_value DB_USERNAME)" -d "$(env_value DB_DATABASE)" --clean --if-exists < "$work/database.dump"
  docker run --rm -v office-central_package_data:/target -v "$work:/backup:ro" alpine sh -c 'find /target -mindepth 1 -delete; tar xzf /backup/packages.tar.gz -C /target'; "${COMPOSE[@]}" start app queue-worker scheduler
}
update_cmd(){
  need_root; need git; local tag="${1:-}"; [[ -n "$tag" ]] || die 'Specify an approved release tag.'; git -C "$ROOT_DIR" diff --quiet && git -C "$ROOT_DIR" diff --cached --quiet || die 'Repository has uncommitted changes.'
  git -C "$ROOT_DIR" fetch --tags --prune; git -C "$ROOT_DIR" rev-parse -q --verify "refs/tags/$tag" >/dev/null || die 'Tag does not exist.'
  local expected_tag; expected_tag="v$(git -C "$ROOT_DIR" show "$tag:VERSION" | tr -d '[:space:]')"; [[ "$tag" == "$expected_tag" ]] || die "Release tag $tag does not match VERSION ${expected_tag#v}."
  backup_cmd; git -C "$ROOT_DIR" describe --tags --exact-match HEAD 2>/dev/null > "$ROOT_DIR/.previous-release" || true; git -C "$ROOT_DIR" checkout --detach "$tag"
  if ! "${COMPOSE[@]}" up -d --build || ! "${COMPOSE[@]}" exec -T app php artisan migrate --force || ! curl -fsS "http://127.0.0.1:80/health" >/dev/null; then echo 'Update failed; inspect migrations before rollback.'; exit 1; fi; echo "$tag" > "$ROOT_DIR/.deployed-version"
}
rollback_cmd(){ need_root; local tag="${1:-$(cat "$ROOT_DIR/.previous-release" 2>/dev/null)}"; [[ -n "$tag" ]]||die 'No previous release recorded.'; git -C "$ROOT_DIR" checkout --detach "$tag"; "${COMPOSE[@]}" up -d --build; curl -fsS "http://127.0.0.1:80/health" >/dev/null; }
doctor_cmd(){ need docker; echo 'Office Central diagnostics'; "${COMPOSE[@]}" ps; docker info >/dev/null&&echo 'Docker: OK'; [[ -w "$ROOT_DIR/storage" ]]&&echo 'Storage: OK'||echo 'Storage: CHECK'; df -h "$ROOT_DIR"; if [[ -f "$ROOT_DIR/.env" ]]; then local domain; domain="$(env_value CENTRAL_FQDN)"; getent hosts "$domain"||true; curl -fsS "http://127.0.0.1:80/health"||true; "${COMPOSE[@]}" exec -T app php artisan migrate:status||true; fi; }
cmd="${1:-}"; shift || true
case "$cmd" in install)install_cmd "$@";;resume)resume_cmd;;start|stop|restart)need_root;"${COMPOSE[@]}" "$cmd";;status)"${COMPOSE[@]}" ps;;logs)"${COMPOSE[@]}" logs -f --tail=200;;backup)backup_cmd;;restore)restore_cmd "$@";;update)update_cmd "$@";;rollback)rollback_cmd "$@";;doctor)doctor_cmd;;*)usage;exit 1;;esac
