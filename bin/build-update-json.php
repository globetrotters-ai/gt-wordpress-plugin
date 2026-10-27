<?php
/**
 * Generate the self-hosted update metadata (`update.json`) for a release.
 *
 * Emits the update-metadata contract to stdout. Shared by `make release` and
 * the tag-triggered release workflow so the published metadata is generated one
 * way only. Pure CLI PHP — no WordPress runtime.
 *
 * Usage:
 *   php bin/build-update-json.php <version> <zip-path> <package-base-url>
 *
 * Example:
 *   php bin/build-update-json.php 0.2.0 \
 *       build/globetrotters-ai-presence-0.2.0.zip \
 *       https://globetrotters.ai/wp-plugin
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

if ( $argc < 4 ) {
	fwrite( STDERR, "Usage: php bin/build-update-json.php <version> <zip-path> <package-base-url>\n" );
	exit( 1 );
}

$version      = trim( (string) $argv[1] );
$zip_path     = (string) $argv[2];
$package_base = rtrim( (string) $argv[3], '/' );

if ( ! is_readable( $zip_path ) ) {
	fwrite( STDERR, "Zip not found or unreadable: {$zip_path}\n" );
	exit( 1 );
}

$root        = dirname( __DIR__ );
$plugin_file = $root . '/globetrotters-ai-presence.php';
$readme_file = $root . '/readme.txt';

$plugin_src = (string) file_get_contents( $plugin_file );
$readme_src = (string) file_get_contents( $readme_file );

/**
 * Pull a single-line header value (e.g. "Requires PHP: 7.4") from a source blob.
 */
$header = static function ( string $source, string $label ): string {
	if ( preg_match( '/^[\s*#]*' . preg_quote( $label, '/' ) . '\s*:\s*(.+)$/mi', $source, $m ) ) {
		return trim( $m[1] );
	}
	return '';
};

$sha256 = hash_file( 'sha256', $zip_path );
if ( ! is_string( $sha256 ) ) {
	fwrite( STDERR, "Failed to hash zip: {$zip_path}\n" );
	exit( 1 );
}

/**
 * Extract the topmost changelog entry from readme.txt and render it as simple
 * HTML (`<p>` heading + `<ul>` of bullet lines).
 */
$changelog_html = static function ( string $readme ): string {
	if ( ! preg_match( '/==\s*Changelog\s*==(.*)$/is', $readme, $m ) ) {
		return '';
	}
	$body = $m[1];

	// First "= x.y.z =" entry through to the next one (or end).
	if ( ! preg_match( '/=\s*([^=]+?)\s*=\s*(.*?)(?:\n=\s|\z)/is', $body, $e ) ) {
		return '';
	}
	$heading = trim( $e[1] );
	$lines   = preg_split( '/\r?\n/', trim( $e[2] ) ) ?: array();

	$items = array();
	$buf   = '';
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( preg_match( '/^\*\s+(.*)$/', $line, $lm ) ) {
			if ( '' !== $buf ) {
				$items[] = $buf;
			}
			$buf = trim( $lm[1] );
		} else {
			// Continuation of a wrapped bullet line.
			$buf = '' === $buf ? $line : $buf . ' ' . $line;
		}
	}
	if ( '' !== $buf ) {
		$items[] = $buf;
	}

	$html = '<h4>' . htmlspecialchars( $heading, ENT_QUOTES ) . '</h4>';
	if ( array() !== $items ) {
		$html .= '<ul>';
		foreach ( $items as $item ) {
			$html .= '<li>' . htmlspecialchars( $item, ENT_QUOTES ) . '</li>';
		}
		$html .= '</ul>';
	}
	return $html;
};

$metadata = array(
	'id'           => 'globetrotters-ai-presence',
	'slug'         => 'globetrotters-ai-presence',
	'version'      => $version,
	'package'      => $package_base . '/globetrotters-ai-presence-' . $version . '.zip',
	'sha256'       => $sha256,
	'requires'     => $header( $plugin_src, 'Requires at least' ),
	'requires_php' => $header( $plugin_src, 'Requires PHP' ),
	'tested'       => $header( $readme_src, 'Tested up to' ),
	'url'          => 'https://globetrotters.ai/wp-plugin',
	'last_updated' => gmdate( 'c' ),
	'sections'     => array(
		'changelog' => $changelog_html( $readme_src ),
	),
);

echo json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
