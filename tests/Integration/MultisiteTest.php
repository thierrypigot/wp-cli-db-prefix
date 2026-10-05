<?php
/**
 * Integration tests on a multisite network.
 *
 * @package WP_CLI_DB_Prefix
 */

/**
 * Network tables, per-site roles and rollback on a 4-site network.
 */
class MultisiteTest extends Integration_Test_Case {

	/**
	 * Installs a network before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->install_multisite();
	}

	/**
	 * Roles of each user on each site, keyed by site path.
	 *
	 * @return array
	 */
	private function network_roles() {
		return array(
			'main'  => $this->roles( 'admin', 'example.test/' ),
			'alpha' => array( $this->roles( 'admin', 'example.test/alpha/' ), $this->roles( 'editor', 'example.test/alpha/' ) ),
			'beta'  => array( $this->roles( 'admin', 'example.test/beta/' ), $this->roles( 'editor', 'example.test/beta/' ) ),
			'gamma' => array( $this->roles( 'admin', 'example.test/gamma/' ), $this->roles( 'author', 'example.test/gamma/' ) ),
		);
	}

	/**
	 * Every table, the roles option of every site and the {prefix}{id}_ user meta keys are renamed.
	 */
	public function test_rename_network() {
		$before_tables = $this->tables();
		$before_roles  = $this->network_roles();

		$result = self::wp_ok( 'db-prefix rename k7qm_ --yes --url=example.test' );

		$this->assertStringContainsString( '4 site(s)', $result['stdout'] );
		$this->assertSame( 'k7qm_', $this->config_prefix() );
		$this->assertSame( preg_replace( '/^wp_/', 'k7qm_', $before_tables ), $this->tables() );
		$this->assertSame( $before_roles, $this->network_roles() );
		$this->assertSame( array( 'editor' ), $this->roles( 'editor', 'example.test/alpha/' ) );
		$this->assertSame( array( 'contributor' ), $this->roles( 'editor', 'example.test/beta/' ) );
		$this->assertSame( array( 'admin' ), self::wp_json( 'echo json_encode( get_super_admins() );', 'example.test' ) );
	}

	/**
	 * Sub-site tables ({prefix}{id}_*) are not mistaken for another installation.
	 */
	public function test_subsites_are_not_other_installations() {
		$result = self::wp_ok( 'db-prefix rename k7qm_ --dry-run --url=example.test' );

		$this->assertStringNotContainsString( 'Other installation', $result['stderr'] );
		$this->assertStringContainsString( 'k7qm_3_options', $result['stdout'] );
	}

	/**
	 * A failure on site 3 rolls back sites 1 and 2, then the tables.
	 */
	public function test_failure_on_one_site_rolls_back_the_network() {
		$before_tables = $this->tables();
		$before_roles  = $this->network_roles();

		$result = self::wp( 'db-prefix rename k7qm_ --yes --url=example.test', array( 'fail-site-3-roles.php' ) );

		$this->assertSame( 1, $result['code'] );
		$this->assertStringContainsString( 'All changes were rolled back.', $result['stderr'] );
		$this->assertSame( 'wp_', $this->config_prefix() );
		$this->assertSame( $before_tables, $this->tables() );
		$this->assertSame( $before_roles, $this->network_roles() );
	}
}
