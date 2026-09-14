# Canonical endpoint and outage policy

Agents store one stable URL such as `https://license.company.com`, never a raw IP. Create an `A` record from `license.company.com` to the Central IPv4 address with TTL 300; add an `AAAA` record for IPv6. Treat this FQDN as the service identity. When the server IP changes, update DNS and leave every agent configuration unchanged.

Temporary DNS, TLS, routing, timeout, HTTP 502, or HTTP 503 failures are communication failures. The agent keeps the last valid Ed25519-signed lease until its `expires_at`, continues serving Office, retries at `refresh_after_seconds`, and records a local warning. It locks only after an explicit structured response such as `LICENSE_REVOKED`, `LICENSE_SUSPENDED`, `LICENSE_HARDWARE_MISMATCH`, `INSTALLATION_LOCKED`, or `SECURITY_VIOLATION`, or after the signed offline grace expires according to its last trusted server time.

TLS terminates at the reverse proxy and may renew normally through ACME. Agents trust the platform CA chain and do not pin a leaf certificate. Lease authenticity uses a separate Ed25519 key: the private key stays only in Central secrets; the public key ships with the Phase 2 agent. A certificate renewal therefore cannot invalidate cached leases.

For a domain migration, publish the new endpoint through a signed endpoint configuration, operate both names during a grace window, update agent configuration, and retire the old name only after adoption is measured. The signing public key, installation identity, license history, and package hashes remain stable throughout the migration.
