# Backup and restore

`sudo ./centralctl.sh backup` produces a permission-0600 archive with the PostgreSQL custom-format dump, private package volume, and `.env` secrets. Copy it to encrypted off-host storage and define retention outside the application host. Never commit an archive.

Test recovery on an isolated server: install the same tagged Central version, copy the archive locally, run `sudo ./centralctl.sh restore /secure/path/archive.tar.gz`, then verify `/health`, login, package SHA-256 values, and a lease refresh. Restore requires the literal confirmation `RESTORE` because it overwrites the live database and package volume.

The database, package storage, configuration, and signing keys form one recovery unit. Losing the Ed25519 private key prevents new leases that validate against deployed agents; changing it requires a planned public-key rotation in Phase 2.
