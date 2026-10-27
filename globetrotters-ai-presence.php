<?php
/**
 * Plugin Name:       Globetrotters AI Presence
 * Plugin URI:        https://globetrotters.ai/
 * Update URI:        https://globetrotters.ai/wp-plugin
 * Description:        Installs the Official AI Presence at your site's apex — serves the AI discovery files (llms.txt, ai.json, .well-known/*) and injects server-rendered JSON-LD, pulling everything from Globetrotters so nothing has to be uploaded by hand.
 * Version:           0.12.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Globetrotters
 * Author URI:        https://globetrotters.ai/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       globetrotters-ai-presence
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.12.0';

define( __NAMESPACE__ . '\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader for the plugin's own classes.
 *
 * The shipped plugin does not depend on Composer's autoloader — Composer is a
 * dev-only tool here (PHPUnit, PHPCS). Mapping ``Globetrotters\AiPresence\`` to
 * ``src/`` keeps the distributed ZIP dependency-free.
 */
spl_autoload_register(
	static function ( string $classname ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( strncmp( $classname, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $classname, strlen( $prefix ) );
		$path     = PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
