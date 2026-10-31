<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\AiReferrers;
use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Analytics\PageViewStore;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Tests\Support\FakePageViewStore;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\PageViewCounter
 * @covers \Globetrotters\AiPresence\Analytics\AiReferrers
 */
final class PageViewCounterTest extends TestCase {

	private const HOME     = 'https://acme.example/';
	private const BROWSER  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
	private const ORIGIN   = 'https://acme.example';
	private const BODY_OK  = '{"p":"/visiter/marche"}';

	private FakePageViewStore $store;

	/**
	 * The stored schema version the counter sees.
	 */
	private int $db_version = Schema::VERSION;

	/**
	 * Options other than the schema version, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Every option name read, in call order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				$this->reads[] = $name;
				if ( Schema::VERSION_OPTION === $name ) {
					return $this->db_version;
				}
				return $this->options[ $name ] ?? $default;
			}
		);
		$this->options[ AiReferrers::OPTION ] = array(
			'version'    => 'v1',
			'fetched_at' => time() - DAY_IN_SECONDS,
			'hosts'      => array(
				'chatgpt.com' => 'chatgpt',
				'claude.ai'   => 'claude',
			),
			'utmSources' => array(
				'chatgpt.com' => 'chatgpt',
				'perplexity'  => 'perplexity',
			),
		);
		// Present, so a read of it would succeed and be recorded: no beacon
		// may ever load it.
		$this->options[ VendorRanges::OPTION ] = array(
			'version'    => 'v1',
			'fetched_at' => time(),
			'checked_at' => time(),
			'ranges'     => array(),
		);
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'home_url' )->justReturn( self::HOME );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		$this->store = new FakePageViewStore();
	}

	/**
	 * @dataProvider path_provider
	 *
	 * @param mixed $raw Submitted value.
	 */
	public function test_paths_are_validated_and_folded_like_the_backend( $raw, ?string $expected ): void {
		$this->assertSame( $expected, PageViewCounter::normalise_path( $raw ) );
	}

	/**
	 * @return array<string, array{0:mixed, 1:?string}>
	 */
	public static function path_provider(): array {
		return array(
			'plain'                => array( '/visiter/marche', '/visiter/marche' ),
			'root'                 => array( '/', '/' ),
			'trailing slash'       => array( '/visiter/', '/visiter' ),
			'several trailing'     => array( '/visiter///', '/visiter' ),
			'only slashes'         => array( '///', null ),
			'case kept'            => array( '/Visiter', '/Visiter' ),
			'percent-encoded'      => array( '/caf%C3%A9', '/caf%C3%A9' ),
			'query string'         => array( '/visiter?utm=x', null ),
			'fragment'             => array( '/visiter#top', null ),
			'relative'             => array( 'visiter', null ),
			'protocol-relative'    => array( '//evil.example/x', null ),
			'absolute URL'         => array( 'https://acme.example/x', null ),
			'empty'                => array( '', null ),
			'other literal'        => array( '(other)', null ),
			'control character'    => array( "/a\nb", null ),
			'NUL'                  => array( "/a\0b", null ),
			'at the cap'           => array( '/' . str_repeat( 'a', 511 ), '/' . str_repeat( 'a', 511 ) ),
			'over the cap'         => array( '/' . str_repeat( 'a', 512 ), null ),
			'not a string'         => array( array( '/x' ), null ),
			'number'               => array( 42, null ),
			'null'                 => array( null, null ),
		);
	}

	/**
	 * @dataProvider ua_provider
	 */
	public function test_user_agents_are_bucketed_coarsely( string $ua, ?string $expected ): void {
		$this->assertSame( $expected, PageViewCounter::bucket( $ua ) );
	}

