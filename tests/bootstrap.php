<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads Composer's autoloader (dev tooling) plus the plugin's own ``src/``
 * classes via a matching PSR-4 map, so unit tests can exercise them in
 * isolation with Brain\Monkey mocking the WordPress API.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Run 'composer install' before running the test suite.\n" );
	exit( 1 );
}

require $autoload;

// Composer autoloads both the plugin (``src/``) and test (``tests/``) classes
// via the PSR-4 maps in composer.json.

// WordPress time/size constants referenced by the plugin, defined here for unit runs.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1024 * 1024 );

// The plugin's own version constant (defined at runtime in the main plugin file,
// which is not loaded under unit tests). Pinned so version-compare tests have a
// stable baseline.
defined( 'Globetrotters\\AiPresence\\VERSION' ) || define( 'Globetrotters\\AiPresence\\VERSION', '0.1.0' );

// Minimal WP_Error stand-in for asserting the update integrity guard's failure
// paths without loading WordPress. Mirrors the subset of the real API the tests
// use (get_error_code()/get_error_message()).
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
