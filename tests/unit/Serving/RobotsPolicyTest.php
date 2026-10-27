<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Globetrotters\AiPresence\Serving\RobotsPolicy;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * Port of the Symfony bundle's ``RobotsPolicyTest``, plus WordPress's own output.
 *
 * @covers \Globetrotters\AiPresence\Serving\RobotsPolicy
 */
final class RobotsPolicyTest extends TestCase {

	public function test_agents_are_matched_the_way_crawlers_match_them(): void {
		$policy = RobotsPolicy::parse( "User-agent: GPTBot/1.1\nUser-agent: claudebot\nDisallow: /\n" );

		$this->assertTrue( $policy->names( 'GPTBot' ) );
		$this->assertTrue( $policy->names( 'ClaudeBot' ) );
		$this->assertFalse( $policy->names( 'CCBot' ) );
		$this->assertFalse( $policy->names( '*' ) );
	}

	public function test_consecutive_user_agents_share_a_group_and_a_rule_closes_it(): void {
		$policy = RobotsPolicy::parse( "User-agent: *\nUser-agent: CCBot\nDisallow: /a\nUser-agent: GPTBot\nDisallow: /b\n" );

		$this->assertSame( array( 'Disallow: /a' ), $policy->wildcard_lines() );
		$this->assertTrue( $policy->names( 'CCBot' ) );
		$this->assertTrue( $policy->names( 'GPTBot' ) );
	}

	/**
	 * The reported file. Google groups on Allow/Disallow only ("Rules other
	 * than allow, disallow, and user-agent are ignored"), so the Content-Signal
	 * does not end the section and ``*`` shares GPTBot's rule.
	 */
	public function test_a_content_signal_between_user_agents_does_not_end_the_section(): void {
		$policy = RobotsPolicy::parse( "User-agent: *\nContent-Signal: ai-train=no\nUser-agent: GPTBot\nDisallow: /private\n" );

		$this->assertTrue( $policy->names( 'GPTBot' ) );
		$this->assertSame( array( 'Content-Signal: ai-train=no', 'Disallow: /private' ), $policy->wildcard_lines() );
		$this->assertFalse( $policy->ends_inside_a_group() );
	}

	/**
	 * @dataProvider non_rule_lines
	 * @param string   $line     What sits between the two user-agent lines.
	 * @param string[] $expected The wildcard's member lines.
	 */
	public function test_only_a_rule_ends_a_user_agent_section( string $line, array $expected ): void {
		$policy = RobotsPolicy::parse( "User-agent: GPTBot\n" . $line . "\nUser-agent: *\nDisallow: /private\n" );

		$this->assertTrue( $policy->names( 'GPTBot' ) );
		// A line written before ``*`` joined the section still belongs to it.
		$this->assertSame( $expected, $policy->wildcard_lines() );
	}

	/**
	 * @return array<string, array{0:string, 1:string[]}>
	 */
	public static function non_rule_lines(): array {
		return array(
			'content-signal'    => array( 'Content-Signal: search=yes', array( 'Content-Signal: search=yes', 'Disallow: /private' ) ),
			'crawl-delay'       => array( 'Crawl-delay: 10', array( 'Crawl-delay: 10', 'Disallow: /private' ) ),
			'unknown directive' => array( 'Noindex: /tmp', array( 'Noindex: /tmp', 'Disallow: /private' ) ),
			'sitemap'           => array( 'Sitemap: https://x.test/sitemap.xml', array( 'Disallow: /private' ) ),
			'comment'           => array( '# a note', array( 'Disallow: /private' ) ),
			'blank line'        => array( '', array( 'Disallow: /private' ) ),
		);
	}

	public function test_allow_and_disallow_do_end_a_section(): void {
		$policy = RobotsPolicy::parse(
			"User-agent: *\nAllow: /public\nUser-agent: GPTBot\nDisallow: /\n"
			. "User-agent: *\nDisallow: /tmp\nUser-agent: CCBot\nDisallow: /\n"
		);

		$this->assertSame( array( 'Allow: /public', 'Disallow: /tmp' ), $policy->wildcard_lines() );
		$this->assertTrue( $policy->names( 'GPTBot' ) );
		$this->assertTrue( $policy->names( 'CCBot' ) );
	}

	public function test_metadata_after_a_rule_stays_with_that_group(): void {
		$policy = RobotsPolicy::parse( "User-agent: *\nDisallow: /a\nCrawl-delay: 5\nUser-agent: GPTBot\nDisallow: /b\n" );

		$this->assertSame( array( 'Disallow: /a', 'Crawl-delay: 5' ), $policy->wildcard_lines() );
	}

