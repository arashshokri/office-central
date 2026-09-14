# Office Central

Office Central is a production-oriented Laravel 12 control plane for products, customers, immutable releases, private packages, licenses, hardware-bound installations, signed offline leases, clone detection, security events, and audit history. The domain is product-neutral; Office is the first product configured by an administrator.

## Architecture

Laravel/PHP-FPM serves the bilingual admin panel and versioned Agent API. PostgreSQL is the source of truth, Redis backs cache, queues and rate limits through the pure-PHP Predis client, Nginx serves the application, package ZIPs live on private persistent storage, and Ed25519 signs leases independently of TLS. Compose runs `app`, `nginx`, `postgres`, `redis`, `queue-worker`, and `scheduler` with persistent volumes.

## Quick start

Production requires Linux, Docker Engine, Compose v2, Git, and OpenSSL:

```bash
git clone <private-repository> office-central
cd office-central
sudo ./centralctl.sh install --domain license.company.com --public-ip 203.0.113.10 --email admin@company.com
```

Create an `A` record (and `AAAA` when used) before installation. Terminate automatically renewed ACME TLS at the edge. See [deployment](docs/deployment.md), [endpoint policy](docs/central-endpoint.md), and the [Persian administrator guide](docs/admin-guide-fa.md).

## Admin panel

Run `php artisan office:create-admin` for an administrator. Roles are `super_admin`, `admin`, and read-only `viewer`. The UI supports Persian RTL and English LTR and stores each user's locale. Dashboard values query live data. Admins can create customers/products, upload ZIP releases, publish immutable releases, generate hashed licenses, change license state, and inspect installations, security events, and audits.

## Agent API

- `POST /api/v1/licenses/activate`
- `POST /api/v1/installations/heartbeat`
- `POST /api/v1/installations/lease`
- `POST /api/v1/packages/token`
- `GET /api/v1/packages/download/{token}`
- `GET /health`

Authenticated requests require a bearer installation credential plus fresh `X-Request-Nonce` and `X-Request-Timestamp`. Full license keys and credentials are returned once and only SHA-256 hashes are stored. Package tokens are short-lived and single-use. See [OpenAPI](openapi/openapi.yaml).

## Licensing, clones, and outages

Activation locks the license row in a transaction before counting installations. First activation binds stable hardware identifiers. A copied installation presenting different hardware receives `LICENSE_HARDWARE_MISMATCH`; Central records a clone event and leaves the original active.

Every lease contains issued/expiry/server times, product/release, status, refresh and grace intervals, canonical endpoint, and signed hardware binding. Network, DNS, TLS, timeout, 502, and 503 failures are non-authoritative. A Phase 2 agent continues with its last valid signed lease through the configured offline grace. Only explicit security codes or grace expiry change access state. License failure never deletes customer files, uploads, or databases.

## Operations and testing

Use `centralctl.sh` for install, lifecycle, status, logs, doctor, backup, restore, tagged update, and rollback. Nginx Proxy Manager provides the reverse-proxy UI and ACME TLS; the application Nginx listens on loopback port 8787. See [Nginx Proxy Manager](docs/nginx-proxy-manager.md), [commands](docs/centralctl.md), [backup/restore](docs/backup-restore.md), and [updates](docs/update-rollback.md).

```bash
php artisan key:generate
php artisan migrate
php artisan test
```

Tests cover activation, one-time credentials, invalid states, installation limits, clone rejection with original preservation, replay rejection, and the offline-grace contract. Production Compose validation requires a host with Docker installed.

## Phase 2

The Office source and deployment topology are intentionally not assumed. Phase 2 adds the Go agent, real hardware collectors, cached state, boot enforcement, device signing, package installer/upgrader, and health reporting. See [agent integration](docs/phase-2-agent.md).
