#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
trap 'code=$?; printf "ERROR: command failed at line %s (exit %s). Run centralctl.sh doctor or logs.\n" "$LINENO" "$code" >&2; exit "$code"' ERR

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
BACKUP_DIR="${BACKUP_DIR:-$ROOT_DIR/backups}"
# Keep the project name stable to reuse existing persistent volumes.
COMPOSE=(docker compose --project-name office-central --project-directory "$ROOT_DIR" --env-file "$ROOT_DIR/.env" -f "$ROOT_DIR/docker-compose.yml")

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null || die "$1 is required."; }
require_env() { [[ -f "$ROOT_DIR/.env" ]] || die '.env does not exist; run install first.'; }
require_docker() {
    need docker
    docker compose version >/dev/null || die 'Docker Compose v2 is required.'
    docker info >/dev/null 2>&1 || die 'Docker is unavailable. Start Docker or use sudo if socket access requires it.'
}
env_value() {
    local value
    value="$(sed -n "s/^$1=//p" "$ROOT_DIR/.env" | tail -n1)"
    value="${value%$'\r'}"
    if [[ "$value" == \"*\" || "$value" == \'*\' ]]; then value="${value:1:${#value}-2}"; fi
    printf '%s' "$value"
}
set_env() {
    local temp
    temp="$(mktemp "$ROOT_DIR/.env.installing.XXXXXX")"
    ENV_VALUE="$2" awk -v key="$1" '
        index($0, key "=") == 1 { if (!seen++) print key "=" ENVIRON["ENV_VALUE"]; next }
        { sub(/\r$/, ""); print }
        END { if (!seen) print key "=" ENVIRON["ENV_VALUE"] }
    ' "$ROOT_DIR/.env" > "$temp"
    chmod 600 "$temp"; mv -- "$temp" "$ROOT_DIR/.env"
}
option_value() { [[ $# -ge 2 && -n "$2" && "$2" != --* ]] || die "Missing value for $1."; }
validate_domain() {
    [[ ${#1} -le 253 && "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || die 'Invalid domain (use a hostname without https:// or a port).'
}
validate_network() { [[ "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || die 'Invalid Docker network name.'; }
ensure_proxy_network() {
    local network container
    network="$(env_value PROXY_NETWORK)"; network="${network:-proxynet}"
    container="$(env_value PROXY_CONTAINER)"
    validate_network "$network"
    if [[ -n "$container" ]]; then
        docker inspect --type container "$container" >/dev/null 2>&1 || die "Proxy container '$container' does not exist. Use its actual name with proxy --container NAME."
    fi
    if ! docker network inspect "$network" >/dev/null 2>&1; then
        [[ -n "$container" ]] || die "Network '$network' does not exist. Run: bash centralctl.sh proxy --network $network --container YOUR_NPM_CONTAINER"
        docker network create "$network" >/dev/null
    fi
    if [[ -n "$container" ]] && ! docker inspect --type container --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' "$container" | grep -Fxq "$network"; then
        docker network connect "$network" "$container"
        printf 'Connected %s to %s. Also declare this external network in NPM Compose so it survives container recreation.\n' "$container" "$network"
    fi
}
proxy_info() {
    printf '\nNginx Proxy Manager / Add Proxy Host\n'
    printf '  Domain Names: %s\n' "$(env_value CENTRAL_FQDN)"
    printf '  Scheme: http\n  Forward Hostname / IP: office-central-web\n  Forward Port: 80\n'
    printf '  Shared Docker network: %s\n' "$(env_value PROXY_NETWORK)"
    if [[ "$(env_value APP_URL)" == https://* ]]; then
        printf '  SSL: request/select a certificate, enable Force SSL.\n'
    else
        printf '  HTTP mode: use proxy --scheme https after configuring SSL.\n'
    fi
    printf '  Advanced (for ZIP uploads): client_max_body_size 1024m;\n'
    printf 'Panel: %s/login\n' "$(env_value APP_URL)"
}
health_cmd() {
    require_env; require_docker
    "${COMPOSE[@]}" exec -T web wget -q -O - http://127.0.0.1/health || return 1
    printf '\n'
}
build_app() { "${COMPOSE[@]}" build --pull app; }
deploy_stack_without_build() {
    set_env CENTRAL_VERSION "$(tr -d '[:space:]' < "$ROOT_DIR/VERSION")"
    # Migrate before the new web, queue and scheduler processes accept work.
    "${COMPOSE[@]}" stop web queue-worker scheduler
    "${COMPOSE[@]}" up -d --wait --wait-timeout 180 postgres redis app
    "${COMPOSE[@]}" exec -T app php artisan migrate --force
    "${COMPOSE[@]}" exec -T app php artisan optimize:clear
    "${COMPOSE[@]}" exec -T app php artisan optimize
    "${COMPOSE[@]}" up -d --wait --wait-timeout 180
    health_cmd
    git -C "$ROOT_DIR" rev-parse HEAD > "$ROOT_DIR/.deployed-version" 2>/dev/null || true
}
deploy_stack() {
    ensure_proxy_network; "${COMPOSE[@]}" config --quiet
    build_app; deploy_stack_without_build
}
create_admin_cmd() {
    require_env; require_docker
    local email="${1:-$(env_value ACME_EMAIL)}"
    [[ -t 0 && -t 1 ]] || die 'Admin setup requires a terminal. Run bash centralctl.sh admin EMAIL interactively.'
    # Artisan prompts securely: passwords never appear in shell arguments.
    "${COMPOSE[@]}" exec app php artisan office:create-admin --email="$email" --name=Administrator
}
configure_domain() {
    set_env APP_URL "$2://$1"; set_env CENTRAL_PUBLIC_URL "$2://$1"
    set_env CENTRAL_FQDN "$1"; set_env TLS_MODE proxy
    if [[ "$2" == https ]]; then
        set_env SESSION_SECURE_COOKIE true; set_env CENTRAL_REQUIRE_HTTPS true
    else
        set_env SESSION_SECURE_COOKIE false; set_env CENTRAL_REQUIRE_HTTPS false
    fi
}
generate_signing_keys() {
    local private public signing
    private="$(env_value CENTRAL_SIGNING_PRIVATE_KEY)"; public="$(env_value CENTRAL_SIGNING_PUBLIC_KEY)"
    if [[ -n "$private" || -n "$public" ]]; then
        [[ -n "$private" && -n "$public" ]] || die 'Only one signing key exists; repair the key pair before continuing.'
        return
    fi
    signing="$("${COMPOSE[@]}" run --rm --no-deps -T app php -r '$p=sodium_crypto_sign_keypair();echo sodium_bin2base64(sodium_crypto_sign_secretkey($p),7)," ",sodium_bin2base64(sodium_crypto_sign_publickey($p),7);')"
    [[ "$signing" == *' '* ]] || die 'Ed25519 key generation failed.'
    set_env CENTRAL_SIGNING_PRIVATE_KEY "${signing% *}"
    set_env CENTRAL_SIGNING_PUBLIC_KEY "${signing#* }"
}
install_cmd() {
    require_docker; need openssl
    [[ ! -f "$ROOT_DIR/.env" ]] || die '.env already exists; use resume to complete installation, or start to rebuild.'
    local domain=scm.ponet.ir email='' network=proxynet container='' scheme=https skip_admin=false
    while (($#)); do
        case "$1" in
            --domain) option_value "$@"; domain="${2,,}"; shift 2;;
            --email) option_value "$@"; email="$2"; shift 2;;
            --proxy-network) option_value "$@"; network="$2"; shift 2;;
            --proxy-container) option_value "$@"; container="$2"; shift 2;;
            --scheme) option_value "$@"; scheme="$2"; shift 2;;
            --skip-admin) skip_admin=true; shift;;
            --public-ip) option_value "$@"; printf 'DNS is managed externally; --public-ip is no longer required.\n'; shift 2;;
            --tls) option_value "$@"; [[ "$2" == proxy || "$2" == letsencrypt ]] || die 'TLS is terminated in Nginx Proxy Manager.'; shift 2;;
            *) die "Unknown option $1";;
        esac
    done
    [[ -n "$email" ]] || read -rp 'Administrator email: ' email
    validate_domain "$domain"; validate_network "$network"
    [[ "$email" =~ ^[[:alnum:]._%+-]+@[[:alnum:].-]+\.[[:alpha:]]{2,}$ ]] || die 'Invalid email address.'
    [[ "$scheme" == https || "$scheme" == http ]] || die '--scheme must be https or http.'
    [[ "$skip_admin" == true || ( -t 0 && -t 1 ) ]] || die 'Interactive admin setup needs a terminal. Use --skip-admin, then run centralctl.sh admin EMAIL.'
    cp -- "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"; chmod 600 "$ROOT_DIR/.env"
    set_env APP_KEY "base64:$(openssl rand -base64 32)"
    set_env DB_PASSWORD "$(openssl rand -hex 32)"
    set_env REDIS_PASSWORD "$(openssl rand -hex 32)"
    set_env PROXY_NETWORK "$network"; set_env PROXY_CONTAINER "$container"
    configure_domain "$domain" "$scheme"; set_env ACME_EMAIL "$email"
    ensure_proxy_network; "${COMPOSE[@]}" config --quiet
    build_app; generate_signing_keys; deploy_stack_without_build
    [[ "$skip_admin" == true ]] || create_admin_cmd "$email"
    proxy_info
}
resume_cmd() {
    require_env; require_docker
    ensure_proxy_network; "${COMPOSE[@]}" config --quiet
    build_app; generate_signing_keys; deploy_stack_without_build
    create_admin_cmd; proxy_info
}
proxy_cmd() {
    require_env; require_docker
    local domain network container scheme changed=false
    domain="$(env_value CENTRAL_FQDN)"; network="$(env_value PROXY_NETWORK)"; network="${network:-proxynet}"
    container="$(env_value PROXY_CONTAINER)"; scheme="$(env_value APP_URL)"; scheme="${scheme%%:*}"
    while (($#)); do
        changed=true
        case "$1" in
            --domain) option_value "$@"; domain="${2,,}"; shift 2;;
            --network|--proxy-network) option_value "$@"; network="$2"; shift 2;;
            --container|--proxy-container) option_value "$@"; container="$2"; shift 2;;
            --scheme) option_value "$@"; scheme="$2"; shift 2;;
            *) die "Unknown option $1";;
        esac
    done
    if [[ "$changed" == true ]]; then
        validate_domain "$domain"; validate_network "$network"
        [[ "$scheme" == https || "$scheme" == http ]] || die '--scheme must be https or http.'
        set_env PROXY_NETWORK "$network"; set_env PROXY_CONTAINER "$container"
        configure_domain "$domain" "$scheme"; ensure_proxy_network
        printf 'Proxy configuration saved. Run bash centralctl.sh start to apply it.\n'
    fi
    proxy_info
}
backup_cmd() (
    require_env; require_docker; need tar
    mkdir -p -- "$BACKUP_DIR"
    local work archive app_id running
    archive="$BACKUP_DIR/office-central-$(date -u +%Y%m%dT%H%M%SZ)-$$.tar.gz"
    work="$(mktemp -d "$BACKUP_DIR/.backup-work.XXXXXX")"
    trap 'rm -rf -- "$work"' EXIT
    app_id="$("${COMPOSE[@]}" ps -aq app)"; [[ -n "$app_id" ]] || die 'Start the application before backing up.'
    # Pause writers for a consistent database + files snapshot, restoring
    # exactly their previous running state even if the backup fails.
    running="$("${COMPOSE[@]}" ps --services --status running | grep -E '^(web|app|queue-worker|scheduler)$' || true)"
    local -a writers=()
    while IFS= read -r service; do [[ -z "$service" ]] || writers+=("$service"); done <<< "$running"
    trap 'code=$?; if ((${#writers[@]})); then "${COMPOSE[@]}" start "${writers[@]}" || code=1; fi; rm -rf -- "$work"; exit "$code"' EXIT
    if ((${#writers[@]})); then "${COMPOSE[@]}" stop "${writers[@]}"; fi
    "${COMPOSE[@]}" exec -T postgres pg_dump -U "$(env_value DB_USERNAME)" -d "$(env_value DB_DATABASE)" -Fc > "$work/database.dump"
    cp -- "$ROOT_DIR/.env" "$work/environment"
    docker run --rm --volumes-from "$app_id:ro" -v "$work:/backup" alpine:3.21 tar czf /backup/packages.tar.gz -C /srv/office-central/packages .
    docker run --rm --volumes-from "$app_id:ro" -v "$work:/backup" alpine:3.21 tar czf /backup/storage.tar.gz -C /var/www/html/storage .
    tar czf "$archive" -C "$work" .; chmod 600 "$archive"
    printf 'Backup: %s\n' "$archive"
)
restore_cmd() (
    require_env; require_docker
    local archive="${1:-}" confirm work app_id
    [[ -f "$archive" ]] || die 'Backup archive is required.'
    archive="$(cd -- "$(dirname -- "$archive")" && pwd -P)/$(basename -- "$archive")"
    work="$(mktemp -d)"; trap 'rm -rf -- "$work"' EXIT
    # Only restore a trusted archive; it contains secrets.
    tar xzf "$archive" -C "$work"
    for file in database.dump environment packages.tar.gz; do [[ -f "$work/$file" ]] || die "Backup is missing $file."; done
    for key in APP_KEY CENTRAL_SIGNING_PRIVATE_KEY CENTRAL_SIGNING_PUBLIC_KEY; do
        [[ "$(sed -n "s/^$key=//p" "$work/environment" | tail -n1)" == "$(env_value "$key")" ]] || die "Backup $key differs. Restore the backup environment on an isolated installation first."
    done
    read -rp 'Restore overwrites the database, packages and storage. Type RESTORE: ' confirm
    [[ "$confirm" == RESTORE ]] || die 'Cancelled.'
    app_id="$("${COMPOSE[@]}" ps -aq app)"; [[ -n "$app_id" ]] || die 'Start the application before restoring.'
    "${COMPOSE[@]}" stop web app queue-worker scheduler
    "${COMPOSE[@]}" exec -T postgres dropdb -U "$(env_value DB_USERNAME)" --if-exists "$(env_value DB_DATABASE)"
    "${COMPOSE[@]}" exec -T postgres createdb -U "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)"
    "${COMPOSE[@]}" exec -T postgres pg_restore -U "$(env_value DB_USERNAME)" -d "$(env_value DB_DATABASE)" --no-owner --no-privileges < "$work/database.dump"
    docker run --rm --volumes-from "$app_id" -v "$work:/backup:ro" alpine:3.21 sh -c 'find /srv/office-central/packages -mindepth 1 -delete; tar xzf /backup/packages.tar.gz -C /srv/office-central/packages'
    if [[ -f "$work/storage.tar.gz" ]]; then
        docker run --rm --volumes-from "$app_id" -v "$work:/backup:ro" alpine:3.21 sh -c 'find /var/www/html/storage -mindepth 1 -delete; tar xzf /backup/storage.tar.gz -C /var/www/html/storage'
    fi
    "${COMPOSE[@]}" start app
    "${COMPOSE[@]}" exec -T app php artisan optimize:clear
    "${COMPOSE[@]}" exec -T app php artisan optimize
    "${COMPOSE[@]}" up -d --wait --wait-timeout 180
    health_cmd
)
require_clean_git() {
    need git
    [[ -z "$(git -C "$ROOT_DIR" status --porcelain)" ]] || die 'Repository has local changes. Commit or save them before update/rollback.'
}
update_cmd() {
    require_env; require_docker; require_clean_git
    local ref="${1:-main}" target expected previous
    [[ "$ref" != -* ]] || die 'Invalid release reference.'
    git -C "$ROOT_DIR" fetch origin --tags --prune
    if [[ "$ref" == main ]]; then
        target=refs/remotes/origin/main
        git -C "$ROOT_DIR" merge-base --is-ancestor HEAD "$target" || die 'Local history diverges from origin/main; resolve it before updating.'
    else
        target="refs/tags/$ref"
        git -C "$ROOT_DIR" rev-parse --verify "$target^{commit}" >/dev/null || die 'Release tag does not exist.'
        expected="v$(git -C "$ROOT_DIR" show "$target:VERSION" | tr -d '[:space:]')"
        [[ "$ref" == "$expected" ]] || die "Tag does not match VERSION ($expected)."
    fi
    previous="$(git -C "$ROOT_DIR" rev-parse HEAD)"
    backup_cmd
    printf '%s\n' "$previous" > "$ROOT_DIR/.previous-release"
    if [[ "$ref" == main && "$(git -C "$ROOT_DIR" branch --show-current)" == main ]]; then
        git -C "$ROOT_DIR" merge --ff-only "$target"
    else
        git -C "$ROOT_DIR" checkout --detach "$target"
    fi
    deploy_stack
    printf 'Updated to %s.\n' "$ref"
}
rollback_cmd() {
    require_env; require_docker; require_clean_git
    local ref="${1:-$(cat "$ROOT_DIR/.previous-release" 2>/dev/null || true)}" previous
    [[ -n "$ref" && "$ref" != -* ]] || die 'No previous release recorded. Supply a release tag or commit.'
    git -C "$ROOT_DIR" rev-parse --verify "$ref^{commit}" >/dev/null || die 'Rollback reference does not exist locally.'
    previous="$(git -C "$ROOT_DIR" rev-parse HEAD)"
    backup_cmd
    git -C "$ROOT_DIR" checkout --detach "$ref"
    printf '%s\n' "$previous" > "$ROOT_DIR/.previous-release"
    deploy_stack
    printf 'Application rollback completed. Database migrations were not reversed.\n'
}
doctor_cmd() {
    require_env; require_docker
    local failed=0 network container
    printf 'Office Central diagnostics\n'
    "${COMPOSE[@]}" config --quiet || failed=1
    "${COMPOSE[@]}" ps -a
    network="$(env_value PROXY_NETWORK)"; network="${network:-proxynet}"
    docker network inspect "$network" --format 'Proxy network: {{.Name}}' || failed=1
    container="$(env_value PROXY_CONTAINER)"
    if [[ -n "$container" ]]; then
        if docker inspect --type container --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' "$container" | grep -Fxq "$network"; then
            printf 'Proxy container shares the network: OK\n'
        else printf 'Proxy container is missing from the shared network.\n'; failed=1; fi
    fi
    "${COMPOSE[@]}" exec -T web nginx -t || failed=1
    "${COMPOSE[@]}" exec -T app sh -c 'test -w /srv/office-central/packages && test -w /var/www/html/storage' || failed=1
    health_cmd || failed=1
    "${COMPOSE[@]}" exec -T app php artisan migrate:status || failed=1
    df -h "$ROOT_DIR"; proxy_info
    return "$failed"
}
usage() {
    cat <<'HELP'
Office Central Docker manager (Bash, Docker Compose v2)
  bash centralctl.sh                       Interactive menu
  bash centralctl.sh install --email admin@example.com
       [--domain scm.ponet.ir]
       [--proxy-network proxynet] [--proxy-container NPM_CONTAINER]
       [--scheme https|http] [--skip-admin]
  bash centralctl.sh resume                Complete an interrupted installation
  bash centralctl.sh start                 Build, migrate and start / apply configuration
  bash centralctl.sh stop|restart|status|health|doctor
  bash centralctl.sh logs [SERVICE]
  bash centralctl.sh admin [EMAIL]          Create/reset administrator interactively
  bash centralctl.sh proxy                 Show Nginx Proxy Manager settings
  bash centralctl.sh proxy --domain scm.ponet.ir --network proxynet
       [--container NPM_CONTAINER] [--scheme https|http]
  bash centralctl.sh backup
  bash centralctl.sh restore ARCHIVE
  bash centralctl.sh update [main|TAG]      Backup, fetch, build, migrate (default: main)
  bash centralctl.sh rollback [TAG|COMMIT]  Backup and return to previous code
No host ports are published. NPM forwards HTTP to office-central-web:80.
HTTPS certificates are managed in NPM, not in this script.
HELP
}
menu() {
    [[ -t 0 ]] || { usage; return; }
    local choice ref
    while true; do
        printf '\nOffice Central\n1) Install\n2) Start / rebuild\n3) Update\n4) Status\n5) Logs\n6) NPM settings\n7) Backup\n8) Rollback\n9) Doctor\n10) Resume install\n11) Admin\n12) Stop\n0) Exit\n'
        read -rp 'Choose: ' choice || return
        case "$choice" in
            1) install_cmd;; 2) require_env; require_docker; deploy_stack;;
            3) read -rp 'Release tag or main [main]: ' ref; update_cmd "${ref:-main}";;
            4) require_env; require_docker; "${COMPOSE[@]}" ps -a;;
            5) require_env; require_docker; "${COMPOSE[@]}" logs -f --tail=200;;
            6) proxy_cmd;; 7) backup_cmd;; 8) rollback_cmd;; 9) doctor_cmd;;
            10) resume_cmd;; 11) create_admin_cmd;;
            12) require_env; require_docker; "${COMPOSE[@]}" stop;;
            0) return;; *) printf 'Invalid choice.\n';;
        esac
    done
}
main() {
    local cmd="${1:-menu}"
    if (($#)); then shift; fi
    case "$cmd" in
        install) install_cmd "$@";; resume) resume_cmd;;
        start) require_env; require_docker; deploy_stack;;
        stop|restart) require_env; require_docker; "${COMPOSE[@]}" "$cmd";;
        status) require_env; require_docker; "${COMPOSE[@]}" ps -a;;
        logs) require_env; require_docker; "${COMPOSE[@]}" logs -f --tail=200 "$@";;
        health) health_cmd;; admin) create_admin_cmd "$@";; proxy) proxy_cmd "$@";;
        backup) backup_cmd;; restore) restore_cmd "$@";; update) update_cmd "$@";;
        rollback) rollback_cmd "$@";; doctor) doctor_cmd;; menu) menu;;
        help|--help|-h) usage;; *) usage; die "Unknown command $cmd";;
    esac
}
# Parse this list before a checkout can replace the script on disk.
main "$@"; exit $?
