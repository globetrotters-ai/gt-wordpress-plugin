<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\Breadcrumbs;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Serving\Breadcrumbs
 */
final class BreadcrumbsTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://www.visit-acme.example/' );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias(
			fn( $url, $component = -1 ) => parse_url( $url, $component )
		);
	}

	public function test_locally_served_files_point_at_this_apex_not_the_subdomain(): void {
		// The apex is the canonical copy. schema.json / mcp.json / agent-card.json
		// are all in ContentTypes::MAP, so this install answers them itself and
		// must not send an agent to the mirror.
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

		$head = $this->capture_head();

		$this->assertStringContainsString(
			'<link rel="alternate" type="application/ld+json" href="/schema.json">',
			$head
		);
		$this->assertStringContainsString( '<link rel="mcp" href="/.well-known/mcp.json">', $head );
		$this->assertStringContainsString( '<link rel="agent-card" href="/.well-known/agent-card.json">', $head );
	}

	public function test_the_ai_catalog_points_at_the_apex_copy(): void {
		// Since 0.7.0 this install serves ai-catalog.json itself, so — like the
		// other three — it resolves to this apex, and no relation names the
		// subdomain. Matches the Symfony bundle's Breadcrumb::headBlock().
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

		$head = $this->capture_head();

		$this->assertStringContainsString( '<link rel="ai-catalog" href="/.well-known/ai-catalog.json">', $head );
		// The subdomain is named once, by the profile's own rel="related" pointer.
		$this->assertSame( 1, substr_count( $head, 'https://ai.visit-acme.example' ) );
		$this->assertStringContainsString( '<link rel="related" href="https://ai.visit-acme.example/">', $head );
	}

	public function test_the_exact_head_block(): void {
		// Matches the Symfony bundle's Breadcrumb::headBlock(), the related
		// relation last.
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

		$this->assertSame(
			"<!-- Globetrotters — AI presence -->\n"
			. '<link rel="alternate" type="application/ld+json" href="/schema.json">' . "\n"
			. '<link rel="ai-catalog" href="/.well-known/ai-catalog.json">' . "\n"
			. '<link rel="mcp" href="/.well-known/mcp.json">' . "\n"
			. '<link rel="agent-card" href="/.well-known/agent-card.json">' . "\n"
			. '<link rel="related" href="https://ai.visit-acme.example/">' . "\n",
			$this->capture_head()
		);
	}

	/**
	 * Once every discovery file resolves to the apex, the relations above no
	 * longer name the presence host at all, so the profile's pointer to it is a
	 * relation of its own: on every page, whatever the cache holds.
	 */
	public function test_the_presence_host_is_always_named(): void {
		foreach ( array( true, false ) as $front_page ) {
			Functions\when( 'is_front_page' )->justReturn( $front_page );
			$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

			$this->assertStringEndsWith(
				'<link rel="related" href="https://ai.visit-acme.example/">' . "\n",
				$this->capture_head()
			);
		}

		// Cold cache: every relation names the subdomain, and related still closes the block.
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example', array() );

		$this->assertStringEndsWith(
			'<link rel="related" href="https://ai.visit-acme.example/">' . "\n",
			$this->capture_head()
		);
	}

	/**
	 * A bundle cached before 0.7.0 has no ai-catalog.json until its first
	 * refresh after the upgrade, and the router 404s it meanwhile — so the
	 * relation keeps naming the Globetrotters copy until the local one is
	 * cached. Matches the Symfony bundle's Breadcrumb::href().
	 */
	public function test_a_served_file_not_cached_yet_still_points_at_the_subdomain(): void {
		$legacy = array_fill_keys( ContentTypes::paths(), '{}' );
		unset( $legacy[ ContentTypes::AI_CATALOG ] );
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example', $legacy );

		$head = $this->capture_head();

		$this->assertStringContainsString(
			'<link rel="ai-catalog" href="https://ai.visit-acme.example/.well-known/ai-catalog.json">',
			$head
		);
		$this->assertStringContainsString( '<link rel="mcp" href="/.well-known/mcp.json">', $head );
		$this->assertStringContainsString( '<link rel="agent-card" href="/.well-known/agent-card.json">', $head );
	}

	public function test_the_split_follows_the_served_set_not_a_hardcoded_list(): void {
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

		$head = $this->capture_head();

		foreach ( array( 'schema.json', '.well-known/mcp.json', '.well-known/agent-card.json', '.well-known/ai-catalog.json' ) as $path ) {
			$this->assertTrue( ContentTypes::has( $path ), "$path should be locally served" );
			$this->assertStringContainsString( 'href="/' . $path . '"', $head );
		}
	}

	public function test_the_json_ld_alternate_is_front_page_only(): void {
		// It describes the destination, like HeadInjector's inline block, so it is
		// gated the same way. The three discovery relations are site-level.
		Functions\when( 'is_front_page' )->justReturn( false );
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

		$head = $this->capture_head();

		$this->assertStringNotContainsString( 'rel="alternate"', $head );
		$this->assertStringContainsString( 'rel="mcp"', $head );
		$this->assertStringContainsString( 'rel="ai-catalog"', $head );
	}

	public function test_no_visible_markup_is_ever_added_to_the_page(): void {
		// Only head <link> pointers. A plugin has no container to put a visible
		// anchor in at wp_footer, so the customer places that one themselves.
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

		$head = $this->capture_head();

		$this->assertStringNotContainsString( '<a ', $head );
		$this->assertStringNotContainsString( '<style', $head );
		$this->assertFalse( method_exists( Breadcrumbs::class, 'inject_footer' ) );
	}

	public function test_nothing_is_emitted_under_the_full_apex_profile(): void {
		$this->configure( Options::PROFILE_FULL_APEX, 'https://ai.visit-acme.example' );

		$this->assertSame( '', $this->capture_head() );
	}

	public function test_nothing_is_emitted_before_the_origin_has_been_derived(): void {
		// A link to a placeholder host would be worse than no link at all.
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, '' );

		$this->assertSame( '', $this->capture_head() );
	}

	public function test_nothing_is_emitted_when_the_presence_is_on_this_very_host(): void {
		// No separate site to link — the block would only add a self-reference.
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://WWW.Visit-Acme.example' );

		$this->assertSame( '', $this->capture_head() );
	}

	public function test_an_origin_esc_url_rejects_emits_nothing_at_all(): void {
		// esc_url() strips a disallowed scheme to '' — without checking that, the
		// head would still carry five empty `<link href="">` tags.
		Functions\when( 'esc_url' )->alias(
			fn( $url ) => 0 === strpos( (string) $url, 'https://' ) ? $url : ''
		);
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'javascript:alert(1)//' );

		$this->assertSame( '', $this->capture_head() );
	}

	public function test_every_url_is_escaped(): void {
		// HeadInjector's `EscapeOutput` exemption is justified by render() building
		// a breakout-safe <script>; none of that reasoning carries to a <link>, so
		// every URL here has to go through esc_url().
		Functions\when( 'esc_url' )->alias( fn( $url ) => 'esc_url(' . $url . ')' );
		$this->configure( Options::PROFILE_SUBDOMAIN_BREADCRUMB, 'https://ai.visit-acme.example' );

		$head = $this->capture_head();

		$this->assertSame( 5, substr_count( $head, 'href="esc_url(' ) );
		$this->assertStringNotContainsString( 'href="https://', $head );
		$this->assertStringNotContainsString( 'href="/', $head );
	}

	/**
	 * Seed the profile setting, the derived state and the cache.
	 *
	 * @param string                     $profile Install profile.
	 * @param string                     $origin  Derived presence origin.
	 * @param array<string, string>|null $files   Cached artefacts; null caches the full served set.
	 */
	private function configure( string $profile, string $origin, ?array $files = null ): void {
		$this->store[ Options::SETTINGS ]     = array( 'profile' => $profile );
		$this->store[ Options::STATE ]        = array( 'presence_origin' => $origin );
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => $files ?? array_fill_keys( ContentTypes::paths(), '{}' ),
			'version' => 'v1',
		);
	}

	private function capture_head(): string {
		ob_start();
		( new Breadcrumbs( new Options(), new ArtefactCache() ) )->inject_head();
		return (string) ob_get_clean();
	}
}
