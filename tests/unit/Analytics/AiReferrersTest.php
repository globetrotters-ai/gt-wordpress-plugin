<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\AiReferrers;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * Matching a page view's referrer host and ``utm_source`` against the synced AI
 * referrer list (AC7, AC12).
 *
 * @covers \Globetrotters\AiPresence\Analytics\AiReferrers
 */
final class AiReferrersTest extends TestCase {

	use WpOptions;

	/**
	 * Every option name read through get_option, in call order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = array();

	/**
	 * The backend's served ``searchHosts``.
	 */
	private const SEARCH_HOSTS = array( 'google.*', 'www.google.*', 'bing.com', 'www.bing.com', 'duckduckgo.com', 'www.duckduckgo.com' );

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				$this->reads[] = $name;
				return $this->store[ $name ] ?? $default;
			}
		);
		$this->seed( time() - DAY_IN_SECONDS );
	}

	/**
	 * @dataProvider match_provider
	 */
	public function test_a_referrer_host_or_utm_source_resolves_to_its_platform( string $referrer, string $utm, string $expected ): void {
		$this->assertSame( $expected, ( new AiReferrers() )->match( $referrer, $utm ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function match_provider(): array {
		return array(
			'host'                         => array( 'chatgpt.com', '', 'chatgpt' ),
			'host, any case'               => array( 'ChatGPT.COM', '', 'chatgpt' ),
			'host with a port'             => array( 'chatgpt.com:443', '', 'chatgpt' ),
			'host with a trailing dot'     => array( 'claude.ai.', '', 'claude' ),
			'host, port and dot'           => array( 'Claude.AI.:8443', '', 'claude' ),
			'utm_source'                   => array( '', 'perplexity', 'perplexity' ),
			'utm_source, any case + space' => array( '', '  ChatGPT.com ', 'chatgpt' ),
			'host wins over utm_source'    => array( 'claude.ai', 'chatgpt.com', 'claude' ),
			'unknown host, known utm'      => array( 'news.example', 'perplexity.ai', 'perplexity' ),
			'unknown host'                 => array( 'www.google.com', '', 'none' ),
			'unknown utm_source'           => array( '', 'newsletter', 'none' ),
			'subdomain is another host'    => array( 'evil.chatgpt.com', '', 'none' ),
			'suffix trick'                 => array( 'chatgpt.com.evil.example', '', 'none' ),
			'host key is not a utm key'    => array( '', 'claude.ai', 'none' ),
			'utm key is not a host key'    => array( 'perplexity', '', 'none' ),
			// The specified probes.
			'copilot tag, no referrer'     => array( '', 'copilot.com?form=MG0AUO', 'copilot' ),
			'chatgpt tag behind google'    => array( 'www.google.fr', 'chatgpt.com', 'none' ),
			'chatgpt tag, no referrer'     => array( '', 'chatgpt.com', 'chatgpt' ),
			'gemini tag'                   => array( '', 'gemini', 'gemini' ),
			'chatgpt referrer and tag'     => array( 'chatgpt.com', 'chatgpt.com', 'chatgpt' ),
			// utm_source is cut at its first ``?``, then looked up exactly.
			'tag cut, any case'            => array( '', ' Copilot.COM?form=x ', 'copilot' ),
			'tag that is only a query'     => array( '', '?form=x', 'none' ),
			'cut tag is never a prefix'    => array( '', 'copilot.com.evil?form=x', 'none' ),
			'tag prefix is not a match'    => array( '', 'chatgpt', 'none' ),
			// No utm_source lookup behind a mixed search host.
			'tag behind google.co.uk'      => array( 'www.google.co.uk', 'perplexity', 'none' ),
			'tag behind bing'              => array( 'www.bing.com', 'chatgpt.com', 'none' ),
			'tag behind duckduckgo'        => array( 'duckduckgo.com:443', 'chatgpt.com', 'none' ),
			'host match behind no search'  => array( 'gemini.google.com', 'chatgpt.com', 'gemini' ),
			'tag behind a google lookalike' => array( 'notgoogle.com', 'chatgpt.com', 'chatgpt' ),
		);
	}

	/**
	 * @dataProvider search_host_provider
	 *
	 * @param array<int, string> $patterns The stored ``searchHosts``.
	 */
	public function test_search_host_patterns( array $patterns, string $host, bool $is_search ): void {
		$this->store[ AiReferrers::OPTION ]['searchHosts'] = $patterns;

		// A search host turns the utm_source lookup off: the tag then counts
		// for nothing.
		$this->assertSame( $is_search ? 'none' : 'chatgpt', ( new AiReferrers() )->match( $host, 'chatgpt.com' ) );
	}

	/**
	 * @return array<string, array{0:array<int, string>, 1:string, 2:bool}>
	 */
	public static function search_host_provider(): array {
		return array(
			'google.* on one TLD label'    => array( self::SEARCH_HOSTS, 'google.fr', true ),
			'google.* on two TLD labels'   => array( self::SEARCH_HOSTS, 'google.co.uk', true ),
			'www.google.* on two labels'   => array( self::SEARCH_HOSTS, 'www.google.com.br', true ),
			'google.* is not a subdomain'  => array( self::SEARCH_HOSTS, 'news.google.com', false ),
			'google.* is not a lookalike'  => array( self::SEARCH_HOSTS, 'notgoogle.com', false ),
			'google.* needs a TLD'         => array( self::SEARCH_HOSTS, 'google', false ),
			'google.* not three labels'    => array( self::SEARCH_HOSTS, 'google.co.uk.evil', false ),
			'google.* TLD of 2+ letters'   => array( self::SEARCH_HOSTS, 'google.f', false ),
			'google.* TLD letters only'    => array( self::SEARCH_HOSTS, 'google.123', false ),
			'bare host matches exactly'    => array( self::SEARCH_HOSTS, 'bing.com', true ),
			'bare host not its subdomain'  => array( self::SEARCH_HOSTS, 'cn.bing.com', false ),
			'bare host not another TLD'    => array( self::SEARCH_HOSTS, 'bing.fr', false ),
			'a bare dot is literal'        => array( array( 'bing.com' ), 'bingxcom', false ),
			'empty list matches nothing'   => array( array(), 'www.google.fr', false ),
		);
	}

	public function test_a_host_mapped_to_none_still_wins_over_the_utm_source(): void {
		$this->store[ AiReferrers::OPTION ]['hosts']['news.example'] = 'none';

		$this->assertSame( 'none', ( new AiReferrers() )->match( 'news.example', 'chatgpt.com' ) );
	}

	public function test_an_empty_search_host_list_never_matches_the_empty_host(): void {
		$this->store[ AiReferrers::OPTION ]['searchHosts'] = array();

		$this->assertSame( 'chatgpt', ( new AiReferrers() )->match( '', 'chatgpt.com' ) );
	}

	public function test_a_list_stored_before_search_hosts_still_loads_and_matches_as_before(): void {
		// Written by 0.10.0 and earlier: no ``searchHosts`` key at all.
		unset( $this->store[ AiReferrers::OPTION ]['searchHosts'] );
		$referrers = new AiReferrers();

		$this->assertSame( array(), $referrers->stored()['searchHosts'] );
		$this->assertSame( 'chatgpt', $referrers->match( 'chatgpt.com', '' ) );
		$this->assertSame( 'chatgpt', $referrers->match( 'www.google.fr', 'chatgpt.com' ), 'A tag behind Google counts, as it did before the key.' );
		$this->assertSame( 'copilot', $referrers->match( '', 'copilot.com?form=MG0AUO' ) );
	}

	public function test_a_list_stored_before_search_hosts_reports_no_version(): void {
		// So the ranges sync sends no If-None-Match and a 200 brings the key:
		// a 304 would keep the old shape until the backend's version moved.
		unset( $this->store[ AiReferrers::OPTION ]['searchHosts'] );

		$this->assertNull( ( new AiReferrers() )->version() );
		$this->assertTrue( ( new AiReferrers() )->is_fresh() );
	}

	public function test_a_view_with_no_hint_never_reads_the_list(): void {
		// AC12: the common case — a direct or internal visit — pays nothing.
		$this->reads = array();

		$this->assertSame( 'none', ( new AiReferrers() )->match( '', '' ) );

		$this->assertSame( array(), $this->reads );
	}

	public function test_a_hint_that_normalises_to_nothing_never_reads_the_list(): void {
		$this->reads = array();

		$this->assertSame( 'none', ( new AiReferrers() )->match( '.', '   ' ) );

		$this->assertSame( array(), $this->reads );
	}

	public function test_only_the_ai_referrer_option_is_ever_read(): void {
		$this->reads = array();

		( new AiReferrers() )->match( 'chatgpt.com', 'perplexity' );

		$this->assertSame( array( AiReferrers::OPTION ), array_values( array_unique( $this->reads ) ) );
	}

	public function test_a_missing_list_matches_nothing(): void {
		// AC7: the list deleted, a ChatGPT click is still a view, as ``none``.
		unset( $this->store[ AiReferrers::OPTION ] );

		$this->assertSame( 'none', ( new AiReferrers() )->match( 'chatgpt.com', 'chatgpt.com' ) );
	}

	public function test_a_list_older_than_14_days_matches_nothing(): void {
		$this->seed( time() - AiReferrers::STALE_AFTER_SECONDS - 60 );

		$this->assertSame( 'none', ( new AiReferrers() )->match( 'chatgpt.com', '' ) );
	}

	public function test_a_list_just_inside_14_days_still_matches(): void {
		$this->seed( time() - AiReferrers::STALE_AFTER_SECONDS + 60 );

		$this->assertSame( 'chatgpt', ( new AiReferrers() )->match( 'chatgpt.com', '' ) );
	}

	/**
	 * @dataProvider unreadable_provider
	 *
	 * @param mixed $stored Stored option value.
	 */
	public function test_an_unreadable_list_matches_nothing( $stored ): void {
		$this->store[ AiReferrers::OPTION ] = $stored;

		$this->assertSame( 'none', ( new AiReferrers() )->match( 'chatgpt.com', 'chatgpt.com' ) );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function unreadable_provider(): array {
		return array(
			'not an array'     => array( 'garbage' ),
			'no fetched_at'    => array(
				array(
					'version' => 'v1',
					'hosts'   => array( 'chatgpt.com' => 'chatgpt' ),
				),
			),
			'hosts not a list' => array(
				array(
					'version'    => 'v1',
					'fetched_at' => time(),
					'hosts'      => 'chatgpt.com',
					'utmSources' => 'chatgpt.com',
				),
			),
			'bad platform'     => array(
				array(
					'version'    => 'v1',
					'fetched_at' => time(),
					'hosts'      => array( 'chatgpt.com' => 'Not A Platform!' ),
					'utmSources' => array( 'chatgpt.com' => array( 'chatgpt' ) ),
				),
			),
		);
	}

	public function test_parse_keeps_only_well_formed_entries(): void {
		$parsed = AiReferrers::parse(
			array(
				'hosts'      => array(
					'chatgpt.com'     => 'chatgpt',
					'Claude.AI.'      => 'claude',
					'bad.example'     => 'Not a platform',
					'long.example'    => str_repeat( 'a', 33 ),
					'list.example'    => array( 'chatgpt' ),
					''                => 'chatgpt',
					'copilot.example' => 'copilot',
				),
				'utmSources'  => array(
					' Perplexity '            => 'perplexity',
					'Copilot.com?form=MG0AUO' => 'copilot',
					'?form=x'                 => 'copilot',
					'x'                       => 7,
				),
				'searchHosts' => array( ' Google.* ', 'www.bing.com', '', '   ', 7, array( 'x' ), null, str_repeat( 'a', 254 ) ),
			)
		);

		$this->assertSame(
			array(
				'hosts'       => array(
					'chatgpt.com'     => 'chatgpt',
					'claude.ai'       => 'claude',
					'copilot.example' => 'copilot',
				),
				'utmSources'  => array(
					'perplexity'  => 'perplexity',
					'copilot.com' => 'copilot',
				),
				'searchHosts' => array( 'google.*', 'www.bing.com' ),
			),
			$parsed
		);
	}

	/**
	 * @dataProvider absent_provider
	 *
	 * @param mixed $wire The ``aiReferrers`` member as received.
	 */
	public function test_parse_reads_an_absent_or_malformed_member_as_empty_lists( $wire ): void {
		$this->assertSame(
			array(
				'hosts'       => array(),
				'utmSources'  => array(),
				'searchHosts' => array(),
			),
			AiReferrers::parse( $wire )
		);
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function absent_provider(): array {
		return array(
			'absent'         => array( null ),
			'a string'       => array( 'chatgpt.com' ),
			'members absent' => array( array() ),
			'members wrong'  => array(
				array(
					'hosts'       => 'chatgpt.com',
					'utmSources'  => 3,
					'searchHosts' => 'google.*',
				),
			),
		);
	}

	public function test_replace_stores_the_list_unautoloaded_and_stamps_it(): void {
		$autoload = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $auto = null ) use ( &$autoload ) {
				$this->store[ $name ] = $value;
				$autoload             = $auto;
				return true;
			}
		);
		$referrers = new AiReferrers();

		$referrers->replace(
			'v9',
			array(
				'hosts'       => array( 'chatgpt.com' => 'chatgpt' ),
				'utmSources'  => array(),
				'searchHosts' => array( 'google.*' ),
			),
			1700000000
		);

		$this->assertFalse( $autoload );
		$this->assertSame(
			array(
				'version'     => 'v9',
				'fetched_at'  => 1700000000,
				'hosts'       => array( 'chatgpt.com' => 'chatgpt' ),
				'utmSources'  => array(),
				'searchHosts' => array( 'google.*' ),
			),
			$this->store[ AiReferrers::OPTION ]
		);
		$this->assertSame( 'v9', $referrers->version() );
	}

	public function test_restamp_renews_the_age_and_keeps_the_lists(): void {
		$this->seed( time() - 20 * DAY_IN_SECONDS );
		$referrers = new AiReferrers();
		$this->assertSame( 'none', $referrers->match( 'chatgpt.com', '' ) );

		$referrers->restamp( time() );

		$this->assertTrue( $referrers->is_fresh() );
		$this->assertSame( 'chatgpt', $referrers->match( 'chatgpt.com', '' ) );
	}

	public function test_restamp_without_a_list_writes_nothing(): void {
		unset( $this->store[ AiReferrers::OPTION ] );

		( new AiReferrers() )->restamp( time() );

		$this->assertArrayNotHasKey( AiReferrers::OPTION, $this->store );
	}

	public function test_the_matcher_never_touches_the_vendor_ranges(): void {
		// The beacon must never load the 1,000-range vendor list to match a
		// referral; the dependency is ruled out at the source.
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Analytics/AiReferrers.php' );

		$this->assertStringNotContainsString( 'VendorRanges', $source );
		$this->assertStringNotContainsString( 'gtaip_vendor_ranges', $source );
	}

	/**
	 * Store a list fetched at the given time.
	 *
	 * @param int $fetched_at Unix time.
	 */
	private function seed( int $fetched_at ): void {
		$this->store[ AiReferrers::OPTION ] = array(
			'version'     => 'v1',
			'fetched_at'  => $fetched_at,
			'hosts'       => array(
				'chatgpt.com'       => 'chatgpt',
				'chat.openai.com'   => 'chatgpt',
				'claude.ai'         => 'claude',
				'copilot.com'       => 'copilot',
				'gemini.google.com' => 'gemini',
				'perplexity.ai'     => 'perplexity',
			),
			'utmSources'  => array(
				'chatgpt.com'   => 'chatgpt',
				'copilot.com'   => 'copilot',
				'gemini'        => 'gemini',
				'perplexity'    => 'perplexity',
				'perplexity.ai' => 'perplexity',
			),
			'searchHosts' => self::SEARCH_HOSTS,
		);
	}
}
