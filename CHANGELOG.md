# Changelog

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
