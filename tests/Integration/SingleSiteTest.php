<?php
/**
 * Integration tests on a single site.
 *
 * @package WP_CLI_DB_Prefix
 */

/**
 * Renaming, refusals, rollback, maintenance mode and translations on a single site.
 */
class SingleSiteTest extends Integration_Test_Case {

	/**
	 * Installs a single site before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->install_single_site();
	}

	/**
	 * Tables, wp-config.php, roles and user meta keys all move to the new prefix.
	 */
	public function test_rename() {
		$before = $this->tables();

		$result = self::wp_ok( 'db-prefix rename k7qm_ --yes' );

		$this->assertStringContainsString( 'Prefix renamed to "k7qm_"', $result['stdout'] );
		$this->assertSame( 'k7qm_', $this->config_prefix() );
		$this->assertSame( preg_replace( '/^wp_/', 'k7qm_', $before ), $this->tables() );
		$this->assertSame( array( 'administrator' ), $this->roles( 'admin' ) );
		$this->assertSame( array( 'editor' ), $this->roles( 'editor' ) );
		$this->assertTrue( self::wp_json( "echo json_encode( user_can( get_user_by( 'login', 'editor' ), 'edit_others_posts' ) );" ) );
		$this->assertFileDoesNotExist( self::$site_dir . '/.maintenance' );
	}

	/**
	 * A dry run changes nothing.
	 */
	public function test_dry_run_changes_nothing() {
		$tables = $this->tables();
		$config = file_get_contents( self::$site_dir . '/wp-config.php' );

		$result = self::wp_ok( 'db-prefix rename k7qm_ --dry-run' );

		$this->assertStringContainsString( 'Dry run complete, nothing was changed.', $result['stdout'] );
		$this->assertSame( $tables, $this->tables() );
		$this->assertSame( $config, file_get_contents( self::$site_dir . '/wp-config.php' ) );
	}

	/**
	 * Without a prefix, a random one is generated in the xxxx_ format and used.
	 */
	public function test_generated_prefix() {
		$result = self::wp_ok( 'db-prefix rename --yes' );
		$prefix = $this->config_prefix();

		$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9]{3}_$/', $prefix );
		$this->assertStringContainsString( "Generated prefix: {$prefix}", $result['stdout'] );
		$this->assertContains( $prefix . 'options', $this->tables() );
	}

	/**
	 * A generated prefix in a dry run comes with the command to reuse it.
	 */
	public function test_generated_prefix_dry_run_shows_command() {
		$result = self::wp_ok( 'db-prefix rename --dry-run' );

		$this->assertMatchesRegularExpression( '/To use this one, run: wp db-prefix rename [a-z][a-z0-9]{3}_/', $result['stdout'] );
		$this->assertSame( 'wp_', $this->config_prefix() );
	}

	/**
	 * Invalid or conflicting prefixes are refused before any change.
	 *
	 * @dataProvider refused_prefixes
	 *
	 * @param string $prefix   Prefix.
	 * @param string $expected Text expected in the error.
	 */
	public function test_refused_prefix_changes_nothing( $prefix, $expected ) {
		self::wp_json( "\$GLOBALS['wpdb']->query( 'CREATE TABLE taken_options (id INT)' ); echo 1;" );
		$tables = $this->tables();

		$result = self::wp( 'db-prefix rename ' . $prefix . ' --yes' );

		$this->assertSame( 1, $result['code'] );
		$this->assertStringContainsString( $expected, $result['stderr'] );
		$this->assertSame( $tables, $this->tables() );
		$this->assertSame( 'wp_', $this->config_prefix() );
	}

	/**
	 * Refused prefixes.
	 *
	 * @return array
	 */
	public function refused_prefixes() {
		return array(
			'invalid format'   => array( 'Bad-Prefix', 'rejected' ),
			'same as current'  => array( 'wp_', 'same as the current one' ),
			'table name taken' => array( 'taken_', 'Table taken_options already exists.' ),
			// 50 + "term_relationships" (18) = 68 characters, over MySQL's 64.
			'name too long'    => array( str_repeat( 'a', 49 ) . '_', 'Name too long after renaming' ),
		);
	}

	/**
	 * Another installation sharing the database (prefix starting with ours) is left untouched.
	 */
	public function test_other_installation_is_left_untouched() {
		self::wp_json( "global \$wpdb; \$wpdb->query( 'CREATE TABLE wp_shop_options LIKE wp_options' ); \$wpdb->query( 'CREATE TABLE wp_shop_posts LIKE wp_posts' ); echo 1;" );

		$result = self::wp_ok( 'db-prefix rename k7qm_ --yes' );

		$this->assertStringContainsString( 'Other installation(s) found in the database (wp_shop_)', $result['stderr'] );
		$this->assertContains( 'wp_shop_options', $this->tables() );
		$this->assertContains( 'wp_shop_posts', $this->tables() );
		$this->assertNotContains( 'k7qm_shop_options', $this->tables() );
	}

	/**
	 * When a step fails, every applied step is rolled back.
	 */
	public function test_failure_rolls_everything_back() {
		$tables = $this->tables();
		$config = file_get_contents( self::$site_dir . '/wp-config.php' );

		$result = self::wp( 'db-prefix rename k7qm_ --yes', array( 'fail-usermeta-update.php' ) );

		$this->assertSame( 1, $result['code'] );
		$this->assertStringContainsString( 'All changes were rolled back.', $result['stderr'] );
		$this->assertSame( $tables, $this->tables() );
		$this->assertSame( $config, file_get_contents( self::$site_dir . '/wp-config.php' ) );
		$this->assertSame( array( 'administrator' ), $this->roles( 'admin' ) );
		$this->assertSame( array( 'editor' ), $this->roles( 'editor' ) );
		$this->assertFileDoesNotExist( self::$site_dir . '/.maintenance' );
	}

	/**
	 * With --skip-config, wp-config.php is untouched and the site stays in maintenance mode.
	 */
	public function test_skip_config_keeps_maintenance_mode() {
		$config = file_get_contents( self::$site_dir . '/wp-config.php' );

		$result = self::wp_ok( 'db-prefix rename k7qm_ --skip-config --yes' );

		$this->assertStringContainsString( 'maintenance mode (503)', $result['stderr'] );
		$this->assertSame( $config, file_get_contents( self::$site_dir . '/wp-config.php' ) );
		$this->assertFileExists( self::$site_dir . '/.maintenance' );

		// Maintenance lasts beyond WordPress's 10-minute limit, until lifted by hand.
		include self::$site_dir . '/.maintenance';
		$this->assertGreaterThan( time() + 3600, $upgrading );
	}

	/**
	 * Messages follow the site language.
	 */
	public function test_messages_in_french() {
		$result = self::wp( 'db-prefix rename Bad --yes', array( 'locale-fr.php' ) );

		$this->assertStringContainsString( "Préfixe «\u{00A0}Bad\u{00A0}» refusé", $result['stderr'] );
	}
}
