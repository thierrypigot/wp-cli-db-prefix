<?php
/**
 * WP-CLI DB Prefix: safely renames the WordPress table prefix.
 *
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Thierry Pigot, WeAre[WP]
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-cli-db-prefix
 * Domain Path:       /languages
 *
 * @package WP_CLI_DB_Prefix
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__ . '/src/class-wp-cli-db-prefix-command.php';

/**
 * Loads the package translations, based on the site language.
 *
 * Falls back to another variant of the same language (fr_BE, fr_CA... use fr_FR).
 */
function wp_cli_db_prefix_load_textdomain() {
	$locale = determine_locale();
	$file   = __DIR__ . '/languages/wp-cli-db-prefix-' . $locale . '.mo';

	if ( ! file_exists( $file ) ) {
		$variants = glob( __DIR__ . '/languages/wp-cli-db-prefix-' . substr( $locale, 0, 2 ) . '_*.mo' );
		$file     = $variants ? $variants[0] : '';
	}

	if ( $file ) {
		load_textdomain( 'wp-cli-db-prefix', $file, $locale );
	}
}

WP_CLI::add_hook( 'after_wp_load', 'wp_cli_db_prefix_load_textdomain' );

WP_CLI::add_command( 'db-prefix', 'WP_CLI_DB_Prefix_Command' );
