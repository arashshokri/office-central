#!/usr/bin/env bash
# Exercise deployment orchestration in an isolated directory with mocked Docker
# and Git. This does not substitute for building/running the real Compose stack.
set -Eeuo pipefail
REPO="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
BASH_BIN="${BASH:-bash}"
temp_root="$(cd -- "${TMPDIR:-/tmp}" && pwd -P)"
work="$(mktemp -d "$temp_root/centralctl-test.XXXXXX")"
[[ "$work" == "$temp_root"/centralctl-test.* ]] || exit 1
trap 'rm -rf -- "$work"' EXIT
mkdir -p "$work/project" "$work/bin" "$work/state/sample"
mkdir -p "$work/project/scripts" "$work/project/public/agent"
cp "$REPO/scripts/prepare-public.sh" "$work/project/scripts/"
cp "$REPO/centralctl.sh" "$REPO/.env.example" "$REPO/VERSION" "$work/project/"
printf 'sample data\n' > "$work/state/sample/file"
export MOCK_LOG="$work/commands" MOCK_STATE="$work/state" BACKUP_DIR="$work/project/backups"
export MOCK_VERSION_FILE="$work/project/VERSION"
export PATH="$work/bin:$PATH"
: > "$MOCK_LOG"

cat > "$work/bin/docker" <<'MOCK'
#!/usr/bin/env sh
set -eu
if [ "$1" = compose ]; then
    shift
    while [ "$#" -gt 0 ]; do
        case "$1" in --project-name|--project-directory|--env-file|-f) shift 2;; *) break;; esac
    done
    printf 'compose %s\n' "$*" >> "$MOCK_LOG"
    cmd="$1"; shift
    case "$cmd" in
        config) exit "${MOCK_CONFIG_FAIL:-0}";;
        run) printf 'test-private-key test-public-key';;
        ps)
            case "$*" in
                '-aq app') printf 'app-container\n';;
                '--services --status running') printf 'web\napp\nqueue-worker\nscheduler\n';;
            esac;;
        exec)
            case "$*" in
                *pg_dump*) [ "${MOCK_DUMP_FAIL:-0}" = 0 ] || exit 7; printf 'test-database-dump\n';;
                *'migrate --force'*) exit "${MOCK_MIGRATE_FAIL:-0}";;
                *wget*) [ "${MOCK_HEALTH_FAIL:-0}" = 0 ] || exit 8; printf '{"status":"ok"}';;
            esac;;
    esac
    exit 0
fi
printf 'docker %s\n' "$*" >> "$MOCK_LOG"
case "$1" in
    inspect)
        case "$*" in *--format*) [ ! -f "$MOCK_STATE/connected" ] || printf 'proxynet\n';; esac;;
    network)
        case "$2" in
            inspect) [ "${MOCK_NETWORK_MISSING:-0}" = 0 ] || [ -f "$MOCK_STATE/network" ];;
            create) touch "$MOCK_STATE/network";;
            connect) touch "$MOCK_STATE/connected";;
        esac;;
    run)
        args="$*"; mount=''
        while [ "$#" -gt 0 ]; do
            if [ "$1" = -v ]; then mount="${2%:/backup}"; shift 2; else shift; fi
        done
        case "$args" in
            *'tar czf /backup/packages.tar.gz'*) tar czf "$mount/packages.tar.gz" -C "$MOCK_STATE/sample" .;;
            *'tar czf /backup/storage.tar.gz'*) tar czf "$mount/storage.tar.gz" -C "$MOCK_STATE/sample" .;;
        esac;;
esac
MOCK
cat > "$work/bin/git" <<'MOCK'
#!/usr/bin/env sh
set -eu
printf 'git %s\n' "$*" >> "$MOCK_LOG"
if [ "$1" = -C ]; then shift 2; fi
case "$1" in
    status) [ "${MOCK_DIRTY:-0}" = 0 ] || printf ' M app/example.php\n';;
    rev-parse) printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n';;
    branch) printf 'main\n';;
    show) cat "$MOCK_VERSION_FILE";;
    merge-base) exit "${MOCK_DIVERGED:-0}";;
