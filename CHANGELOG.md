# Changelog

## 1.4.0-rc.4

- Install the standalone Go agent, customer launcher, owner source builder and validation workflow in the Office repository itself.
- Build protected Office runtime bundles from an owner folder, source ZIP or an explicitly selected GitHub ref without changing the original checkout.
- Import Office GitHub release runtime assets instead of source archives; verify manifest version, architecture, image hashes and GitHub asset digest.
- Explain missing build assets and source-version conflicts in repository sync; retain manual runtime uploads.
- Add populated license-form, GitHub delivery and unsafe source archive regression coverage.

## 1.4.0-rc.3

- Version panel/login CSS and JavaScript URLs to bypass stale proxy/CDN asset responses.
- Display the configured Central hostname in the sidebar.
- Fix /licenses/create being captured by the license detail route.
- Distinguish source archives from protected runtimes in license creation, retain form input and show actionable validation errors.
- Show customer installation commands and consumed-code guidance on installer licenses.

## 1.4.0-rc.2

- Repair restrictive Git checkout permissions inside the PHP image before running as www-data.
- Check application bootstrap readability in PHP health checks instead of reporting a broken runtime healthy.
- Prepare only the public Nginx bind mount for readable assets/helper downloads; preserve host environment, backup and key permissions.
- Use a scoped umask for Git checkout/merge while keeping secrets under umask 077.
- Add restrictive-checkout regression tests and a Docker bootstrap smoke check to CI.

## 1.4.0-rc.1

- Add a compiled Linux Office installer and persistent licensing daemon.
- Reserve one-use install codes until the assigned runtime passes health checks; retain runtime licenses after consumption.
- Sign device requests and state envelopes; bind state to hardware and installation identity, reject replay and forged responses.
- Isolate copied installations without changing the legitimate original; allow new-license reactivation with customer data intact.
- Add Office panel/API/job/schedule and websocket/RDP license enforcement.
- Add an update-only Nginx entry for update.ponet.ir and protected runtime package validation/import.
- Build encoded customer images separately from readable owner images; require a licensed ionCube PHP 8.4 encoder and loader.
- Preserve database volumes and APP_KEY; take database/storage snapshots before migrations on existing installations.

This is a release candidate. Real Linux Docker/encoder/clone smoke tests remain required; no remote customer deployment was performed by this workspace.
