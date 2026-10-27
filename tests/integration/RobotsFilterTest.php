<?php
/**
 * Integration: the robots_txt filter against a live WordPress core.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\AiUserAgents;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\RobotsFilter;
use Globetrotters\AiPresence\Serving\Sitemap;
use Globetrotters\AiPresence\Settings\Options;
use WP_UnitTestCase;

/**
 * Drives {@see RobotsFilter} with real options/cache. The end-to-end wiring of
 * WordPress's virtual robots.txt is covered additionally by the CI curl smoke.
 */
final class RobotsFilterTest extends WP_UnitTestCase {

	private const BASE       = "User-agent: *\nDisallow:\n";
	private const SITE_URL   = 'https://acme.example';

	public function test_robots_txt_filter_is_registered(): void {
		$this->assertNotFalse(
			has_filter( 'robots_txt' ),
			'Plugin boot() must hook the robots_txt filter.'
		);
	}

	public function test_appends_ai_block_and_sitemap_when_public_and_connected(): void {
		$this->connect_with_bundle();

		$output = ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, true );

		$this->assertStringContainsString( '# Globetrotters AI Presence', $output );
		$this->assertStringContainsString( 'User-agent: GPTBot', $output );
		$this->assertStringContainsString( 'User-agent: ClaudeBot', $output );
		$this->assertStringContainsString( 'Content-Signal: search=yes, ai-input=yes', $output );
		// Training is the site owner's opt-in, never the default.
		$this->assertStringNotContainsString( 'ai-train', $output );
		// This site's own host, not the configured Globetrotters origin: a
		// cross-host Sitemap directive is ignored without cross-domain
		// verification, and in plugin mode the sitemap is served from here.
		$this->assertStringContainsString(
			'Sitemap: ' . Sitemap::site_origin() . '/' . Sitemap::PATH,
			$output
		);
		$this->assertStringNotContainsString( self::SITE_URL . '/sitemap.xml', $output );
	}

	/**
	 * The registry the ranges sync stored is what the registered filter names:
	 * a new crawler needs no plugin release.
	 */
	public function test_the_registered_filter_names_the_synced_registry(): void {
		$this->connect_with_bundle();
		( new AiUserAgents() )->replace( 'v1', array( array( 'name' => 'NewAI-Bot' ) ), time() );

		$output = apply_filters( 'robots_txt', self::BASE, true );

		$this->assertStringContainsString( 'User-agent: NewAI-Bot', $output );
		$this->assertStringNotContainsString( 'User-agent: GPTBot', $output );
	}

	/**
	 * WordPress's generated robots.txt already carries a wildcard group; ours
	 * must not add a second one, which could override the site's real rules.
	 */
	public function test_does_not_duplicate_the_wildcard_group(): void {
		$this->connect_with_bundle();

		$output = ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, true );

		$this->assertSame( 1, substr_count( $output, 'User-agent: *' ) );
	}

	/**
	 * Through the registered filter, on the rules WordPress core generates: the
	 * AI crawlers the block names stay out of /wp-admin/ exactly as before,
	 * rather than being handed an ``Allow: /`` that releases them from it.
	 */
	public function test_named_crawlers_inherit_wordpress_own_rules(): void {
		$this->connect_with_bundle();
		$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		$output = (string) apply_filters( 'robots_txt', $core, true );

		$this->assertStringContainsString(
			"User-agent: cohere-ai\nUser-agent: ExaSearchBot\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nContent-Signal: search=yes, ai-input=yes\n",
			$output
		);
		$this->assertDoesNotMatchRegularExpression( '~^Allow: /$~m', $output );
	}

	public function test_the_opt_ins_are_read_from_the_saved_settings(): void {
		$this->connect_with_bundle();
		update_option(
			Options::SETTINGS,
			array(
				'website_url'      => self::SITE_URL,
				'robots_ai_agents' => 'allow_all',
				'robots_ai_train'  => true,
			)
		);

		$output = ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, true );

		$this->assertStringContainsString( "User-agent: GPTBot\nAllow: /\nContent-Signal: search=yes, ai-input=yes, ai-train=yes\n", $output );
	}

	/**
	 * Against real options: the ARD catalog this install serves,
	 * root-relative like the backend's, just before the Sitemap line.
	 */
	public function test_names_the_catalog_while_it_is_cached(): void {
		$this->connect_with_bundle( array( ContentTypes::AI_CATALOG => '{"specVersion":"1.0"}' ) );

		$output = ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, true );

		$this->assertStringContainsString(
			"Agentmap: /.well-known/ai-catalog.json\nSitemap: " . Sitemap::site_origin() . '/' . Sitemap::PATH . "\n",
			$output
		);
	}

	/**
	 * A bundle cached by 0.6.x has no catalog until its next refresh; the
	 * root-relative line would advertise a 404 meanwhile.
	 */
	public function test_omits_agentmap_while_the_catalog_is_not_cached(): void {
		$this->connect_with_bundle();

		$output = ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, true );

		$this->assertStringContainsString( RobotsFilter::MARKER, $output );
		$this->assertStringNotContainsString( 'Agentmap:', $output );
	}

	public function test_no_block_when_site_is_not_public(): void {
		$this->connect_with_bundle();

		$this->assertSame(
			self::BASE,
			( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, false )
		);
	}

	public function test_no_block_when_not_connected(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'llms.txt' => '# hello' ),
				'version' => 'v1',
			),
			false
		);

		$this->assertSame(
			self::BASE,
			( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, true )
		);
	}

	public function test_no_block_when_bundle_empty(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::SITE_URL ) );

		$this->assertSame(
			self::BASE,
			( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::BASE, true )
		);
	}

	/**
	 * Connect the site and cache a bundle.
	 *
	 * @param array<string, string> $extra Further cached files.
	 */
	private function connect_with_bundle( array $extra = array() ): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::SITE_URL ) );
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'llms.txt' => '# hello' ) + $extra,
				'version' => 'v1',
			),
			false
		);
	}
}
