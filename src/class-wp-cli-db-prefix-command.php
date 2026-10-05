<?php
/**
 * WP-CLI command that renames the WordPress table prefix.
 *
 * @package WP_CLI_DB_Prefix
 */

/**
 * Renames the table prefix of a WordPress site or multisite network.
 *
 * Order of operations, each one rolled back if a later step fails:
 * 1. every table renamed in a single RENAME TABLE statement (atomic on the MySQL side);
 * 2. {prefix}user_roles option, or {prefix}{id}_user_roles for each site of a network;
 * 3. {prefix}* user meta keys (capabilities, user_level, settings, {prefix}{id}_* included);
 * 4. $table_prefix rewritten in wp-config.php, last.
 */
class WP_CLI_DB_Prefix_Command extends WP_CLI_Command {

	/**
	 * Maximum length of a MySQL table name.
	 */
	const MAX_TABLE_NAME_LENGTH = 64;

	/**
	 * Pattern of the $table_prefix line in wp-config.php.
	 */
	const CONFIG_PATTERN = '/(\$table_prefix\s*=\s*)([\'"])([A-Za-z0-9_]*)\2(\s*;)/';

	/**
	 * Steps already applied, so they can be rolled back.
	 *
	 * @var array
	 */
	private $done = array();

	/**
	 * Whether the command created the .maintenance file.
	 *
	 * @var bool
	 */
	private $maintenance_created = false;

