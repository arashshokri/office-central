# Office Central

Office Central is a production-oriented Laravel 12 control plane for products, customers, immutable releases, private packages, licenses, hardware-bound installations, signed access states, temporary locks, clone detection, security events, and audit history. The domain is product-neutral; Office is the first product configured by an administrator.

## Architecture

Laravel/PHP-FPM serves the bilingual admin panel and versioned Agent API. PostgreSQL is the source of truth, Redis backs cache, queues and rate limits through the pure-PHP Predis client, Nginx serves the application, package ZIPs live on private persistent storage, and Ed25519 signs access states independently of TLS. Compose runs `web`, `app`, `postgres`, `redis`, `queue-worker`, and `scheduler` with persistent volumes.

## Quick start

Production requires Linux, Docker Engine, Compose v2.20+, Git, OpenSSL, and an existing Nginx Proxy Manager (NPM):

```bash
git clone --branch v1.4.0-rc.12 https://github.com/arashshokri/office-central.git office-central
cd office-central
sudo bash centralctl.sh install --domain scm.ponet.ir --email admin@company.com --proxy-network proxynet
```

In NPM, add a Proxy Host for your domain with **Scheme `http`, Forward Hostname `office-central-web`, Forward Port `80`**. NPM and Central's web container must share `proxynet` (or the network specified at install). Central publishes no host ports; TLS certificates and Force SSL are managed in NPM. See the [Persian Docker/NPM guide](docs/docker-npm-fa.md), [deployment](docs/deployment.md), and [endpoint policy](docs/central-endpoint.md).

The canonical domain for this installation is **`scm.ponet.ir`**. The installer defaults to it; keep it stable for Agent integrations. The panel is available at `https://scm.ponet.ir/login` after configuring NPM and SSL.

Run `sudo bash centralctl.sh` for the interactive menu. `start` rebuilds/migrates the local code for web changes; `update` backs up and updates from GitHub `main`, or accepts a release tag. `proxy` displays the exact NPM settings. Use `--scheme http` at installation only when the domain is served over plain HTTP; switch to HTTPS with `proxy --scheme https` followed by `start` after enabling SSL in NPM.

## Admin panel

Run `php artisan office:create-admin` for an administrator. Roles are `super_admin`, `admin`, and read-only `viewer`. The UI supports Persian RTL and English LTR and stores each user's locale. Dashboard values query live data. Admins can create, edit and delete customers/products/releases, publish immutable releases, manage licenses, and inspect installations, security events, and audits. User management is available to super administrators. See [record and license management](docs/admin-records-fa.md) for deletion rules, recoverable license codes and the simplified repository connection flow.

## Agent API

Central also supports advanced customer/license/release search, manifest requirement details, reimporting deleted releases while preserving their original UUID/checksum/publication status, and a product capability catalog with per-license **future feature plans**. These plans are administrative preparation only: current Office agents continue installing the complete runtime. See the [Persian capability and modular roadmap guide](docs/modular-preparation-fa.md). No customer data is removed when changing a plan.

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

Office includes a standalone intelligent helper and the runtime license integration. Its default setup detects an existing Office deployment and connects it to Central after a health check, without redeploying the application or changing customer data. Issue an **attach_once** connection license; no runtime package or commercial encoder is required for this path.

For a new server, the owner-side `scripts/office-helper.py` builds ready Docker bundles from an Office folder, source ZIP or pinned GitHub ref. Source encoding with ionCube is optional. Upload the runtime ZIP to Central or attach `office-runtime-VERSION-amd64.zip` to the matching GitHub Release. The customer launcher detects fresh installation and downloads the assigned images.

Central candidate: `v1.4.0-rc.7`. Office integration: `v3.8.22`. See the [Persian operational guide](docs/office-agent-fa.md) and [protocol](docs/phase-2-agent.md). NPM routes the update domain to `office-central-update:80`; admin/login routes remain private to the Central panel domain. Existing v1 licenses remain compatible. Real Docker deployment on the customer server still requires verification.
