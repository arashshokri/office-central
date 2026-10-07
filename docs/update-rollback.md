# Updates and rollback

`sudo bash centralctl.sh update` fetches and fast-forwards to GitHub `main`; it rejects a dirty checkout or divergent history. For reviewed releases, pass an immutable tag to `update TAG`; the tag must match `VERSION`. Before changing code, it creates a database/packages/storage/environment backup and records the previous commit. It rebuilds the app image, migrates before starting web/workers, clears and rebuilds caches, and calls `/health` inside the web container. Rollback targets must also support the external NPM network configuration.

Prefer additive, backward-compatible migrations. If health fails before an incompatible migration, use `sudo ./centralctl.sh rollback`. If schema/data must also move backward, restore the automatic backup after assessing data created since the update. The script deliberately avoids blind down-migrations because they can lose production data.
