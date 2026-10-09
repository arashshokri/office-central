# Changelog

## 1.4.0-rc.16 — 2026-10-09

- Creating a product with the slug of a soft-deleted product restores that product with the submitted details and preserves its numeric ID, UUID and audit history. Deleted releases and revoked licenses are not restored.
- Preserve database slug uniqueness and reject active collisions or renaming another product into a deleted identity with a localized form error. Serialize restoration and handle concurrent create collisions without a server error.
- Verify restored product identity, admin permissions, retained historical installation/release links, and signed revocation after parent restoration.

## 1.4.0-rc.15 — 2026-10-09

- Recognize validated Office source ZIPs by their contents on GitHub import and manual upload, independently of the existing product slug. Retained archives are re-indexed without replacing packages, product identities or licenses.
- Keep the signed v2 runtime product identity canonical (`office`) so existing Office license verification accepts packages for a customized Central product slug.
- Report source inspection totals and precise rejection reasons even when no archive needs indexing; permit an empty legacy manifest to be filled while keeping validated published manifests immutable.
- Upload ZIPs in authenticated 256 KiB chunks with bounded retries, real overall progress and same-page continuation. Validate ownership, size, offset and repeated chunk contents; recover partial writes after worker interruption.
- Reuse normal package and release validation for final submission, cache successful saves for lost-response retries, preserve uploaded files for form corrections and expire abandoned private uploads after 24 hours.
- Add regression tests for custom-product GitHub/source licensing and signed installation, interrupted/repeated uploads, final validation, cross-user access and cleanup. Document accepted GitHub ZIP structure and server upgrade steps.

## 1.4.0-rc.14 — 2026-10-09

- Install a fresh Office server from a validated GitHub source ZIP using the managed build and immutable local infrastructure image IDs. Consume the one-use code only after the health/receipt flow succeeds; reject installation over unrelated existing databases.
- Recheck retained Office archives on release/license pages and repository resync, eliminating dependence on a manual index command. Report validation failures instead of a generic runtime-only instruction.
- Normalize public customer domains and HTTP inputs to HTTPS with explicit form guidance and localized URL/email errors.
- Render panel dates using Jalali in the Tehran timezone and replace native Gregorian expiry inputs with a themed Jalali calendar. Keep API/database ISO dates unchanged.

## 1.4.0-rc.13 — 2026-10-09

- Import Office Git tags/source ZIPs alongside ready GitHub runtime assets; validate helper integration and VERSION before publication.
- Permit published, validated source releases for existing Office update grants. Signed download authorization and completion receipts bind the exact ZIP checksum.
- Agent builds source in a disposable context before maintenance, verifies the resulting managed image, and uses the existing backup/migration path while preserving the database, storage, secrets and proxy configuration.
- Index previously retained Office source archives during Central deployment without changing original package bytes or identities.
- Add real 0–100% upload progress, a distinct server processing state, duplicate submission prevention and actionable validation/network/proxy/session errors.
- Convert invalid ZIP inspections to form validation errors and align PHP/Nginx upload limits and processing timeouts. NPM upload settings must also be applied externally.
- Existing customers need a one-time agent refresh to 1.4.0-rc.13 or newer for source updates; fresh installations continue to use full Docker runtimes.


## 1.4.0-rc.12

- Re-download and restore deleted repository releases, or repair a missing package, while preserving UUID, original checksum, publication status and history. Reject a changed payload under the same version; stage file writes before replacement.
- Add advanced customer, license, release and capability search; show full administrator license codes, company, installation counts and installed versions.
- Add release notes, validated runtime requirement details and a ZIP upload area in the existing Persian/English, light/dark layout.
- Add license name, edition and expiry editing without resetting code consumption or installation bindings; expose existing status controls with confirmation.
- Prepare a product capability catalog and per-license future feature plans, with stable identifiers, required capabilities, same-product validation and history guards. These administrative plans do not change current Office/agent installation behavior.
- Document the separate next phase for signed module manifests, dependency/version compatibility, entitlement enforcement and preserving customer data.

## 1.4.0-rc.11

- Give Office update checks, installation, status polling and activation separate per-user throttle counters so polling cannot block a version check.
- Return actionable Persian rate-limit messages and Retry-After; wait and retry checks once, back off status polling, and never automatically replay an installation request.
- Add regression coverage that reproduces the former shared-counter failure and tests browser retry behavior.

## 1.4.0-rc.10

- Add direct license version management from the license list, with installed and authorized version summaries.
- Explain draft and source-only releases in the version selector instead of hiding them; only published runtime packages can be granted.
- Replace stale per-installation targets when saving a license update grant, so the signed Office offer follows the selected version.
- Cover administrator permissions, unavailable packages and the complete grant-to-signed-offer workflow without changing installed versions or consumed activation codes.

## 1.4.0-rc.9

- Match Office license, activation and maintenance screens to its existing theme and local assets.
- Restrict license and update controls to system administrators and general managers, including server endpoints.
- Show recoverable full license codes in signed customer metadata; leave legacy hash-only codes empty for administrator replacement.
- Add update-check activity progress, current/target version comparison and explicit confirmation before installation.
- Pin the approved version and release through authorization and execution; reject changed offers and unsupported older helpers without touching customer data.

## 1.4.0-rc.8

- Add customer, product and release editing and safe deletion; revoke deleted licenses while preserving installation history.
- Store new license codes encrypted for authorized retrieval and offer replacement for older hash-only codes.
- Simplify repository connection and refresh user management screens.

## 1.4.0-rc.7

- Snapshot the accepted update response before starting the background worker, avoiding concurrent reads of mutable job progress.
- Supply the example service environment during CI Compose validation on a clean checkout.

## 1.4.0-rc.6

- Redesign the shared Central sidebar, header, dashboards, forms and tables with local Vazirmatn, SVG icons and responsive light/dark themes.
- Grant a published runtime/security update per customer license, with signed read-only license metadata and enforced package authorization.
- Move Office helper controls into Settings / System update, retaining the Office layout and theme.
- Run authenticated asynchronous updates with progress, precise redacted errors, database/storage snapshots and recoverable completion receipts.
- Preserve the current database engine, credentials, storage, proxy networks and deployment configuration during updates.
- Cover update authorization, migration failure, lost confirmation, maintenance access and administrator permissions.

## 1.4.0-rc.5

- Default to connecting an existing Office using an attach_once license; require neither a runtime bundle nor source encoding.
- Detect and validate the running deployment, add a local helper service and enable licensing only after successful health-confirmed completion.
- Preserve application containers, database, files, users, encryption keys and proxy configuration during connection.
- Include the Office license/helper page and read-only helper mounts in the normal Office update.
- Make commercial source encoding optional for fresh Docker package builds and simplify operational instructions.
- Add connection safety, hardware clone, cached configuration and unencoded bundle regression coverage.

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
