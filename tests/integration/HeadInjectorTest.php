<?php
/**
 * Integration: wp_head JSON-LD injection against a live WordPress core.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\HeadInjector;
use WP_UnitTestCase;

/**
 * Confirms the injector emits a single breakout-safe JSON-LD script into the raw
 * ``<head>`` on the front page only, using the real query state from ``go_to``.
 */
final class HeadInjectorTest extends WP_UnitTestCase {

	public function test_head_injector_is_hooked_on_wp_head_at_priority_20(): void {
		global $wp_filter;
		$this->assertArrayHasKey( 'wp_head', (array) $wp_filter );

		$found = false;
		foreach ( $wp_filter['wp_head']->callbacks[20] ?? array() as $callback ) {
			$fn = $callback['function'];
			if ( is_array( $fn ) && $fn[0] instanceof HeadInjector && 'inject' === $fn[1] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Plugin boot() must hook HeadInjector::inject on wp_head at priority 20.' );
	}

	public function test_injects_ld_json_on_front_page(): void {
		$schema = '{"@context":"https://schema.org","@type":"Organization","name":"Acme"}';
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'schema.json' => $schema ),
				'version' => 'v1',
			),
			false
		);

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_front_page(), 'Expected the home URL to resolve as the front page.' );

		ob_start();
		( new HeadInjector( new ArtefactCache() ) )->inject();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<script type="application/ld+json">', $html );
		$this->assertStringContainsString( '"@type":"Organization"', $html );
	}

	public function test_escapes_script_breakout_in_json_values(): void {
		// A value that would close the script element early if emitted verbatim,
		// led by one that escaping only "</" would not survive: "<!--<script>"
		// puts the tokenizer in the double-escaped script state.
		$schema = '{"@context":"https://schema.org","name":"<!--<script></script><script>alert(1)"}';
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'schema.json' => $schema ),
				'version' => 'v1',
			),
			false
		);

		$this->go_to( home_url( '/' ) );

		ob_start();
		( new HeadInjector( new ArtefactCache() ) )->inject();
		$html = (string) ob_get_clean();

		// The only literal </script> is the tag the injector itself closes with,
		// and the payload carries no tag delimiter at all — through core's own
		// wp_json_encode, not a test double.
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$inner = substr( $html, strlen( '<script type="application/ld+json">' ), -strlen( "</script>\n" ) );
		$this->assertStringNotContainsString( '<', $inner );
		$this->assertStringNotContainsString( '>', $inner );
		$this->assertSame( '<!--<script></script><script>alert(1)', json_decode( $inner )->name );
	}

	public function test_no_injection_off_front_page(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'schema.json' => '{"a":1}' ),
				'version' => 'v1',
			),
			false
		);

		$post_id = self::factory()->post->create();
		$this->go_to( (string) get_permalink( $post_id ) );
		$this->assertFalse( is_front_page() );

		ob_start();
		( new HeadInjector( new ArtefactCache() ) )->inject();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_no_injection_when_schema_absent(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'llms.txt' => '# hello' ),
				'version' => 'v1',
			),
			false
		);

		$this->go_to( home_url( '/' ) );

		ob_start();
		( new HeadInjector( new ArtefactCache() ) )->inject();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
