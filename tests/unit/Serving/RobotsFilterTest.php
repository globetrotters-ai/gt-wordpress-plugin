<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\AiUserAgents;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\RobotsFilter;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Settings\RobotsOptions;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * Ported from the Symfony bundle's ``RobotsFilterTest``, including its port of
 * google/robotstxt's matcher; the two plugins must decorate identically.
 *
 * @covers \Globetrotters\AiPresence\Serving\RobotsFilter
 * @covers \Globetrotters\AiPresence\Serving\RobotsPolicy
 */
final class RobotsFilterTest extends TestCase {

	use WpOptions;

	/**
	 * The customer's own apex — where the sitemap is served and advertised.
	 */
	private const SITE = 'https://acme.example';

	/**
	 * What ``do_robots()`` generates for a public site.
	 */
	private const WORDPRESS_DEFAULT = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://acme.example/wp-sitemap.xml\n";

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'home_url' )->justReturn( self::SITE . '/' );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
	}

	/**
	 * The parity gate.
	 *
	 * The fixture is the backend registry's own rendering of the compiled floor
	 * ({@see RobotsFilter::AI_BOTS}), byte for byte: the named-group half of the
	 * robots.txt both lanes emit. It is frozen, like the floor itself (the live
	 * registry syncs from the ranges document), and must never be hand-edited.
	 *
	 * Asserted as an exact whole-block equality rather than a containment, so an
	 * extra group, a reordering or a stray directive fails just as loudly as a
	 * missing name.
	 */
	public function test_ai_user_agent_groups_match_the_backend_registry_fixture(): void {
		$this->assertSame( self::registry_groups_fixture(), RobotsFilter::ai_user_agent_groups( true ) );
	}

	/**
	 * With both opt-ins and no rules of the site's own to inherit, the block is
	 * exactly what the backend registry renders.
	 */
	public function test_both_opt_ins_reproduce_the_registry_block(): void {
		$this->assertSame(
			RobotsFilter::MARKER . "\n"
				. self::registry_groups_fixture()
				. 'Sitemap: ' . self::SITE . "/ai-sitemap.xml\n",
			RobotsFilter::build_block( self::SITE, '', new RobotsOptions( RobotsOptions::ALLOW_ALL, true ) )
		);
	}

	public function test_build_block_is_the_marker_the_groups_and_the_sitemap(): void {
		$this->assertSame(
			RobotsFilter::MARKER . "\n"
				. RobotsFilter::ai_user_agent_groups( false )
				. 'Sitemap: ' . self::SITE . "/ai-sitemap.xml\n",
			RobotsFilter::build_block( self::SITE )
		);
	}

	/**
	 * The wildcard group is WordPress's to emit, not ours.
	 *
	 * The backend renders the whole file and so leads (or trails) with
	 * ``User-agent: *``; this block is appended to a robots.txt that already has
	 * one, and a second wildcard group would be combined with it.
	 */
	public function test_build_block_emits_no_wildcard_group(): void {
		$this->assertStringNotContainsString( 'User-agent: *', RobotsFilter::build_block( self::SITE ) );
		$this->assertStringNotContainsString( 'User-agent: *', RobotsFilter::build_block( self::SITE, self::WORDPRESS_DEFAULT ) );
	}

	/**
	 * ``Agentmap:`` is root-relative, so it is emitted only when asked for —
	 * by the filter, only while the catalog is cached.
	 */
	public function test_build_block_emits_no_agentmap_by_default(): void {
		$this->assertStringNotContainsString( 'Agentmap:', RobotsFilter::build_block( self::SITE ) );
	}

	/**
	 * Since 0.7.0 the catalog is served here, so the line names it,
	 * root-relative like the backend's, just before ``Sitemap:``.
	 */
	public function test_build_block_emits_agentmap_before_the_sitemap_when_asked(): void {
		$this->assertSame(
			RobotsFilter::MARKER . "\n"
				. RobotsFilter::ai_user_agent_groups( false )
				. "Agentmap: /.well-known/ai-catalog.json\n"
				. 'Sitemap: ' . self::SITE . "/ai-sitemap.xml\n",
			RobotsFilter::build_block( self::SITE, '', null, true )
		);
		$this->assertStringEndsWith(
			"\n\nAgentmap: /.well-known/ai-catalog.json\n",
			RobotsFilter::build_block( '', '', null, true )
		);
	}

	public function test_filter_emits_agentmap_while_the_catalog_is_cached(): void {
		$this->connect_and_install();
		$this->store[ ArtefactCache::OPTION ]['files'][ ContentTypes::AI_CATALOG ] = '{"specVersion":"1.0"}';
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$output = $filter->filter( self::WORDPRESS_DEFAULT, true );

		$this->assertStringContainsString(
			"Agentmap: /.well-known/ai-catalog.json\nSitemap: " . self::SITE . "/ai-sitemap.xml\n",
			$output
		);
		$this->assertSame( 1, substr_count( $output, 'Agentmap:' ) );
	}

	/**
	 * A bundle cached before 0.7.0 has no catalog until its next refresh, and
	 * the root-relative line would advertise a 404 meanwhile.
	 */
	public function test_filter_omits_agentmap_while_the_catalog_is_not_cached(): void {
		$this->connect_and_install();
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$output = $filter->filter( self::WORDPRESS_DEFAULT, true );

		$this->assertStringContainsString( RobotsFilter::MARKER, $output );
		$this->assertStringNotContainsString( 'Agentmap:', $output );
	}

	/**
	 * Per RFC 9309 §2.2.1 a crawler obeys only its own most-specific matching
	 * group, so the signal has to be inside every one of them.
	 */
	public function test_every_named_group_carries_its_own_content_signal(): void {
		$block = RobotsFilter::build_block( '' );

		$this->assertSame( substr_count( $block, 'User-agent: ' ), substr_count( $block, 'Content-Signal: ' ) );
	}

	/**
	 * Training is a statement about the customer's whole site, not about the
	 * presence; the block never makes it on their behalf.
	 */
	public function test_training_is_never_signalled_unless_opted_into(): void {
		$this->assertStringNotContainsString( 'ai-train', RobotsFilter::build_block( self::SITE ) );
		$this->assertStringNotContainsString( 'ai-train', RobotsFilter::build_block( self::SITE, self::WORDPRESS_DEFAULT ) );
		$this->assertStringContainsString(
			'Content-Signal: search=yes, ai-input=yes, ai-train=yes',
			RobotsFilter::build_block( self::SITE, self::WORDPRESS_DEFAULT, new RobotsOptions( RobotsOptions::INHERIT, true ) )
		);
	}

	public function test_build_block_omits_sitemap_without_an_origin(): void {
		$block = RobotsFilter::build_block( '' );

		$this->assertStringNotContainsString( 'Sitemap:', $block );
		// Still ends in exactly one newline, so the caller's append is clean.
		$this->assertStringEndsWith( "ai-input=yes\n", $block );
	}

	/**
	 * The property the decorate lane must hold: appending the block changes
	 * nothing any registry agent may fetch. Checked against a port of Google's
	 * own matcher, written independently of the parser under test — keeping the
	 * site's text intact is not enough if the effective policy moves.
	 *
	 * @dataProvider site_robots_provider
	 * @param string $existing The site's robots.txt.
	 */
	public function test_decorating_never_changes_what_an_agent_may_fetch( string $existing ): void {
		$decorated = rtrim( $existing, "\n" ) . "\n\n" . RobotsFilter::build_block( self::SITE, $existing );

		foreach ( self::registry() as $agent ) {
			foreach ( array( '/', '/admin', '/admin/users', '/wp-admin/', '/wp-admin/admin-ajax.php', '/private', '/private/x', '/public', '/page.html' ) as $path ) {
				$this->assertSame(
					self::allows( $existing, $agent, $path ),
					self::allows( $decorated, $agent, $path ),
					sprintf( '%s fetching %s', $agent, $path )
				);
			}
		}
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function site_robots_provider(): array {
		return array(
			'wordpress default'             => array( self::WORDPRESS_DEFAULT ),
			'wildcard restriction'          => array( "User-agent: *\nDisallow: /admin\n" ),
			'full-site disallow'            => array( "User-agent: *\nDisallow: /\n" ),
			'named group beside a wildcard' => array( "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n" ),
			'duplicate matching groups'     => array( "User-agent: GPTBot\nDisallow: /private\n\nUser-agent: *\nAllow: /\n\nUser-agent: gptbot\nDisallow: /admin\n" ),
			'multi-agent group'             => array( "User-agent: ClaudeBot\nUser-agent: CCBot\nDisallow: /\n" ),
			'named block and no wildcard'   => array( "User-agent: GPTBot\nDisallow: /\n" ),
			'googlebot only'                => array( "User-agent: Googlebot\nDisallow: /private\n\nUser-agent: *\nDisallow: /admin\n" ),
			'wildcard with crawl-delay'     => array( "User-agent: *\nCrawl-delay: 10\nDisallow: /admin\n" ),
			'comments and a sitemap'        => array( "# house rules\nSitemap: https://x.test/sitemap.xml\nUser-agent: * # everyone\nDisallow: /admin # back office\n" ),
			'empty file'                    => array( '' ),
			// Only Allow and Disallow end a user-agent section.
			'content-signal between user-agents (reported)' => array( "User-agent: *\nContent-Signal: ai-train=no\nUser-agent: GPTBot\nDisallow: /private\n" ),
			'unknown directive between user-agents' => array( "User-agent: GPTBot\nNoindex: /tmp\nUser-agent: *\nDisallow: /private\n" ),
			'crawl-delay between user-agents' => array( "User-agent: *\nCrawl-delay: 5\nUser-agent: Googlebot\nDisallow: /private\n\nUser-agent: GPTBot\nDisallow: /\n" ),
			'sitemap between user-agents'   => array( "User-agent: *\nSitemap: https://x.test/sitemap.xml\nUser-agent: CCBot\nDisallow: /private\n" ),
			'rules separating groups'       => array( "User-agent: *\nAllow: /public\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /private\n" ),
			'file ending inside a signal-only section' => array( "User-agent: *\nDisallow: /admin\n\nUser-agent: Googlebot\nContent-Signal: ai-train=no\n" ),
			'versioned agent token'         => array( "User-agent: GPTBot2\nDisallow: /\n" ),
		);
	}

	/**
	 * The reported case end to end. The Content-Signal line does not end the
	 * section, so ``*`` and GPTBot share ``Disallow: /private`` — Googlebot is
	 * refused /private before decoration and must still be after it.
	 */
	public function test_a_content_signal_between_user_agents_keeps_googlebot_out_of_private(): void {
		$existing  = "User-agent: *\nContent-Signal: ai-train=no\nUser-agent: GPTBot\nDisallow: /private\n";
		$block     = RobotsFilter::build_block( self::SITE, $existing );
		$decorated = rtrim( $existing, "\n" ) . "\n\n" . $block;

		$this->assertFalse( self::allows( $existing, 'Googlebot', '/private' ) );
		$this->assertFalse( self::allows( $decorated, 'Googlebot', '/private' ) );
		$this->assertTrue( self::allows( $decorated, 'Googlebot', '/public' ) );
		$this->assertStringContainsString( "User-agent: cohere-ai\nUser-agent: ExaSearchBot\nContent-Signal: ai-train=no\nDisallow: /private\n", $block );
		$this->assertStringNotContainsString( 'Allow: /', $block );
		$this->assertDoesNotMatchRegularExpression( '~^User-agent: GPTBot$~m', $block, 'the site names GPTBot' );
	}

	/**
	 * Appended groups must not fall into a user-agent section the site's file
	 * leaves open, or the site's agents would take on the appended rules. An
	 * empty Disallow closes it: Google gives a pathless rule priority 0, which
	 * never decides a match.
	 */
	public function test_a_section_the_site_leaves_open_is_closed_without_changing_it(): void {
		$existing  = "User-agent: *\nDisallow: /admin\n\nUser-agent: Googlebot\nContent-Signal: ai-train=no\n";
		$block     = RobotsFilter::build_block( self::SITE, $existing );
		$decorated = rtrim( $existing, "\n" ) . "\n\n" . $block;

		$this->assertStringStartsWith( RobotsFilter::MARKER . "\nDisallow:", $block );
		$this->assertTrue( self::allows( $existing, 'Googlebot', '/admin' ), 'a section naming Googlebot without rules allows it everything' );
		$this->assertTrue( self::allows( $decorated, 'Googlebot', '/admin' ) );
		// A file whose last section a rule already closed gets no closer.
		$this->assertStringStartsWith(
			RobotsFilter::MARKER . "\nUser-agent: ",
			RobotsFilter::build_block( self::SITE, "User-agent: *\nDisallow: /admin\n" )
		);
	}

	/**
	 * ``allow_all`` grants crawl permission; it does not speak for the site on
	 * content use. A training refusal declared on the wildcard group must reach
	 * every agent's own group, or it becomes "no preference" for them.
	 */
	public function test_allow_all_keeps_the_sites_own_content_signal(): void {
		$existing = "User-agent: *\nContent-Signal: search=yes,ai-train=no\nDisallow: /admin\n";
		$block    = RobotsFilter::build_block( self::SITE, $existing, new RobotsOptions( RobotsOptions::ALLOW_ALL, true ) );

		$this->assertStringContainsString( "User-agent: GPTBot\nAllow: /\nContent-Signal: search=yes,ai-train=no\n\n", $block );
		$this->assertSame( substr_count( $block, 'User-agent: ' ), substr_count( $block, 'Content-Signal: search=yes,ai-train=no' ) );
		$this->assertStringNotContainsString( 'ai-input=yes', $block );
	}

	/**
	 * The oracle is only worth trusting if it reads files the way Google does,
	 * so it is checked against cases taken from Google's robots.txt spec and
	 * google/robotstxt — not from RobotsPolicy.
	 */
	public function test_the_evaluator_groups_and_matches_the_way_google_does(): void {
		// Spec, "Grouping of lines and rules": a and b are one group.
		$this->assertFalse( self::allows( "user-agent: a\nsitemap: https://example.com/sitemap.xml\n\nuser-agent: b\ndisallow: /\n", 'a', '/x' ) );
		$this->assertFalse( self::allows( "User-agent: *\nContent-Signal: ai-train=no\nUser-agent: GPTBot\nDisallow: /private\n", 'Googlebot', '/private' ) );
		$this->assertFalse( self::allows( "User-agent: a\nCrawl-delay: 5\nUser-agent: b\nDisallow: /\n", 'a', '/x' ) );
		// A rule ends the section; the next user-agent starts another.
		$this->assertTrue( self::allows( "User-agent: a\nAllow: /\nUser-agent: b\nDisallow: /\n", 'a', '/x' ) );
		// A section naming the agent decides alone, even with no matching rule.
		$this->assertTrue( self::allows( "User-agent: *\nDisallow: /\n\nUser-agent: a\nDisallow: /other\n", 'a', '/x' ) );
		$this->assertTrue( self::allows( "User-agent: *\nDisallow: /\n\nUser-agent: a\n", 'a', '/x' ) );
		// Longest match wins, Allow wins a tie, a pathless rule is ignored.
		$this->assertFalse( self::allows( "User-agent: *\nAllow: /p\nDisallow: /p/\n", 'a', '/p/x' ) );
		$this->assertTrue( self::allows( "User-agent: *\nDisallow: /\nAllow: /\n", 'a', '/x' ) );
		$this->assertTrue( self::allows( "User-agent: *\nDisallow:\n", 'a', '/x' ) );
		$this->assertFalse( self::allows( "User-agent: *\nDisallow: /*.pdf$\n", 'a', '/doc.pdf' ) );
		$this->assertTrue( self::allows( "User-agent: *\nDisallow: /*.pdf$\n", 'a', '/doc.pdf?x' ) );
		// Spec: googlebot/1.2 and googlebot* are equivalent to googlebot.
		$this->assertFalse( self::allows( "User-agent: googlebot/1.2\nDisallow: /\n", 'Googlebot', '/' ) );
		$this->assertFalse( self::allows( "User-agent: googlebot*\nDisallow: /\n", 'Googlebot', '/' ) );
	}

	public function test_agents_the_site_already_names_are_never_added(): void {
		$block = RobotsFilter::build_block( self::SITE, "User-agent: GPTBot\nDisallow: /private\n\nUser-agent: gptbot\nDisallow: /admin\n\nUser-agent: *\nDisallow: /tmp\n" );

		// A group appended for GPTBot would merge with the site's two, and its
		// Allow: / would outrank their Disallow lines.
		$this->assertDoesNotMatchRegularExpression( '~^User-agent: GPTBot$~mi', $block );
		$this->assertStringContainsString( 'User-agent: ClaudeBot', $block );
	}

	public function test_inherited_rules_are_carried_once_in_one_shared_group(): void {
		$block = RobotsFilter::build_block( self::SITE, self::WORDPRESS_DEFAULT );

		$this->assertStringContainsString( "User-agent: GPTBot\nUser-agent: OAI-SearchBot\n", $block );
		$this->assertStringContainsString(
			"User-agent: cohere-ai\nUser-agent: ExaSearchBot\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nContent-Signal: search=yes, ai-input=yes\n\n",
			$block
		);
		$this->assertSame( 1, substr_count( $block, 'Disallow: /wp-admin/' ) );
		$this->assertDoesNotMatchRegularExpression( '~^Allow: /$~m', $block );
		// Core's sitemap line is a global record, not a rule to copy.
		$this->assertStringNotContainsString( 'wp-sitemap.xml', $block );
	}

	public function test_the_sites_own_content_signal_is_inherited_instead_of_ours(): void {
		$block = RobotsFilter::build_block(
			self::SITE,
			"User-agent: *\nContent-Signal: search=yes, ai-train=no\nAllow: /\n",
			new RobotsOptions( RobotsOptions::INHERIT, true )
		);

		$this->assertStringContainsString( 'Content-Signal: search=yes, ai-train=no', $block );
		$this->assertSame( 1, substr_count( $block, 'Content-Signal:' ) );
	}

	/**
	 * Apple documents that Applebot follows Googlebot's group when the file
	 * names Googlebot and not Applebot — an Applebot group would take it off
	 * those rules, so none is added.
	 */
	public function test_applebot_stays_on_googlebots_rules_when_the_site_names_only_googlebot(): void {
		$block = RobotsFilter::build_block( self::SITE, "User-agent: Googlebot\nDisallow: /private\n" );

		$this->assertDoesNotMatchRegularExpression( '~^User-agent: (Applebot|Googlebot)$~m', $block );
		$this->assertStringContainsString( 'User-agent: Applebot-Extended', $block );
	}

	public function test_allow_all_is_the_explicit_opt_in_to_override_wildcard_rules(): void {
		$existing  = "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/\n";
		$decorated = $existing . "\n" . RobotsFilter::build_block( self::SITE, $existing, new RobotsOptions( RobotsOptions::ALLOW_ALL ) );

		$this->assertTrue( self::allows( $decorated, 'ClaudeBot', '/wp-admin/' ), 'the grant it opts into' );
		$this->assertFalse( self::allows( $decorated, 'Mozilla', '/wp-admin/' ), 'unnamed agents keep the wildcard rules' );
		$this->assertFalse( self::allows( $decorated, 'GPTBot', '/page.html' ), 'a group the site wrote is never overridden' );
	}

	public function test_a_site_naming_every_agent_gets_only_the_marker_and_the_sitemap(): void {
		$existing = implode( "\n", array_map( static fn( string $agent ): string => 'User-agent: ' . $agent, self::registry() ) ) . "\nDisallow: /\n";

		$this->assertSame(
			RobotsFilter::MARKER . "\nSitemap: " . self::SITE . "/ai-sitemap.xml\n",
			RobotsFilter::build_block( self::SITE, $existing )
		);
	}

	public function test_filter_appends_block_when_connected_public_and_installed(): void {
		$this->connect_and_install();
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$output = $filter->filter( self::WORDPRESS_DEFAULT, true );

		$this->assertStringStartsWith( rtrim( self::WORDPRESS_DEFAULT, "\n" ) . "\n\n" . RobotsFilter::MARKER . "\n", $output );
		$this->assertSame( 1, substr_count( $output, 'User-agent: *' ) );
		$this->assertStringContainsString( "User-agent: cohere-ai\nUser-agent: ExaSearchBot\nDisallow: /wp-admin/\n", $output );
		$this->assertStringContainsString( 'Sitemap: ' . self::SITE . '/ai-sitemap.xml', $output );
		// Never the configured Globetrotters origin. A cross-host ``Sitemap:``
		// is ignored without cross-domain verification, and in plugin mode the
		// sitemap is served from this apex.
		$this->assertStringNotContainsString( 'globetrotters.ai', $output );
	}

	public function test_filter_reads_the_opt_ins_from_the_settings(): void {
		$this->connect_and_install();
		$this->store[ Options::SETTINGS ] += array(
			'robots_ai_agents' => RobotsOptions::ALLOW_ALL,
			'robots_ai_train'  => true,
		);
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$output = $filter->filter( self::WORDPRESS_DEFAULT, true );

		$this->assertStringContainsString( "User-agent: GPTBot\nAllow: /\nContent-Signal: search=yes, ai-input=yes, ai-train=yes\n", $output );
	}

	public function test_filter_is_noop_when_site_is_not_public(): void {
		$this->connect_and_install();
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$this->assertSame( 'ORIGINAL', $filter->filter( 'ORIGINAL', false ) );
	}

	public function test_filter_is_noop_when_not_connected(): void {
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$this->assertSame( 'ORIGINAL', $filter->filter( 'ORIGINAL', true ) );
	}

	public function test_filter_is_noop_before_a_bundle_is_installed(): void {
		// URL set (connected) but nothing has been pulled yet.
		$this->store[ Options::SETTINGS ] = array( 'website_url' => 'https://acme.globetrotters.ai' );
		$filter                           = new RobotsFilter( new Options(), new ArtefactCache() );

		$this->assertSame( 'ORIGINAL', $filter->filter( 'ORIGINAL', true ) );
	}

	/**
	 * At ``PHP_INT_MAX`` this sees every other filter's return value, and a
	 * callback that echoes instead of returning hands on null. That must pass
	 * through, not become a fatal on /robots.txt.
	 */
	public function test_filter_passes_a_non_string_output_through(): void {
		$this->connect_and_install();
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$this->assertNull( $filter->filter( null, '1' ) );
	}

	/**
	 * Core passes ``blog_public`` as stored: the string ``'0'`` for a site
	 * asking search engines away.
	 */
	public function test_filter_reads_cores_blog_public_string(): void {
		$this->connect_and_install();
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$this->assertSame( 'ORIGINAL', $filter->filter( 'ORIGINAL', '0' ) );
		$this->assertStringContainsString( RobotsFilter::MARKER, $filter->filter( 'ORIGINAL', '1' ) );
	}

	/**
	 * A synced registry names an agent the compiled constant has never heard
	 * of, with no plugin release.
	 */
	public function test_a_synced_registry_names_an_agent_the_constant_does_not(): void {
		$this->connect_and_install();
		$this->sync_registry( array( array( 'name' => 'GPTBot' ), array( 'name' => 'NewAI-Bot' ) ), time() );
		$filter = new RobotsFilter( new Options(), new ArtefactCache() );

		$output = $filter->filter( self::WORDPRESS_DEFAULT, true );

		$this->assertStringContainsString( "User-agent: GPTBot\nUser-agent: NewAI-Bot\nDisallow: /wp-admin/\n", $output );
		$this->assertStringNotContainsString( 'User-agent: ClaudeBot', $output, 'the synced list replaces the constant' );
	}

	/**
	 * With no list, a stale one or an unusable one, the output is byte-identical
	 * to the compiled constant's.
	 *
	 * @dataProvider registries_that_fall_back
	 *
	 * @param mixed $agents   The stored ``agents``.
	 * @param int   $age_days Days since the list was fetched.
	 */
	public function test_robots_txt_falls_back_to_the_constant( $agents, int $age_days ): void {
		$this->connect_and_install();
		$expected = ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::WORDPRESS_DEFAULT, true );
		$this->assertSame( rtrim( self::WORDPRESS_DEFAULT, "\n" ) . "\n\n" . RobotsFilter::build_block( self::SITE, self::WORDPRESS_DEFAULT ), $expected );

		$this->sync_registry( $agents, time() - $age_days * DAY_IN_SECONDS );

		$this->assertSame( $expected, ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::WORDPRESS_DEFAULT, true ) );
	}

	/**
	 * @return array<string, array{0:mixed, 1:int}>
	 */
	public static function registries_that_fall_back(): array {
		return array(
			'stale'       => array( array( array( 'name' => 'NewAI-Bot' ) ), 15 ),
			'empty'       => array( array(), 0 ),
			'all invalid' => array( array( array( 'name' => "Evil\nAllow: /" ), array( 'name' => 'Bot/1.0' ) ), 0 ),
			'not a list'  => array( 'GPTBot', 0 ),
			'over 64'     => array( array_fill( 0, 65, array( 'name' => 'Bot' ) ), 0 ),
		);
	}

	public function test_an_invalid_synced_entry_is_skipped_and_the_rest_named(): void {
		$this->connect_and_install();
		$this->sync_registry( array( array( 'name' => "Evil\nAllow: /" ), array( 'name' => 'NewAI-Bot' ) ), time() );

		$output = ( new RobotsFilter( new Options(), new ArtefactCache() ) )->filter( self::WORDPRESS_DEFAULT, true );

		$this->assertStringContainsString( "User-agent: NewAI-Bot\nDisallow: /wp-admin/\n", $output );
		$this->assertStringNotContainsString( 'Evil', $output );
	}

	/**
	 * The synced ``fallbackGroup`` decides, not the compiled map: a new agent
	 * reading another's group is left off, and an agent the sync no longer
	 * gives a fallback is named again.
	 */
	public function test_a_synced_fallback_group_is_honoured(): void {
		$registry = array(
			array(
				'name'          => 'NewAI-Bot',
				'fallbackGroup' => 'GPTBot',
			),
			array( 'name' => 'Applebot' ),
		);
		$existing = "User-agent: GPTBot\nDisallow: /private\n\nUser-agent: Googlebot\nDisallow: /private\n";

		$block = RobotsFilter::build_block( self::SITE, $existing, null, false, $registry );

		$this->assertStringNotContainsString( 'User-agent: NewAI-Bot', $block );
		$this->assertStringContainsString( 'User-agent: Applebot', $block );
	}

	public function test_a_synced_name_repeated_in_any_case_is_named_once_with_its_first_fallback(): void {
		$registry = array(
			array(
				'name'          => 'NewAI-Bot',
				'fallbackGroup' => 'GPTBot',
			),
			array( 'name' => 'newai-bot' ),
		);

		$block = RobotsFilter::build_block( self::SITE, "User-agent: GPTBot\nDisallow: /private\n", null, false, $registry );

		$this->assertStringNotContainsString( 'User-agent: NewAI-Bot', $block );
		$this->assertSame( 1, substr_count( strtolower( RobotsFilter::build_block( self::SITE, '', null, false, $registry ) ), 'user-agent: newai-bot' ) );
	}

	/**
	 * Store a synced registry the way the ranges sync does.
	 *
	 * @param mixed $agents     The ``agents`` value.
	 * @param int   $fetched_at When it was fetched.
	 */
	private function sync_registry( $agents, int $fetched_at ): void {
		$this->store[ AiUserAgents::OPTION ] = array(
			'version'    => 'v1',
			'fetched_at' => $fetched_at,
			'agents'     => $agents,
		);
	}

	/**
	 * The checked-in canonical named-group block.
	 */
	private static function registry_groups_fixture(): string {
		$path = dirname( __DIR__, 2 ) . '/fixtures/robots-ai-groups.txt';
		self::assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/**
	 * The registry's agents, read from the fixture rather than the class.
	 *
	 * @return string[]
	 */
	private static function registry(): array {
		preg_match_all( '~^User-agent: (.+)$~m', self::registry_groups_fixture(), $matches );
		return $matches[1];
	}

	/**
	 * Whether Google would let ``$agent`` fetch ``$path`` — a port of
	 * google/robotstxt's RobotsMatcher (HandleUserAgent, HandleAllow,
	 * HandleDisallow, disallow()), kept as the streaming state machine it is
	 * rather than derived from RobotsPolicy's group model, so the two cannot
	 * share a mistake.
	 *
	 * Only an Allow or Disallow line is a separator: Sitemap, Content-Signal,
	 * Crawl-delay and every unknown line leave a user-agent section open. The
	 * next User-agent after a separator starts a new section. Rules are
	 * recorded per section as specific (the section names the agent) or global
	 * (it names only ``*``); a specific match decides, a section naming the
	 * agent with no matching rule allows, and only otherwise does ``*`` count.
	 * Longest pattern wins, Allow wins a tie, and a rule without a path
	 * (priority 0) never decides.
	 *
	 * @param string $robots A whole robots.txt.
	 * @param string $agent  The crawler's product token.
	 * @param string $path   The path it wants.
	 */
	private static function allows( string $robots, string $agent, string $path ): bool {
		$agent              = strtolower( $agent );
		$seen_global        = false;
		$seen_specific      = false;
		$ever_seen_specific = false;
		$seen_separator     = false;
		$priority           = array(
			'allow'    => array(
				'specific' => -1,
				'global'   => -1,
			),
			'disallow' => array(
				'specific' => -1,
				'global'   => -1,
			),
		);

		foreach ( (array) preg_split( '/\r\n|\r|\n/', $robots ) as $raw ) {
			$line  = explode( '#', (string) $raw, 2 )[0];
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$key   = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );

			if ( 'user-agent' === $key ) {
				if ( $seen_separator ) {
					$seen_specific  = false;
					$seen_global    = false;
					$seen_separator = false;
				}
				if ( 1 === preg_match( '~^\*(\s|$)~', $value ) ) {
					$seen_global = true;
				} elseif ( 1 === preg_match( '~^[A-Za-z_-]+~', $value, $token ) && strtolower( $token[0] ) === $agent ) {
					$seen_specific      = true;
					$ever_seen_specific = true;
				}
				continue;
			}
			if ( 'allow' !== $key && 'disallow' !== $key ) {
				continue;
			}
			if ( ! $seen_global && ! $seen_specific ) {
				continue;
			}

			$seen_separator             = true;
			$scope                      = $seen_specific ? 'specific' : 'global';
			$priority[ $key ][ $scope ] = max( $priority[ $key ][ $scope ], self::match_priority( $path, $value ) );
		}

		foreach ( array( 'specific', 'global' ) as $scope ) {
			if ( $priority['allow'][ $scope ] > 0 || $priority['disallow'][ $scope ] > 0 ) {
				return $priority['disallow'][ $scope ] <= $priority['allow'][ $scope ];
			}
			if ( 'specific' === $scope && $ever_seen_specific ) {
				return true;
			}
		}

		return true;
	}

	/**
	 * Google's LongestMatchRobotsMatchStrategy: the pattern's length when it
	 * matches the path (``*`` any run, a trailing ``$`` anchors the end), else -1.
	 *
	 * @param string $path    The path being fetched.
	 * @param string $pattern An Allow or Disallow value.
	 */
	private static function match_priority( string $path, string $pattern ): int {
		$anchored = '$' === substr( $pattern, -1 );
		$body     = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$regex    = '~^' . str_replace( '\*', '.*', preg_quote( $body, '~' ) ) . ( $anchored ? '$' : '' ) . '~';

		return 1 === preg_match( $regex, $path ) ? strlen( $pattern ) : -1;
	}

	private function connect_and_install(): void {
		$this->store[ Options::SETTINGS ]     = array( 'website_url' => 'https://acme.globetrotters.ai' );
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# Acme' ),
			'version' => 'v1',
		);
	}
}