	/**
	 * RFC 9309 product tokens are letters, "-" and "_", and Google stops at the
	 * first other character: ``GPTBot2`` names GPTBot, ``*bot`` names nothing,
	 * and ``* everyone`` is still the wildcard.
	 */
	public function test_product_tokens_are_read_the_way_crawlers_read_them(): void {
		$policy = RobotsPolicy::parse( "User-agent: GPTBot2\nUser-agent: Googlebot-Image/1.0\nDisallow: /\n\nUser-agent: *bot\nDisallow: /b\n\nUser-agent: * everyone\nDisallow: /all\n" );

		$this->assertTrue( $policy->names( 'GPTBot' ) );
		$this->assertTrue( $policy->names( 'Googlebot-Image' ) );
		$this->assertFalse( $policy->names( 'Googlebot' ) );
		$this->assertSame( array( 'Disallow: /all' ), $policy->wildcard_lines() );
	}

	/**
	 * @dataProvider file_endings
	 * @param string $robots A whole robots.txt.
	 * @param bool   $open   Whether it stops inside a section no rule has closed.
	 */
	public function test_reports_whether_the_file_ends_inside_an_open_section( string $robots, bool $open ): void {
		$this->assertSame( $open, RobotsPolicy::parse( $robots )->ends_inside_a_group() );
	}

	/**
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public static function file_endings(): array {
		return array(
			'closed by a rule'             => array( "User-agent: *\nDisallow: /a\n", false ),
			'closed by an empty rule'      => array( "User-agent: *\nDisallow:\n", false ),
			'open after a signal'          => array( "User-agent: *\nContent-Signal: ai-train=no\n", true ),
			'open after a sitemap'         => array( "User-agent: *\nSitemap: https://x.test/s.xml\n", true ),
			'open after a bare user-agent' => array( "User-agent: GPTBot\nDisallow: /\nUser-agent: CCBot\n", true ),
			'no groups at all'             => array( "Sitemap: https://x.test/s.xml\n", false ),
			'empty'                        => array( '', false ),
		);
	}

	public function test_every_wildcard_group_is_combined_in_file_order(): void {
		$policy = RobotsPolicy::parse( "User-agent: *\nDisallow: /a\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nCrawl-delay: 5\n" );

		$this->assertSame( array( 'Disallow: /a', 'Crawl-delay: 5' ), $policy->wildcard_lines() );
	}

	public function test_comments_sitemaps_and_lines_outside_a_group_are_not_members(): void {
		$policy = RobotsPolicy::parse(
			"# house rules\nDisallow: /before-any-group\nSitemap: https://x.test/a.xml\n"
			. "User-agent: * # everyone\nDisallow: /admin # back office\nSitemap: https://x.test/b.xml\n"
		);

		$this->assertSame( array( 'Disallow: /admin' ), $policy->wildcard_lines() );
	}

	/**
	 * RFC 9309 permits empty lines inside a group; it is the next User-agent
	 * after a member line that starts a new one.
	 */
	public function test_a_blank_line_does_not_end_a_group(): void {
		$this->assertSame( array( 'Disallow: /admin' ), RobotsPolicy::parse( "User-agent: *\n\nDisallow: /admin\n" )->wildcard_lines() );
	}

	public function test_crlf_line_endings_and_a_byte_order_mark(): void {
		$policy = RobotsPolicy::parse( "\xEF\xBB\xBFUser-agent: GPTBot\r\nDisallow: /\r\n\r\nUser-agent: *\r\nDisallow: /admin\r\n" );

		$this->assertTrue( $policy->names( 'GPTBot' ) );
		$this->assertSame( array( 'Disallow: /admin' ), $policy->wildcard_lines() );
	}

	public function test_a_file_without_a_wildcard_group_leaves_nothing_to_inherit(): void {
		$this->assertSame( array(), RobotsPolicy::parse( "User-agent: GPTBot\nDisallow: /\n" )->wildcard_lines() );
		$this->assertSame( array(), RobotsPolicy::parse( '' )->wildcard_lines() );
	}

	/**
	 * What ``do_robots()`` generates for a public site: the wildcard rules every
	 * unnamed crawler is on, and core's sitemap line, which is not a member.
	 */
	public function test_wordpress_core_output(): void {
		$policy = RobotsPolicy::parse( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://acme.example/wp-sitemap.xml\n" );

		$this->assertSame( array( 'Disallow: /wp-admin/', 'Allow: /wp-admin/admin-ajax.php' ), $policy->wildcard_lines() );
		$this->assertFalse( $policy->names( 'GPTBot' ) );
		$this->assertFalse( $policy->ends_inside_a_group() );
	}
}
