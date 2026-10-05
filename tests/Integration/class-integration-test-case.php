<?php
/**
 * Base class for the integration tests.
 *
 * Every test gets a fresh database and wp-config.php, then runs the real
 * command in a separate WP-CLI process, exactly as a user would.
 *
 * Environment variables:
 * - WP_CLI_BIN       WP-CLI command (default: wp). Example: "php /path/wp-cli.phar".
 * - WP_VERSION       WordPress version to download (default: latest).
 * - WP_DBP_DB_HOST   Database host (default: 127.0.0.1).
 * - WP_DBP_DB_USER   Database user, allowed to create databases (default: root).
 * - WP_DBP_DB_PASS   Database password (default: empty).
 * - WP_DBP_WORK_DIR  Working folder (default: system temp folder).
 *
 * @package WP_CLI_DB_Prefix
 */

use PHPUnit\Framework\TestCase;

/**
 * Fresh WordPress for each test, and helpers to run WP-CLI against it.
 */
abstract class Integration_Test_Case extends TestCase {

	/**
	 * Folder holding WordPress core files, shared by all tests.
	 *
	 * @var string
	 */
	protected static $site_dir;

	/**
	 * Working folder (outside the site) for backups and temporary files.
	 *
	 * @var string
	 */
	protected static $work_dir;

	/**
	 * Database name of the current test.
	 *
	 * @var string
	 */
	protected $db_name;

	/**
	 * Downloads WordPress once for the whole run.
	 */
	public static function setUpBeforeClass(): void {
		$base = self::env( 'WP_DBP_WORK_DIR', sys_get_temp_dir() . '/wp-cli-db-prefix-tests' );

		self::$work_dir = str_replace( '\\', '/', $base );
		self::$site_dir = self::$work_dir . '/site';

		if ( ! is_dir( self::$site_dir ) ) {
			mkdir( self::$site_dir, 0777, true );
		}

		if ( ! file_exists( self::$site_dir . '/wp-includes/version.php' ) ) {
			self::wp_ok( 'core download --version=' . escapeshellarg( self::env( 'WP_VERSION', 'latest' ) ) . ' --locale=en_US --force' );
		}
	}

	/**
	 * Creates a fresh database and wp-config.php (prefix wp_).
	 */
	protected function setUp(): void {
		$this->db_name = 'wpdbp_' . bin2hex( random_bytes( 4 ) );

		$this->remove_file( self::$site_dir . '/wp-config.php' );
		$this->remove_file( self::$site_dir . '/.maintenance' );

		self::wp_ok(
			sprintf(
				'config create --dbname=%s --dbuser=%s --dbpass=%s --dbhost=%s --dbprefix=wp_ --skip-check --force',
				escapeshellarg( $this->db_name ),
				escapeshellarg( self::env( 'WP_DBP_DB_USER', 'root' ) ),
				escapeshellarg( self::env( 'WP_DBP_DB_PASS', '' ) ),
				escapeshellarg( self::env( 'WP_DBP_DB_HOST', '127.0.0.1' ) )
			)
		);
		self::wp_ok( 'db create' );
	}

	/**
	 * Drops the test database and the maintenance file.
	 */
	protected function tearDown(): void {
		self::wp( 'db drop --yes' );
		$this->remove_file( self::$site_dir . '/.maintenance' );
	}

	/**
	 * Installs a single site with an administrator and an editor.
	 */
	protected function install_single_site() {
		self::wp_ok( 'core install --url=example.test --title=Test --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email' );
		self::wp_ok( 'user create editor editor@example.test --role=editor --user_pass=password' );
	}

	/**
	 * Installs a multisite network: site 1, three sub-sites (site 3 archived), users with different roles.
	 */
	protected function install_multisite() {
		self::wp_ok( 'core multisite-install --url=example.test --title=Network --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email' );

		foreach ( array( 'alpha', 'beta', 'gamma' ) as $slug ) {
			self::wp_ok( "site create --slug={$slug} --title=" . ucfirst( $slug ) . ' --url=example.test' );
		}

		self::wp_ok( 'site archive 3 --url=example.test' );
		self::wp_ok( 'user create editor editor@example.test --role=editor --user_pass=password --url=example.test/alpha/' );
		self::wp_ok( 'user add-role editor contributor --url=example.test/beta/' );
		self::wp_ok( 'user create author author@example.test --role=author --user_pass=password --url=example.test/gamma/' );
	}

