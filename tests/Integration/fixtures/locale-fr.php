<?php
/**
 * Test fixture: runs WordPress in French, without installing a language pack.
 *
 * @package WP_CLI_DB_Prefix
 */

WP_CLI::add_wp_hook(
	'determine_locale',
	static function () {
		return 'fr_FR';
	}
);
