# Canonical endpoint and outage policy

Agents store the stable URL `https://panel.ponet.ir`, never a raw IP. Create an `A` record from `panel.ponet.ir` to the Central IPv4 address with TTL 300; add an `AAAA` record for IPv6. Treat this FQDN as the service identity. When the server IP changes, update DNS and leave every agent configuration unchanged.

Temporary DNS, TLS, routing, timeout, any unsigned HTTP error, malformed response, or an unavailable Central are communication failures. They never lock Office. The Agent keeps its last valid Ed25519-signed state indefinitely, continues serving Office, retries safely, and records a local warning. There is no offline expiry. Only a newer signed state revision can change access.

TLS terminates at the reverse proxy and may renew normally through ACME. Agents trust the platform CA chain and do not pin a leaf certificate. State authenticity uses a separate Ed25519 key: the private key stays only in Central secrets; the public key ships with the Agent. A certificate renewal therefore cannot invalidate cached state.

For a domain migration, publish the new endpoint through a signed endpoint configuration, operate both names during a grace window, update agent configuration, and retire the old name only after adoption is measured. The signing public key, installation identity, license history, and package hashes remain stable throughout the migration.
