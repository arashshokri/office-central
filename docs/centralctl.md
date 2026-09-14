# centralctl operations

- `install [--domain FQDN --public-ip IP --email EMAIL --tls letsencrypt]`: idempotent first installation; refuses to overwrite `.env`.
- `resume`: resumes a failed first installation from the already generated `.env`, starts the stack, migrates, and creates the administrator.
- `status`, `start`, `stop`, `restart`, `logs`: operate the Compose stack.
- `doctor`: checks Docker, containers, DNS, disk/storage, migrations, and `/health`.
- `backup`: creates a mode-0600 archive containing a PostgreSQL custom dump, package volume, and environment/secrets.
- `restore ARCHIVE`: requires typing `RESTORE`, stops writers, rebuilds the database and package volume, then restarts workers.
- `update TAG`: requires a clean repository and an explicit tag; backs up, builds, migrates, and health-checks.
- `rollback [TAG]`: checks out the recorded previous tag and rebuilds. Database rollback requires a compatible migration or backup restore.

Store backups off-host with encryption and restricted access. Periodically perform a restore drill on an isolated stack.