	/**
	 * @return array<string, array{0:string, 1:?string}>
	 */
	public static function ua_provider(): array {
		return array(
			'safari'           => array( self::BROWSER, 'browser' ),
			'chrome'           => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'browser' ),
			'empty'            => array( '', null ),
			'whitespace'       => array( '   ', null ),
			'googlebot'        => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', null ),
			'gptbot'           => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)', null ),
			'crawler'          => array( 'SomeCrawler/1.0', null ),
			'spider'           => array( 'Baiduspider', null ),
			'slurp'            => array( 'Yahoo! Slurp', null ),
			'fetcher'          => array( 'Mozilla/5.0 (compatible; FeedFetcher-Google)', null ),
			'python'           => array( 'python-requests/2.32.3', null ),
			'curl'             => array( 'curl/8.7.1', null ),
			'wget'             => array( 'Wget/1.21', null ),
			'go'               => array( 'Go-http-client/2.0', null ),
			'headless chrome'  => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0.0.0 Safari/537.36', null ),
			'phantomjs'        => array( 'Mozilla/5.0 (Unknown; Linux x86_64) AppleWebKit/538.1 (KHTML, like Gecko) PhantomJS/2.1.1 Safari/538.1', null ),
			'lighthouse'       => array( 'Mozilla/5.0 (Linux; Android 11) Chrome-Lighthouse', null ),
			'link preview'     => array( 'Mozilla/5.0 (compatible; LinkPreview/1.0)', null ),
			'chatgpt agent'    => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 ChatGPT-User/2.0', 'ai_browser' ),
			'chatgpt atlas'    => array( 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 ChatGPT Atlas', 'ai_browser' ),
			'oai'              => array( 'Mozilla/5.0 Chrome/130.0.0.0 Safari/537.36 OAI-SearchAgent', 'ai_browser' ),
			'comet'            => array( 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 (KHTML, like Gecko) Comet/139.0 Chrome/139.0.0.0 Safari/537.36', 'ai_browser' ),
			'perplexity'       => array( 'Mozilla/5.0 Chrome/130.0.0.0 Safari/537.36 Perplexity-User/1.0', 'ai_browser' ),
			'claude'           => array( 'Mozilla/5.0 Chrome/130.0.0.0 Safari/537.36 Claude-User', 'ai_browser' ),
			// The bot marker wins: a self-declared bot is not counted even if it
			// also names an AI vendor.
			'claudebot'        => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', null ),
			'perplexitybot'    => array( 'Mozilla/5.0 (compatible; PerplexityBot/1.0)', null ),
			// A phone brand whose model names contain "bot" is a person, not a
			// crawler; the real crawlers around it still are.
			'cubot phone'      => array( 'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.230 Mobile Safari/537.36', 'browser' ),
			'cubot lowercase'  => array( 'Mozilla/5.0 (Linux; Android 11; cubot_note_20) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0 Mobile Safari/537.36', 'browser' ),
			'bingbot'          => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36', null ),
		);
	}

	/**
	 * @dataProvider origin_provider
	 */
	public function test_only_a_same_origin_request_is_accepted( string $origin, string $referer, string $fetch_site, bool $expected ): void {
		$this->assertSame( $expected, PageViewCounter::is_same_origin( $origin, $referer, self::HOME, $fetch_site ) );
	}

