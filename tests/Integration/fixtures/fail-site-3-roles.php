<?php
/**
 * Test fixture: makes the roles option rename of site 3 fail, on a network.
 *
 * Loaded with --require. Sites 1 and 2 are already done when this query
 * runs, so the command has to undo them, then the tables.
 *
 * @package WP_CLI_DB_Prefix
 */

WP_CLI::add_wp_hook(
	'query',
	static function ( $query ) {
		if ( 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, '3_options`' ) ) {
			return 'UPDATE broken query on purpose';
		}

		return $query;
	}
);
