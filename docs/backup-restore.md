# Backup and restore

`sudo bash centralctl.sh backup` pauses the currently running web/app/queue/scheduler services, produces a permission-0600 archive with the PostgreSQL custom-format dump, private package volume, application storage and `.env` secrets, then restarts those services even if the snapshot fails. The database and Redis remain running. Copy backups off-host with encryption and define retention outside the application host. Never commit an archive.

Test recovery on an isolated server: install the same tagged Central version, copy the archive locally, run `sudo ./centralctl.sh restore /secure/path/archive.tar.gz`, then verify `/health`, login, package SHA-256 values, and a lease refresh. Restore requires the literal confirmation `RESTORE` because it overwrites the live database and package volume.

Before starting a new recovery installation, copy the backup's `environment` file to `.env` and adapt its domain/proxy network settings. Do not generate new application or signing keys; restore checks that they match. Existing environment settings are retained during restore. Older backups without `storage.tar.gz` restore the database and packages only. A failed destructive restore leaves writers stopped so the failure can be repaired before accepting traffic.

The database, package storage, configuration, and signing keys form one recovery unit. Losing the Ed25519 private key prevents new leases that validate against deployed agents; changing it requires a planned public-key rotation in Phase 2.
