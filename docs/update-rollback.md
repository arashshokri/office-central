# Tagged updates and rollback

Publish reviewed code as an immutable tag such as `central-v1.1.0`. On production, `sudo ./centralctl.sh update central-v1.1.0` rejects a dirty checkout, creates a backup, fetches tags, checks out the exact tag, rebuilds containers, applies migrations, and calls `/health`.

Prefer additive, backward-compatible migrations. If health fails before an incompatible migration, use `sudo ./centralctl.sh rollback`. If schema/data must also move backward, restore the automatic backup after assessing data created since the update. The script deliberately avoids blind down-migrations because they can lose production data.