	/**
	 * Renames the WordPress table prefix.
	 *
	 * Checks everything before changing anything, then renames all tables at
	 * once. wp-config.php is only rewritten once the database is up to date.
	 * If a step fails, the steps already applied are rolled back.
	 * Works on single sites and multisite networks.
	 *
	 * ## OPTIONS
	 *
	 * <new_prefix>
	 * : New prefix: lowercase letters, digits and underscores, starting with a letter and ending with an underscore. Example: wawp7k_
	 *
	 * [--dry-run]
	 * : Show what would be done, without changing anything.
	 *
	 * [--skip-config]
	 * : Leave wp-config.php untouched (prefix defined elsewhere, e.g. in an environment variable). The site then stays in maintenance mode until you update the prefix and run `wp maintenance-mode deactivate`.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview.
	 *     $ wp db-prefix rename wawp7k_ --dry-run
	 *
	 *     # Rename.
	 *     $ wp db-prefix rename wawp7k_
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rename( $args, $assoc_args ) {
		global $wpdb;

		$new_prefix  = $args[0];
		$old_prefix  = $wpdb->base_prefix;
		$dry_run     = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$skip_config = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-config', false );

		$this->check_environment();
		$this->validate_prefix( $new_prefix, $old_prefix );

		$plan = $this->build_plan( $old_prefix, $new_prefix, $skip_config );

		$this->print_plan( $plan );

		if ( $dry_run ) {
			WP_CLI::success( __( 'Dry run complete, nothing was changed.', 'wp-cli-db-prefix' ) );
			return;
		}

		WP_CLI::warning( __( 'Back up before continuing: wp db export, plus a copy of wp-config.php.', 'wp-cli-db-prefix' ) );
		WP_CLI::confirm(
			/* translators: 1: site URL, 2: current prefix, 3: new prefix. */
			sprintf( __( 'Rename the table prefix of %1$s from "%2$s" to "%3$s"?', 'wp-cli-db-prefix' ), home_url(), $old_prefix, $new_prefix ),
			$assoc_args
		);

		$this->enable_maintenance();

		try {
			$this->apply( $plan );
		} catch ( RuntimeException $exception ) {
			$this->disable_maintenance();
			WP_CLI::error( $exception->getMessage() );
		}

		// With --skip-config the site cannot find its tables until the prefix is updated by hand:
		// without maintenance mode, it would show the installation screen to any visitor.
		if ( $skip_config ) {
			$this->hold_maintenance();
		} else {
			$this->disable_maintenance();
		}

		// The rest of this WP-CLI request (cron, shutdown) must use the new tables.
		$wpdb->set_prefix( $new_prefix );
		$GLOBALS['table_prefix'] = $new_prefix; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_cache_flush();

		if ( $skip_config ) {
			/* translators: %s: new prefix. */
			WP_CLI::warning( sprintf( __( 'wp-config.php was not changed. The site stays in maintenance mode (503) until you: 1. update the prefix ("%s") where it is defined; 2. run wp maintenance-mode deactivate.', 'wp-cli-db-prefix' ), $new_prefix ) );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: new prefix, 2: number of tables, 3: number of sites, 4: number of user meta keys. */
				__( 'Prefix renamed to "%1$s": %2$d tables, %3$d site(s), %4$d user meta keys. Object cache flushed.', 'wp-cli-db-prefix' ),
				$new_prefix,
				count( $plan['tables'] ),
				count( $plan['sites'] ),
				$plan['usermeta_count']
			)
		);
	}

	/**
	 * Refuses setups the command cannot handle safely.
	 */
	private function check_environment() {
		// The %i placeholder of $wpdb->prepare() (identifiers) was introduced in WordPress 6.2.
		if ( version_compare( $GLOBALS['wp_version'], '6.2', '<' ) ) {
			WP_CLI::error( __( 'WordPress 6.2 or later is required.', 'wp-cli-db-prefix' ) );
		}

		if ( defined( 'CUSTOM_USER_TABLE' ) || defined( 'CUSTOM_USER_META_TABLE' ) ) {
			WP_CLI::error( __( 'CUSTOM_USER_TABLE or CUSTOM_USER_META_TABLE is defined: shared user tables are not supported.', 'wp-cli-db-prefix' ) );
		}
	}

	/**
	 * Checks the format of the new prefix.
	 *
	 * Lowercase only: on Windows or with lower_case_table_names, MySQL may
	 * force table names to lowercase and break the match.
	 *
	 * @param string $new_prefix New prefix.
	 * @param string $old_prefix Current prefix.
	 */
	private function validate_prefix( $new_prefix, $old_prefix ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*_$/', $new_prefix ) ) {
			/* translators: %s: rejected prefix. */
			WP_CLI::error( sprintf( __( 'Prefix "%s" rejected: use lowercase letters, digits and underscores, start with a letter and end with an underscore.', 'wp-cli-db-prefix' ), $new_prefix ) );
		}

		if ( $new_prefix === $old_prefix ) {
			WP_CLI::error( __( 'The new prefix is the same as the current one.', 'wp-cli-db-prefix' ) );
		}
	}

	/**
	 * Gathers and checks everything that will be changed, without changing anything.
	 *
	 * @param string $old_prefix  Current prefix.
	 * @param string $new_prefix  New prefix.
	 * @param bool   $skip_config Leave wp-config.php untouched.
	 * @return array Rename plan.
	 */
	private function build_plan( $old_prefix, $new_prefix, $skip_config ) {
		global $wpdb;

		$plan = array(
			'old_prefix'       => $old_prefix,
			'new_prefix'       => $new_prefix,
			'tables'           => array(),
			'excluded'         => array(),
			'foreign_prefixes' => array(),
			'sites'            => array(),
			'usermeta_count'   => 0,
			'config'           => null,
		);

		$all_tables = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );

		if ( ! $all_tables ) {
			/* translators: %s: database error. */
			WP_CLI::error( sprintf( __( 'Could not list tables: %s', 'wp-cli-db-prefix' ), $wpdb->last_error ) );
		}

		$types = array();
		foreach ( $all_tables as $row ) {
			$types[ $row[0] ] = $row[1];
		}

		// Binary comparison: LIKE ignores case and treats "_" as a wildcard.
		$candidates = array();
		foreach ( array_keys( $types ) as $table ) {
			if ( 0 === strpos( $table, $old_prefix ) ) {
				$candidates[] = $table;
			}
		}

		$core_tables = array( 'options', 'usermeta', 'users', 'posts' );

		if ( is_multisite() ) {
			$core_tables = array_merge( $core_tables, array( 'blogs', 'site', 'sitemeta' ) );
		}

		foreach ( $core_tables as $core_table ) {
			if ( ! in_array( $old_prefix . $core_table, $candidates, true ) ) {
				/* translators: %s: table name. */
				WP_CLI::error( sprintf( __( 'Table %s not found: the current prefix does not match the database.', 'wp-cli-db-prefix' ), $old_prefix . $core_table ) );
			}
		}

		// Another installation in the same database whose prefix starts with ours (e.g. wp_ and wp_shop_).
		// On multisite, {prefix}{id}_* tables belong to the network's sites, not to another installation.
		$subsite_pattern = '/^' . preg_quote( $old_prefix, '/' ) . '[0-9]+_$/';

		foreach ( $candidates as $table ) {
			if ( 'options' !== substr( $table, -7 ) ) {
				continue;
			}

			$prefix = substr( $table, 0, -7 );

			if ( is_multisite() && 1 === preg_match( $subsite_pattern, $prefix ) ) {
				continue;
			}

			if ( $prefix !== $old_prefix && in_array( $prefix . 'posts', $candidates, true ) ) {
				$plan['foreign_prefixes'][] = $prefix;
			}
		}

		foreach ( $candidates as $table ) {
			foreach ( $plan['foreign_prefixes'] as $foreign_prefix ) {
				if ( 0 === strpos( $table, $foreign_prefix ) ) {
					$plan['excluded'][] = $table;
					continue 2;
				}
			}

			if ( 'BASE TABLE' !== $types[ $table ] ) {
				/* translators: 1: table name, 2: table type. */
				WP_CLI::error( sprintf( __( '%1$s is a view (%2$s): its definition would still reference the old names. Not supported.', 'wp-cli-db-prefix' ), $table, $types[ $table ] ) );
			}

			$new_table = $new_prefix . substr( $table, strlen( $old_prefix ) );

			if ( strlen( $new_table ) > self::MAX_TABLE_NAME_LENGTH ) {
				/* translators: 1: maximum length, 2: table name. */
				WP_CLI::error( sprintf( __( 'Name too long after renaming (%1$d characters maximum): %2$s. Choose a shorter prefix.', 'wp-cli-db-prefix' ), self::MAX_TABLE_NAME_LENGTH, $new_table ) );
			}

			$plan['tables'][ $table ] = $new_table;
		}

		// Name conflicts, compared case-insensitively as MySQL may do.
		$existing = array_map( 'strtolower', array_keys( $types ) );
		$renamed  = array_map( 'strtolower', array_keys( $plan['tables'] ) );

		foreach ( $plan['tables'] as $new_table ) {
			$new_table_lower = strtolower( $new_table );

			if ( in_array( $new_table_lower, $existing, true ) && ! in_array( $new_table_lower, $renamed, true ) ) {
				/* translators: %s: table name. */
				WP_CLI::error( sprintf( __( 'Table %s already exists.', 'wp-cli-db-prefix' ), $new_table ) );
			}
		}

		$this->check_triggers( array_keys( $plan['tables'] ) );

		$plan['sites'] = $this->get_sites( $old_prefix, $new_prefix );

		// Each site keeps its roles in its own options table, under a key carrying its prefix.
		foreach ( $plan['sites'] as $site ) {
			$options_table = $site['old'] . 'options';

			if ( ! isset( $plan['tables'][ $options_table ] ) ) {
				/* translators: 1: table name, 2: site ID. */
				WP_CLI::error( sprintf( __( 'Table %1$s of site %2$d not found.', 'wp-cli-db-prefix' ), $options_table, $site['id'] ) );
			}

			$roles = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $options_table, $site['old'] . 'user_roles' ) );

			if ( 1 !== (int) $roles ) {
				/* translators: 1: option name, 2: table name. */
				WP_CLI::error( sprintf( __( 'Option %1$s not found in %2$s.', 'wp-cli-db-prefix' ), $site['old'] . 'user_roles', $options_table ) );
			}

			$new_roles = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $options_table, $site['new'] . 'user_roles' ) );

			if ( 0 !== (int) $new_roles ) {
				/* translators: 1: option name, 2: table name. */
				WP_CLI::error( sprintf( __( 'Option %1$s already exists in %2$s (left over from a previous attempt?).', 'wp-cli-db-prefix' ), $site['new'] . 'user_roles', $options_table ) );
			}
		}

		$usermeta_table = $old_prefix . 'usermeta';

		// Keys already carrying the new prefix would mix with the renamed ones and make rollback impossible.
		$new_keys = (int) $wpdb->get_var( $this->usermeta_count_query( $usermeta_table, $new_prefix ) );

		if ( 0 !== $new_keys ) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: number of user meta keys, 2: new prefix. */
					_n( '%1$d user meta key already starts with "%2$s". Choose another prefix.', '%1$d user meta keys already start with "%2$s". Choose another prefix.', $new_keys, 'wp-cli-db-prefix' ),
					$new_keys,
					$new_prefix
				)
			);
		}

		$plan['usermeta_count'] = (int) $wpdb->get_var( $this->usermeta_count_query( $usermeta_table, $old_prefix ) );

		if ( ! $skip_config ) {
			$plan['config'] = $this->read_config( $old_prefix, $new_prefix );
		}

		return $plan;
	}

	/**
	 * Lists the sites whose roles option must be renamed, with their prefix before and after.
	 *
	 * Same rule as $wpdb->get_blog_prefix(): site 1 uses the base prefix, the
	 * others {prefix}{id}_. Every site of the blogs table is included, archived,
	 * deactivated or spam ones too, since their tables are renamed as well.
	 *
	 * @param string $old_prefix Current prefix.
	 * @param string $new_prefix New prefix.
	 * @return array List of array( 'id' => int, 'old' => string, 'new' => string ).
	 */
	private function get_sites( $old_prefix, $new_prefix ) {
		global $wpdb;

		if ( ! is_multisite() ) {
			return array(
				array(
					'id'  => 1,
					'old' => $old_prefix,
					'new' => $new_prefix,
				),
			);
		}

		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i ORDER BY blog_id', $old_prefix . 'blogs' ) );

		if ( ! $ids ) {
			/* translators: %s: database error. */
			WP_CLI::error( sprintf( __( 'Could not list the network sites: %s', 'wp-cli-db-prefix' ), $wpdb->last_error ) );
		}

		$sites = array();
		foreach ( $ids as $id ) {
			$id      = (int) $id;
			$suffix  = 1 === $id ? '' : $id . '_';
			$sites[] = array(
				'id'  => $id,
				'old' => $old_prefix . $suffix,
				'new' => $new_prefix . $suffix,
			);
		}

		return $sites;
	}

	/**
	 * Builds the query counting user meta keys that start with a prefix (case-sensitive).
	 *
	 * @param string $table  Usermeta table.
	 * @param string $prefix Prefix to look for.
	 * @return string Prepared query.
	 */
	private function usermeta_count_query( $table, $prefix ) {
		global $wpdb;

		return $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s AND BINARY LEFT( meta_key, %d ) = %s',
			$table,
			$wpdb->esc_like( $prefix ) . '%',
			strlen( $prefix ),
			$prefix
		);
	}

	/**
	 * Refuses to rename when a trigger is attached to one of the tables.
	 *
	 * @param string[] $tables Tables to rename.
	 */
	private function check_triggers( $tables ) {
		global $wpdb;

		$triggers = $wpdb->get_results( 'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()' );

		foreach ( (array) $triggers as $trigger ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( in_array( $trigger->EVENT_OBJECT_TABLE, $tables, true ) ) {
				/* translators: 1: trigger name, 2: table name. */
				WP_CLI::error( sprintf( __( 'Trigger %1$s is attached to %2$s: not supported.', 'wp-cli-db-prefix' ), $trigger->TRIGGER_NAME, $trigger->EVENT_OBJECT_TABLE ) );
			}
			// phpcs:enable
		}
	}

	/**
	 * Reads wp-config.php and prepares its new content.
	 *
	 * @param string $old_prefix Current prefix.
	 * @param string $new_prefix New prefix.
	 * @return array Path, current content and new content.
	 */
	private function read_config( $old_prefix, $new_prefix ) {
		$path = WP_CLI\Utils\locate_wp_config();

		if ( ! $path || ! is_readable( $path ) ) {
			WP_CLI::error( __( 'wp-config.php not found or not readable.', 'wp-cli-db-prefix' ) );
		}

		if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			/* translators: %s: path to wp-config.php. */
			WP_CLI::error( sprintf( __( '%s is not writable. Fix the permissions, or use --skip-config and edit it by hand.', 'wp-cli-db-prefix' ), $path ) );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$count = preg_match_all( self::CONFIG_PATTERN, $contents, $matches );

		if ( 1 !== $count ) {
			/* translators: 1: number of lines found, 2: path to wp-config.php. */
			WP_CLI::error( sprintf( __( '%1$d "$table_prefix = \'...\';" lines found in %2$s (exactly one expected). Use --skip-config and change the prefix by hand.', 'wp-cli-db-prefix' ), $count, $path ) );
		}

		if ( $matches[3][0] !== $old_prefix ) {
			/* translators: 1: path to wp-config.php, 2: prefix found in the file, 3: prefix used by WordPress. */
			WP_CLI::error( sprintf( __( '%1$s sets the prefix "%2$s" but WordPress uses "%3$s". Use --skip-config.', 'wp-cli-db-prefix' ), $path, $matches[3][0], $old_prefix ) );
		}

		return array(
			'path'         => $path,
			'contents'     => $contents,
			'new_contents' => preg_replace( self::CONFIG_PATTERN, '${1}${2}' . $new_prefix . '${2}${4}', $contents, 1 ),
		);
	}

	/**
	 * Prints the rename plan.
	 *
	 * @param array $plan Rename plan.
	 */
	private function print_plan( $plan ) {
		$column_table = __( 'table', 'wp-cli-db-prefix' );
		$column_new   = __( 'new name', 'wp-cli-db-prefix' );

		$items = array();
		foreach ( $plan['tables'] as $old_table => $new_table ) {
			$items[] = array(
				$column_table => $old_table,
				$column_new   => $new_table,
			);
		}

		WP_CLI\Utils\format_items( 'table', $items, array( $column_table, $column_new ) );

		/* translators: %d: number of tables. */
		WP_CLI::log( sprintf( __( 'Tables to rename: %d', 'wp-cli-db-prefix' ), count( $plan['tables'] ) ) );

		if ( is_multisite() ) {
			/* translators: %d: number of sites. */
			WP_CLI::log( sprintf( __( 'Multisite network: %d sites, user_roles option renamed in each.', 'wp-cli-db-prefix' ), count( $plan['sites'] ) ) );
		} else {
			/* translators: 1: current option name, 2: new option name. */
			WP_CLI::log( sprintf( __( 'Option %1$s renamed to %2$s', 'wp-cli-db-prefix' ), $plan['old_prefix'] . 'user_roles', $plan['new_prefix'] . 'user_roles' ) );
		}

		/* translators: %d: number of user meta keys. */
		WP_CLI::log( sprintf( __( 'User meta keys to rename: %d', 'wp-cli-db-prefix' ), $plan['usermeta_count'] ) );

		if ( $plan['config'] ) {
			/* translators: %s: path to wp-config.php. */
			WP_CLI::log( sprintf( __( 'wp-config.php: %s', 'wp-cli-db-prefix' ), $plan['config']['path'] ) );
		} else {
			WP_CLI::log( __( 'wp-config.php: not changed (--skip-config)', 'wp-cli-db-prefix' ) );
		}

		if ( $plan['foreign_prefixes'] ) {
			WP_CLI::warning(
				sprintf(
					/* translators: 1: other prefixes found, 2: number of excluded tables, 3: excluded tables. */
					__( 'Other installation(s) found in the database (%1$s). These %2$d tables will not be touched: %3$s', 'wp-cli-db-prefix' ),
					implode( ', ', $plan['foreign_prefixes'] ),
					count( $plan['excluded'] ),
					implode( ', ', $plan['excluded'] )
				)
			);
		}
	}

	/**
	 * Applies the plan, and rolls back the applied steps on failure.
	 *
	 * @param array $plan Rename plan.
	 * @throws RuntimeException On failure, after rollback.
	 */
	private function apply( $plan ) {
		global $wpdb;

		$old_prefix = $plan['old_prefix'];
		$new_prefix = $plan['new_prefix'];

		// 1. Every table in one statement: MySQL renames all or nothing.
		if ( false === $wpdb->query( $this->rename_tables_query( $plan['tables'] ) ) ) {
			/* translators: %s: database error. */
			throw new RuntimeException( sprintf( __( 'Table rename failed, nothing was changed: %s', 'wp-cli-db-prefix' ), $wpdb->last_error ) );
		}
		$this->done[] = array( 'tables' );

		try {
			// 2. Roles option, site by site.
			foreach ( $plan['sites'] as $site ) {
				$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', $site['new'] . 'options', $site['new'] . 'user_roles', $site['old'] . 'user_roles' ) );

				if ( 1 !== $updated ) {
					/* translators: 1: option name, 2: database error. */
					throw new RuntimeException( sprintf( __( 'Could not rename option %1$s: %2$s', 'wp-cli-db-prefix' ), $site['old'] . 'user_roles', $wpdb->last_error ) );
				}
				$this->done[] = array( 'roles', $site );
			}

			// 3. User meta keys, in one query ({prefix}{id}_* keys of a network included).
			$updated = $wpdb->query( $this->usermeta_rename_query( $new_prefix . 'usermeta', $old_prefix, $new_prefix ) );

			if ( false === $updated ) {
				/* translators: %s: database error. */
				throw new RuntimeException( sprintf( __( 'Could not rename user meta keys: %s', 'wp-cli-db-prefix' ), $wpdb->last_error ) );
			}
			$this->done[] = array( 'usermeta' );

			// 4. wp-config.php last: until now, the site can be put back as it was.
			if ( $plan['config'] ) {
				$written = file_put_contents( $plan['config']['path'], $plan['config']['new_contents'], LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

				if ( strlen( $plan['config']['new_contents'] ) !== $written ) {
					$this->done[] = array( 'config' );
					/* translators: %s: path to wp-config.php. */
					throw new RuntimeException( sprintf( __( 'Could not write %s.', 'wp-cli-db-prefix' ), $plan['config']['path'] ) );
				}
			}
		} catch ( RuntimeException $exception ) {
			$errors = $this->rollback( $plan );

			if ( $errors ) {
				throw new RuntimeException( $exception->getMessage() . "\n" . __( 'Rollback failed, restore your backup:', 'wp-cli-db-prefix' ) . "\n" . implode( "\n", $errors ) );
			}

			throw new RuntimeException( $exception->getMessage() . "\n" . __( 'All changes were rolled back.', 'wp-cli-db-prefix' ) );
		}
	}

	/**
	 * Rolls back the applied steps, in reverse order.
	 *
	 * @param array $plan Rename plan.
	 * @return string[] Errors met during rollback.
	 */
	private function rollback( $plan ) {
		global $wpdb;

		$old_prefix = $plan['old_prefix'];
		$new_prefix = $plan['new_prefix'];
		$errors     = array();

		foreach ( array_reverse( $this->done ) as $step ) {
			switch ( $step[0] ) {
				case 'config':
					$written = file_put_contents( $plan['config']['path'], $plan['config']['contents'], LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

					if ( strlen( $plan['config']['contents'] ) !== $written ) {
						/* translators: 1: current prefix, 2: path to wp-config.php. */
						$errors[] = sprintf( __( '- wp-config.php: set $table_prefix = \'%1$s\'; back in %2$s', 'wp-cli-db-prefix' ), $old_prefix, $plan['config']['path'] );
					}
					break;

				case 'usermeta':
					if ( false === $wpdb->query( $this->usermeta_rename_query( $new_prefix . 'usermeta', $new_prefix, $old_prefix ) ) ) {
						/* translators: %s: database error. */
						$errors[] = sprintf( __( '- user meta keys: %s', 'wp-cli-db-prefix' ), $wpdb->last_error );
					}
					break;

				case 'roles':
					$site  = $step[1];
					$query = $wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', $site['new'] . 'options', $site['old'] . 'user_roles', $site['new'] . 'user_roles' );

					if ( false === $wpdb->query( $query ) ) {
						/* translators: 1: option name, 2: database error. */
						$errors[] = sprintf( __( '- option %1$s: %2$s', 'wp-cli-db-prefix' ), $site['old'] . 'user_roles', $wpdb->last_error );
					}
					break;

				case 'tables':
					if ( false === $wpdb->query( $this->rename_tables_query( array_flip( $plan['tables'] ) ) ) ) {
						/* translators: %s: database error. */
						$errors[] = sprintf( __( '- tables: %s', 'wp-cli-db-prefix' ), $wpdb->last_error );
					}
					break;
			}
		}

		$this->done = array();

		return $errors;
	}

	/**
	 * Builds the RENAME TABLE statement for all tables.
	 *
	 * @param array $tables Current name => new name.
	 * @return string Prepared query.
	 */
	private function rename_tables_query( $tables ) {
		global $wpdb;

		$pairs  = implode( ', ', array_fill( 0, count( $tables ), '%i TO %i' ) );
		$values = array();

		foreach ( $tables as $from => $to ) {
			$values[] = $from;
			$values[] = $to;
		}

		return $wpdb->prepare( "RENAME TABLE {$pairs}", $values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Builds the query replacing one prefix with another in user meta keys.
	 *
	 * @param string $table Usermeta table.
	 * @param string $from  Prefix to replace.
	 * @param string $to    Replacement prefix.
	 * @return string Prepared query.
	 */
	private function usermeta_rename_query( $table, $from, $to ) {
		global $wpdb;

		return $wpdb->prepare(
			'UPDATE %i SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s AND BINARY LEFT( meta_key, %d ) = %s',
			$table,
			$to,
			strlen( $from ) + 1,
			$wpdb->esc_like( $from ) . '%',
			strlen( $from ),
			$from
		);
	}

	/**
	 * Puts the site in maintenance mode during the operation, so visitors get
	 * a 503 instead of the installation screen or a database error.
	 */
	private function enable_maintenance() {
		$file = ABSPATH . '.maintenance';

		if ( file_exists( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false !== file_put_contents( $file, '<?php $upgrading = ' . time() . '; ?>' ) ) {
			$this->maintenance_created = true;
		}
	}

	/**
	 * Keeps the site in maintenance mode until it is lifted by hand.
	 *
	 * WordPress ignores a .maintenance file older than 10 minutes: the timestamp
	 * is set in the future so maintenance lasts until it is lifted manually.
	 */
	private function hold_maintenance() {
		if ( ! $this->maintenance_created ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( ABSPATH . '.maintenance', '<?php $upgrading = ' . ( time() + DAY_IN_SECONDS ) . '; ?>' );
		$this->maintenance_created = false;
	}

	/**
	 * Removes the .maintenance file if the command created it.
	 */
	private function disable_maintenance() {
		if ( $this->maintenance_created ) {
			wp_delete_file( ABSPATH . '.maintenance' );
			$this->maintenance_created = false;
		}
	}
}
