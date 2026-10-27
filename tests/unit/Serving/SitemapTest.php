<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Serving\ApiCatalog;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\Sitemap;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Serving\Sitemap
 */
final class SitemapTest extends TestCase {

	private const SITE = 'https://acme.example';

	/**
	 * 2026-09-09T00:00:00Z.
	 */
	private const CHANGED_AT = 1788912000;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'esc_xml' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_XML1, 'UTF-8' ) );
	}

	public function test_renders_the_site_root_and_every_cached_artefact(): void {
		$xml = Sitemap::render( self::SITE, array( 'llms.txt', 'ai.json' ), self::CHANGED_AT );

		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
				. "  <url>\n    <loc>https://acme.example/</loc>\n  </url>\n"
				. "  <url>\n    <loc>https://acme.example/llms.txt</loc>\n    <lastmod>2026-09-09</lastmod>\n  </url>\n"
				. "  <url>\n    <loc>https://acme.example/ai.json</loc>\n    <lastmod>2026-09-09</lastmod>\n  </url>\n"
				. "  <url>\n    <loc>https://acme.example/.well-known/api-catalog</loc>\n    <lastmod>2026-09-09</lastmod>\n  </url>\n"
				. "</urlset>\n",
			$xml
		);
	}

	/**
	 * The point of generating rather than fetching: a sitemap may only list
	 * same-host URLs, and this one is built from what the cache actually holds,
	 * so it cannot advertise a path this install answers with a 404.
	 */
	public function test_lists_nothing_that_is_not_cached(): void {
		$xml = Sitemap::render( self::SITE, array( 'llms.txt' ), self::CHANGED_AT );

		$this->assertStringContainsString( '<loc>https://acme.example/llms.txt</loc>', $xml );
		foreach ( ContentTypes::paths() as $path ) {
			if ( 'llms.txt' === $path ) {
				continue;
			}
			$this->assertStringNotContainsString( '/' . $path . '</loc>', $xml );
		}
	}

	/**
	 * Heavy files live on the Globetrotters host and are never served here, so
	 * they can never appear — the offload list needs no mirroring because the
	 * listing is derived from the cache rather than from a path list.
	 */
	public function test_never_names_another_host(): void {
		$xml = Sitemap::render( self::SITE, ContentTypes::paths(), self::CHANGED_AT );

		$this->assertSame(
			substr_count( $xml, '<loc>' ),
			substr_count( $xml, '<loc>' . self::SITE )
		);
		$this->assertStringNotContainsString( 'globetrotters.ai', $xml );
		$this->assertStringNotContainsString( 'llms-full.txt', $xml );
		$this->assertStringNotContainsString( 'content.md', $xml );
	}

	/**
	 * The drift marker is sync bookkeeping, not a crawl surface.
	 */
	public function test_excludes_the_version_marker(): void {
		$xml = Sitemap::render( self::SITE, ContentTypes::paths(), self::CHANGED_AT );

		$this->assertStringNotContainsString( ContentTypes::VERSION_MARKER, $xml );
	}

	/**
	 * ``<lastmod>`` is optional, and an absent stamp beats a false one: an
	 * install that has not recorded a content change yet has nothing true to
	 * say about when the content last moved.
	 */
	/**
	 * ``/`` is the customer's own homepage, edited in WordPress independently of
	 * the presence bundle. Stamping it with the bundle's change date would tell
	 * a crawler a page rewritten today was last modified whenever the artefacts
	 * last moved — a freshness claim about a page this plugin knows nothing
	 * about, and the signal search engines use to schedule a recrawl.
	 */
	public function test_the_site_root_carries_no_lastmod(): void {
		$xml = Sitemap::render( self::SITE, array( 'schema.json' ), self::CHANGED_AT );

		$this->assertStringContainsString(
			"<loc>https://acme.example/</loc>\n  </url>",
			$xml
		);
		// The artefacts still carry it — the bundle's date is true of those.
		$this->assertStringContainsString(
			"<loc>https://acme.example/schema.json</loc>\n    <lastmod>2026-09-09</lastmod>",
			$xml
		);
		$this->assertSame( 1, substr_count( $xml, '<lastmod>' ) );
	}

	public function test_omits_lastmod_when_no_change_has_been_recorded(): void {
		$xml = Sitemap::render( self::SITE, array( 'llms.txt' ), 0 );

		$this->assertStringNotContainsString( '<lastmod>', $xml );
		$this->assertStringContainsString( '<loc>https://acme.example/llms.txt</loc>', $xml );
	}

	public function test_ordering_is_deterministic(): void {
		$paths = ContentTypes::paths();

		$this->assertSame(
			Sitemap::render( self::SITE, $paths, self::CHANGED_AT ),
			Sitemap::render( self::SITE, array_reverse( $paths ), self::CHANGED_AT )
		);
	}

	/**
	 * The listing is intersected with the served map rather than trusting the
	 * caller, so nothing the plugin does not serve can reach the file.
	 */
	public function test_listable_intersects_with_the_served_map(): void {
		$this->assertSame(
			array( 'ai.json', 'schema.json' ),
			Sitemap::listable( array( 'schema.json', 'ai.json', 'llms-full.txt', ContentTypes::VERSION_MARKER ) )
		);
	}

	/**
	 * The API catalog is generated rather than cached, so it is listed when it
	 * would be answered: once any of its targets is cached. Matches
	 * the Symfony bundle's SitemapTest.
	 */
	public function test_listable_names_the_api_catalog_only_when_it_is_served(): void {
		$this->assertSame(
			array( '.well-known/mcp.json', ApiCatalog::PATH ),
			Sitemap::listable( array( '.well-known/mcp.json' ) )
		);
		$this->assertNotContains( ApiCatalog::PATH, Sitemap::listable( array( 'schema.json' ) ) );
		// Passing a listing back in neither drops nor repeats it.
		$this->assertSame(
			array( 'llms.txt', ApiCatalog::PATH ),
			Sitemap::listable( Sitemap::listable( array( 'llms.txt' ) ) )
		);
	}

	/**
	 * Dated by the bundle's change like the artefacts it lists: it is rendered
	 * from them.
	 */
	public function test_the_api_catalog_is_listed_after_the_artefacts(): void {
		$xml = Sitemap::render( self::SITE, array( 'llms.txt' ), self::CHANGED_AT );

		$this->assertStringEndsWith(
			"  <url>\n    <loc>https://acme.example/llms.txt</loc>\n    <lastmod>2026-09-09</lastmod>\n  </url>\n"
				. "  <url>\n    <loc>https://acme.example/.well-known/api-catalog</loc>\n    <lastmod>2026-09-09</lastmod>\n  </url>\n"
				. "</urlset>\n",
			$xml
		);
	}

	/**
	 * The full served set, in ContentTypes order: since 0.7.0 that includes the
	 * four agent discovery files, and since 0.8.2 the API catalog after them. Matches the Symfony bundle's SitemapTest.
	 */
	public function test_listable_names_every_served_artefact_but_the_marker(): void {
		$this->assertSame(
			array(
				'llms.txt',
				'ai.json',
				'schema.json',
				'.well-known/mcp.json',
				'.well-known/agent-card.json',
				'.well-known/ai-catalog.json',
				'.well-known/agent-skills/index.json',
				'agent.json',
				'AGENTS.md',
				'.well-known/api-catalog',
			),
			Sitemap::listable( array_reverse( ContentTypes::paths() ) )
		);
	}

	public function test_listable_is_empty_for_a_marker_only_bundle(): void {
		$this->assertSame( array(), Sitemap::listable( array( ContentTypes::VERSION_MARKER ) ) );
	}

	public function test_site_origin_is_scheme_host_and_port_only(): void {
		Functions\when( 'home_url' )->justReturn( 'https://acme.example/blog/' );

		$this->assertSame( 'https://acme.example', Sitemap::site_origin() );
	}

	public function test_site_origin_keeps_a_non_default_port(): void {
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8889/' );

		$this->assertSame( 'http://localhost:8889', Sitemap::site_origin() );
	}

	public function test_site_origin_is_empty_when_home_url_is_unusable(): void {
		Functions\when( 'home_url' )->justReturn( 'not a url' );

		$this->assertSame( '', Sitemap::site_origin() );
	}
}
