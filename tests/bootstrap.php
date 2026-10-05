<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests load the command class outside WordPress: WP_CLI comes from the
 * wp-cli/wp-cli dev dependency, and the few WordPress functions it calls are
 * replaced by minimal stand-ins. Integration tests run the real command in a
 * separate WP-CLI process and need none of this.
 *
 * @package WP_CLI_DB_Prefix
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	/**
	 * Stand-in for the WordPress translation function: returns the text unchanged.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Stand-in for the WordPress plural translation function.
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Number.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		unset( $domain );
		return 1 === (int) $number ? $single : $plural;
	}
}

require_once dirname( __DIR__ ) . '/src/class-wp-cli-db-prefix-command.php';
require_once __DIR__ . '/Integration/class-integration-test-case.php';
