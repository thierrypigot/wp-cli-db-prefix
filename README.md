# WP-CLI DB Prefix

[Français](README.fr.md)

WP-CLI command that safely renames the WordPress table prefix (`wp_` by default), on a single site or a multisite network.

It checks everything before changing anything, renames every table in one atomic statement, rewrites `wp-config.php` last, and rolls back automatically if a step fails.

## Requirements

- WP-CLI 2.x
- WordPress 6.2 or later
- PHP 7.4 or later
- MySQL or MariaDB

## Installation

As a WP-CLI package, installed for the current system user:

```bash
wp package install thierrypigot/wp-cli-db-prefix
```

Or one-off, without installing anything:

```bash
git clone https://github.com/thierrypigot/wp-cli-db-prefix.git
wp --require=wp-cli-db-prefix/command.php db-prefix rename wawp7k_ --dry-run
```

## Usage

```bash
# 1. Back up.
wp db export before-prefix.sql
cp wp-config.php wp-config.php.before-prefix

# 2. Preview.
wp db-prefix rename wawp7k_ --dry-run

# 3. Rename (asks for confirmation).
wp db-prefix rename wawp7k_
```

`wp-config.php.before-prefix` contains your database credentials: delete it once the site has been checked.

On a multisite network, run the command once, from the network's main site.

### Options

| Option | Effect |
|---|---|
| `--dry-run` | Shows the plan without changing anything. |
| `--skip-config` | Leaves `wp-config.php` untouched, for a prefix defined elsewhere (environment variable, Bedrock, etc.). The site then stays in maintenance mode (503): update the prefix where it is defined, then run `wp maintenance-mode deactivate`. Without that, WordPress would show the installation screen to any visitor. |
| `--yes` | Skips the confirmation (scripts). |

The new prefix must contain only lowercase letters, digits and underscores, start with a letter and end with an underscore.

## What it does

1. **Checks everything before changing anything:**
   - prefix format;
   - core tables present (and network tables on multisite);
   - no table already uses a target name;
   - no name longer than 64 characters;
   - no views or triggers on the tables (refused);
   - `user_roles` option present on every site;
   - no user meta key already using the new prefix;
   - a single, writable `$table_prefix` line in `wp-config.php`.
2. **Puts the site in maintenance mode** (`.maintenance` file), so visitors get a 503 instead of an error or the installation screen.
3. **Renames every table in a single `RENAME TABLE` statement.** MySQL renames all or nothing, and foreign keys follow.
4. Renames the `{prefix}user_roles` option, or `{prefix}{id}_user_roles` on each site of a network.
5. Renames the `{prefix}*` user meta keys (capabilities, user level, settings, `{prefix}{id}_*` on a network) in one query.
6. **Rewrites `wp-config.php` last.**
7. Flushes the object cache (Redis, Memcached).

If a step fails, the steps already applied are rolled back in reverse order, and the command says so. If the rollback itself fails, the command lists what is left to fix by hand.

**Another installation in the same database:** if the database also holds a site whose prefix starts with yours (e.g. `wp_shop_` while renaming `wp_`), its tables are detected, listed and left untouched.

## Translations

Messages follow the site language. English and French (`fr_FR`) are included, and other variants of French (`fr_BE`, `fr_CA`...) use `fr_FR`. To add a language, translate `languages/wp-cli-db-prefix.pot`, then generate the `.mo` file with `wp i18n make-mo languages`.

The command help (`wp help db-prefix rename`) stays in English: WP-CLI reads it from the code and does not translate it.

## Limits

- `CUSTOM_USER_TABLE` / `CUSTOM_USER_META_TABLE` are not supported (refused).
- Only the `user_roles` option is renamed in the options tables, as WordPress itself does. A plugin storing an option named after `$wpdb->prefix` has to be fixed by hand (rare).
- Flushing the object cache flushes all of it (like `wp cache flush`), including the cache of other sites sharing the same Redis server.

## Development

Tested on WordPress 7.1 with MariaDB 11.4, on a single site and on a 4-site multisite network (one archived site), including forced failures to check the rollback.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Developed by [Thierry Pigot](https://wearewp.pro), WeAre[WP].