esac
MOCK
cat > "$work/bin/openssl" <<'MOCK'
#!/usr/bin/env sh
case "$*" in
    *-base64*) printf 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n';;
    *-hex*) printf '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\n';;
esac
MOCK
chmod +x "$work/bin/"*
checks=0
fail() { printf 'FAIL: %s\n' "$*" >&2; cat "$work/output" >&2; exit 1; }
pass() { checks=$((checks + 1)); printf 'PASS: %s\n' "$1"; }
run() { "$BASH_BIN" "$work/project/centralctl.sh" "$@" > "$work/output" 2>&1; }
expect_failure() { if run "$@"; then fail "Expected failure: $*"; fi; }
env_has() { grep -Fxq "$1" "$work/project/.env" || fail "Missing environment setting: $1"; }
logged() { grep -Fq -- "$1" "$MOCK_LOG" || fail "Missing command: $1"; }
not_logged() { if grep -Fq -- "$1" "$MOCK_LOG"; then fail "Unexpected command: $1"; fi; }

expect_failure install --domain https://invalid.example.com --email admin@example.com --skip-admin
[[ ! -f "$work/project/.env" ]] || fail 'Invalid input created an environment'
expect_failure install --domain
pass 'Invalid domains and missing option values fail before environment creation'

run install --domain CENTRAL.EXAMPLE.COM --email admin@example.com --scheme http --skip-admin
env_has APP_URL=http://central.example.com
env_has SESSION_SECURE_COOKIE=false
env_has CENTRAL_REQUIRE_HTTPS=false
env_has CENTRAL_SIGNING_PRIVATE_KEY=test-private-key
env_has CENTRAL_SIGNING_PUBLIC_KEY=test-public-key
[[ $(grep -c 'compose build' "$MOCK_LOG") -eq 1 ]] || fail 'Install built the app more than once'
not_logged --password
pass 'HTTP installation generates keys once and builds once without exposing admin passwords'

cp "$work/project/.env" "$work/original-env"
expect_failure install --domain other.example.com --email admin@example.com --skip-admin
cmp -s "$work/project/.env" "$work/original-env" || fail 'Install overwrote the existing environment'
pass 'Repeated installation preserves environment and secrets'

export MOCK_NETWORK_MISSING=1
run proxy --domain secure.example.com --scheme https --container proxy-nginx
unset MOCK_NETWORK_MISSING
env_has APP_URL=https://secure.example.com
env_has SESSION_SECURE_COOKIE=true
env_has CENTRAL_REQUIRE_HTTPS=true
env_has CENTRAL_SIGNING_PRIVATE_KEY=test-private-key
logged 'docker network create proxynet'
logged 'docker network connect proxynet proxy-nginx'
pass 'HTTPS/domain changes preserve keys and create/connect the shared proxy network'

: > "$MOCK_LOG"
printf 'public installer\n' > "$work/project/public/agent/install.sh"
chmod 0700 "$work/project/public" "$work/project/public/agent"
chmod 0600 "$work/project/public/agent/install.sh"
run start
[[ $(stat -c %a "$work/project/public/agent/install.sh") == 644 ]] || fail 'Nginx cannot read a restrictive checkout'
[[ $(stat -c %a "$work/project/public/agent") == 755 ]] || fail 'Nginx cannot traverse public directories'
[[ $(stat -c %a "$work/project/.env") == 600 ]] || fail 'Public preparation exposed the private environment'
pass 'Restrictive public checkout is repaired while environment secrets stay private'
logged 'compose exec -T app php artisan optimize:clear'
logged 'compose exec -T app php artisan optimize'
migrate_line="$(grep -n 'migrate --force' "$MOCK_LOG" | cut -d: -f1)"
start_line="$(grep -n 'compose up -d --wait --wait-timeout 180$' "$MOCK_LOG" | cut -d: -f1)"
[[ "$migrate_line" -lt "$start_line" ]] || fail 'Workers started before migrations'
pass 'Start rebuilds, migrates and refreshes caches before starting workers'

