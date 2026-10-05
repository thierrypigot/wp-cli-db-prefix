# Tests

Two PHPUnit suites:

- **unit**: pure logic (prefix validation, `wp-config.php` rewriting, backup folder checks). No database, runs in a fraction of a second.
- **integration**: the real command, run through WP-CLI on a real WordPress (single site and 4-site multisite network) and a real MySQL or MariaDB database. Each test gets a fresh database. Failures are injected with fixtures loaded through `--require` (`tests/Integration/fixtures/`), which hook into WordPress's `query` filter: the package code has no test-only branch.

GitHub Actions runs both on every push and pull request: unit tests on PHP 7.4 to 8.4, integration tests on PHP 7.4 + WordPress 6.2 + MySQL 8.0, PHP 8.3 + latest WordPress + MySQL 8.0, and PHP 8.4 + latest WordPress + MariaDB 11.4.

## Running them locally

```bash
composer install
composer test:unit
```

Integration tests need WP-CLI, a database server whose user can create databases, and `mysqldump` (or `mariadb-dump`) plus the `mysql` client in the `PATH`:

```bash
export WP_CLI_BIN="wp"             # or "php /path/to/wp-cli.phar"
export WP_DBP_DB_HOST="127.0.0.1"  # host[:port]
export WP_DBP_DB_USER="root"
export WP_DBP_DB_PASS="root"
export WP_VERSION="latest"         # optional
export WP_DBP_WORK_DIR="/tmp/wp-cli-db-prefix-tests"  # optional

composer test:integration
```

WordPress is downloaded once into `$WP_DBP_WORK_DIR/site`. Each test creates a database named `wpdbp_<random>` and drops it at the end.
