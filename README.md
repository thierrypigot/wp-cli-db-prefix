# WP-CLI DB Prefix

[Français](README.fr.md)

A free tool to change the table "prefix" of a WordPress site, without breaking anything.

## What is it?

A WordPress site stores everything (pages, posts, accounts, settings) in a database. That database is split into drawers called "tables". Every drawer's name starts with the same label: `wp_`, unless someone changed it at install time.

Since that label is the same on millions of sites, the robots that attack websites know it. Replacing it with a label of your own, such as `k7qm_`, makes their job a little harder. It is a small security measure, on top of the others (updates, strong passwords, backups), not a protection on its own.

Doing it by hand is tricky: every drawer has to be renamed, several hidden settings fixed and the site's configuration file edited. Miss one thing and the site stops showing up. This tool does it all in a single command. It checks everything before starting, and if anything goes wrong, it puts the site back exactly as it was.

## Who is behind it?

[Thierry Pigot](https://wearewp.pro), president of WeAre[WP], a French agency specialised in WordPress. The tool was built for the maintenance of our clients' sites, then published so anyone can use it. It is free and open source (GPL licence).

## Who is it for?

The tool runs with **WP-CLI**, WordPress's "terminal": you type commands instead of clicking in the dashboard. It is the tool of the people who look after a site technically (developer, agency, host).

If that is not you, simply send this page to the person who manages your site.

## Installation

One command, on the site's server:

```bash
wp package install thierrypigot/wp-cli-db-prefix
```

## How it works

**1. See what will happen, without touching anything:**

```bash
wp db-prefix rename --dry-run
```

The tool picks a new random label (for example `k7qm_`), lists everything it would do, and stops there. Nothing is changed.

**2. Make the change, with a backup first:**

```bash
wp db-prefix rename --safe
```

The tool backs up the site, asks for confirmation, changes the label, then shows the commands to go back if needed. To choose the label yourself, add it: `wp db-prefix rename k7qm_ --safe`.

**3. Check the site**, then delete the backup: it holds the database passwords.

## What the tool does to protect your site

- **It checks everything before starting.** At the slightest doubt, it stops without touching anything and explains why.
- **It backs up first**, with `--safe`, in a folder site visitors cannot reach.
- **It puts the site in maintenance mode** during the operation, which takes a few seconds: visitors see a waiting message instead of an error.
- **It changes all the drawers at once**: either everything is renamed, or nothing is.
- **It edits the configuration file last**, once everything else has succeeded.
- **It undoes everything if a step fails**, and says so clearly.
- **It leaves other sites alone** if they share the same database.

It also works on networks of sites (WordPress multisite), and speaks English or French depending on the site language.

---

## Going further (technical part)

### Requirements

WP-CLI 2.x, WordPress 6.2 or later, PHP 7.4 or later, MySQL or MariaDB. `mysqldump` (or `mariadb-dump`) is needed for `--safe`.

Without installing, one-off: `wp --require=path/to/wp-cli-db-prefix/command.php db-prefix rename --dry-run`.

### Options

| Option | Effect |
|---|---|
| `[<new_prefix>]` | New prefix: lowercase letters, digits and underscores, starting with a letter and ending with an underscore. If omitted, a 4-character prefix is drawn at random (`random_int()`), free of conflicts with existing tables. |
| `--dry-run` | Shows the plan without changing anything. With a random prefix, the command shows how to run again with that exact prefix, since a new one is drawn on every run. |
| `--safe` | Exports the affected tables (`wp db export`) and copies `wp-config.php` before any change. The export content is checked. If the backup fails, the command stops without changing anything. |
| `--backup-dir=<path>` | Backup folder. Default: `private_html/db-prefix-backups` (Cloudways) or `db-prefix-backups`, next to the site folder. A folder inside the web root is refused. |
| `--skip-config` | Leaves `wp-config.php` untouched (prefix defined in an environment variable, Bedrock, etc.). The site stays in maintenance mode: update the prefix where it is defined, then run `wp maintenance-mode deactivate`. |
| `--yes` | Skips the confirmation (scripts). |

### Sequence

1. Checks: prefix format, core tables (and network tables on multisite), name conflicts, 64-character limit, views and triggers (refused), `user_roles` option of every site, user meta keys already using the new prefix, single writable `$table_prefix` line in `wp-config.php`.
2. With `--safe`: checked SQL export and copy of `wp-config.php`, unguessable file names, `600` permissions, folder protected by an `.htaccess` and an `index.php` as a second line of defence.
3. Maintenance mode (`.maintenance` file).
4. Every table renamed in a single atomic `RENAME TABLE` statement. Foreign keys follow.
5. `{prefix}user_roles` option, or `{prefix}{id}_user_roles` on each site of a network.
6. `{prefix}*` user meta keys, in one query.
7. `wp-config.php`, last.
8. Object cache flushed.

On failure, the steps already applied are rolled back in reverse order. If the rollback itself fails, the command lists what is left to fix by hand.

### Going back to the saved state

After a rename with `--safe`, the command prints the exact commands to run. They follow this pattern:

```bash
cp <backup>/wp-config-<date>.php wp-config.php
wp db import <backup>/db-prefix-<old>_<date>.sql
wp db query "DROP TABLE $(wp db tables '<new>*' --all-tables --format=csv)"
wp cache flush
```

### Translations

Messages follow the site language: English and French (`fr_FR`, also used for `fr_BE`, `fr_CA`, etc.). To add a language, translate `languages/wp-cli-db-prefix.pot`, then run `wp i18n make-mo languages`. The command help (`wp help db-prefix rename`) stays in English: WP-CLI reads it from the code and does not translate it.

### Limits

- `CUSTOM_USER_TABLE` / `CUSTOM_USER_META_TABLE` are not supported (refused).
- Only the `user_roles` option is renamed in the options tables, as WordPress does. A plugin storing an option named after `$wpdb->prefix` has to be fixed by hand (rare).
- Flushing the object cache flushes all of it, including the cache of other sites sharing the same Redis server.

### Tests

[![Tests](https://github.com/thierrypigot/wp-cli-db-prefix/actions/workflows/tests.yml/badge.svg)](https://github.com/thierrypigot/wp-cli-db-prefix/actions/workflows/tests.yml)

PHPUnit unit tests, plus integration tests that run the real command on a real WordPress (single site and 4-site multisite network): rename, refusals, forced failures and rollback, `--safe` backup followed by a full restore. GitHub Actions runs them on every push, from PHP 7.4 + WordPress 6.2 to PHP 8.4 + latest WordPress, on MySQL and MariaDB. See [tests/README.md](tests/README.md) to run them locally.

### Licence

GPL-2.0-or-later. See [LICENSE](LICENSE). Release history: [CHANGELOG.md](CHANGELOG.md).
