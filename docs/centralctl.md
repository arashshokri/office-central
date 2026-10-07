# centralctl operations

- No arguments: interactive management menu. Use `bash centralctl.sh` (with `sudo` only if required for Docker access).
- `install [--domain FQDN --email EMAIL --proxy-network NETWORK --proxy-container NAME --scheme https|http --skip-admin]`: first installation; refuses to overwrite `.env`. Generates secrets, builds, migrates and prompts for an administrator. NPM handles TLS; `https` is the public scheme by default, `http` supports an HTTP-only preview. `--skip-admin` permits noninteractive install; run `admin EMAIL` later. Legacy `--public-ip` / `--tls letsencrypt` options are accepted but do not configure certificates.
- `resume`: resumes a failed first installation from the already generated `.env`, starts the stack, migrates, and creates the administrator.
- `start`: rebuilds the local app image, migrates, clears/rebuilds caches and starts all services with readiness checks. Use after web edits or `.env` changes.
- `status`, `stop`, `restart`, `logs [SERVICE]`: operate the Compose stack. `restart` does not apply new environment or rebuild code.
- `admin [EMAIL]`: creates/resets the administrator with a secure interactive password prompt.
- `proxy`: prints NPM settings. `proxy --domain FQDN --network NETWORK [--container NAME --scheme https|http]` saves changes and connects the proxy if specified; run `start` to apply them. Declare the external network in NPM's Compose file to persist its connection.
- `health`: calls `/health` inside the web container without using a host port.
- `doctor`: checks Docker, Compose configuration, shared proxy network, Nginx syntax, disk, migrations and internal `/health`; exits nonzero when a check fails.
- `backup`: briefly stops running web/app/queue/scheduler services for a consistent mode-0600 archive with a PostgreSQL dump, package and application storage volumes, and environment/secrets. Restarts exactly those previously running services, including on failure.
- `restore ARCHIVE`: validates archive files and matching encryption/signing keys, requires typing `RESTORE`, stops writers, rebuilds the database/packages/storage and starts the stack. Existing environment is retained. For a new host, provision the saved environment before starting services. Older archives without application storage are accepted.
- `update [main|TAG]`: defaults to remote `main`, requires a clean repository, rejects divergent history, backs up, builds, migrates and health-checks. Explicit tags must match `VERSION`.
- `rollback [TAG|COMMIT]`: backs up, checks out the recorded previous commit (or supplied ref) and rebuilds. Database rollback requires compatible migrations or backup restore. Release code must support this installation's proxy configuration.

Store backups off-host with encryption and restricted access. Periodically perform a restore drill on an isolated stack.
