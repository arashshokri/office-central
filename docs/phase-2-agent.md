# Phase 2 agent contract

The Go agent will store `central_url`, installation ID, the one-time installation credential, Central public signing key, last signed lease, last trusted server time, and signed hardware binding under root-only permissions. It gathers stable hardware inputs and applies fingerprint algorithm version 1. CPU cores, RAM, and disk capacity are inventory and do not independently invalidate a license.

At boot it verifies the cached signature and local hardware binding, tries Central, and separates transport failure from explicit security state. During an outage it serves through the signed grace period and retries. A copied installation whose local hardware differs raises suspicion immediately; when Central becomes reachable, heartbeat/lease returns `LICENSE_HARDWARE_MISMATCH`. Central records `clone_detected` and leaves the original installation active.

Package installation verifies the response SHA-256 before unpacking. Phase 2 will add device request signatures, real hardware collectors, atomic package activation, health probes, and upgrade rollback after the Office repositories are inspected.
