# Office agent protocol v2

The implementation is in agent/, the Central v2 API, and integrations/office/. Operational instructions and release prerequisites are in [the Persian guide](office-agent-fa.md). Existing v1 licenses remain compatible; helper licenses cannot activate through v1.

## Lifecycle

1. HTTPS bootstrap provides the Central public key and helper binary hashes. The key is pinned locally on first use.
2. The device creates a root-only Ed25519 key and a persistent client_request_id before activation.
3. POST /api/v2/installer/begin reserves the code for that request/device/hardware and returns a recoverable installation credential plus signed provisioning state.
4. For `attach_once`, the helper detects the running `office-web`, verifies its read-only helper mounts and health, starts its local proof service, and completes without image downloads, Compose writes or database migrations. The enforcement marker is written only after successful completion. For `installer_once`, signed download authorization permits only the assigned runtime release. The client verifies its origin, size, checksum, manifest, architecture and Docker image IDs.
5. For a fresh installation, after loading images, preserving existing credentials/volumes, snapshotting prior data, migrating, creating an initial administrator and checking application/DB/Redis/storage/version, complete consumes the install code.
6. Daemon state polling maintains the runtime authorization independently of the consumed code.
7. A hardware mismatch returns a signed lock for the presented device; the legitimate original record stays active.
8. A replacement code for the same customer/product creates a new bound installation after local health validation. Customer data and the original license remain intact.

Lost begin/completion/reactivation responses are recoverable with persisted request identity and the same device. An administrator must issue a new code to replace a reservation belonging to an unavailable device. No automatic reservation expiry permits stealing a code after the initial customer begins installation.

## Hardware binding

Fingerprint v2 is SHA-256 of the exact UTF-8 bytes:

~~~text
office-hardware-v2\n<lowercase DMI product_uuid>\n<lowercase 32-hex machine-id>
~~~

The UUID must be canonical and not all-zero/all-ff. Collection runs on the host, not inside the customer app container. The app requests a fresh nonce-bound hardware proof over a root-owned local Unix socket, authenticated with an independent random control token. The device signs:

~~~text
office-hardware-proof/v2\n<nonce>\n<fingerprint>
~~~

The public device key is itself inside the Central-signed state. A copied cached state on different hardware fails this local verification even when Central is unreachable. Original and clone have independent local decisions. UUIDs/machine-ids are spoofable by root/hypervisor operators; this mechanism is not TPM attestation.

## Device authentication

Begin uses the public key inside the submitted JSON; subsequent requests additionally require the installation bearer credential, valid device signature, nonce and timestamp. The signature covers:

~~~text
METHOD\n/path\nunix_timestamp\n32-hex_nonce\nsha256(exact_request_body)
~~~

Timestamps allow a five-minute window; authenticated nonces are stored uniquely per installation. Rate limits apply per authenticated installation rather than shared NAT IP. The raw license is not written to local or central storage. Central stores only the credential hash; deterministic HMAC recovery is keyed by the private Central signing key.

## Signed envelopes

Response envelope fields are payload (base64url without padding), signature and algorithm=Ed25519. The signature covers the exact original JSON bytes, prefixed by:

~~~text
office-agent/v2\n
~~~

There is no cross-language JSON reserialization/canonicalization. State and download envelopes have distinct kind fields and protocol=2. State includes installation, device public key, bound/presented fingerprints, monotonic per-installation sequence, access, completion, release and deployment data. The client refuses forged keys/signatures, wrong-purpose responses, another installation and stale sequences. A new installation identity starts its own sequence only after authenticated, verified activation.

## Availability and locks

Central/network/HTTP failures never create a new lock or offline expiry. A running local helper keeps its last valid signed decision indefinitely, still proving the current hardware on each application check. A signed authoritative lock remains locked until a newer signed authorization or valid replacement activation arrives.

The Office login and authenticated administrator reactivation page remain accessible. Business pages/APIs return HTTP 423. Queues and schedules stop acquiring/running work without deleting jobs. The daemon stops clone/locked business workers and terminal/RDP gateways to close existing sessions; application and DB containers remain so reactivation can preserve data. Missing/unverifiable local state or an unavailable local proof service limits access.

## Data safety and packages

No customer source checkout/build, arbitrary archive script execution, down -v or migrate:fresh exists in the helper. Persistent database/storage volume names stay compatible with Office. Existing credentials and APP_KEY require the original environment when adopting. Before migration on existing data, writers stop, a DB dump/storage archive and checksums are saved; a failed snapshot restarts prior writers. Once migration starts, failures leave business services stopped for an explicit resume/recovery.

Source encoding is optional and independent of license enforcement. The standard `managed` target inherits the production runtime. When encoding is requested, the `customer` target starts from the PHP base, copies encoded application code and encoded precompiled Blade, and never inherits the readable production image. The owner-side package builder verifies proprietary PHP encoding and the loader before creating the runtime ZIP. Protected Blade files are immutable for the app user. Encoded publication requires a trusted operator and licensed encoder; package metadata alone is not independent attestation of an operator's image contents.

For production promotion, rehearse existing-deployment connection, Docker builds/installation/resume, changed hardware clones, reactivation, outages and migration recovery on disposable Linux VMs first.

## Customer-authorized updates

The signed state carries read-only license metadata and a published runtime update explicitly allowed on the license (or the installation override). Merely publishing a release grants no download permission. Central refuses cross-product, unpublished and unassigned packages and validates the successful release/hash/version receipt before advancing the installed version.

Office administrators use Settings / System update. Authenticated local control requests reserve one background job and return HTTP 202 immediately. The public read-only job file exposes stage/progress and redacted errors; private Compose snapshots and pending completion receipts stay root-only. Identity proofs remain available during the job. The helper resolves the existing Compose model, preserves secrets, database image, storage and proxy membership, snapshots SQL/storage before migration, and replaces only application services. Migration failures retain maintenance mode and backups. Repeating a pending successful receipt never repeats migration. No automatic database rollback is performed.
