<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Boots a real WordPress core via the WordPress PHPUnit test library (provided
 * by wp-env at ``WP_TESTS_DIR``) and loads this plugin as a must-use plugin so
 * its runtime hooks — ``parse_request`` routing, the ``robots_txt`` filter,
 * ``wp_head`` JSON-LD, and the refresh cron — are exercised against live core.
 *
 * Run it through wp-env so the harness path and databases are wired up:
 *
 *   wp-env run tests-cli --env-cwd=wp-content/plugins/globetrotters-ai-presence \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

// The WordPress test library. wp-env exposes it at /wordpress-phpunit and sets
// WP_TESTS_DIR; the fallback keeps a bare `phpunit` invocation honest.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $_tests_dir ) || '' === $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

// The WP test bootstrap requires the Yoast PHPUnit Polyfills. Loading Composer's
// autoloader here makes them (and the plugin's own dev classes) available, and
// the explicit path constant covers the case where the autoloader is absent.
$_autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( is_readable( $_autoload ) ) {
	require_once $_autoload;
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

$_functions = $_tests_dir . '/includes/functions.php';
if ( ! is_readable( $_functions ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$_tests_dir}.\n" .
		"Run the integration suite through wp-env (see this file's header).\n"
	);
	exit( 1 );
}

require_once $_functions;

/**
 * Load the plugin before WordPress finishes booting, so its ``plugins_loaded``
 * hook (which wires every runtime seam) fires inside the test request.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/globetrotters-ai-presence.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
