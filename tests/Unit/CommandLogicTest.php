<?php
/**
 * Unit tests for the parts of the command that need no database.
 *
 * @package WP_CLI_DB_Prefix
 */

use PHPUnit\Framework\TestCase;
use WP_CLI\ExitException;
use WP_CLI\Loggers\Execution;

/**
 * Prefix validation, wp-config.php rewriting and backup folder checks.
 */
class CommandLogicTest extends TestCase {

	/**
	 * Command under test.
	 *
	 * @var WP_CLI_DB_Prefix_Command
	 */
	private $command;

	/**
	 * Logger capturing WP_CLI output.
	 *
	 * @var Execution
	 */
	private $logger;

	/**
	 * Makes WP_CLI::error() throw instead of exiting, and captures its output.
	 */
	protected function setUp(): void {
		$this->logger = new Execution();
		WP_CLI::set_logger( $this->logger );
		$this->set_capture_exit( true );

		$this->command = new WP_CLI_DB_Prefix_Command();
	}

	/**
	 * Restores WP_CLI's default exit behaviour.
	 */
	protected function tearDown(): void {
		$this->set_capture_exit( false );
	}

	/**
	 * Sets WP_CLI::$capture_exit, private in WP-CLI (WP_CLI::runcommand() uses it the same way).
	 *
	 * @param bool $capture Throw an ExitException instead of exiting.
	 */
	private function set_capture_exit( $capture ) {
		$property = new ReflectionProperty( 'WP_CLI', 'capture_exit' );
		$property->setAccessible( true );
		$property->setValue( null, $capture );
	}

	/**
	 * Calls a private method of the command.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call( $method, ...$args ) {
		$reflection = new ReflectionMethod( $this->command, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( $this->command, ...$args );
	}

	/**
	 * Asserts that a private method stops the command with an error containing a text.
	 *
	 * @param string $expected Text expected in the error.
	 * @param string $method   Method name.
	 * @param mixed  ...$args  Arguments.
	 */
	private function assertCommandError( $expected, $method, ...$args ) {
		try {
			$this->call( $method, ...$args );
		} catch ( ExitException $exception ) {
			$this->assertSame( 1, $exception->getCode() );
			$this->assertStringContainsString( $expected, $this->logger->stderr );
			return;
		}

		$this->fail( "{$method}() did not stop the command." );
	}

	/**
	 * Valid prefixes go through without error.
	 *
	 * @dataProvider valid_prefixes
	 *
	 * @param string $prefix Prefix.
	 */
	public function test_valid_prefix_is_accepted( $prefix ) {
		$this->call( 'validate_prefix', $prefix, 'wp_' );
		$this->assertSame( '', $this->logger->stderr );
	}

	/**
	 * Valid prefixes.
	 *
	 * @return array
	 */
	public function valid_prefixes() {
		return array(
			'short'                => array( 'a_' ),
			'letters and digits'   => array( 'k7qm_' ),
			'inner underscore'     => array( 'site_2026_' ),
			'starts like old one'  => array( 'wp_shop_' ),
		);
	}

	/**
	 * Invalid prefixes are rejected.
	 *
	 * @dataProvider invalid_prefixes
	 *
	 * @param string $prefix Prefix.
	 */
	public function test_invalid_prefix_is_rejected( $prefix ) {
		$this->assertCommandError( 'rejected', 'validate_prefix', $prefix, 'wp_' );
	}

	/**
	 * Invalid prefixes.
	 *
	 * @return array
	 */
	public function invalid_prefixes() {
		return array(
			'no trailing underscore' => array( 'wawp' ),
			'uppercase'              => array( 'Wawp_' ),
			'starts with a digit'    => array( '7wawp_' ),
			'hyphen'                 => array( 'wa-wp_' ),
			'quote'                  => array( "wa'wp_" ),
			'space'                  => array( 'wa wp_' ),
			'only underscore'        => array( '_' ),
			'empty'                  => array( '' ),
		);
	}

	/**
	 * The current prefix is rejected as a new one.
	 */
	public function test_same_prefix_is_rejected() {
		$this->assertCommandError( 'same as the current one', 'validate_prefix', 'wp_', 'wp_' );
	}

