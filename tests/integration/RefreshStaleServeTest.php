<?php
/**
 * Integration: refresh, cron scheduling, and stale-serve against a live core.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Cache\RefreshScheduler;
use Globetrotters\AiPresence\Client\GtClient;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Sync\ArtefactSync;
use WP_Error;
use WP_UnitTestCase;

/**
 * Exercises the real refresh path — {@see GtClient} → ``wp_remote_get`` — with
 * Globetrotters stubbed via ``pre_http_request`` so CI never touches the
 * network. Covers a full successful pull, the all-or-nothing stale-serve
 * guarantee — against a partial pull, an all-empty pull, a JSON artefact that
 * is not JSON and a persist that does not land — and the WP-Cron arm/disarm
 * lifecycle.
 */
final class RefreshStaleServeTest extends WP_UnitTestCase {

	private const BASE = 'https://acme.example';

	/**
	 * The active pre_http_request stub, tracked so it can be removed per-test.
	 *
	 * @var callable|null
	 */
	private $http_stub = null;

	public function tear_down(): void {
		if ( null !== $this->http_stub ) {
			remove_filter( 'pre_http_request', $this->http_stub, 10 );
			$this->http_stub = null;
		}
		parent::tear_down();
	}

	public function test_successful_sync_stores_the_full_bundle(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );

		$bodies = $this->required_bodies();
		$this->stub_http(
			function ( string $url ) use ( $bodies ) {
				foreach ( $bodies as $path => $body ) {
					if ( $this->url_ends_with( $url, '/' . $path ) ) {
						return $this->http_ok( $body );
					}
				}
				// The version marker is fetched separately; let it synthesize.
				return $this->http_ok( '' );
			}
		);

		$result = $this->new_sync()->run();

		$this->assertTrue( $result->is_success(), $result->error_message() );

		$stored = get_option( ArtefactCache::OPTION );
		$this->assertIsArray( $stored );
		$this->assertSame( '# llms', $stored['files']['llms.txt'] );
		$this->assertArrayHasKey( '.well-known/mcp.json', $stored['files'] );
		// The agent discovery files, byte for byte from the published copy.
		foreach ( array( 'agent.json', 'AGENTS.md', '.well-known/ai-catalog.json', '.well-known/agent-skills/index.json' ) as $path ) {
			$this->assertSame( $bodies[ $path ], $stored['files'][ $path ], $path );
		}

