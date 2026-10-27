<?php
/**
 * Integration: the subdomain_breadcrumb inbound link against a live WordPress.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\Breadcrumbs;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Settings\Options;
use WP_UnitTestCase;

/**
 * Confirms the discovery block is wired into the real hook graph, is gated on
 * the install profile, adds nothing visible to the page, and escapes what it
 * prints with core's own escapers.
 */
final class BreadcrumbsTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( Options::SETTINGS );
		delete_option( Options::STATE );
		parent::tearDown();
	}

	public function test_breadcrumbs_are_hooked_on_wp_head_at_20(): void {
		$this->assertTrue(
			$this->is_hooked( 'wp_head', 'inject_head' ),
			'Plugin boot() must hook Breadcrumbs::inject_head on wp_head at priority 20.'
		);
	}

	public function test_nothing_is_hooked_on_wp_footer(): void {
		// The visible anchor is deliberately not emitted: wp_footer fires after
		// all theme markup, so a plugin has no container to place it in.
		$this->assertFalse( $this->is_hooked( 'wp_footer', 'inject_footer' ) );
		$this->assertFalse( method_exists( Breadcrumbs::class, 'inject_footer' ) );
		// And with page-view counting off — the default, which is how this
		// suite boots — the plugin has no wp_footer callback of any kind. The
		// one it can have when counting is on is pinned by PageViewScriptTest.
		$this->assertSame( array(), $this->plugin_callbacks( 'wp_footer' ) );
	}

	public function test_emits_the_discovery_block_under_the_breadcrumb_profile(): void {
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB );

		$this->go_to( home_url( '/' ) );
		$head = $this->capture( 'inject_head' );

		// Apex-canonical: the files this install serves resolve to this host.
		$this->assertStringContainsString(
			'<link rel="alternate" type="application/ld+json" href="/schema.json">',
			$head
		);
		$this->assertStringContainsString( '<link rel="mcp" href="/.well-known/mcp.json">', $head );
		$this->assertStringContainsString( '<link rel="agent-card" href="/.well-known/agent-card.json">', $head );
		// Since 0.7.0 the catalog is served here too, so no relation names the
		// subdomain.
		$this->assertStringContainsString( '<link rel="ai-catalog" href="/.well-known/ai-catalog.json">', $head );
		// The presence host is named once, by the profile's own relation, and
		// that relation closes the block.
		$this->assertSame( 1, substr_count( $head, 'ai.example-destination.test' ) );
		$this->assertStringEndsWith(
			'<link rel="related" href="https://ai.example-destination.test/">' . "\n",
			$head
		);
		// Nothing visible: no anchor, no styling of the customer's theme.
		$this->assertStringNotContainsString( '<a ', $head );
		$this->assertStringNotContainsString( '<style', $head );
	}

	/**
	 * A cache from 0.6.x has no ai-catalog.json until the upgrade's first
	 * successful refresh, and the router 404s it meanwhile, so the relation
	 * keeps naming the Globetrotters copy until the local one is cached.
	 */
	public function test_a_legacy_cache_keeps_the_catalog_on_the_subdomain(): void {
		$legacy = array_fill_keys( ContentTypes::paths(), '{}' );
		unset( $legacy[ ContentTypes::AI_CATALOG ] );
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.example-destination.test', $legacy );

		$this->go_to( home_url( '/' ) );
		$head = $this->capture( 'inject_head' );

		$this->assertStringContainsString(
			'<link rel="ai-catalog" href="https://ai.example-destination.test/.well-known/ai-catalog.json">',
			$head
		);
		$this->assertStringContainsString( '<link rel="mcp" href="/.well-known/mcp.json">', $head );
	}

	public function test_interior_pages_and_a_cold_cache_still_name_the_presence_host(): void {
		$post_id = self::factory()->post->create();

		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB );
		$this->go_to( get_permalink( $post_id ) );
		$interior = $this->capture( 'inject_head' );

		$this->assertStringNotContainsString( 'rel="alternate"', $interior );
		$this->assertStringEndsWith(
			'<link rel="related" href="https://ai.example-destination.test/">' . "\n",
			$interior
		);

		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.example-destination.test', array() );
		$this->go_to( home_url( '/' ) );
		$this->assertStringEndsWith(
			'<link rel="related" href="https://ai.example-destination.test/">' . "\n",
			$this->capture( 'inject_head' )
		);
	}

	public function test_emits_nothing_under_the_full_apex_profile(): void {
		$this->configure( Options::PROFILE_FULL_APEX );

		$this->assertSame( '', $this->capture( 'inject_head' ) );
	}

	public function test_a_hostile_derived_origin_emits_nothing(): void {
		// gtaip_state is written by the sync layer, but it is still an option a
		// compromised or hand-edited install could carry. Real esc_url() rejects
		// the scheme, and the emptiness check turns that into "emit nothing".
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'javascript:alert(1)//' );

		$this->assertSame( '', $this->capture( 'inject_head' ) );
	}

	/**
	 * Whether Plugin::boot() hooked the given Breadcrumbs method at priority 20.
	 *
	 * @param string $hook   Action name.
	 * @param string $method Method name on Breadcrumbs.
	 */
	private function is_hooked( string $hook, string $method ): bool {
		global $wp_filter;
		// `??` does not save the `->callbacks` dereference, so an entirely
		// unregistered hook has to be excluded first or this crashes instead of
		// failing with the assertion's message.
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks[20] ?? array() as $callback ) {
			$fn = $callback['function'];
			if ( is_array( $fn ) && $fn[0] instanceof Breadcrumbs && $method === $fn[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every callback on a hook whose object belongs to this plugin, at any
	 * priority, as ``Class::method`` strings.
	 *
	 * @param string $hook Action name.
	 * @return string[]
	 */
	private function plugin_callbacks( string $hook ): array {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return array();
		}
		$found = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && is_object( $fn[0] ) && 0 === strpos( get_class( $fn[0] ), 'Globetrotters\\AiPresence\\' ) ) {
					$found[] = get_class( $fn[0] ) . '::' . $fn[1];
				}
			}
		}
		return $found;
	}

	/**
	 * Seed the profile setting and the state a refresh would have derived.
	 *
	 * @param string                     $profile Install profile.
	 * @param string                     $origin  Derived presence origin.
	 * @param array<string, string>|null $files   Cached artefacts; null caches the full served set.
	 */
	private function configure(
		string $profile,
		string $origin = 'https://ai.example-destination.test',
		?array $files = null
	): void {
		update_option( Options::SETTINGS, array( 'profile' => $profile ) );
		update_option( Options::STATE, array( 'presence_origin' => $origin ) );
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => $files ?? array_fill_keys( ContentTypes::paths(), '{}' ),
				'version' => 'v1',
			),
			false
		);
	}

	/**
	 * Buffer one of the injector's methods.
	 *
	 * @param string $method Method name on Breadcrumbs.
	 */
	private function capture( string $method ): string {
		ob_start();
		( new Breadcrumbs( new Options(), new ArtefactCache() ) )->$method();
		return (string) ob_get_clean();
	}
}
