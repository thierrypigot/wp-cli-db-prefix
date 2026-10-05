<?php
/**
 * Integration tests of the --safe backup mode.
 *
 * Needs mysqldump (or mariadb-dump) and the mysql client in the PATH.
 *
 * @package WP_CLI_DB_Prefix
 */

/**
 * Backup, refused folders and full restore.
 */
class SafeModeTest extends Integration_Test_Case {

	/**
	 * Backup folder of the current test, outside the site.
	 *
	 * @var string
	 */
	private $backup_dir;

	/**
	 * Installs a single site before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->install_single_site();

		$this->backup_dir = self::$work_dir . '/backups-' . $this->db_name;
	}

	/**
	 * Removes the backup folder.
	 */
	protected function tearDown(): void {
		$this->remove_dir( $this->backup_dir );
		parent::tearDown();
	}

	/**
	 * The backup is written and checked, the rename happens, and the printed steps restore the site.
	 */
	public function test_backup_then_restore() {
		$tables = $this->tables();
		$config = file_get_contents( self::$site_dir . '/wp-config.php' );

		$result = self::wp_ok( 'db-prefix rename k7qm_ --safe --backup-dir=' . escapeshellarg( $this->backup_dir ) . ' --yes' );

		$sql   = glob( $this->backup_dir . '/db-prefix-wp_*.sql' );
		$copy  = glob( $this->backup_dir . '/wp-config-*.php' );
		$dump  = file_get_contents( $sql[0] );

		$this->assertCount( 1, $sql );
		$this->assertCount( 1, $copy );
		$this->assertStringContainsString( 'CREATE TABLE `wp_options`', $dump );
		$this->assertStringContainsString( 'CREATE TABLE `wp_usermeta`', $dump );
		$this->assertSame( $config, file_get_contents( $copy[0] ) );
		$this->assertFileExists( $this->backup_dir . '/.htaccess' );
		$this->assertStringContainsString( 'To go back to the saved state:', $result['stdout'] );
		$this->assertSame( 'k7qm_', $this->config_prefix() );

		// The restore steps printed by the command.
		copy( $copy[0], self::$site_dir . '/wp-config.php' );
		self::wp_ok( 'db import ' . escapeshellarg( $sql[0] ) );
		self::wp_json( "global \$wpdb; foreach ( \$wpdb->get_col( 'SHOW TABLES' ) as \$t ) { if ( 0 === strpos( \$t, 'k7qm_' ) ) { \$wpdb->query( 'DROP TABLE ' . \$t ); } } echo 1;" );

		$this->assertSame( 'wp_', $this->config_prefix() );
		$this->assertSame( $tables, $this->tables() );
		$this->assertSame( array( 'editor' ), $this->roles( 'editor' ) );
	}

	/**
	 * The backup only holds this site's tables, not those of another installation.
	 */
	public function test_backup_skips_other_installation() {
		self::wp_json( "global \$wpdb; \$wpdb->query( 'CREATE TABLE wp_shop_options LIKE wp_options' ); \$wpdb->query( 'CREATE TABLE wp_shop_posts LIKE wp_posts' ); echo 1;" );

		self::wp_ok( 'db-prefix rename k7qm_ --safe --backup-dir=' . escapeshellarg( $this->backup_dir ) . ' --yes' );

		$sql = glob( $this->backup_dir . '/db-prefix-wp_*.sql' );

		$this->assertStringNotContainsString( 'CREATE TABLE `wp_shop_options`', file_get_contents( $sql[0] ) );
	}

	/**
	 * A backup folder inside the web root is refused, before any change.
	 */
	public function test_backup_folder_inside_web_root_is_refused() {
		$tables = $this->tables();

		$result = self::wp( 'db-prefix rename k7qm_ --safe --backup-dir=' . escapeshellarg( self::$site_dir . '/backups' ) . ' --yes' );

		$this->assertSame( 1, $result['code'] );
		$this->assertStringContainsString( 'inside the web root', $result['stderr'] );
		$this->assertSame( $tables, $this->tables() );
		$this->assertDirectoryDoesNotExist( self::$site_dir . '/backups' );
	}

	/**
	 * ".." in the part of the path that does not exist yet is refused.
	 */
	public function test_backup_folder_with_dot_dot_is_refused() {
		$dir = self::$work_dir . '/missing/../../' . basename( self::$work_dir ) . '/site/x';

		$result = self::wp( 'db-prefix rename k7qm_ --safe --backup-dir=' . escapeshellarg( $dir ) . ' --yes' );

		$this->assertSame( 1, $result['code'] );
		// Linux cannot resolve ".." through a missing folder: "ambiguous". Windows resolves it on paper: "inside the web root".
		$this->assertMatchesRegularExpression( '/ambiguous|inside the web root/', $result['stderr'] );
		$this->assertSame( 'wp_', $this->config_prefix() );
		$this->assertDirectoryDoesNotExist( self::$site_dir . '/x' );
	}
}