	/**
	 * Precedence: a real ``Origin`` host decides; else ``Sec-Fetch-Site``
	 * (``same-origin`` accepts, anything else refuses); else the ``Referer``
	 * host. ``Origin: null`` counts as absent — it is what a
	 * ``Referrer-Policy: no-referrer`` page sends on a beacon POST.
	 *
	 * @return array<string, array{0:string, 1:string, 2:string, 3:bool}>
	 */
	public static function origin_provider(): array {
		return array(
			'same origin'                        => array( 'https://acme.example', '', '', true ),
			'same host, any case'                => array( 'https://ACME.example', '', '', true ),
			'other host'                         => array( 'https://evil.example', '', '', false ),
			'suffix trick'                       => array( 'https://acme.example.evil.example', '', '', false ),
			'subdomain'                          => array( 'https://www.acme.example', '', '', false ),
			'origin wins over referer'           => array( 'https://evil.example', 'https://acme.example/page', '', false ),
			'origin wins over sec-fetch-site'    => array( 'https://evil.example', '', 'same-origin', false ),
			'own origin wins over sec-fetch-site' => array( 'https://acme.example', '', 'cross-site', true ),
			'garbage origin'                     => array( 'not a url', '', 'same-origin', false ),
			'opaque origin, nothing else'        => array( 'null', '', '', false ),
			'no-referrer page: sec-fetch-site'   => array( 'null', '', 'same-origin', true ),
			'no-referrer page, any case'         => array( 'NULL', '', 'Same-Origin', true ),
			'sec-fetch-site same-site'           => array( 'null', '', 'same-site', false ),
			'sec-fetch-site cross-site'          => array( '', '', 'cross-site', false ),
			'sec-fetch-site none'                => array( '', '', 'none', false ),
			'sec-fetch-site wins over referer'   => array( '', 'https://acme.example/visiter', 'cross-site', false ),
			'opaque origin falls to referer'     => array( 'null', 'https://acme.example/visiter', '', true ),
			'referer when no origin'             => array( '', 'https://acme.example/visiter', '', true ),
			'foreign referer'                    => array( '', 'https://evil.example/acme.example', '', false ),
			'neither'                            => array( '', '', '', false ),
		);
	}

	public function test_a_beacon_from_a_no_referrer_page_is_counted(): void {
		$this->assertTrue( $this->counter()->handle( 'null', '', self::BROWSER, self::BODY_OK, 'same-origin' ) );

		$this->assertCount( 1, $this->store->writes );
	}

	public function test_page_views_are_inert_until_the_counter_table_is_installed(): void {
		// Schema version 1 has the event buffer but not the counter table: an
		// install mid-upgrade, or one whose CREATE failed for this table only.
		$this->db_version = 1;

		$this->assertFalse( $this->counter()->handle( self::ORIGIN, '', self::BROWSER, self::BODY_OK ) );
		$this->assertSame( array(), $this->store->writes );
	}

	/**
	 * @dataProvider body_provider
	 */
	public function test_the_body_must_be_a_small_json_object_with_a_path( string $body, ?string $expected ): void {
		$this->assertSame( $expected, PageViewCounter::path_from_body( $body ) );
	}

	/**
	 * @return array<string, array{0:string, 1:?string}>
	 */
	public static function body_provider(): array {
		return array(
			'valid'          => array( self::BODY_OK, '/visiter/marche' ),
			'folded'         => array( '{"p":"/visiter/"}', '/visiter' ),
			'empty'          => array( '', null ),
			'not json'       => array( 'p=/visiter', null ),
			'list'           => array( '["/visiter"]', null ),
			'missing p'      => array( '{"path":"/visiter"}', null ),
			'p not a string' => array( '{"p":["/visiter"]}', null ),
			'invalid path'   => array( '{"p":"/visiter?email=a@b.c"}', null ),
			'over 1 KB'      => array( '{"p":"/' . str_repeat( 'a', 1100 ) . '"}', null ),
		);
	}

	public function test_a_valid_beacon_increments_todays_counter(): void {
		$this->assertTrue( $this->counter()->handle( self::ORIGIN, '', self::BROWSER, self::BODY_OK ) );

		$this->assertSame(
			array( array( 'increment', gmdate( 'Y-m-d' ), '/visiter/marche', 'browser' ) ),
			$this->store->writes
		);
	}

	public function test_an_ai_browser_is_counted_in_its_own_bucket(): void {
		$this->counter()->handle( self::ORIGIN, '', 'Mozilla/5.0 Chrome/141 Safari/537.36 ChatGPT Atlas', self::BODY_OK );

		$this->assertSame( 'ai_browser', $this->store->writes[0][3] );
	}

	public function test_a_signed_beacon_with_a_stock_browser_ua_counts_as_an_ai_browser(): void {
		$this->assertTrue( $this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"chatgpt.com"}', '', '"https://www.meta.com"' ) );

		$this->assertSame(
			array( array( 'increment', gmdate( 'Y-m-d' ), '/x', 'ai_browser' ) ),
			$this->store->writes,
			'ai_browser, and so no referral platform.'
		);
	}

