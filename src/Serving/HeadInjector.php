<?php
/**
 * Injects server-rendered JSON-LD into wp_head.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

use Globetrotters\AiPresence\Cache\ArtefactCache;

/**
 * Emits the destination JSON-LD into ``<head>`` server-side.
 *
 * Server-rendered JSON-LD lands in the raw HTML crawlers read without executing
 * JavaScript — the readiness-optimal outcome (no ``server_rendered_schema``
 * penalty). v1 injects the homepage ``schema.json`` on the front page; per-URL
 * injection can layer on later once a public URL→JSON-LD map is available.
 */
final class HeadInjector {

	private ArtefactCache $cache;

	public function __construct( ArtefactCache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register the hook.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'inject' ), 20 );
	}

	/**
	 * Print the JSON-LD script tag on the front page.
	 */
	public function inject(): void {
		if ( ! is_front_page() ) {
			return;
		}

		$json = $this->cache->get( 'schema.json' );
		if ( null === $json ) {
			return;
		}

		$markup = self::render( $json );
		if ( '' === $markup ) {
			return;
		}

		// $markup is a fully-formed, breakout-safe <script> tag (see render()).
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build a breakout-safe ``<script type="application/ld+json">`` tag.
	 *
	 * Re-encodes through ``wp_json_encode`` (dropping invalid JSON) with every
	 * ``<`` and ``>`` written as a JSON unicode escape (``JSON_HEX_TAG``), which
	 * any JSON-LD consumer decodes straight back. Returns an empty string when
	 * the input isn't valid JSON.
	 *
	 * Escaping only ``</`` is not enough. A value such as ``<!--<script>`` moves
	 * the HTML tokenizer into the double-escaped script state, where the real
	 * closing tag no longer ends the element and the rest of the homepage is
	 * swallowed into the script. Script data only changes state on a ``<``, so a
	 * body containing none cannot leave it, whatever the value says.
	 *
	 * @param string $json Raw JSON-LD document.
	 */
	public static function render( string $json ): string {
		if ( '' === trim( $json ) ) {
			return '';
		}
		$decoded = json_decode( $json );
		if ( null === $decoded ) {
			return '';
		}
		$encoded = wp_json_encode( $decoded, JSON_HEX_TAG );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return '';
		}
		return '<script type="application/ld+json">' . $encoded . '</script>' . "\n";
	}
}
