<?php
/**
 * Integration: server-log capture against a live WordPress + MySQL.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\EventRecorder;
use Globetrotters\AiPresence\Analytics\FlushRequest;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Analytics\Flusher;
use Globetrotters\AiPresence\Analytics\IngestClient;
use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\Router;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeFlushRequest;
use WP_UnitTestCase;

/**
 * Exercises the capture path end-to-end: a served artefact request writes one
 * row to the real buffer table, under the canonical path, with a real-UTC
 * timestamp — including on a site whose timezone is not UTC, which is the case
 * that silently breaks.
 */
final class ServerLogCaptureTest extends WP_UnitTestCase {

	private Options $options;
	private MysqlEventStore $store;

	public function set_up(): void {
		parent::set_up();

		// PHPUnit runs under CLI, where there is no request method; the router
		// serves GET/HEAD only.
		$_SERVER['REQUEST_METHOD'] = 'GET';

		( new Schema() )->install();
		$this->options = new Options();
		$this->options->update_settings(
			array(
				'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
				'ingest_token'    => 'tok_integration',
			)
		);
		$this->store = new MysqlEventStore();
		$this->clear_buffer();

		$files = array();
		foreach ( ContentTypes::paths() as $path ) {
			$files[ $path ] = 'body-for-' . $path;
		}
		update_option( ArtefactCache::OPTION, array( 'files' => $files, 'version' => 'v1' ), false );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
		$_SERVER['REMOTE_ADDR']     = '160.79.104.10';
		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function tear_down(): void {
		$this->clear_buffer();
		wp_clear_scheduled_hook( FlushScheduler::HOOK_NOW );
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	public function test_the_buffer_table_is_installed(): void {
		$this->assertTrue( Schema::is_ready() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_storage_is_installed_only_once_reporting_is_configured(): void {
		$this->forget_installation();

		// Saving something else must not provision storage an install won't use.
		update_option( Options::SETTINGS, array( 'refresh_interval' => 'hourly' ) );
		$this->assertFalse( Schema::is_ready() );

		// Saving credentials must — through the ``update_option`` hook boot()
		// registers, so this works from WP-CLI as well as the settings screen.
		update_option(
			Options::SETTINGS,
			array(
				'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
				'ingest_token'    => 'tok_integration',
			)
		);

		$this->assertTrue( Schema::is_ready() );
	}

	public function test_each_served_path_writes_one_row_under_its_canonical_path(): void {
		foreach ( ContentTypes::paths() as $path ) {
			$_SERVER['REQUEST_URI'] = '/' . $path;
			$this->router()->maybe_serve();
		}

		$buffered = $this->store->oldest( 100 );
		$this->assertCount( count( ContentTypes::paths() ), $buffered );

		$paths = array();
		foreach ( $buffered as $row ) {
			$paths[] = $row['event']->path();
		}
		foreach ( ContentTypes::paths() as $path ) {
			$this->assertContains( '/' . $path, $paths );
		}
	}

	public function test_query_strings_are_reported_as_the_canonical_path(): void {
		// '/llms.txt?v=2' is dropped at ingest; the canonical path is not.
		$_SERVER['REQUEST_URI'] = '/llms.txt?v=2';

		$this->router()->maybe_serve();

		$this->assertSame( '/llms.txt', $this->store->oldest( 1 )[0]['event']->path() );
	}

	public function test_unserved_paths_are_not_captured(): void {
		foreach ( array( '/index.html', '/wp-login.php', '/llms.txt/' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->router()->maybe_serve();
		}

		$this->assertSame( 0, $this->store->stats()['count'] );
	}

	public function test_timestamp_is_real_utc_on_a_paris_site(): void {
		// The silent-failure case: current_time() on a Europe/Paris site reports
		// every hit two hours off, and the backend re-stamps rather than errors.
		update_option( 'timezone_string', 'Europe/Paris' );
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$this->router()->maybe_serve();

		$event = $this->store->oldest( 1 )[0]['event'];
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event->ts() );
		$this->assertSame( gmdate( 'Y-m-d\TH:i' ), substr( $event->ts(), 0, 16 ) );
		$this->assertNotSame(
			(string) current_time( 'mysql' ),
			str_replace( array( 'T', 'Z' ), array( ' ', '' ), $event->ts() ),
			'A site-local timestamp is exactly the bug this guards against.'
		);
	}

	public function test_capture_records_the_request_details(): void {
		$_SERVER['REQUEST_URI']  = '/ai.json';
		$_SERVER['HTTP_REFERER'] = 'https://example.test/';

		$this->router()->maybe_serve();

		$event = $this->store->oldest( 1 )[0]['event'];
		$this->assertSame( 200, $event->status() );
		$this->assertSame( strlen( 'body-for-ai.json' ), $event->bytes() );
		$this->assertSame( '160.79.104.10', $event->ip() );
		$this->assertSame( 'https://example.test/', $event->referer() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $event->id() );
	}

	public function test_proxy_headers_are_only_honoured_from_a_trusted_proxy(): void {
		$_SERVER['REQUEST_URI']          = '/llms.txt';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 198.51.100.4';

		// No proxy listed: the connection's own address, whatever the header says.
		$this->router()->maybe_serve();
		$this->assertSame( '160.79.104.10', $this->store->oldest( 1 )[0]['event']->ip() );

		// The peer listed: the hop it appended — not the leftmost entry, which is
		// the client's own claim.
		$this->clear_buffer();
		$this->options->update_settings( array( 'trusted_proxies' => '160.79.104.10' ) );

		$this->router()->maybe_serve();
		$this->assertSame( '198.51.100.4', $this->store->oldest( 1 )[0]['event']->ip() );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_capture_is_off_without_ingest_credentials(): void {
		$this->options->update_settings(
			array(
				'ingest_endpoint' => '',
				'ingest_token'    => '',
			)
		);
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$this->router()->maybe_serve();

		$this->assertSame( 0, $this->store->stats()['count'] );
	}

	public function test_serving_still_works_when_capture_storage_is_unavailable(): void {
		// Stands in for a site whose database user cannot CREATE: the version
		// option is what every read and write is gated on, so clearing it is the
		// same code path as a missing table — and unlike a real DROP, it works
		// inside the WordPress test harness (which rewrites DROP TABLE to DROP
		// TEMPORARY TABLE and so cannot remove a real one).
		$this->forget_installation();
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$router = $this->router();
		$router->maybe_serve();

		// Capture degrades; serving does not.
		$this->assertSame( array( array( 'llms.txt', 'body-for-llms.txt' ) ), $router->emitted );

		( new Schema() )->install();
	}

	public function test_a_filling_buffer_queues_an_early_flush(): void {
		// The ceiling this defends: without an early flush a site busy enough to
		// fill the buffer inside one 15-minute interval drops everything above it.
		$this->fill_past_high_water();
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$router = $this->router( $this->live_scheduler() );
		// The fill gauge only reads on every 25th insert, so drive a full cycle.
		for ( $i = 0; $i < 25; $i++ ) {
			$router->maybe_serve();
		}

		$this->assertIsInt( wp_next_scheduled( FlushScheduler::HOOK_NOW ) );
	}

	public function test_an_ordinary_hit_queues_nothing(): void {
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$router = $this->router( $this->live_scheduler() );
		for ( $i = 0; $i < 25; $i++ ) {
			$router->maybe_serve();
		}

		// A quiet site rides the recurring 15-minute event alone.
		$this->assertFalse( wp_next_scheduled( FlushScheduler::HOOK_NOW ) );
	}

	public function test_stored_rows_survive_a_round_trip(): void {
		$store = new MysqlEventStore();
		$event = new Event( '9f2c8a1e-5f3b-4a6d-9c2e-7b1d0f4a8c33', '2026-08-10T09:14:22Z', '/llms.txt', 'ClaudeBot/1.0', '160.79.104.10', '', 200, 4211 );

		$id = $store->append( $event );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $event->to_payload(), $store->oldest( 1 )[0]['event']->to_payload() );
		$this->assertSame( 1, $store->delete( array( $id ) ) );
		$this->assertSame( 0, $store->stats()['count'] );
	}

	public function test_delete_oldest_removes_in_insertion_order(): void {
		$store = new MysqlEventStore();
		for ( $i = 0; $i < 5; $i++ ) {
			$store->append( new Event( 'uuid-' . $i, '2026-08-10T09:14:22Z', '/llms.txt', 'agent', '160.79.104.10', '', 200, 1 ) );
		}

		$this->assertSame( 2, $store->delete_oldest( 2 ) );

		$this->assertSame( 'uuid-2', $store->oldest( 1 )[0]['event']->id() );
		$this->assertSame( 3, $store->stats()['count'] );
	}

	/**
	 * A Router with a live recorder that records emit() instead of exiting.
	 *
	 * @param FlushRequest|null $flushes Scheduler to use; a counting double by default.
	 */
	private function router( ?FlushRequest $flushes = null ): Router {
		$recorder = new EventRecorder(
			new EventBuffer( new MysqlEventStore() ),
			$this->options,
			$flushes ?? new FakeFlushRequest()
		);

		return new class( new ArtefactCache(), new Options(), $recorder ) extends Router {
			/**
			 * @var array<int, array{0:string,1:string}>
			 */
			public array $emitted = array();

			protected function emit( string $path, string $body, array $headers ): void {
				$this->emitted[] = array( $path, $body );
			}
		};
	}

	/**
	 * A real scheduler, so the cron write itself is under test.
	 *
	 * The Flusher it carries is never run — request_flush() only queues.
	 */
	private function live_scheduler(): FlushScheduler {
		return new FlushScheduler(
			new Flusher( new EventBuffer( new MysqlEventStore() ), new IngestClient(), $this->options ),
			$this->options
		);
	}

	/**
	 * Seed the buffer past the high-water mark with max-length events.
	 *
	 * Writes straight to the store: this is the starting state, not the
	 * behaviour under test, and it keeps the fill to a couple of hundred rows.
	 */
	private function fill_past_high_water(): void {
		$fat    = str_repeat( 'u', Event::MAX_FIELD_CHARS );
		$sample = new Event( 'sizer', '2026-08-10T09:14:22Z', '/llms.txt', $fat, '160.79.104.10', $fat, 200, 4211 );
		$needed = (int) ceil( EventBuffer::MAX_BYTES * 0.6 / $sample->size_bytes() ) + 1;

		for ( $i = 0; $i < $needed; $i++ ) {
			$this->store->append(
				new Event( 'seed-' . $i, '2026-08-10T09:14:22Z', '/llms.txt', $fat, '160.79.104.10', $fat, 200, 4211 )
			);
		}
	}

	/**
	 * Empty the buffer table between cases.
	 */
	private function clear_buffer(): void {
		global $wpdb;
		$table = Schema::table();
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore
	}

	/**
	 * Put the plugin back into its "no capture storage" state.
	 */
	private function forget_installation(): void {
		delete_option( Schema::VERSION_OPTION );
	}
}