	public function test_the_signature_agent_only_moves_a_counted_browser_view(): void {
		$this->assertSame( 'ai_browser', PageViewCounter::bucket( self::BROWSER, '"https://www.meta.com"' ) );
		$this->assertSame( 'browser', PageViewCounter::bucket( self::BROWSER, '   ' ), 'An empty header is no signature.' );
		$this->assertNull( PageViewCounter::bucket( 'Meta-WebIndexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/web-crawlers) bot', '"https://www.meta.com"' ), 'A bot stays uncounted.' );
		$this->assertNull( PageViewCounter::bucket( '', '"https://www.meta.com"' ), 'So does an empty UA.' );
	}

	/**
	 * @dataProvider rejected_provider
	 */
	public function test_a_rejected_beacon_writes_nothing( string $origin, string $referer, string $ua, string $body ): void {
		$this->assertFalse( $this->counter()->handle( $origin, $referer, $ua, $body ) );

		$this->assertSame( array(), $this->store->writes );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string, 3:string}>
	 */
	public static function rejected_provider(): array {
		return array(
			'cross-origin'  => array( 'https://evil.example', '', self::BROWSER, self::BODY_OK ),
			'no origin'     => array( '', '', self::BROWSER, self::BODY_OK ),
			'bot UA'        => array( self::ORIGIN, '', 'Googlebot/2.1', self::BODY_OK ),
			'curl'          => array( self::ORIGIN, '', 'curl/8.7.1', self::BODY_OK ),
			'empty UA'      => array( self::ORIGIN, '', '', self::BODY_OK ),
			'bad body'      => array( self::ORIGIN, '', self::BROWSER, 'nope' ),
			'query in path' => array( self::ORIGIN, '', self::BROWSER, '{"p":"/x?y=1"}' ),
		);
	}

	public function test_nothing_but_day_path_and_bucket_is_persisted(): void {
		// The UA string is read to bucket the hit and then forgotten: no
		// argument of any write carries it, or any part of the request besides
		// the path.
		$ua = 'Mozilla/5.0 (X11; Linux x86_64) UniqueMarker/9.9 Firefox/131.0';

		$this->counter()->handle( self::ORIGIN, 'https://acme.example/from', $ua, self::BODY_OK );

		$this->assertCount( 1, $this->store->writes );
		foreach ( $this->store->writes as $write ) {
			foreach ( $write as $argument ) {
				$this->assertStringNotContainsString( 'UniqueMarker', (string) $argument );
				$this->assertStringNotContainsString( 'Mozilla', (string) $argument );
				$this->assertStringNotContainsString( '/from', (string) $argument );
			}
		}
		$row = array_values( $this->store->rows )[0];
		$this->assertSame( array( 'id', 'day', 'path', 'bucket', 'views', 'record_id' ), array_keys( $row ) );
	}

	public function test_past_the_daily_path_cap_new_paths_count_under_other(): void {
		$day = gmdate( 'Y-m-d' );
		for ( $i = 0; $i < PageViewStore::MAX_PATHS_PER_DAY; $i++ ) {
			$this->store->seed( $day, '/p' . $i );
		}
		$counter = $this->counter();

		$counter->count( '/one-too-many', 'browser' );
		$counter->count( '/and-another', 'ai_browser' );
		$counter->count( '/p7', 'browser' );

		$paths = array_column( $this->store->rows, 'path' );
		$this->assertNotContains( '/one-too-many', $paths );
		$this->assertNotContains( '/and-another', $paths );
		$this->assertSame( PageViewStore::MAX_PATHS_PER_DAY, $this->store->distinct_paths( $day ) );
		$other = array_values(
			array_filter( $this->store->rows, fn( $row ) => PageViewStore::OTHER_PATH === $row['path'] )
		);
		$this->assertCount( 2, $other, 'One (other) row per bucket.' );
		$this->assertSame( 1, $other[0]['views'] );
		// A path already counted today keeps counting under its own name.
		$p7 = array_values( array_filter( $this->store->rows, fn( $row ) => '/p7' === $row['path'] ) );
		$this->assertSame( 2, $p7[0]['views'] );
	}

