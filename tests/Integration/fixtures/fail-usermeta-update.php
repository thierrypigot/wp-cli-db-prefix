<?php
/**
 * Test fixture: makes the user meta rename fail, to exercise the rollback.
 *
 * Loaded with --require. Tables and roles option are already renamed when
 * this query runs, so the command has to undo both.
 *
 * @package WP_CLI_DB_Prefix
 */

WP_CLI::add_wp_hook(
	'query',
	static function ( $query ) {
		if ( 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, 'SET meta_key = CONCAT' ) ) {
			return 'UPDATE broken query on purpose';
		}

		return $query;
	}
);
