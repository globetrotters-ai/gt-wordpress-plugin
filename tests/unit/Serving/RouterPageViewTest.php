<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use ArrayObject;
use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\AiReferrers;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\EventRecorder;
use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\Router;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\FakeFlushRequest;
use Globetrotters\AiPresence\Tests\Support\FakePageViewStore;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * The same-origin page-view counter endpoint on the router.
 *
 * @covers \Globetrotters\AiPresence\Serving\Router
 */
final class RouterPageViewTest extends TestCase {

	use WpOptions;

	private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

	private FakePageViewStore $views;

	/**
	 * @var array<string, mixed>
	 */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			fn( $key ) => strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) )
		);
		Functions\when( 'home_url' )->justReturn( 'https://acme.example/' );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$this->server_backup = $_SERVER;
		$this->views         = new FakePageViewStore();
		$this->store[ Schema::VERSION_OPTION ] = Schema::VERSION;
		$this->configure( true );
		$this->beacon();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		parent::tearDown();
	}

	public function test_a_same_origin_beacon_is_counted_and_answered_204(): void {
		$router = $this->spy_router( '{"p":"/visiter/marche/"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame(
			array( array( 'increment', gmdate( 'Y-m-d' ), '/visiter/marche', 'browser' ) ),
			$this->views->writes
		);
	}

	public function test_a_beacon_from_an_ai_answer_counts_under_its_platform_only(): void {
		$this->seed_ai_list();
		$router = $this->spy_router( '{"p":"/x","r":"chatgpt.com","u":"chatgpt.com"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame(
			array( array( 'increment', gmdate( 'Y-m-d' ), '/x', 'browser@chatgpt' ) ),
			$this->views->writes
		);
		// Only the platform is kept: the hints are in no write and no header.
		$kept = (string) json_encode( array( $this->views->writes, $this->views->rows, $router->headers ) );
		$this->assertStringNotContainsString( 'chatgpt.com', $kept );
	}

	/**
	 * @dataProvider beacon_body_provider
	 */
	public function test_the_endpoint_never_reads_the_vendor_ranges( string $body, bool $reads_ai_list ): void {
		// AC12: a beacon never loads the IP range list, and loads the AI
		// referrer list only when it carries a hint.
		$this->seed_ai_list();
		$this->store[ VendorRanges::OPTION ] = array(
			'version'    => 'v1',
			'fetched_at' => time(),
			'checked_at' => time(),
			'ranges'     => array(),
		);
		$reads = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( &$reads ) {
				$reads[] = $name;
				return $this->store[ $name ] ?? $default;
			}
		);
		$router = $this->spy_router( $body );

		$router->maybe_serve();

		$this->assertCount( 1, $this->views->writes );
		$this->assertNotContains( VendorRanges::OPTION, $reads );
		$this->assertSame( $reads_ai_list, in_array( AiReferrers::OPTION, $reads, true ) );
	}

	/**
	 * @return array<string, array{0:string, 1:bool}>
	 */
	public static function beacon_body_provider(): array {
		return array(
			'no hint'         => array( '{"p":"/x"}', false ),
			'referrer host'   => array( '{"p":"/x","r":"chatgpt.com"}', true ),
			'utm_source'      => array( '{"p":"/x","u":"perplexity"}', true ),
			'unmatched hints' => array( '{"p":"/x","r":"www.google.com","u":"news"}', true ),
		);
	}

	public function test_the_204_is_uncacheable_and_carries_nothing_else(): void {
		$router = $this->spy_router( '{"p":"/"}' );

		$router->maybe_serve();

		$this->assertSame(
			array(
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control'          => 'no-store',
				'Surrogate-Control'      => 'no-store',
			),
			$router->headers
		);
		// No cookie, and no CORS grant: a cross-origin page cannot read or
		// probe this endpoint.
		foreach ( array_keys( $router->headers ) as $name ) {
			$this->assertStringNotContainsStringIgnoringCase( 'cookie', $name );
			$this->assertStringNotContainsStringIgnoringCase( 'access-control', $name );
		}
	}

	/**
	 * @dataProvider discarded_provider
	 *
	 * @param array<string, string> $server Request headers to override.
	 */
	public function test_a_discarded_beacon_still_answers_204_and_writes_nothing( array $server, string $body ): void {
		foreach ( $server as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
		$router = $this->spy_router( $body );

		$router->maybe_serve();

		// Same answer either way: the endpoint does not reveal what it counted.
		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame( array(), $this->views->writes );
	}

	/**
	 * @return array<string, array{0:array<string, string>, 1:string}>
	 */
	public static function discarded_provider(): array {
		return array(
			'cross-origin'   => array( array( 'HTTP_ORIGIN' => 'https://evil.example' ), '{"p":"/x"}' ),
			'bot'            => array( array( 'HTTP_USER_AGENT' => 'Googlebot/2.1' ), '{"p":"/x"}' ),
			'no UA'          => array( array( 'HTTP_USER_AGENT' => '' ), '{"p":"/x"}' ),
			'query string'   => array( array(), '{"p":"/x?email=a@b.c"}' ),
			'not json'       => array( array(), 'hello' ),
			'declared large' => array( array( 'CONTENT_LENGTH' => '4096' ), '{"p":"/x"}' ),
		);
	}

	public function test_an_oversized_body_is_never_read_past_the_cap(): void {
		$router = $this->spy_router( '{"p":"/' . str_repeat( 'a', 2000 ) . '"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame( array(), $this->views->writes );
	}

	public function test_the_endpoint_never_reads_an_ip_header(): void {
		// Every header that could carry a visitor address is present, and every
		// read of the request's server array is recorded. None of them may be
		// touched: the counter is not allowed to know who sent the beacon.
		$_SERVER = new class( array_merge(
			$_SERVER,
			array(
				'REMOTE_ADDR'           => '203.0.113.7',
				'HTTP_X_FORWARDED_FOR'  => '198.51.100.1',
				'HTTP_CF_CONNECTING_IP' => '198.51.100.2',
				'HTTP_X_REAL_IP'        => '198.51.100.3',
				'HTTP_FORWARDED'        => 'for=198.51.100.4',
				'HTTP_CLIENT_IP'        => '198.51.100.5',
				'HTTP_TRUE_CLIENT_IP'   => '198.51.100.6',
				'HTTP_COOKIE'           => 'wordpress_logged_in=secret',
			)
		) ) extends ArrayObject {
			/**
			 * @var array<int, string>
			 */
			public array $reads = array();

			/**
			 * @param mixed $key Offset.
			 * @return mixed
			 */
			#[\ReturnTypeWillChange]
			public function offsetGet( $key ) {
				$this->reads[] = (string) $key;
				return parent::offsetGet( $key );
			}

			/**
			 * @param mixed $key Offset.
			 */
			public function offsetExists( $key ): bool {
				$this->reads[] = (string) $key;
				return parent::offsetExists( $key );
			}
		};
		$router = $this->spy_router( '{"p":"/visiter"}' );

		$router->maybe_serve();

		$reads = $_SERVER->reads;
		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertCount( 1, $this->views->writes, 'The spy must not stop the beacon being counted.' );
		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_COOKIE' ) as $header ) {
			$this->assertNotContains( $header, $reads, "{$header} must never be read." );
		}
	}

	public function test_the_counter_source_names_no_ip_source(): void {
		// Belt and braces for the runtime check above: neither file handling the
		// beacon mentions an address header or the proxy resolver at all.
		$counter = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Analytics/PageViewCounter.php' );
		foreach ( array( 'REMOTE_ADDR', 'FORWARDED', 'CONNECTING_IP', 'ClientIp', 'setcookie', 'HTTP_COOKIE' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $counter );
		}
	}

	public function test_counting_off_still_answers_the_cheap_204_and_counts_nothing(): void {
		// Pages a cache stored while counting was on keep beaconing after it is
		// switched off. Letting those fall through to WordPress would cost a
		// full 404 render each, and trip security plugins' 404 lockouts.
		$this->configure( false );
		$router = $this->spy_router( '{"p":"/x"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame( Router::page_view_response_headers(), $router->headers );
		$this->assertSame( array(), $this->views->writes );
		$this->assertSame( 0, $router->body_reads, 'Nothing to count, so the body is not read.' );
	}

	public function test_a_beacon_while_counting_is_off_triggers_no_other_work(): void {
		// The off path is the cheap one: a bare 204 and nothing else — no event
		// captured, no early flush requested, no cron event queued, no counter
		// or cache touched. Only the Symfony bundle's twin of this path had an
		// opportunistic flush to guard; this one must stay just as inert.
		$this->configure( false );
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_using_ext_object_cache' )->never();
		$events  = new FakeEventStore();
		$flushes = new FakeFlushRequest();
		$router  = $this->spy_router(
			'{"p":"/x"}',
			new EventRecorder( new EventBuffer( $events ), new Options(), $flushes )
		);

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame( 0, $flushes->requests );
		$this->assertSame( array(), $events->rows );
		$this->assertSame( array(), $this->views->writes );
		$this->assertSame( 0, $router->body_reads );
	}

	public function test_no_reporting_credentials_still_answers_204_and_counts_nothing(): void {
		$this->store[ Options::SETTINGS ] = array( 'count_page_views' => true );
		$router                           = $this->spy_router( '{"p":"/x"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame( array(), $this->views->writes );
	}

	public function test_the_signature_agent_is_passed_and_moves_the_view_to_ai_browser(): void {
		$_SERVER['HTTP_SIGNATURE_AGENT'] = '"https://www.meta.com"';
		$router                          = $this->spy_router( '{"p":"/visiter"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame(
			array( array( 'increment', gmdate( 'Y-m-d' ), '/visiter', 'ai_browser' ) ),
			$this->views->writes
		);
	}

	public function test_sec_fetch_site_is_passed_for_a_no_referrer_page(): void {
		// A Referrer-Policy: no-referrer page sends Origin: null and no Referer;
		// the browser's own Sec-Fetch-Site is what proves it came from here.
		$_SERVER['HTTP_ORIGIN']         = 'null';
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
		$router                         = $this->spy_router( '{"p":"/visiter"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertCount( 1, $this->views->writes );
	}

	public function test_a_cross_site_fetch_with_an_opaque_origin_is_not_counted(): void {
		$_SERVER['HTTP_ORIGIN']         = 'null';
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
		$_SERVER['HTTP_REFERER']        = 'https://acme.example/visiter';
		$router                         = $this->spy_router( '{"p":"/visiter"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
		$this->assertSame( array(), $this->views->writes );
	}

	/**
	 * @dataProvider other_method_provider
	 */
	public function test_only_post_is_answered( string $method ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$router                    = $this->spy_router( '{"p":"/x"}' );

		$router->maybe_serve();

		$this->assertSame( array(), $router->statuses );
		$this->assertSame( array(), $router->emitted );
		$this->assertSame( array(), $this->views->writes );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function other_method_provider(): array {
		return array(
			'GET'     => array( 'GET' ),
			'HEAD'    => array( 'HEAD' ),
			'PUT'     => array( 'PUT' ),
			'OPTIONS' => array( 'OPTIONS' ),
		);
	}

	/**
	 * @dataProvider non_canonical_provider
	 */
	public function test_the_endpoint_answers_at_one_url_only( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;
		$router                 = $this->spy_router( '{"p":"/x"}' );

		$router->maybe_serve();

		$this->assertSame( array(), $router->statuses );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function non_canonical_provider(): array {
		return array(
			'faked authority' => array( '//evil/.well-known/globetrotters/pv' ),
			'trailing slash'  => array( '/.well-known/globetrotters/pv/' ),
			'double slash'    => array( '/.well-known//globetrotters/pv' ),
		);
	}

	public function test_a_query_string_on_the_endpoint_is_tolerated(): void {
		$_SERVER['REQUEST_URI'] = PageViewCounter::PATH . '?cache-buster=1';
		$router                 = $this->spy_router( '{"p":"/x"}' );

		$router->maybe_serve();

		$this->assertSame( array( 204 ), $router->statuses );
	}

	public function test_a_post_to_an_artefact_path_still_falls_through(): void {
		// The artefact surface stays GET/HEAD-only: the POST branch answers its
		// own path and nothing else.
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/llms.txt';
		$router                 = $this->spy_router( '{"p":"/x"}' );

		$router->maybe_serve();

		$this->assertSame( array(), $router->statuses );
		$this->assertSame( array(), $router->emitted );
	}

	private function seed_ai_list(): void {
		$this->store[ AiReferrers::OPTION ] = array(
			'version'    => 'v1',
			'fetched_at' => time(),
			'hosts'      => array( 'chatgpt.com' => 'chatgpt' ),
			'utmSources' => array( 'perplexity' => 'perplexity' ),
		);
	}

	private function beacon(): void {
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['REQUEST_URI']     = PageViewCounter::PATH;
		$_SERVER['HTTP_ORIGIN']     = 'https://acme.example';
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER;
		$_SERVER['CONTENT_LENGTH']  = '20';
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_SEC_FETCH_SITE'] );
	}

	private function configure( bool $on ): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint'  => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'     => 'tok_live',
			'count_page_views' => $on,
		);
	}

	/**
	 * A Router that records its responses instead of sending + exiting, and
	 * reads its request body from the given string.
	 *
	 * @param string             $body     Raw request body.
	 * @param EventRecorder|null $recorder Optional capture recorder.
	 */
	private function spy_router( string $body, ?EventRecorder $recorder = null ): Router {
		$router = new class( new ArtefactCache(), new Options(), $recorder, new PageViewCounter( $this->views, new AiReferrers() ) ) extends Router {
			public string $body = '';

			public int $body_reads = 0;

			/**
			 * @var array<int, int>
			 */
			public array $statuses = array();

			/**
			 * @var array<int, array{0:string,1:string}>
			 */
			public array $emitted = array();

			/**
			 * @var array<string, string>
			 */
			public array $headers = array();

			protected function read_body( int $max_bytes ): string {
				$this->body_reads++;
				return substr( $this->body, 0, $max_bytes );
			}

			protected function emit_status( int $status, array $headers ): void {
				$this->statuses[] = $status;
				$this->headers    = $headers;
			}

			protected function emit( string $path, string $body, array $headers ): void {
				$this->emitted[] = array( $path, $body );
			}
		};
		$router->body = $body;
		return $router;
	}
}