	public function test_a_concurrent_view_on_a_new_path_past_the_cap_is_never_lost(): void {
		$day = gmdate( 'Y-m-d' );
		for ( $i = 0; $i < PageViewStore::MAX_PATHS_PER_DAY; $i++ ) {
			$this->store->seed( $day, '/p' . $i );
		}
		// Another beacon for the same new path lands between this one's insert
		// and its cap check.
		$this->store->on_distinct_paths = function ( FakePageViewStore $store ) use ( $day ): void {
			$store->on_distinct_paths = null;
			$store->increment( $day, '/racing', 'browser' );
		};

		$this->counter()->count( '/racing', 'browser' );

		$views = array();
		foreach ( $this->store->rows as $row ) {
			$views[ $row['path'] ] = ( $views[ $row['path'] ] ?? 0 ) + $row['views'];
		}
		// Both views survive: the row is kept (one path over the cap) rather
		// than deleted with the concurrent view inside it.
		$this->assertSame( 2, ( $views['/racing'] ?? 0 ) + ( $views[ PageViewStore::OTHER_PATH ] ?? 0 ) );
	}

	public function test_a_path_seen_in_another_bucket_is_not_new_for_the_cap(): void {
		$day = gmdate( 'Y-m-d' );
		for ( $i = 0; $i < PageViewStore::MAX_PATHS_PER_DAY; $i++ ) {
			$this->store->seed( $day, '/p' . $i );
		}

		$this->counter()->count( '/p3', 'ai_browser' );

		$this->assertContains( '/p3', array_column( array_filter( $this->store->rows, fn( $row ) => 'ai_browser' === $row['bucket'] ), 'path' ) );
	}

	public function test_the_cap_is_per_day(): void {
		$yesterday = gmdate( 'Y-m-d', time() - 86400 );
		for ( $i = 0; $i < PageViewStore::MAX_PATHS_PER_DAY; $i++ ) {
			$this->store->seed( $yesterday, '/p' . $i );
		}

		$this->counter()->count( '/fresh', 'browser' );

		$this->assertContains( '/fresh', array_column( $this->store->rows, 'path' ) );
	}

	public function test_without_a_persistent_object_cache_nothing_is_rate_limited(): void {
		Functions\expect( 'wp_cache_incr' )->never();
		$counter = $this->counter();

		for ( $i = 0; $i < 200; $i++ ) {
			$counter->count( '/hot', 'browser' );
		}

		$this->assertSame( 200, array_values( $this->store->rows )[0]['views'] );
	}

	public function test_with_a_persistent_object_cache_a_path_is_capped_per_minute(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		$cache = array();
		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $value, $group ) use ( &$cache ) {
				if ( isset( $cache[ $group . $key ] ) ) {
					return false;
				}
				$cache[ $group . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_incr' )->alias(
			function ( $key, $offset, $group ) use ( &$cache ) {
				$cache[ $group . $key ] += $offset;
				return $cache[ $group . $key ];
			}
		);
		$counter = $this->counter();

		for ( $i = 0; $i < PageViewCounter::MAX_PER_PATH_PER_MINUTE + 30; $i++ ) {
			$counter->count( '/hot', 'browser' );
		}
		$counter->count( '/cold', 'browser' );

		$views = array_column( $this->store->rows, 'views', 'path' );
		$this->assertSame( PageViewCounter::MAX_PER_PATH_PER_MINUTE, $views['/hot'] );
		$this->assertSame( 1, $views['/cold'], 'The cap is per path, not site-wide.' );
		// Keyed on the path alone: nothing about the visitor reaches the key.
		foreach ( array_keys( $cache ) as $key ) {
			$this->assertStringStartsWith( 'gtaip_pv', $key );
		}
	}

