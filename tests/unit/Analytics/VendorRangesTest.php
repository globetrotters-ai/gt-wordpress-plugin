<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\AiReferrers;
use Globetrotters\AiPresence\Analytics\AiUserAgents;
use Globetrotters\AiPresence\Analytics\PageCacheExclusion;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;
use WP_Error;

/**
 * @covers \Globetrotters\AiPresence\Analytics\VendorRanges
 * @covers \Globetrotters\AiPresence\Analytics\IpRange
 * @covers \Globetrotters\AiPresence\Analytics\AiReferrers
 * @covers \Globetrotters\AiPresence\Analytics\AiUserAgents
 */
final class VendorRangesTest extends TestCase {

	use WpOptions;

	private const ENDPOINT = 'https://api.globetrotters.ai/presence/analytics/server-log';

	/**
	 * Requests made through wp_remote_get.
	 *
	 * @var array<int, array{url:string, args:array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * The canned response: code, body, or a WP_Error.
	 *
	 * @var array{code:int, body:string}|WP_Error
	 */
	private $response;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => self::ENDPOINT,
			'ingest_token'    => 'tok_live_secret',
		);
		$this->response = array(
			'code' => 200,
			'body' => self::body( 'v1' ),
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
	}

	public function test_fetches_the_ranges_url_with_the_bearer_token(): void {
		$this->assertTrue( $this->ranges()->refresh() );

		$this->assertSame( self::ENDPOINT . '/ranges', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer tok_live_secret', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
		$this->assertSame( 'v1', $this->ranges()->version() );
	}

	public function test_sends_the_stored_version_as_an_etag_and_a_304_refreshes_the_age(): void {
		$this->seed( 'v1', time() - 10 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 10 * DAY_IN_SECONDS );
		$this->response = array(
			'code' => 304,
			'body' => '',
		);

		$this->assertTrue( $this->ranges()->refresh() );

		$this->assertSame( '"v1"', $this->requests[0]['args']['headers']['If-None-Match'] );
		$this->assertSame( 'v1', $this->ranges()->version() );
		$this->assertGreaterThan( time() - 5, $this->ranges()->fetched_at() );
	}

	public function test_one_fetch_stores_the_ranges_and_the_ai_referrer_list_apart(): void {
		$this->assertTrue( $this->ranges()->refresh() );

		$this->assertSame( 'v1', $this->ranges()->version() );
		$this->assertArrayNotHasKey( 'aiReferrers', $this->store[ VendorRanges::OPTION ], 'The ranges option carries only the ranges.' );
		$referrers = $this->store[ AiReferrers::OPTION ];
		$this->assertSame( 'v1', $referrers['version'] );
		$this->assertGreaterThan( time() - 5, $referrers['fetched_at'] );
		$this->assertSame( array( 'chatgpt.com' => 'chatgpt', 'claude.ai' => 'claude' ), $referrers['hosts'] );
		$this->assertSame( array( 'perplexity' => 'perplexity' ), $referrers['utmSources'] );
		$this->assertSame( array( 'google.*', 'www.bing.com' ), $referrers['searchHosts'] );
		$this->assertSame( 'chatgpt', ( new AiReferrers() )->match( 'chatgpt.com', '' ) );
		$this->assertSame( 'none', ( new AiReferrers() )->match( 'www.bing.com', 'perplexity' ) );
	}

	public function test_no_etag_while_the_ai_referrer_list_predates_search_hosts(): void {
		// The 0.10.0 -> 0.11.0 upgrade: the list was stored at the current
		// version by a release that dropped ``searchHosts``. A 304 would keep
		// it without the key until the backend's version next moves.
		$this->seed( 'v1', time() - 2 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 2 * DAY_IN_SECONDS );
		unset( $this->store[ AiReferrers::OPTION ]['searchHosts'] );

		$this->assertTrue( $this->ranges()->refresh() );

		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
		$this->assertSame( array( 'google.*', 'www.bing.com' ), $this->store[ AiReferrers::OPTION ]['searchHosts'] );
	}

	public function test_a_304_restamps_the_ai_referrer_list_with_the_ranges(): void {
		$this->seed( 'v1', time() - 13 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 13 * DAY_IN_SECONDS );
		$this->response = array(
			'code' => 304,
			'body' => '',
		);

		$this->assertTrue( $this->ranges()->refresh() );

		$this->assertGreaterThan( time() - 5, $this->store[ AiReferrers::OPTION ]['fetched_at'] );
		$this->assertSame( array( 'chatgpt.com' => 'chatgpt' ), $this->store[ AiReferrers::OPTION ]['hosts'] );
	}

	public function test_no_etag_while_the_ai_referrer_list_is_missing(): void {
		// The 0.8.x -> 0.9.0 upgrade: the ranges are cached and current, but
		// the list has never been stored. A 304 would leave it missing until
		// the backend's version next moves, so the sync asks for the body.
		$this->seed( 'v1', time() - 2 * DAY_IN_SECONDS );

		$this->assertTrue( $this->ranges()->refresh() );

		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
		$this->assertSame( 'chatgpt', ( new AiReferrers() )->match( 'chatgpt.com', '' ) );
	}

	public function test_no_etag_while_the_two_lists_disagree_on_the_version(): void {
		$this->seed( 'v1', time() - 2 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v0', time() - 2 * DAY_IN_SECONDS );

		$this->ranges()->refresh();

		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
		$this->assertSame( 'v1', $this->store[ AiReferrers::OPTION ]['version'] );
	}

	public function test_a_200_without_ai_referrers_stores_empty_lists(): void {
		// A backend from before the list existed: the referral match degrades
		// to ``none``, and the version still moves with the ranges'.
		$this->seed_referrers( 'v0', time() );
		$this->response = array(
			'code' => 200,
			'body' => self::body( 'v2', false ),
		);

		$this->assertTrue( $this->ranges()->refresh() );

		$referrers = $this->store[ AiReferrers::OPTION ];
		$this->assertSame( 'v2', $referrers['version'] );
		$this->assertSame( array(), $referrers['hosts'] );
		$this->assertSame( array(), $referrers['utmSources'] );
		$this->assertSame( array(), $referrers['searchHosts'] );
		$this->assertSame( 'none', ( new AiReferrers() )->match( 'chatgpt.com', '' ) );
	}

	public function test_a_failure_keeps_the_previous_ai_referrer_list(): void {
		$fetched = time() - 2 * DAY_IN_SECONDS;
		$this->seed( 'v1', $fetched );
		$this->seed_referrers( 'v1', $fetched );
		$before         = $this->store[ AiReferrers::OPTION ];
		$this->response = array(
			'code' => 500,
			'body' => 'oops',
		);

		$this->ranges()->refresh();

		$this->assertSame( $before, $this->store[ AiReferrers::OPTION ] );
	}

	/**
	 * @dataProvider failures
	 *
	 * @param array{code:int, body:string}|WP_Error $response Canned failure.
	 */
	public function test_a_failure_keeps_the_previous_list( $response ): void {
		$fetched = time() - 2 * DAY_IN_SECONDS;
		$this->seed( 'v1', $fetched );
		$this->response = $response;

		$this->assertFalse( $this->ranges()->refresh() );

		$this->assertSame( 'v1', $this->ranges()->version() );
		$this->assertSame( $fetched, $this->ranges()->fetched_at() );
		$this->assertSame( array( true, 'chatgpt' ), $this->verdict( '20.171.206.7' ) );
		$this->assertFalse( $this->ranges()->is_due(), 'A failed attempt still counts as the day\'s attempt.' );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function failures(): array {
		return array(
			'transport error' => array( new WP_Error( 'http_request_failed', 'timeout' ) ),
			'401'             => array(
				array(
					'code' => 401,
					'body' => '',
				),
			),
			'500'             => array(
				array(
					'code' => 500,
					'body' => 'oops',
				),
			),
			'garbage 200'     => array(
				array(
					'code' => 200,
					'body' => '<html>',
				),
			),
		);
	}

	public function test_fetches_at_most_once_a_day(): void {
		$ranges = $this->ranges();
		$ranges->refresh_if_due();
		$ranges->refresh_if_due();

		$this->assertCount( 1, $this->requests );
	}

	public function test_does_not_fetch_without_reporting_credentials(): void {
		$this->store[ Options::SETTINGS ] = array();

		$this->ranges()->refresh_if_due();

		$this->assertSame( array(), $this->requests );
	}

	public function test_verified_true_with_vendor_inside_an_ipv4_range(): void {
		$this->seed( 'v1', time() );
		$this->assertSame( array( true, 'chatgpt' ), $this->verdict( '20.171.206.0' ) );
		$this->assertSame( array( true, 'chatgpt' ), $this->verdict( '20.171.206.255' ) );
	}

	public function test_verified_true_inside_an_ipv6_range(): void {
		$this->seed( 'v1', time() );
		$this->assertSame( array( true, 'claude' ), $this->verdict( '2600:1f28:365:80b0::1' ) );
	}

	public function test_verified_false_outside_every_range_on_a_fresh_list(): void {
		$this->seed( 'v1', time() );
		$this->assertSame( array( false, null ), $this->verdict( '20.171.207.0' ) );
		$this->assertSame( array( false, null ), $this->verdict( '2600:1f28:365:80b1::1' ) );
		$this->assertSame( array( false, null ), $this->verdict( '' ), 'No resolvable ip is still not a vendor.' );
	}

	public function test_an_ipv4_mapped_ipv6_peer_matches_its_ipv4_range(): void {
		$this->seed( 'v1', time() );
		$this->assertSame( array( true, 'chatgpt' ), $this->verdict( '::ffff:20.171.206.10' ) );
		$this->assertSame( array( false, null ), $this->verdict( '::ffff:20.171.207.1' ) );
	}

	public function test_an_ipv4_address_never_matches_an_ipv6_span(): void {
		$this->seed( 'v1', time() );
		$this->assertSame( array( false, null ), $this->verdict( '0.0.0.1' ) );
	}

	public function test_verified_null_when_the_list_is_missing_or_stale(): void {
		$this->assertSame( array( null, null ), $this->verdict( '20.171.206.7' ), 'missing' );

		$this->seed( 'v1', time() - 15 * DAY_IN_SECONDS );
		$this->assertSame( array( null, null ), $this->verdict( '20.171.206.7' ), 'stale' );

		$this->seed( 'v1', time() - 13 * DAY_IN_SECONDS );
		$this->assertSame( array( true, 'chatgpt' ), $this->verdict( '20.171.206.7' ), '13 days is still fresh' );
	}

	public function test_invalid_entries_are_skipped(): void {
		$parsed = VendorRanges::parse(
			(string) json_encode(
				array(
					'version' => 'abc123',
					'ranges'  => array(
						array(
							'platform' => 'x',
							'start'    => 'nope',
							'end'      => '1.1.1.1',
						),
						array(
							'platform' => 'x',
							'start'    => '1.1.1.9',
							'end'      => '1.1.1.1',
						),
						array(
							'platform' => 'x',
							'start'    => '1.1.1.1',
							'end'      => '::1',
						),
						array(
							'platform' => 'Perplexity',
							'start'    => '1.1.1.1',
							'end'      => '1.1.1.2',
						),
					),
				)
			)
		);

		$this->assertNotNull( $parsed );
		$this->assertCount( 1, $parsed['ranges'] );
		$this->assertSame( 'perplexity', $parsed['ranges'][0]['platform'] );
	}

	public function test_neither_option_is_autoloaded(): void {
		$autoload = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $auto = null ) use ( &$autoload ) {
				$this->store[ $name ] = $value;
				$autoload[ $name ]    = $auto;
				return true;
			}
		);

		$this->ranges()->refresh();

		$this->assertSame(
			array(
				VendorRanges::OPTION => false,
				AiReferrers::OPTION  => false,
			),
			$autoload
		);
	}

	public function test_one_fetch_stores_the_cache_exclusion_list_beside_the_others(): void {
		$exclusion = new PageCacheExclusion( new Options() );

		$this->assertTrue( $this->ranges( $exclusion )->refresh() );

		$this->assertSame( 'v1', $exclusion->version() );
		$this->assertSame( array( 'OAI-SearchBot', 'ChatGPT-User' ), $exclusion->user_agents() );
		$this->assertArrayNotHasKey( 'cacheExclusionUserAgents', $this->store[ VendorRanges::OPTION ] );
	}

	public function test_no_etag_while_the_cache_exclusion_list_is_missing(): void {
		// The 0.9.x -> 0.10.0 upgrade: ranges and referrers current, no list.
		$this->seed( 'v1', time() - 2 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 2 * DAY_IN_SECONDS );
		$exclusion = new PageCacheExclusion( new Options() );

		$this->ranges( $exclusion )->refresh();

		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
		$this->assertSame( array( 'OAI-SearchBot', 'ChatGPT-User' ), $exclusion->user_agents() );
	}

	public function test_a_304_restamps_the_cache_exclusion_list(): void {
		$this->seed( 'v1', time() - 13 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 13 * DAY_IN_SECONDS );
		$exclusion = new PageCacheExclusion( new Options() );
		$exclusion->replace( 'v1', array( 'ChatGPT-User' ), time() - 13 * DAY_IN_SECONDS );
		$this->response = array(
			'code' => 304,
			'body' => '',
		);

		$this->assertTrue( $this->ranges( $exclusion )->refresh() );

		$this->assertSame( '"v1"', $this->requests[0]['args']['headers']['If-None-Match'] );
		$this->assertGreaterThan( time() - 5, $this->store[ PageCacheExclusion::OPTION ]['fetched_at'] );
		$this->assertSame( array( 'ChatGPT-User' ), $exclusion->user_agents() );
	}

	public function test_an_upgrade_without_the_cache_exclusion_list_fetches_within_the_hour(): void {
		$this->seed( 'v1', time() - 2 * HOUR_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 2 * HOUR_IN_SECONDS );

		$this->assertTrue( $this->ranges( new PageCacheExclusion( new Options() ) )->is_due() );
	}

	public function test_an_upgrade_without_the_ai_list_fetches_within_the_hour(): void {
		// 0.8.x checked the ranges two hours ago; 0.9.0 has no referrer list.
		$this->seed( 'v1', time() - 2 * HOUR_IN_SECONDS );

		$this->assertTrue( $this->ranges()->is_due() );
	}

	public function test_a_behind_ai_list_still_waits_an_hour_between_attempts(): void {
		$this->seed( 'v1', time() - 10 * MINUTE_IN_SECONDS );

		$this->assertFalse( $this->ranges()->is_due() );
	}

	public function test_lists_in_step_wait_the_full_day(): void {
		$this->seed( 'v1', time() - 2 * HOUR_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 2 * HOUR_IN_SECONDS );

		$this->assertFalse( $this->ranges()->is_due() );
	}

	public function test_one_fetch_stores_the_ai_user_agent_registry_beside_the_others(): void {
		$agents = new AiUserAgents();

		$this->assertTrue( $this->ranges( null, $agents )->refresh() );

		$this->assertSame( 'v1', $agents->version() );
		$this->assertSame(
			array(
				array( 'name' => 'GPTBot' ),
				array(
					'name'          => 'Applebot',
					'fallbackGroup' => 'Googlebot',
				),
			),
			$agents->agents()
		);
		$this->assertArrayNotHasKey( 'aiUserAgents', $this->store[ VendorRanges::OPTION ] );
	}

	public function test_no_etag_while_the_ai_user_agent_registry_is_missing(): void {
		// An upgrade: ranges and referrers current, no registry stored yet.
		$this->seed( 'v1', time() - 2 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 2 * DAY_IN_SECONDS );
		$agents = new AiUserAgents();

		$this->ranges( null, $agents )->refresh();

		$this->assertArrayNotHasKey( 'If-None-Match', $this->requests[0]['args']['headers'] );
		$this->assertSame( 'v1', $agents->version() );
	}

	public function test_an_upgrade_without_the_ai_user_agent_registry_fetches_within_the_hour(): void {
		$this->seed( 'v1', time() - 2 * HOUR_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 2 * HOUR_IN_SECONDS );

		$this->assertTrue( $this->ranges( null, new AiUserAgents() )->is_due() );
	}

	public function test_a_304_restamps_the_ai_user_agent_registry(): void {
		$this->seed( 'v1', time() - 13 * DAY_IN_SECONDS );
		$this->seed_referrers( 'v1', time() - 13 * DAY_IN_SECONDS );
		$agents = new AiUserAgents();
		$agents->replace( 'v1', array( array( 'name' => 'GPTBot' ) ), time() - 20 * DAY_IN_SECONDS );
		$this->response = array(
			'code' => 304,
			'body' => '',
		);

		$this->assertTrue( $this->ranges( null, $agents )->refresh() );

		$this->assertSame( '"v1"', $this->requests[0]['args']['headers']['If-None-Match'] );
		$this->assertSame( array( array( 'name' => 'GPTBot' ) ), $agents->agents() );
	}

	public function test_a_200_without_ai_user_agents_stores_an_empty_registry(): void {
		$agents = new AiUserAgents();
		$agents->replace( 'v0', array( array( 'name' => 'GPTBot' ) ), time() );
		$this->response = array(
			'code' => 200,
			'body' => self::body( 'v2', false ),
		);

		$this->assertTrue( $this->ranges( null, $agents )->refresh() );

		$this->assertSame( 'v2', $agents->version() );
		$this->assertNull( $agents->agents() );
	}

	public function test_a_failure_keeps_the_previous_ai_user_agent_registry(): void {
		$agents = new AiUserAgents();
		$agents->replace( 'v1', array( array( 'name' => 'GPTBot' ) ), time() - DAY_IN_SECONDS );
		$this->response = array(
			'code' => 500,
			'body' => '',
		);

		$this->assertFalse( $this->ranges( null, $agents )->refresh() );

		$this->assertSame( array( array( 'name' => 'GPTBot' ) ), $agents->agents() );
	}

	/**
	 * @param PageCacheExclusion|null $exclusion   The cache exclusion store, when wired.
	 * @param AiUserAgents|null       $user_agents The AI crawler registry store, when wired.
	 */
	private function ranges( ?PageCacheExclusion $exclusion = null, ?AiUserAgents $user_agents = null ): VendorRanges {
		return new VendorRanges( new Options(), null, $exclusion, $user_agents );
	}

	/**
	 * @param string $ip Client ip.
	 * @return array{0:bool|null, 1:string|null}
	 */
	private function verdict( string $ip ): array {
		$verdict = $this->ranges()->verify( $ip );
		return array( $verdict['verified'], $verdict['vendor'] );
	}

	/**
	 * @param string $version    List version.
	 * @param int    $fetched_at When it was fetched.
	 */
	private function seed( string $version, int $fetched_at ): void {
		$parsed                                = VendorRanges::parse( self::body( $version ) );
		$this->store[ VendorRanges::OPTION ] = array(
			'version'    => $version,
			'fetched_at' => $fetched_at,
			'checked_at' => $fetched_at,
			'ranges'     => $parsed['ranges'] ?? array(),
		);
	}

	/**
	 * @param string $version    List version.
	 * @param int    $fetched_at When it was fetched.
	 */
	private function seed_referrers( string $version, int $fetched_at ): void {
		$this->store[ AiReferrers::OPTION ] = array(
			'version'     => $version,
			'fetched_at'  => $fetched_at,
			'hosts'       => array( 'chatgpt.com' => 'chatgpt' ),
			'utmSources'  => array(),
			'searchHosts' => array( 'google.*' ),
		);
	}

	/**
	 * @param string $version       List version.
	 * @param bool   $ai_referrers  Whether the document carries ``aiReferrers``.
	 */
	private static function body( string $version, bool $ai_referrers = true ): string {
		$document = array(
			'version'     => $version,
			'generatedAt' => '2026-10-14T03:00:00Z',
			'ranges'      => array(
				array(
					'platform' => 'chatgpt',
					'start'    => '20.171.206.0',
					'end'      => '20.171.206.255',
				),
				array(
					'platform' => 'claude',
					'start'    => '2600:1f28:365:80b0::',
					'end'      => '2600:1f28:365:80b0:ffff:ffff:ffff:ffff',
				),
			),
		);
		if ( $ai_referrers ) {
			$document['aiReferrers'] = array(
				'hosts'       => array(
					'chatgpt.com' => 'chatgpt',
					'claude.ai'   => 'claude',
				),
				'utmSources'  => array( 'perplexity' => 'perplexity' ),
				'searchHosts' => array( 'google.*', 'www.bing.com' ),
			);
			$document['cacheExclusionUserAgents'] = array( 'OAI-SearchBot', 'ChatGPT-User', 'not a token' );
			$document['aiUserAgents']             = array(
				array( 'name' => 'GPTBot' ),
				array(
					'name'          => 'Applebot',
					'fallbackGroup' => 'Googlebot',
				),
				array( 'name' => 'not a token' ),
			);
		}
		return (string) json_encode( $document );
	}
}
