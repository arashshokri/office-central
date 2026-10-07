# Office Central

Office Central is a production-oriented Laravel 12 control plane for products, customers, immutable releases, private packages, licenses, hardware-bound installations, signed access states, temporary locks, clone detection, security events, and audit history. The domain is product-neutral; Office is the first product configured by an administrator.

## Architecture

Laravel/PHP-FPM serves the bilingual admin panel and versioned Agent API. PostgreSQL is the source of truth, Redis backs cache, queues and rate limits through the pure-PHP Predis client, Nginx serves the application, package ZIPs live on private persistent storage, and Ed25519 signs access states independently of TLS. Compose runs `web`, `app`, `postgres`, `redis`, `queue-worker`, and `scheduler` with persistent volumes.

## Quick start

Production requires Linux, Docker Engine, Compose v2.20+, Git, OpenSSL, and an existing Nginx Proxy Manager (NPM):

```bash
git clone --branch v1.3.0 https://github.com/arashshokri/office-central.git office-central
cd office-central
sudo bash centralctl.sh install --domain scm.ponet.ir --email admin@company.com --proxy-network proxynet
```

In NPM, add a Proxy Host for your domain with **Scheme `http`, Forward Hostname `office-central-web`, Forward Port `80`**. NPM and Central's web container must share `proxynet` (or the network specified at install). Central publishes no host ports; TLS certificates and Force SSL are managed in NPM. See the [Persian Docker/NPM guide](docs/docker-npm-fa.md), [deployment](docs/deployment.md), and [endpoint policy](docs/central-endpoint.md).

The canonical domain for this installation is **`scm.ponet.ir`**. The installer defaults to it; keep it stable for Agent integrations. The panel is available at `https://scm.ponet.ir/login` after configuring NPM and SSL.

Run `sudo bash centralctl.sh` for the interactive menu. `start` rebuilds/migrates the local code for web changes; `update` backs up and updates from GitHub `main`, or accepts a release tag. `proxy` displays the exact NPM settings. Use `--scheme http` at installation only when the domain is served over plain HTTP; switch to HTTPS with `proxy --scheme https` followed by `start` after enabling SSL in NPM.

## Admin panel

Run `php artisan office:create-admin` for an administrator. Roles are `super_admin`, `admin`, and read-only `viewer`. The UI supports Persian RTL and English LTR and stores each user's locale. Dashboard values query live data. Admins can create customers/products, upload ZIP releases, publish immutable releases, generate hashed licenses, change license state, and inspect installations, security events, and audits.

## Agent API

- `POST /api/v1/agent/activate`
- `POST /api/v1/agent/state`
- `POST /api/v1/installations/heartbeat`
- `POST /api/v1/packages/token`
- `GET /api/v1/packages/download/{token}`
- `GET /health`

Authenticated requests require a bearer installation credential plus fresh `X-Request-Nonce` and `X-Request-Timestamp`. Full license keys and credentials are returned once and only SHA-256 hashes are stored. Package tokens are short-lived and single-use. See [OpenAPI](openapi/openapi.yaml).

## Licensing, temporary locks, clones, and outages

Activation locks the license row in a transaction before counting installations. First activation binds stable hardware identifiers. A copied installation presenting different hardware receives `LICENSE_HARDWARE_MISMATCH`; Central records a clone event and leaves the original active.

Every state contains a monotonic revision, access decision, optional customer-facing message, product/release target, canonical endpoint, and signed hardware binding. Network, DNS, TLS, timeout, and HTTP 5xx failures are non-authoritative and never lock Office. An Agent keeps its last signed state indefinitely. Only a newer, valid Ed25519-signed state changes access. A temporary lock only displays a support screen; it never deletes customer files, uploads, or databases.

## Operations and testing

Use `centralctl.sh` for install, lifecycle, status, logs, doctor, backup, restore, update, and rollback. The application Nginx listens on container port 80, reachable by NPM at `office-central-web:80`. See [commands](docs/centralctl.md), [backup/restore](docs/backup-restore.md), and [updates](docs/update-rollback.md).

```bash
php artisan key:generate
php artisan migrate
php artisan test
```

Tests cover activation, one-time credentials, signed state revisions, temporary lock/unlock, invalid states, installation limits, clone rejection with original preservation, replay rejection, roles, MFA, and indefinite fail-open behavior during Central outages. Production Compose execution requires a host with Docker installed.

## Office customer helper (release candidate)

The compiled Linux helper uses `https://update.ponet.ir`, signed v2 device requests, hardware-bound access states, one-use install reservations and health-confirmed consumption. It loads protected runtime images, preserves customer volumes and encryption keys, snapshots existing data before migrations, and supports in-panel reactivation after a hardware transfer. The `customer` Office build requires a licensed ionCube encoder and matching loader; raw GitHub ZIPs cannot be installed with this protocol. See the [complete Persian setup guide](docs/office-agent-fa.md) and [protocol](docs/phase-2-agent.md).

The candidate tag is `v1.4.0-rc.1`. Customer Docker/ionCube smoke testing is still required on Linux before a production release. Central's update-only Nginx service is reachable by NPM at `office-central-update:80`; it exposes no admin/login routes. Existing v1 licenses remain compatible. Build helpers with `bash centralctl.sh agent-build` or `bash scripts/build-agent.sh` when Go is available.
