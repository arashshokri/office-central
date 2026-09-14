# Production deployment

Use Debian 12 or Ubuntu 24.04 with Docker Engine, Compose v2, Git, OpenSSL, at least 2 GB RAM, and sufficient disk for PostgreSQL plus immutable packages. Open public TCP 80 and 443. Keep ports 81 and 8787 on loopback. Create the DNS `A`/`AAAA` records before installation.

```bash
git clone <private-central-repository> office-central
cd office-central
sudo ./centralctl.sh install --domain license.company.com --public-ip 203.0.113.10 --email admin@company.com
```

The installer validates the FQDN, generates application/database/Redis/Ed25519 secrets, builds the stack, migrates PostgreSQL, and securely prompts for the first administrator. The included Nginx Proxy Manager publishes ports 80/443 and forwards to the internal `nginx:80`; its UI is loopback-only on port 81. Configure `my.ponet.ir` and Let's Encrypt as described in [Nginx Proxy Manager](nginx-proxy-manager.md). The internal application Nginx is bound to `127.0.0.1:8787`.

Run `sudo ./centralctl.sh doctor` after installation. Deploy updates only from reviewed tags with `sudo ./centralctl.sh update central-v1.1.0`. It requires a clean tree, backs up first, builds, migrates, and checks `/health`. Roll back application code with `sudo ./centralctl.sh rollback`; restore the paired backup if a migration was not backward-compatible.

Production Git access should use a repository-scoped read-only SSH deploy key. Pin the host in `known_hosts`; do not place personal tokens in `.env` or shell scripts. To move Central to a new IP, restore database/packages/secrets on the new host, verify locally, update DNS, and keep the canonical FQDN unchanged.
