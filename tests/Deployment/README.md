# Deployment checks

Run on Linux or WSL with Bash and standard Unix tools:

```bash
bash -n centralctl.sh
bash tests/Deployment/centralctl_test.sh
```

The orchestration test copies the manager and environment example into an isolated temporary directory and substitutes Docker, Git and OpenSSL commands. It checks input validation, preserving secrets, HTTP/HTTPS switching, network attachment, one build per install, migrations before workers, failure exit codes, consistent backups and writer recovery, restore confirmation, dirty/divergent checkout rejection, and update/rollback state. It never operates the real Docker daemon or repository.

The mocks do not verify container builds, image dependencies, actual database restoration, SSL certificates, DNS or NPM routing. Verify those on a Docker host with:

```bash
bash centralctl.sh start
bash centralctl.sh doctor
curl -fsS https://YOUR_CENTRAL_DOMAIN/health
```

For the PHP proxy regression checks with development dependencies installed:

```bash
php artisan test --filter='ProxyHeadersTest|AdminSecurityTest'
```
