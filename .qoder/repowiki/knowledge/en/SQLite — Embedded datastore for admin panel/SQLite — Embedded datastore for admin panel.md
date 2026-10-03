---
kind: external_dependency
name: SQLite — Embedded datastore for admin panel
slug: sqlite
category: external_dependency
category_hints:
    - migration_status
scope:
    - '**'
source_files:
    - includes/db.php
    - DEPLOYMENT.md
---

### Identity
SQLite is the embedded database used by the admin panel; the data file lives at `/var/lib/aircoins/aircoins.db`.

### Role
Stores admin users, routers, audit log, and hotspot user/voucher state. No external DBMS is required — the installer initializes the schema on first run.

### Migration status
The codebase ships an inline schema migration layer (`includes/db.php`); new fields/columns are added via migration functions rather than external SQL files. There is no Composer or migration framework — migrations are PHP functions invoked during bootstrap.

### Operational notes
- WAL mode + `synchronous=NORMAL` to reduce SD-card wear on SBCs.
- Database directory permissions: `0750 www-data`.
- Back up `/var/lib/aircoins/aircoins.db` along with `/etc/aircoins/secret.key` (router passwords are encrypted with the sodium key).