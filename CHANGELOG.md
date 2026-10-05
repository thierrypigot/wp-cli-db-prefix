# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-05

### Added

- `wp db-prefix rename <new_prefix>` command, with `--dry-run`, `--skip-config` and `--yes`.
- Pre-flight checks before any change: prefix format, core tables present, target names free, 64-character limit, views and triggers, `user_roles` option of every site, user meta keys already using the new prefix, single writable `$table_prefix` line in `wp-config.php`.
- Atomic rename of every table in a single `RENAME TABLE` statement; foreign keys follow.
- `wp-config.php` rewritten last; automatic rollback of every applied step on failure.
- Detection of other installations sharing the database (e.g. `wp_shop_` when renaming `wp_`): their tables are listed and left untouched.
- Multisite support: network tables, `{prefix}{id}_*` site tables, `user_roles` option of each site, `{prefix}{id}_*` user meta keys.
- Maintenance mode during the operation; kept until lifted by hand with `--skip-config`, so the installation screen is never exposed.
- Object cache flushed after the rename.
- English and French (`fr_FR`) translations, loaded from the site language.

[1.0.0]: https://github.com/thierrypigot/wp-cli-db-prefix/releases/tag/v1.0.0
