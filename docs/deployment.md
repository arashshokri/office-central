# Production deployment

Use Debian 12 or Ubuntu 24.04 with Docker Engine, Compose v2.20+, Git, OpenSSL, at least 2 GB RAM, and sufficient disk for PostgreSQL plus immutable packages. Nginx Proxy Manager owns the public ports; Central publishes no host ports. Both web containers must share an external Docker network. Create DNS `A`/`AAAA` records for your NPM host before enabling SSL.

```bash
git clone --branch v1.3.0 https://github.com/arashshokri/office-central.git office-central
cd office-central
sudo bash centralctl.sh install --domain scm.ponet.ir --email admin@company.com --proxy-network proxynet
```

The installer validates the FQDN, generates application/database/Redis/Ed25519 secrets, builds the stack, migrates PostgreSQL, and securely prompts for the first administrator. Add an NPM Proxy Host for the domain with scheme `http`, hostname `office-central-web`, port `80`; configure its certificate and Force SSL there. Use `--proxy-container NAME` if the installer needs to create/connect the shared network. Persist the NPM network connection in its Compose configuration. See [NPM setup](host-nginx-proxy.md) or the [Persian walkthrough](docker-npm-fa.md).

Run `sudo bash centralctl.sh doctor` after installation. For local web edits, `start` rebuilds the image, clears/recreates caches and applies migrations. `update` defaults to GitHub `main`; for reviewed releases use `update TAG`. It requires a clean tree, verifies tags against `VERSION`, backs up first, builds, migrates, and checks `/health` internally. Roll back application code with `rollback`; restore the paired backup if a migration was not backward-compatible. Use only releases supporting the external proxy network on this installation.

Production Git access should use a repository-scoped read-only SSH deploy key. Pin the host in `known_hosts`; do not place personal tokens in `.env` or shell scripts. To move Central to a new IP, restore database/packages/secrets on the new host, verify locally, update DNS, and keep the canonical FQDN unchanged.
