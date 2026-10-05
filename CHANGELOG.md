# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- PHPUnit test suites: unit tests (no database) and integration tests running the real command through WP-CLI on single sites and multisite networks, including forced failures and a `--safe` backup and restore.
- GitHub Actions workflow: unit tests on PHP 7.4 to 8.4, integration tests from PHP 7.4 + WordPress 6.2 + MySQL 8.0 to PHP 8.4 + latest WordPress + MariaDB 11.4.

### Changed

- `command.php` now checks the `WP_CLI` constant instead of the `WP_CLI` class, so the command is never registered outside WP-CLI (the class also exists when WP-CLI is a dev dependency).

## [1.1.0] - 2026-10-05

### Added

- The new prefix is now optional: when omitted, a random 4-character prefix (such as `k7qm_`) is generated with `random_int()`, free of conflicts with existing tables and user meta keys. A dry run shows the command to run again with that exact prefix.
- `--safe` option: backs up the affected tables (`wp db export`) and `wp-config.php` before any change, checks the export, and stops without changing anything if the backup fails. Prints the commands to go back to the saved state.
- `--backup-dir=<path>` option. Default: `private_html/db-prefix-backups` or `db-prefix-backups` next to the site folder. Folders inside the web root, and paths with `..` in a part not yet created, are refused. Files get unguessable names and `600` permissions; the folder gets an `.htaccess` and an `index.php`.

### Changed

- README rewritten for non-technical readers first, with the technical reference below.

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

[Unreleased]: https://github.com/thierrypigot/wp-cli-db-prefix/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/thierrypigot/wp-cli-db-prefix/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/thierrypigot/wp-cli-db-prefix/releases/tag/v1.0.0