		$state = get_option( Options::STATE );
		$this->assertSame( '', (string) $state['last_error'] );
	}

	public function test_failed_sync_keeps_previous_bundle_and_records_error(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );

		// A known-good bundle already in place.
		$this->seed_good_bundle();

		// Every fetch fails at the transport layer.
		$this->stub_http(
			static function ( string $url ) {
				return new WP_Error( 'http_request_failed', 'connection refused' );
			}
		);

		$result = $this->new_sync()->run();

		$this->assertFalse( $result->is_success() );
		$this->assertNotSame( '', $result->error_message() );

		// Stale-serve: the previous bundle is untouched.
		$stored = get_option( ArtefactCache::OPTION );
		$this->assertSame( 'GOOD', $stored['files']['llms.txt'] );
		$this->assertSame( 'good-1', $stored['version'] );

		// The failure is recorded for the admin status page.
		$state = get_option( Options::STATE );
		$this->assertNotSame( '', (string) $state['last_error'] );
	}

	/**
	 * The four agent discovery files are required artefacts since 0.7.0, on
	 * the same pull, validate, cache path as mcp.json: one gone upstream fails
	 * the whole refresh, and the bundle already cached keeps serving.
	 *
	 * @dataProvider agent_discovery_path_provider
	 */
	public function test_a_missing_agent_discovery_file_keeps_the_previous_bundle( string $missing ): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );
		$this->seed_good_bundle();

		$bodies = $this->required_bodies();
		unset( $bodies[ $missing ] );
		$this->stub_http(
			function ( string $url ) use ( $bodies, $missing ) {
				if ( $this->url_ends_with( $url, '/' . $missing ) ) {
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => 404,
							'message' => 'Not Found',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				foreach ( $bodies as $path => $body ) {
					if ( $this->url_ends_with( $url, '/' . $path ) ) {
						return $this->http_ok( $body );
					}
				}
				return $this->http_ok( '' );
			}
		);

		$result = $this->new_sync()->run();

		$this->assertFalse( $result->is_success() );
		$stored = get_option( ArtefactCache::OPTION );
		$this->assertSame( 'GOOD', $stored['files']['llms.txt'] );
		$this->assertSame( 'good-1', $stored['version'] );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function agent_discovery_path_provider(): array {
		return array(
			'agent.json'         => array( 'agent.json' ),
			'AGENTS.md'          => array( 'AGENTS.md' ),
			'ai-catalog.json'    => array( '.well-known/ai-catalog.json' ),
			'agent-skills index' => array( '.well-known/agent-skills/index.json' ),
		);
	}

	public function test_all_empty_pull_is_refused_and_keeps_the_previous_bundle(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );
		$this->seed_good_bundle();

		// Every required file answers 200 with an empty body. A single empty
		// artefact is legitimate; all of them at once is a broken origin or an
		// interposed proxy, and caching it would blank the apex.
		$this->stub_http( fn( string $url ) => $this->http_ok( '' ) );

		$result = $this->new_sync()->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'empty', $result->error_message() );

		$stored = get_option( ArtefactCache::OPTION );
		$this->assertSame( 'GOOD', $stored['files']['llms.txt'] );
		$this->assertSame( 'good-1', $stored['version'] );
		$this->assertNotSame( '', (string) get_option( Options::STATE )['last_error'] );
	}

	/**
	 * A maintenance page or proxy error answered with a 200 passes the status and
	 * size checks alike. Publishing it would put HTML where a discovery document
	 * was, so the whole pull is refused.
	 */
	public function test_an_html_page_served_as_json_is_refused_and_keeps_the_previous_bundle(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );
		$this->seed_good_bundle();

		$bodies                = $this->required_bodies();
		$bodies['schema.json'] = '<!DOCTYPE html><html><body>Down for maintenance</body></html>';
		$this->stub_http(
			function ( string $url ) use ( $bodies ) {
				foreach ( $bodies as $path => $body ) {
					if ( $this->url_ends_with( $url, '/' . $path ) ) {
						return $this->http_ok( $body );
					}
				}
				return $this->http_ok( '' );
			}
		);

		$result = $this->new_sync()->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( '/schema.json', $result->error_message() );

		$stored = get_option( ArtefactCache::OPTION );
		$this->assertSame( 'GOOD', $stored['files']['llms.txt'] );
		$this->assertSame( 'good-1', $stored['version'] );
		$this->assertNotSame( '', (string) get_option( Options::STATE )['last_error'] );
	}

	public function test_a_failed_persist_is_a_failed_refresh(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );
		$this->seed_good_bundle();
		// Through Options so the defaults apply — a fresh install has no state row.
		$before = ( new Options() )->state();

		$bodies = $this->required_bodies();
		$this->stub_http(
			function ( string $url ) use ( $bodies ) {
				foreach ( $bodies as $path => $body ) {
					if ( $this->url_ends_with( $url, '/' . $path ) ) {
						return $this->http_ok( $body );
					}
				}
				return $this->http_ok( '' );
			}
		);

		// A write that lands nowhere: core sees the value as unchanged and
		// returns false without touching the row. Stands in for the field
		// versions — a full disk, a read-only replica, an option-size limit.
		$veto = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . ArtefactCache::OPTION, $veto, 10, 2 );
		$result = $this->new_sync()->run();
		remove_filter( 'pre_update_option_' . ArtefactCache::OPTION, $veto, 10 );

		// The pull itself succeeded, so nothing but the persist can have failed.
		$this->assertFalse( $result->is_success(), 'A bundle that was not stored is not a successful refresh.' );

		// Stale-serve, and a state that still describes what is actually served:
		// advancing installed_version/content_hash here would make the settings
		// screen report a healthy install serving artefacts it does not hold.
		$stored = get_option( ArtefactCache::OPTION );
		$this->assertSame( 'GOOD', $stored['files']['llms.txt'] );
		$state = ( new Options() )->state();
		$this->assertNotSame( '', (string) $state['last_error'] );
		$this->assertSame( $before['installed_version'], $state['installed_version'] );
		$this->assertSame( $before['content_hash'], $state['content_hash'] );
	}

	public function test_an_unchanged_bundle_is_not_reported_as_a_failed_write(): void {
		$files = array(
			'llms.txt' => 'SAME',
			'ai.json'  => '{}',
		);

		$this->assertTrue( ( new ArtefactCache() )->store( $files, 'v1' ) );

		// The trap this guards against, asserted against core rather than
		// assumed: re-writing the byte-identical option returns false, exactly
		// as a failed write does.
		$this->assertFalse( update_option( ArtefactCache::OPTION, get_option( ArtefactCache::OPTION ), false ) );

		// So storing the same bundle again must still report success — a
		// republish that changes nothing is not a refresh failure.
		$this->assertTrue( ( new ArtefactCache() )->store( $files, 'v1' ) );
		$this->assertSame( 'SAME', ( new ArtefactCache() )->get( 'llms.txt' ) );
	}

	public function test_scheduler_arms_and_disarms_the_refresh_event(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );

		$scheduler = new RefreshScheduler( $this->new_sync(), new ArtefactCache(), new Options() );
		$scheduler->register();
		$scheduler->ensure_scheduled();

		$this->assertNotFalse(
			wp_next_scheduled( RefreshScheduler::HOOK ),
			'A connected install must arm the refresh cron event.'
		);

		$scheduler->unschedule();
		$this->assertFalse( wp_next_scheduled( RefreshScheduler::HOOK ) );
	}

	/**
	 * An install upgraded from a release that scheduled the pull itself at the
	 * configured cadence is moved onto the hourly poll on its next request.
	 */
	public function test_scheduler_replaces_an_event_left_at_the_old_cadence(): void {
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );
		$scheduler = new RefreshScheduler( $this->new_sync(), new ArtefactCache(), new Options() );
		$scheduler->register();
		wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', RefreshScheduler::HOOK );

		$scheduler->ensure_scheduled();

		$event = wp_get_scheduled_event( RefreshScheduler::HOOK );
		$this->assertNotFalse( $event );
		$this->assertSame( 'hourly', $event->schedule );
		$this->assertLessThanOrEqual( time(), $event->timestamp );

		$scheduler->unschedule();
	}

	public function test_scheduler_stays_disarmed_when_not_connected(): void {
		$scheduler = new RefreshScheduler( $this->new_sync(), new ArtefactCache(), new Options() );
		$scheduler->register();
		$scheduler->ensure_scheduled();

		$this->assertFalse(
			wp_next_scheduled( RefreshScheduler::HOOK ),
			'An unconnected install must not schedule a refresh.'
		);
	}

	/**
	 * A known-good bundle already in place, for the stale-serve assertions.
	 */
	private function seed_good_bundle(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'     => array(
					'llms.txt' => 'GOOD',
					'ai.json'  => '{}',
				),
				'version'   => 'good-1',
				'stored_at' => time(),
			),
			false
		);
	}

	private function new_sync(): ArtefactSync {
		return new ArtefactSync( new GtClient(), new ArtefactCache(), new Options() );
	}

	/**
	 * The required (non-marker) apex files and canned bodies.
	 *
	 * @return array<string, string>
	 */
	private function required_bodies(): array {
		return array(
			'llms.txt'                            => '# llms',
			'ai.json'                             => '{"ok":true}',
			'schema.json'                         => '{"@type":"Thing"}',
			'.well-known/mcp.json'                => '{"mcp":1}',
			'.well-known/agent-card.json'         => '{"agent":1}',
			'.well-known/ai-catalog.json'         => '{"specVersion":"1.0","entries":[]}',
			'.well-known/agent-skills/index.json' => '{"skills":[]}',
			'agent.json'                          => '{"name":"Acme"}',
			'AGENTS.md'                           => "# Acme\n",
		);
	}

	/**
	 * Register a pre_http_request stub that maps a request URL to a response.
	 *
	 * @param callable $responder fn(string $url): array|WP_Error
	 */
	private function stub_http( callable $responder ): void {
		$this->http_stub = static function ( $preempt, $args, $url ) use ( $responder ) {
			return $responder( (string) $url );
		};
		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );
	}

	/**
	 * A well-formed HTTP 200 response array for the WP HTTP API.
	 *
	 * @param string $body Response body.
	 * @return array<string, mixed>
	 */
	private function http_ok( string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function url_ends_with( string $url, string $suffix ): bool {
		return '' !== $suffix && substr( $url, -strlen( $suffix ) ) === $suffix;
	}
}