: > "$MOCK_LOG"; export MOCK_MIGRATE_FAIL=9
expect_failure start
unset MOCK_MIGRATE_FAIL
if grep -Fxq 'compose up -d --wait --wait-timeout 180' "$MOCK_LOG"; then fail 'Started all services after a failed migration'; fi
pass 'Migration failure does not start the new web/workers'

export MOCK_HEALTH_FAIL=1
expect_failure health
expect_failure doctor
unset MOCK_HEALTH_FAIL
pass 'Health failures propagate through health and doctor exit codes'

: > "$MOCK_LOG"
run backup
archive="$(find "$work/project/backups" -maxdepth 1 -name '*.tar.gz' | head -n1)"
[[ -n "$archive" ]] || fail 'Backup archive missing'
tar tzf "$archive" > "$work/archive-files"
for file in database.dump environment packages.tar.gz storage.tar.gz; do
    grep -Fxq "./$file" "$work/archive-files" || fail "Backup missing $file"
done
logged 'compose stop web app queue-worker scheduler'
logged 'compose start web app queue-worker scheduler'
pass 'Consistent backup includes database, packages, storage and environment and resumes writers'

: > "$MOCK_LOG"; export MOCK_DUMP_FAIL=1
expect_failure backup
unset MOCK_DUMP_FAIL
logged 'compose start web app queue-worker scheduler'
[[ -z "$(find "$work/project/backups" -maxdepth 1 -name '.backup-work.*' -print)" ]] || fail 'Failed backup leaked temporary data'
pass 'Failed backups resume writers and clean up temporary secrets'

: > "$MOCK_LOG"
expect_failure restore "$archive" <<< CANCEL
not_logged dropdb
run restore "$archive" <<< RESTORE
logged 'pg_restore -U office_central -d office_central --no-owner --no-privileges'
pass 'Restore requires confirmation and restores before accepting traffic'

: > "$MOCK_LOG"; export MOCK_DIRTY=1
expect_failure update
unset MOCK_DIRTY
not_logged pg_dump
export MOCK_DIVERGED=1
expect_failure update
unset MOCK_DIVERGED
not_logged pg_dump
pass 'Dirty or divergent repositories are rejected before backup/code changes'

: > "$MOCK_LOG"
run update
logged 'merge --ff-only refs/remotes/origin/main'
[[ -s "$work/project/.previous-release" ]] || fail 'Previous commit not recorded'
run rollback
logged 'checkout --detach aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
pass 'Update defaults to main and rollback records/restores the previous commit with backups'

: > "$MOCK_LOG"
expect_failure update v0.0.0
not_logged pg_dump
release_tag="v$(tr -d '[:space:]' < "$work/project/VERSION")"
run update "$release_tag"
logged "checkout --detach refs/tags/$release_tag"
env_has "CENTRAL_VERSION=${release_tag#v}"
pass 'Tagged updates verify VERSION and deploy the selected release'

export MOCK_CONFIG_FAIL=1
expect_failure doctor
unset MOCK_CONFIG_FAIL
pass 'Invalid Compose configuration fails diagnostics'

cp "$work/project/.env" "$work/complete-env"
sed 's/^CENTRAL_SIGNING_PUBLIC_KEY=.*/CENTRAL_SIGNING_PUBLIC_KEY=/' "$work/complete-env" > "$work/project/.env"
: > "$MOCK_LOG"
expect_failure resume
not_logged 'compose run'
not_logged 'migrate --force'
grep -q 'Only one signing key exists' "$work/output" || fail 'Incomplete key pair was not detected'
cp "$work/complete-env" "$work/project/.env"
pass 'Interrupted key generation never silently rotates an existing private key'

rm -- "$work/project/.env"
run install --email admin@example.com --skip-admin
env_has APP_URL=https://scm.ponet.ir
env_has CENTRAL_PUBLIC_URL=https://scm.ponet.ir
env_has CENTRAL_FQDN=scm.ponet.ir
pass 'Default installation uses the fixed scm.ponet.ir domain'

printf '%s checks passed (mocked orchestration; real Docker runtime still requires verification).\n' "$checks"