	/**
	 * Runs WP-CLI against the test site, with the package loaded.
	 *
	 * @param string   $command  WP-CLI command, without the leading "wp".
	 * @param string[] $requires Extra files to load with --require (test fixtures).
	 * @return array{code: int, stdout: string, stderr: string}
	 */
	protected static function wp( $command, $requires = array() ) {
		$line = self::env( 'WP_CLI_BIN', 'wp' )
			. ' --path=' . escapeshellarg( self::$site_dir )
			. ' --require=' . escapeshellarg( dirname( __DIR__, 2 ) . '/command.php' );

		foreach ( $requires as $require ) {
			$line .= ' --require=' . escapeshellarg( __DIR__ . '/fixtures/' . $require );
		}

		$line .= ' ' . $command;

		$process = proc_open( $line, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, self::$site_dir );
		$stdout  = stream_get_contents( $pipes[1] );
		$stderr  = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'code'   => proc_close( $process ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	/**
	 * Runs WP-CLI and fails the test if the command fails.
	 *
	 * @param string   $command  WP-CLI command.
	 * @param string[] $requires Extra files to load with --require.
	 * @return array{code: int, stdout: string, stderr: string}
	 */
	protected static function wp_ok( $command, $requires = array() ) {
		$result = self::wp( $command, $requires );

		self::assertSame( 0, $result['code'], "wp {$command} failed:\n{$result['stdout']}\n{$result['stderr']}" );

		return $result;
	}

	/**
	 * Runs PHP code in WordPress (wp eval) and decodes its JSON output.
	 *
	 * Single quotes only in the code: on Windows, escapeshellarg() turns double quotes into spaces.
	 *
	 * @param string $php PHP code that echoes JSON.
	 * @param string $url Site URL, for multisite.
	 * @return mixed
	 */
	protected static function wp_json( $php, $url = '' ) {
		$command = 'eval ' . escapeshellarg( $php );

		if ( $url ) {
			$command .= ' --url=' . escapeshellarg( $url );
		}

		$result = self::wp_ok( $command );

		return json_decode( trim( $result['stdout'] ), true );
	}

	/**
	 * Lists the tables of the test database.
	 *
	 * @return string[]
	 */
	protected function tables() {
		$tables = self::wp_json( "echo json_encode( \$GLOBALS['wpdb']->get_col( 'SHOW TABLES' ) );" );
		sort( $tables );

		return $tables;
	}

	/**
	 * Returns the $table_prefix value written in wp-config.php.
	 *
	 * @return string
	 */
	protected function config_prefix() {
		preg_match( WP_CLI_DB_Prefix_Command::CONFIG_PATTERN, file_get_contents( self::$site_dir . '/wp-config.php' ), $matches );

		return $matches[3];
	}

	/**
	 * Returns the roles of a user on a site.
	 *
	 * @param string $login User login.
	 * @param string $url   Site URL (multisite) or empty.
	 * @return string[]
	 */
	protected function roles( $login, $url = '' ) {
		return self::wp_json( "echo json_encode( get_user_by( 'login', '{$login}' )->roles );", $url );
	}

	/**
	 * Reads an environment variable with a default.
	 *
	 * @param string $name    Variable name.
	 * @param string $default Default value.
	 * @return string
	 */
	protected static function env( $name, $default ) {
		$value = getenv( $name );

		return false === $value ? $default : $value;
	}

	/**
	 * Deletes a file if it exists.
	 *
	 * @param string $file Path.
	 */
	protected function remove_file( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}

	/**
	 * Deletes a folder and its content.
	 *
	 * @param string $dir Path.
	 */
	protected function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $dir );
	}
}