	public function test_a_plain_view_keeps_the_0_8_key(): void {
		$this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x"}' );

		$this->assertSame( array( array( 'increment', gmdate( 'Y-m-d' ), '/x', 'browser' ) ), $this->store->writes );
	}

	/**
	 * @dataProvider referral_provider
	 */
	public function test_a_view_from_an_ai_answer_counts_under_its_platform( string $body, string $expected ): void {
		$this->assertTrue( $this->counter()->handle( self::ORIGIN, '', self::BROWSER, $body ) );

		$this->assertSame( array( array( 'increment', gmdate( 'Y-m-d' ), '/x', $expected ) ), $this->store->writes );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function referral_provider(): array {
		return array(
			'referrer host'           => array( '{"p":"/x","r":"chatgpt.com"}', 'browser@chatgpt' ),
			'referrer host, port'     => array( '{"p":"/x","r":"Claude.AI:443"}', 'browser@claude' ),
			'utm_source'              => array( '{"p":"/x","u":"perplexity"}', 'browser@perplexity' ),
			'host wins'               => array( '{"p":"/x","r":"claude.ai","u":"chatgpt.com"}', 'browser@claude' ),
			'unknown host'            => array( '{"p":"/x","r":"www.google.com"}', 'browser' ),
			'unknown utm_source'      => array( '{"p":"/x","u":"newsletter"}', 'browser' ),
			'r not a string'          => array( '{"p":"/x","r":["chatgpt.com"]}', 'browser' ),
			'u not a string'          => array( '{"p":"/x","u":{"v":"perplexity"}}', 'browser' ),
			'r a number, u valid'     => array( '{"p":"/x","r":7,"u":"perplexity"}', 'browser@perplexity' ),
			'empty hints'             => array( '{"p":"/x","r":"","u":""}', 'browser' ),
		);
	}

	/**
	 * @dataProvider undecodable_hint_provider
	 */
	public function test_an_undecodable_hint_never_loses_the_view( string $body ): void {
		// ``JSON.stringify`` escapes a lone UTF-16 surrogate (``"\ud83d"``),
		// which PHP's decoder refuses. The hint is useless, the view is not.
		$this->assertTrue( $this->counter()->handle( self::ORIGIN, '', self::BROWSER, $body ) );

		$this->assertSame( array( array( 'increment', gmdate( 'Y-m-d' ), '/x', 'browser' ) ), $this->store->writes );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function undecodable_hint_provider(): array {
		return array(
			'lone high surrogate'       => array( '{"p":"/x","u":"\ud83d"}' ),
			'lone low surrogate'        => array( '{"p":"/x","u":"a\ude00b"}' ),
			'high before a non-low'     => array( '{"p":"/x","u":"\ud83dA"}' ),
			'lone surrogate in r and u' => array( '{"p":"/x","r":"\udfff","u":"\ud800"}' ),
		);
	}

	public function test_a_paired_surrogate_still_decodes_as_is(): void {
		$this->assertSame( '/x', PageViewCounter::path_from_body( '{"p":"/x","u":"😀"}' ) );
	}

	public function test_a_lone_surrogate_in_the_path_is_still_refused(): void {
		// Only the hints are repaired; the path is persisted, so it stays
		// held to the backend's rules and to valid UTF-8.
		$this->assertNull( PageViewCounter::path_from_body( '{"p":"/x\ud83d"}' ) );
	}

	public function test_an_ai_browser_view_never_takes_a_platform_or_reads_the_list(): void {
		$this->reads = array();

		$this->counter()->handle( self::ORIGIN, '', 'Mozilla/5.0 Chrome/141 Safari/537.36 ChatGPT Atlas', '{"p":"/x","r":"chatgpt.com","u":"chatgpt.com"}' );

		$this->assertSame( 'ai_browser', $this->store->writes[0][3] );
		$this->assertNotContains( AiReferrers::OPTION, $this->reads );
	}

	public function test_a_beacon_without_hints_never_opens_the_ai_list(): void {
		// AC12, first half.
		$this->reads = array();

		$this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x"}' );

		$this->assertCount( 1, $this->store->writes );
		$this->assertNotContains( AiReferrers::OPTION, $this->reads );
	}

	/**
	 * @dataProvider any_beacon_provider
	 */
	public function test_no_beacon_ever_opens_the_vendor_ranges( string $ua, string $body ): void {
		// AC12, second half: matching a referral never loads the IP list.
		$this->reads = array();

		$this->counter()->handle( self::ORIGIN, '', $ua, $body );

		$this->assertNotContains( VendorRanges::OPTION, $this->reads );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function any_beacon_provider(): array {
		return array(
			'plain'          => array( self::BROWSER, '{"p":"/x"}' ),
			'referred'       => array( self::BROWSER, '{"p":"/x","r":"chatgpt.com","u":"perplexity"}' ),
			'unmatched'      => array( self::BROWSER, '{"p":"/x","r":"www.google.com"}' ),
			'ai browser'     => array( 'Mozilla/5.0 Chrome/130 Safari/537.36 Claude-User', '{"p":"/x","r":"claude.ai"}' ),
			'bot (rejected)' => array( 'Googlebot/2.1', '{"p":"/x","r":"chatgpt.com"}' ),
		);
	}

	public function test_no_counter_row_keeps_the_referrer_or_the_utm_source(): void {
		// AC5: only the platform is kept. Neither the referrer host, the
		// utm_source value nor the parameter's name reaches any write, open or
		// sealed.
		$counter = $this->counter();
		$counter->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"chatgpt.com"}' );
		$counter->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","u":"chatgpt.com"}' );
		$counter->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"www.google.com","u":"utm_source"}' );
		$counter->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x"}' );
		$this->store->seal( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 100 );

		$this->assertCount( 4, $this->store->writes );
		$persisted = (string) json_encode( array( $this->store->writes, $this->store->rows, $this->store->sealed( 100 ) ) );
		foreach ( array( 'chatgpt.com', 'utm_source', 'google' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $persisted );
		}
		$this->assertSame(
			array(
				'browser@chatgpt' => 2,
				'browser'         => 2,
			),
			array_column( $this->store->rows, 'views', 'bucket' )
		);
	}

	public function test_a_missing_ai_list_counts_a_chatgpt_click_as_none(): void {
		// AC7.
		unset( $this->options[ AiReferrers::OPTION ] );

		$this->assertTrue( $this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"chatgpt.com"}' ) );

		$this->assertSame( 'browser', $this->store->writes[0][3] );
	}

	public function test_a_stale_ai_list_counts_a_chatgpt_click_as_none(): void {
		$this->options[ AiReferrers::OPTION ]['fetched_at'] = time() - AiReferrers::STALE_AFTER_SECONDS - 60;

		$this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"chatgpt.com"}' );

		$this->assertSame( 'browser', $this->store->writes[0][3] );
	}

