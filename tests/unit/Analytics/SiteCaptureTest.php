<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\CaptureContext;
use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Analytics\SiteCapture;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\FakeFlushRequest;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\SiteCapture
 */
final class SiteCaptureTest extends TestCase {

	use WpOptions;

	private const GPTBOT  = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';
	private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
	private const HTML    = array( 'Content-Type: text/html; charset=UTF-8' );
	private const AGENT   = '"https://www.meta.com"';
	private const INPUT   = 'sig1=("@authority" "signature-agent");created=1793356800;expires=1793356860;keyid="k1";alg="ed25519";tag="web-bot-auth"';
	private const VALUE   = 'sig1=:YWJjZGVm:';

	private FakeEventStore $events;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		SiteCapture::reset();
		Functions\when( 'wp_generate_uuid4' )->justReturn( '9f2c8a1e-5f3b-4a6d-9c2e-7b1d0f4a8c33' );
		$this->store[ Options::SETTINGS ]      = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok',
		);
		$this->store[ Schema::VERSION_OPTION ] = Schema::VERSION;
		$this->store[ VendorRanges::OPTION ]   = array(
			'version'    => 'v1',
			'fetched_at' => time(),
			'checked_at' => time(),
			'ranges'     => array(
				array(
					'platform' => 'chatgpt',
					'start'    => '20.171.206.0',
					'end'      => '20.171.206.255',
				),
			),
		);
		$this->events = new FakeEventStore();
	}

	protected function tearDown(): void {
		SiteCapture::reset();
		parent::tearDown();
	}

	public function test_a_bot_page_fetch_is_captured_as_a_site_event(): void {
		$this->assertTrue( $this->capture( self::request( self::GPTBOT, '/visiter/musees/?utm=x' ) ) );

		$event = $this->only_event();
		$this->assertTrue( $event->is_site() );
		$this->assertSame( '/visiter/musees', $event->path() );
		$this->assertSame( self::GPTBOT, $event->ua() );
		$this->assertSame( 200, $event->status() );
		$this->assertTrue( $event->verified() );
		$this->assertSame( 'chatgpt', $event->vendor() );
	}

	public function test_a_browser_visit_is_not_captured(): void {
		$this->assertFalse( $this->capture( self::request( self::BROWSER, '/visiter' ) ) );
		$this->assertSame( array(), $this->events->rows );
	}

	public function test_a_signed_request_with_a_browser_ua_is_captured_with_its_signature(): void {
		$this->assertTrue( $this->capture( self::signed( self::request( self::BROWSER, '/visiter' ) ) ) );

		$this->assertSame(
			array(
				'agent'     => self::AGENT,
				'input'     => self::INPUT,
				'value'     => self::VALUE,
				'authority' => 'visit-acme.example',
			),
			$this->only_event()->to_payload()['signature']
		);
	}

	public function test_an_empty_signature_agent_does_not_pass_the_gate(): void {
		$server                         = self::signed( self::request( self::BROWSER, '/visiter' ) );
		$server['HTTP_SIGNATURE_AGENT'] = '  ';

		$this->assertFalse( $this->capture( $server ) );
		$this->assertSame( array(), $this->events->rows );
	}

	/**
	 * @dataProvider partial_signatures
	 *
	 * @param string $missing The header left out.
	 */
	public function test_partial_signature_headers_send_no_signature_object( string $missing ): void {
		$server = self::signed( self::request( self::GPTBOT, '/visiter' ) );
		unset( $server[ $missing ] );

		$this->assertTrue( $this->capture( $server ) );

		$this->assertArrayNotHasKey( 'signature', $this->only_event()->to_payload() );
		$this->assertArrayNotHasKey( 'signature', $this->only_event()->to_row() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function partial_signatures(): array {
		return array(
			'no Signature-Agent' => array( 'HTTP_SIGNATURE_AGENT' ),
			'no Signature-Input' => array( 'HTTP_SIGNATURE_INPUT' ),
			'no Signature'       => array( 'HTTP_SIGNATURE' ),
		);
	}

	public function test_each_signature_member_is_trimmed_and_clipped_at_1024(): void {
		$server                         = self::signed( self::request( self::BROWSER, '/visiter' ) );
		$server['HTTP_SIGNATURE_AGENT'] = '  ' . self::AGENT . "\t";
		$server['HTTP_SIGNATURE']       = 'sig1=:' . str_repeat( 'A', 2000 ) . ':';
		$server['HTTP_HOST']            = str_repeat( 'h', 1500 );

		$this->capture( $server );

		$signature = $this->only_event()->signature();
		$this->assertSame( self::AGENT, $signature['agent'] );
		$this->assertSame( 1024, strlen( $signature['value'] ) );
		$this->assertStringStartsWith( 'sig1=:AAA', $signature['value'] );
		$this->assertSame( 1024, strlen( $signature['authority'] ) );
		$this->assertSame( self::INPUT, $signature['input'] );
	}

	public function test_a_signed_event_carries_no_ip(): void {
		$this->capture( self::signed( self::request( self::BROWSER, '/visiter' ) ) );

		$this->assertStringNotContainsString( '20.171.206.7', (string) json_encode( $this->only_event()->to_payload() ) );
	}

	public function test_an_empty_or_non_browser_ua_is_captured(): void {
		$this->assertTrue( $this->capture( self::request( '', '/' ) ) );
		$this->assertTrue( $this->capture( self::request( 'Some-Agent/2.0', '/a' ) ) );
		$this->assertCount( 2, $this->events->rows );
		$this->assertSame( '/', $this->events->rows[1]->path() );
	}

	/**
	 * @dataProvider skipped_requests
	 *
	 * @param array<string, string> $server   Request values.
	 * @param string[]              $headers  Response headers.
	 * @param bool                  $excluded WordPress context exclusion.
	 */
	public function test_requests_outside_scope_are_skipped( array $server, array $headers, bool $excluded ): void {
		$this->assertFalse( $this->capture( $server, $headers, 200, $excluded ) );
		$this->assertSame( array(), $this->events->rows );
	}

	/**
	 * @return array<string, array{0:array<string, string>, 1:string[], 2:bool}>
	 */
	public static function skipped_requests(): array {
		$post                   = self::request( self::GPTBOT, '/contact' );
		$post['REQUEST_METHOD'] = 'POST';
		return array(
			'admin / REST / feed / preview context' => array( self::request( self::GPTBOT, '/a' ), self::HTML, true ),
			'POST'                                  => array( $post, self::HTML, false ),
			'non-HTML response'                     => array( self::request( self::GPTBOT, '/feed-ish' ), array( 'Content-Type: application/rss+xml' ), false ),
			'wp-admin path'                         => array( self::request( self::GPTBOT, '/wp-admin/' ), self::HTML, false ),
			'wp-json path'                          => array( self::request( self::GPTBOT, '/wp-json/wp/v2/posts' ), self::HTML, false ),
			'wp-login'                              => array( self::request( self::GPTBOT, '/wp-login.php' ), self::HTML, false ),
			'xmlrpc'                                => array( self::request( self::GPTBOT, '/xmlrpc.php' ), self::HTML, false ),
			'counter endpoint'                      => array( self::request( self::GPTBOT, '/.well-known/globetrotters/pv' ), self::HTML, false ),
			'artefact path'                         => array( self::request( self::GPTBOT, '/llms.txt' ), self::HTML, false ),
			'artefact alias'                        => array( self::request( self::GPTBOT, '/agents.md' ), self::HTML, false ),
			'static asset'                          => array( self::request( self::GPTBOT, '/wp-content/x.css' ), self::HTML, false ),
			'image'                                 => array( self::request( self::GPTBOT, '/logo.PNG' ), self::HTML, false ),
			'profiler'                              => array( self::request( self::GPTBOT, '/_profiler/abc' ), self::HTML, false ),
			'protocol-relative uri'                 => array( self::request( self::GPTBOT, '//evil/x' ), self::HTML, false ),
		);
	}

	public function test_a_permalink_starting_with_wp_dash_is_a_site_page(): void {
		$this->assertFalse( SiteCapture::is_excluded_path( '/wp-performance-tips' ) );
		$this->assertTrue( SiteCapture::is_excluded_path( '/wp-cron.php' ) );
		$this->assertTrue( SiteCapture::is_excluded_path( '/wp-includes/js/x' ) );
	}

	public function test_absent_content_type_counts_as_html(): void {
		$this->assertTrue( $this->capture( self::request( self::GPTBOT, '/a' ), array() ) );
	}

	public function test_head_is_captured_and_any_status_is_recorded(): void {
		$server                   = self::request( self::GPTBOT, '/gone' );
		$server['REQUEST_METHOD'] = 'HEAD';

		$this->assertTrue( $this->capture( $server, self::HTML, 404 ) );
		$this->assertSame( 404, $this->only_event()->status() );
	}

	public function test_a_request_the_plugin_already_answered_is_not_captured_twice(): void {
		SiteCapture::exclude_current_request();
		$this->assertFalse( $this->capture( self::request( self::GPTBOT, '/a' ) ) );
	}

	public function test_nothing_is_written_when_capture_is_off(): void {
		$this->store[ Options::SETTINGS ]['capture_site_bots'] = false;
		$this->assertFalse( $this->capture( self::request( self::GPTBOT, '/a' ) ) );

		$this->store[ Options::SETTINGS ] = array( 'capture_site_bots' => true );
		$this->assertFalse( $this->capture( self::request( self::GPTBOT, '/a' ) ), 'Not without reporting.' );
		$this->assertSame( array(), $this->events->rows );
	}

	public function test_nothing_is_written_before_schema_3(): void {
		$this->store[ Schema::VERSION_OPTION ] = 2;
		$this->assertFalse( $this->capture( self::request( self::GPTBOT, '/a' ) ) );
	}

	public function test_no_ip_is_persisted_or_sent(): void {
		$this->capture( self::request( self::GPTBOT, '/a' ) );
		$event = $this->only_event();

		$this->assertSame( '', $event->ip() );
		$row = $event->to_row();
		$this->assertNull( $row['ip'] );
		$this->assertNotContains( '20.171.206.7', $row );
		$payload = $event->to_payload();
		$this->assertArrayNotHasKey( 'ip', $payload );
		$this->assertStringNotContainsString( '20.171.206.7', (string) json_encode( $payload ) );
	}

	public function test_verification_follows_the_range_list(): void {
		$outside                = self::request( self::GPTBOT, '/a' );
		$outside['REMOTE_ADDR'] = '198.51.100.9';
		$this->capture( $outside );
		$this->assertFalse( $this->events->rows[1]->verified() );
		$this->assertNull( $this->events->rows[1]->vendor() );

		$this->store[ VendorRanges::OPTION ]['fetched_at'] = time() - 15 * DAY_IN_SECONDS;
		$this->capture( self::request( self::GPTBOT, '/a' ) );
		$this->assertNull( $this->events->rows[2]->verified(), 'Stale list.' );
	}

	public function test_a_forged_forwarded_for_from_an_untrusted_peer_does_not_verify(): void {
		$server                         = self::request( self::GPTBOT, '/a' );
		$server['REMOTE_ADDR']          = '198.51.100.9';
		$server['HTTP_X_FORWARDED_FOR'] = '20.171.206.7';

		$this->capture( $server );

		$this->assertFalse( $this->only_event()->verified() );
	}

	public function test_content_length_is_kept_when_known(): void {
		$this->capture( self::request( self::GPTBOT, '/a' ), array( 'Content-Type: text/html', 'Content-Length: 15320' ) );
		$this->assertSame( 15320, $this->only_event()->to_payload()['bytes'] );

		$this->events->rows = array();
		$this->capture( self::request( self::GPTBOT, '/b' ) );
		$this->assertNull( $this->only_event()->to_payload()['bytes'] );
	}

	public function test_a_long_path_is_clipped_to_512(): void {
		$this->capture( self::request( self::GPTBOT, '/' . str_repeat( 'a', 700 ) ) );
		$this->assertSame( 512, strlen( $this->only_event()->path() ) );
	}

	public function test_cdn_markers_are_recorded_on_a_captured_request(): void {
		$server                = self::request( self::GPTBOT, '/a' );
		$server['HTTP_CF_RAY'] = '8a1b2c3d4e5f-CDG';

		$this->capture( $server );

		$this->assertSame( array( 'cf-ray' ), $this->store[ CaptureContext::MARKERS_OPTION ] );
	}

	public function test_a_store_failure_never_throws(): void {
		$this->events->fail_append = true;
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		$this->assertFalse( $this->capture( self::request( self::GPTBOT, '/a' ) ) );
	}

	/**
	 * @param array<string, string> $server   Request values.
	 * @param string[]              $headers  Response headers.
	 * @param int                   $status   Status.
	 * @param bool                  $excluded Context exclusion.
	 */
	private function capture( array $server, array $headers = self::HTML, int $status = 200, bool $excluded = false ): bool {
		$options = new Options();
		$ranges  = new VendorRanges( $options );
		return ( new SiteCapture(
			new EventBuffer( $this->events ),
			$options,
			new FakeFlushRequest(),
			$ranges,
			new CaptureContext( $options, $ranges, static fn() => false )
		) )->capture( $server, $headers, $status, $excluded );
	}

	private function only_event(): Event {
		$this->assertCount( 1, $this->events->rows );
		return array_values( $this->events->rows )[0];
	}

	/**
	 * The request, signed with Web Bot Auth headers.
	 *
	 * @param array<string, string> $server Request values.
	 * @return array<string, string>
	 */
	private static function signed( array $server ): array {
		return $server + array(
			'HTTP_HOST'            => 'visit-acme.example',
			'HTTP_SIGNATURE_AGENT' => self::AGENT,
			'HTTP_SIGNATURE_INPUT' => self::INPUT,
			'HTTP_SIGNATURE'       => self::VALUE,
		);
	}

	/**
	 * @param string $ua  User-Agent.
	 * @param string $uri Request URI.
	 * @return array<string, string>
	 */
	private static function request( string $ua, string $uri ): array {
		return array(
			'REQUEST_METHOD'  => 'GET',
			'REQUEST_URI'     => $uri,
			'HTTP_USER_AGENT' => $ua,
			'REMOTE_ADDR'     => '20.171.206.7',
		);
	}
}
