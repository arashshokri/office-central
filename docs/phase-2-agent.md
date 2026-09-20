# Phase 2 agent contract (design only)

The Agent is deliberately not installed in a live Office instance during Central development. Integration requires a separate reviewed release, a full backup, a staging rehearsal, and explicit operator approval.

## Persistent state

The Agent stores `https://panel.ponet.ir`, installation ID, the one-time installation credential, the independently provisioned Central public signing key, highest accepted `state_revision`, last signed state, and signed hardware binding under root-only permissions. The raw activation license is discarded after activation and is never stored in the Office database. A public key returned by the API is informational and must never replace the pinned trust key by itself.

## Availability rule: fail open indefinitely

DNS, TLS, routing, timeout, connection refusal, unsigned HTTP errors, malformed responses, or a stopped Central are communication failures. They never change Office access. The Agent keeps the last correctly signed state indefinitely and retries with bounded exponential backoff. There is no offline expiry and no clock-based lock.

Only a newer Ed25519-signed response from Central may change access. The Agent rejects signatures from another key and rejects any state whose revision is lower than its highest accepted revision. A state with `access=locked` presents a non-destructive support screen; it does not stop containers, alter the database, delete files, or run migrations. A newer signed `access=allowed` state removes that screen immediately. The default poll interval is five seconds.

## Routes

- Activation: `POST https://panel.ponet.ir/api/v1/agent/activate`
- State synchronization: `POST https://panel.ponet.ir/api/v1/agent/state`
- Package token: `POST https://panel.ponet.ir/api/v1/packages/token`

Authenticated calls use the installation bearer credential plus a unique nonce and current timestamp. Hardware mismatch is reported to Central and must not mutate customer data.

## Future Office integration gate

The Agent implementation will be delivered as an isolated component with dry-run mode enabled by default. The first production rollout must initially collect health and versions only. Lock-screen enforcement remains disabled until Central, backup/restore, signature verification, fail-open network tests, and the customer-specific license have all been validated.