	/**
	 * The $table_prefix line is rewritten whatever its quotes and spacing.
	 *
	 * @dataProvider config_lines
	 *
	 * @param string $line     Line as written in wp-config.php.
	 * @param string $expected Line after rewriting.
	 */
	public function test_config_pattern_rewrites_table_prefix( $line, $expected ) {
		$count  = preg_match_all( WP_CLI_DB_Prefix_Command::CONFIG_PATTERN, $line, $matches );
		$result = preg_replace( WP_CLI_DB_Prefix_Command::CONFIG_PATTERN, '${1}${2}k7qm_${2}${4}', $line, 1 );

		$this->assertSame( 1, $count );
		$this->assertSame( 'wp_', $matches[3][0] );
		$this->assertSame( $expected, $result );
	}

	/**
	 * $table_prefix lines.
	 *
	 * @return array
	 */
	public function config_lines() {
		return array(
			'single quotes' => array( "\$table_prefix = 'wp_';", "\$table_prefix = 'k7qm_';" ),
			'double quotes' => array( '$table_prefix = "wp_";', '$table_prefix = "k7qm_";' ),
			'no spaces'     => array( "\$table_prefix='wp_';", "\$table_prefix='k7qm_';" ),
			'tabs'          => array( "\$table_prefix\t=\t'wp_' ;", "\$table_prefix\t=\t'k7qm_' ;" ),
		);
	}

	/**
	 * A prefix computed at runtime is not a literal: no match, the command asks for --skip-config.
	 */
	public function test_config_pattern_ignores_dynamic_prefix() {
		$this->assertSame( 0, preg_match_all( WP_CLI_DB_Prefix_Command::CONFIG_PATTERN, "\$table_prefix = getenv( 'WP_PREFIX' );" ) );
	}

	/**
	 * Two definitions (one commented out, for example) are counted, so the command refuses to guess.
	 */
	public function test_config_pattern_counts_every_definition() {
		$config = "// \$table_prefix = 'old_';\n\$table_prefix = 'wp_';";

		$this->assertSame( 2, preg_match_all( WP_CLI_DB_Prefix_Command::CONFIG_PATTERN, $config ) );
	}

	/**
	 * Paths are compared in one normalized form.
	 */
	public function test_normalize_path() {
		$this->assertSame( 'C:/sites/demo/', $this->call( 'normalize_path', 'C:\\sites\\demo' ) );
		$this->assertSame( '/var/www/html/', $this->call( 'normalize_path', '/var//www/html/' ) );
	}

	/**
	 * A folder inside the web root is refused, including with another letter case.
	 *
	 * @dataProvider folders_inside_web_root
	 *
	 * @param string $dir Backup folder.
	 */
	public function test_backup_folder_inside_web_root_is_refused( $dir ) {
		$this->assertCommandError( 'inside the web root', 'check_outside_web_roots', $dir, array( '/home/app/public_html' ) );
	}

	/**
	 * Folders inside the web root.
	 *
	 * @return array
	 */
	public function folders_inside_web_root() {
		return array(
			'the web root itself' => array( '/home/app/public_html' ),
			'a subfolder'         => array( '/home/app/public_html/backups' ),
			'another case'        => array( '/home/app/Public_HTML/backups' ),
		);
	}

	/**
	 * Folders next to the web root are accepted, even with a similar name.
	 *
	 * @dataProvider folders_outside_web_root
	 *
	 * @param string $dir Backup folder.
	 */
	public function test_backup_folder_outside_web_root_is_accepted( $dir ) {
		$this->call( 'check_outside_web_roots', $dir, array( '/home/app/public_html' ) );
		$this->assertSame( '', $this->logger->stderr );
	}

	/**
	 * Folders outside the web root.
	 *
	 * @return array
	 */
	public function folders_outside_web_root() {
		return array(
			'private_html'      => array( '/home/app/private_html/db-prefix-backups' ),
			'parent'            => array( '/home/app/db-prefix-backups' ),
			'similar name'      => array( '/home/app/public_html_backups' ),
		);
	}
}