	public function test_a_counter_without_a_list_never_matches(): void {
		( new PageViewCounter( $this->store ) )->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"chatgpt.com"}' );

		$this->assertSame( 'browser', $this->store->writes[0][3] );
	}

	public function test_platforms_wait_for_the_widened_bucket_column(): void {
		// Schema 3's bucket column is 16 characters: ``browser@perplexity``
		// would be refused or truncated there, so until the upgrade lands a
		// referred view still counts, as ``none``.
		$this->db_version = 3;

		$this->assertTrue( $this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"chatgpt.com"}' ) );

		$this->assertSame( 'browser', $this->store->writes[0][3] );
	}

	public function test_a_direct_count_with_a_platform_also_waits_for_the_schema(): void {
		$this->db_version = 3;

		$this->counter()->count( '/x', 'browser', 'perplexity' );

		$this->assertSame( 'browser', $this->store->writes[0][3] );
	}

	public function test_an_ai_browser_count_never_takes_a_platform(): void {
		$this->counter()->count( '/x', 'ai_browser', 'chatgpt' );

		$this->assertSame( 'ai_browser', $this->store->writes[0][3] );
	}

	public function test_a_0_8_open_day_keeps_counting_after_the_upgrade(): void {
		$day = gmdate( 'Y-m-d' );
		$this->store->seed( $day, '/x', 'browser', 5 );

		$this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x"}' );
		$this->counter()->handle( self::ORIGIN, '', self::BROWSER, '{"p":"/x","r":"chatgpt.com"}' );

		$this->assertSame(
			array(
				'browser'         => 6,
				'browser@chatgpt' => 1,
			),
			array_column( $this->store->rows, 'views', 'bucket' )
		);
	}

	public function test_past_the_cap_a_path_folds_into_other_with_its_platform(): void {
		$day = gmdate( 'Y-m-d' );
		for ( $i = 0; $i < PageViewStore::MAX_PATHS_PER_DAY; $i++ ) {
			$this->store->seed( $day, '/p' . $i );
		}
		$counter = $this->counter();

		$counter->count( '/new', 'browser', 'chatgpt' );
		$counter->count( '/new', 'browser' );
		// A path already counted today is not new: its first referred view
		// adds a platform row beside it rather than folding.
		$counter->count( '/p7', 'browser', 'claude' );

		$this->assertNotContains( '/new', array_column( $this->store->rows, 'path' ) );
		$other = array();
		foreach ( $this->store->rows as $row ) {
			if ( PageViewStore::OTHER_PATH === $row['path'] ) {
				$other[ $row['bucket'] ] = $row['views'];
			}
		}
		$this->assertSame(
			array(
				'browser@chatgpt' => 1,
				'browser'         => 1,
			),
			$other
		);
		$p7 = array_values( array_filter( $this->store->rows, fn( $row ) => '/p7' === $row['path'] && 'browser@claude' === $row['bucket'] ) );
		$this->assertSame( 1, $p7[0]['views'] );
		$this->assertSame( PageViewStore::MAX_PATHS_PER_DAY, $this->store->distinct_paths( $day ) );
	}

	/**
	 * @dataProvider stored_bucket_provider
	 */
	public function test_the_stored_bucket_carries_the_platform_only_when_matched( string $bucket, string $platform, string $stored ): void {
		$this->assertSame( $stored, PageViewCounter::stored_bucket( $bucket, $platform ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function stored_bucket_provider(): array {
		return array(
			'none'             => array( 'browser', 'none', 'browser' ),
			'matched'          => array( 'browser', 'perplexity', 'browser@perplexity' ),
			'ai browser'       => array( 'ai_browser', 'none', 'ai_browser' ),
			'invalid platform' => array( 'browser', 'Not Valid', 'browser' ),
			'empty platform'   => array( 'browser', '', 'browser' ),
		);
	}

	/**
	 * @dataProvider split_provider
	 *
	 * @param array{0:string, 1:string} $expected Bucket and platform.
	 */
	public function test_a_stored_bucket_splits_back_into_bucket_and_platform( string $stored, array $expected ): void {
		$this->assertSame( $expected, PageViewCounter::split_stored_bucket( $stored ) );
	}

	/**
	 * @return array<string, array{0:string, 1:array{0:string, 1:string}}>
	 */
	public static function split_provider(): array {
		return array(
			'0.8.x row'        => array( 'browser', array( 'browser', 'none' ) ),
			'0.8.x ai browser' => array( 'ai_browser', array( 'ai_browser', 'none' ) ),
			'matched'          => array( 'browser@chatgpt', array( 'browser', 'chatgpt' ) ),
			'invalid platform' => array( 'browser@Not Valid', array( 'browser', 'none' ) ),
			'empty platform'   => array( 'browser@', array( 'browser', 'none' ) ),
		);
	}

	private function counter(): PageViewCounter {
		return new PageViewCounter( $this->store, new AiReferrers() );
	}
}
