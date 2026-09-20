# Production deployment

Use Debian 12 or Ubuntu 24.04 with Docker Engine, Compose v2, Git, OpenSSL, at least 2 GB RAM, and sufficient disk for PostgreSQL plus immutable packages. The existing host reverse proxy owns public ports 80 and 443. Office Central binds `0.0.0.0:8787` by default; restrict that port to the reverse proxy with the host firewall. Create the DNS `A`/`AAAA` records before installation.

```bash
git clone <private-central-repository> office-central
cd office-central
sudo ./centralctl.sh install --domain panel.ponet.ir --public-ip 203.0.113.10 --email admin@company.com
```

The installer validates the FQDN, generates application/database/Redis/Ed25519 secrets, builds the stack, migrates PostgreSQL, and securely prompts for the first administrator. Configure the existing Nginx UI or host proxy to send `panel.ponet.ir` to `http://127.0.0.1:8787` as described in [host Nginx proxy](host-nginx-proxy.md). TLS issuance and renewal remain in that existing proxy.

Run `sudo ./centralctl.sh doctor` after installation. Deploy updates only from reviewed tags with `sudo ./centralctl.sh update v1.2.0`. It requires a clean tree, verifies the tag against `VERSION`, backs up first, builds, migrates, and checks `/health`. Roll back application code with `sudo ./centralctl.sh rollback`; restore the paired backup if a migration was not backward-compatible.

Production Git access should use a repository-scoped read-only SSH deploy key. Pin the host in `known_hosts`; do not place personal tokens in `.env` or shell scripts. To move Central to a new IP, restore database/packages/secrets on the new host, verify locally, update DNS, and keep the canonical FQDN unchanged.
