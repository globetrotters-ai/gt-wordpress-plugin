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
use Globetrotters\AiPresence\Analytics\Flusher;
use Globetrotters\AiPresence\Analytics\IngestResult;
use Globetrotters\AiPresence\Analytics\IngestTransport;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresence\Tests\Support\FakePageViewStore;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\Flusher
 */
final class FlusherTest extends TestCase {

	use WpOptions;

	private const ENDPOINT = 'https://api.globetrotters.ai/presence/analytics/server-log';

	private FakeEventStore $events;
	private FakePageViewStore $views;
	private EventBuffer $buffer;
	private Options $options;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_json_encode' )->alias( fn( $data, $flags = 0 ) => json_encode( $data, (int) $flags ) );
		Functions\when( '__' )->returnArg();

		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => self::ENDPOINT,
			'ingest_token'    => 'tok_live_secret',
		);
		$this->store[ Schema::VERSION_OPTION ] = Schema::VERSION;

		$this->events  = new FakeEventStore();
		$this->views   = new FakePageViewStore();
		$this->options = new Options();
		$this->buffer  = new EventBuffer( $this->events );
	}

	public function test_envelope_matches_the_wire_contract(): void {
		$this->fill( 2 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$envelope = $transport->envelope();
		$this->assertSame( self::ENDPOINT, $transport->calls[0]['url'] );
		$this->assertSame( 'tok_live_secret', $transport->calls[0]['token'] );
		$this->assertStringStartsWith( 'wordpress-plugin/', $envelope['producer'] );
		$this->assertSame( 1.0, $envelope['sampleRate'] );
		$this->assertSame( 0, $envelope['dropped'] );
		$this->assertCount( 2, $envelope['events'] );
		$this->assertSame(
			array( 'id', 'ts', 'path', 'ua', 'ip', 'referer', 'status', 'bytes' ),
			array_keys( $envelope['events'][0] )
		);
		$this->assertSame( '/llms.txt', $envelope['events'][0]['path'] );
		$this->assertNull( $envelope['events'][0]['referer'] );
	}

	public function test_accepted_batch_drains_the_buffer_and_stamps_state(): void {
		$this->fill( 3 );

		$this->assertTrue( $this->flusher( new FakeIngestTransport() )->run() );

		$this->assertSame( 0, $this->buffer->count() );
		$state = $this->options->flush_state();
		$this->assertArrayNotHasKey( Options::STATE, $this->store, 'A flush must not rewrite the sync state.' );
		$this->assertGreaterThan( 0, (int) $state['last_flush_ok'] );
		$this->assertSame( 1, (int) $state['flush_count'] );
		$this->assertSame( '', (string) $state['last_flush_error'] );
	}

	public function test_retry_resends_the_same_payload_with_the_same_uuids(): void {
		// The property that makes retry safe: the backend dedupes on `id`, so a
		// flush that timed out but was received cannot double-count. Regenerating
		// IDs on retry would inflate the customer's own numbers.
		$this->fill( 3 );
		$transport = new FakeIngestTransport(
			array( IngestResult::error( 'cURL error 28: Operation timed out' ), IngestResult::http( 202 ) )
		);
		$flusher = $this->flusher( $transport );

		$this->assertFalse( $flusher->run() );
		$this->assertSame( 3, $this->buffer->count(), 'A failed flush must leave every event buffered.' );

		$this->assertTrue( $flusher->run() );

		$this->assertCount( 2, $transport->calls );
		$this->assertSame(
			$transport->calls[0]['json'],
			$transport->calls[1]['json'],
			'The retry must be byte-identical to the attempt it repeats.'
		);
		$this->assertSame( 0, $this->buffer->count() );
	}

	public function test_rate_limiting_is_a_retry_not_a_loss(): void {
		$this->fill( 2 );
		$transport = new FakeIngestTransport( array( IngestResult::http( 429 ) ) );

		$this->assertFalse( $this->flusher( $transport )->run() );

		$this->assertSame( 2, $this->buffer->count() );
		$this->assertStringContainsString( 'rate limiting', (string) $this->options->flush_state()['last_flush_error'] );
	}

	public function test_batch_is_split_at_the_event_cap(): void {
		$this->events->bytes_override = 1024;
		$this->fill( 2500 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		// Three batches: 1000, 1000, 500 — never one oversized POST.
		$this->assertCount( 3, $transport->calls );
		$this->assertCount( IngestTransport::MAX_EVENTS_PER_BATCH, $transport->envelope( 0 )['events'] );
		$this->assertCount( IngestTransport::MAX_EVENTS_PER_BATCH, $transport->envelope( 1 )['events'] );
		$this->assertCount( 500, $transport->envelope( 2 )['events'] );
		$this->assertSame( 0, $this->buffer->count() );
	}

	public function test_a_rejected_batch_stops_the_run_rather_than_hammering(): void {
		$this->events->bytes_override = 1024;
		$this->fill( 2500 );
		$transport = new FakeIngestTransport( array( IngestResult::http( 202 ), IngestResult::http( 500 ) ) );

		$this->flusher( $transport )->run();

		$this->assertCount( 2, $transport->calls );
		$this->assertSame( 1500, $this->buffer->count() );
	}

	public function test_a_full_buffer_drains_in_a_single_run(): void {
		// The per-run batch ceiling is sized to the buffer bound on purpose: a
		// backlog must not need an hour of cron cycles to clear, because hits
		// older than 90 minutes on arrival are re-stamped and mis-bucketed.
		$this->events->bytes_override = 1024;
		$this->fill( EventBuffer::MAX_EVENTS );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertCount( 5, $transport->calls );
		$this->assertSame( 0, $this->buffer->count() );
	}

	public function test_batch_is_halved_until_it_fits_the_body_cap(): void {
		// 700 max-length User-Agents: inside the buffer's 512KB bound (so nothing
		// is pruned) but well past the 256KB body cap, so the batch has to shrink
		// before it is sent — and the remainder must stay buffered, not vanish.
		$this->fill( 700, str_repeat( 'u', Event::MAX_FIELD_CHARS ) );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertLessThan( 700, count( $transport->envelope( 0 )['events'] ) );
		$sent = 0;
		foreach ( $transport->calls as $index => $call ) {
			$this->assertLessThanOrEqual( IngestTransport::MAX_BODY_BYTES, strlen( $call['json'] ) );
			$sent += count( $transport->envelope( $index )['events'] );
		}
		// Split, not truncated: every buffered event still goes out.
		$this->assertSame( 700, $sent );
		$this->assertSame( 0, $this->buffer->count() );
	}

	public function test_gzipped_batches_respect_the_tighter_compressed_cap(): void {
		if ( ! function_exists( 'gzencode' ) ) {
			$this->markTestSkipped( 'zlib unavailable — compression is optional in the contract and the client falls back to a raw body.' );
		}

		// Incompressible payloads: with gzip the backend applies a 64KB wire cap
		// instead of the 256KB uncompressed one, so the fit has to be measured on
		// the compressed body rather than the JSON.
		$this->fill_incompressible( 700 );
		$transport = new FakeIngestTransport( array(), true );

		$this->flusher( $transport )->run();

		$this->assertNotSame( array(), $transport->calls );
		foreach ( $transport->calls as $call ) {
			$this->assertLessThanOrEqual(
				IngestTransport::MAX_COMPRESSED_BODY_BYTES,
				strlen( (string) gzencode( $call['json'] ) )
			);
		}
	}

	public function test_dropped_count_ships_and_is_cleared_only_on_acceptance(): void {
		$this->fill( 2 );
		$this->events->add_dropped( 7 );
		$transport = new FakeIngestTransport( array( IngestResult::http( 500 ), IngestResult::http( 202 ) ) );
		$flusher   = $this->flusher( $transport );

		$flusher->run();
		$this->assertSame( 7, $transport->envelope( 0 )['dropped'] );
		$this->assertSame( 7, $this->buffer->dropped_pending(), 'A rejected batch has not reported its drops.' );

		$flusher->run();

		$this->assertSame( 7, $transport->envelope( 1 )['dropped'] );
		$this->assertSame( 0, $this->buffer->dropped_pending() );
	}

	public function test_an_unencodable_event_is_dropped_rather_than_sent_as_an_empty_body(): void {
		// Fields are recorded verbatim by design, so malformed UTF-8 can reach the
		// encoder. wp_json_encode() then returns false, and posting the resulting
		// empty body would be silent data loss: the endpoint answers 202 to an
		// empty body, which this class would read as acceptance and delete rows it
		// never actually sent.
		$this->events->append(
			new Event( 'uuid-bad', '2026-08-10T09:14:22Z', '/llms.txt', "\xB1\x31 broken", '160.79.104.10', '', 200, 4211 )
		);
		$this->fill( 1 );
		$transport = new FakeIngestTransport();

		$flusher = $this->flusher( $transport );
		$flusher->run();

		foreach ( $transport->calls as $call ) {
			$this->assertNotSame( '', $call['json'], 'An empty body must never be sent.' );
		}
		// The offending event is discarded and counted, so it neither vanishes
		// unnoticed nor wedges every later flush behind it. Clearing it costs one
		// cycle: the run stops after the discard and the healthy event behind it
		// waits for the next one.
		$this->assertSame( 1, $this->buffer->dropped_total() );
		$this->assertSame( 1, $this->buffer->count() );

		$this->assertTrue( $flusher->run() );

		$this->assertSame( 0, $this->buffer->count() );
		$this->assertCount( 1, $transport->envelope( 0 )['events'] );
	}

	public function test_page_views_ride_in_the_envelope_under_their_own_key(): void {
		$this->fill( 1 );
		$this->views->seed( self::day( 1 ), '/visiter/marche', 'browser', 42 );
		$this->views->seed( self::day( 2 ), '(other)', 'ai_browser', 3 );
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$envelope = $transport->envelope();
		$this->assertCount( 1, $envelope['events'] );
		$this->assertCount( 2, $envelope['pageViews'] );
		$record = $envelope['pageViews'][0];
		$this->assertSame( array( 'id', 'day', 'path', 'bucket', 'platform', 'count' ), array_keys( $record ) );
		$this->assertSame( self::day( 1 ), $record['day'] );
		$this->assertSame( '/visiter/marche', $record['path'] );
		$this->assertSame( 'browser', $record['bucket'] );
		$this->assertSame( 'none', $record['platform'] );
		$this->assertSame( 42, $record['count'] );
		$this->assertNotSame( '', $record['id'] );
		$this->assertSame( '(other)', $envelope['pageViews'][1]['path'] );
		$this->assertSame( array(), $this->views->rows, 'Accepted records are deleted.' );
	}

	public function test_a_referred_page_view_carries_its_platform_on_the_wire(): void {
		// The stored bucket ``browser@chatgpt`` is the counter's key, never a
		// wire value: it goes out as the plain bucket plus ``platform``. A row
		// a 0.8.x install left in the table reads as ``none``.
		$this->views->seed( self::day( 1 ), '/x', 'browser@chatgpt', 2 );
		$this->views->seed( self::day( 1 ), '/x', 'browser', 1 );
		$this->views->seed( self::day( 1 ), '(other)', 'browser@perplexity', 4 );
		$this->views->seed( self::day( 1 ), '/x', 'ai_browser', 5 );
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$sent = array_map(
			fn( $record ) => array( $record['path'], $record['bucket'], $record['platform'], $record['count'] ),
			$transport->envelope()['pageViews']
		);
		$this->assertSame(
			array(
				array( '/x', 'browser', 'chatgpt', 2 ),
				array( '/x', 'browser', 'none', 1 ),
				array( '(other)', 'browser', 'perplexity', 4 ),
				array( '/x', 'ai_browser', 'none', 5 ),
			),
			$sent
		);
	}

	public function test_an_envelope_without_page_views_carries_no_page_view_key(): void {
		$this->fill( 1 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertArrayNotHasKey( 'pageViews', $transport->envelope() );
	}

	public function test_only_closed_days_are_sent(): void {
		$this->views->seed( self::day( 0 ), '/today', 'browser', 5 );
		$this->views->seed( self::day( 1 ), '/yesterday', 'browser', 7 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertSame( array( '/yesterday' ), array_column( $transport->envelope()['pageViews'], 'path' ) );
		$this->assertSame( array( '/today' ), array_column( $this->views->rows, 'path' ), "Today's counter keeps counting." );
		$this->assertNull( array_values( $this->views->rows )[0]['record_id'], "Today's counter is not sealed." );
	}

	public function test_a_page_view_only_flush_is_sent_with_an_empty_event_list(): void {
		$this->views->seed( self::day( 1 ), '/visiter', 'browser', 9 );
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$this->assertCount( 1, $transport->calls );
		$this->assertSame( array(), $transport->envelope()['events'] );
		$this->assertCount( 1, $transport->envelope()['pageViews'] );
		$this->assertGreaterThan( 0, (int) $this->options->flush_state()['last_flush_ok'] );
	}

	public function test_nothing_to_send_when_only_today_is_counted(): void {
		$this->views->seed( self::day( 0 ), '/today', 'browser', 5 );
		$transport = new FakeIngestTransport();

		$this->assertFalse( $this->flusher( $transport )->run() );

		$this->assertSame( array(), $transport->calls );
	}

	public function test_page_views_are_deleted_only_after_acceptance_and_retried_with_the_same_ids(): void {
		$this->views->seed( self::day( 1 ), '/a', 'browser', 2 );
		$this->views->seed( self::day( 3 ), '/b', 'ai_browser', 1 );
		$transport = new FakeIngestTransport(
			array( IngestResult::error( 'cURL error 28: Operation timed out' ), IngestResult::http( 500 ), IngestResult::http( 202 ) )
		);
		$flusher   = $this->flusher( $transport );

		$this->assertFalse( $flusher->run() );
		$this->assertCount( 2, $this->views->rows, 'A failed flush keeps every record.' );
		$this->assertFalse( $flusher->run() );
		$this->assertTrue( $flusher->run() );

		$this->assertCount( 3, $transport->calls );
		$first = array_column( $transport->envelope( 0 )['pageViews'], 'id' );
		$this->assertCount( 2, array_unique( $first ) );
		$this->assertSame( $first, array_column( $transport->envelope( 1 )['pageViews'], 'id' ) );
		$this->assertSame( $first, array_column( $transport->envelope( 2 )['pageViews'], 'id' ) );
		$this->assertSame( $transport->calls[0]['json'], $transport->calls[2]['json'], 'The retry is byte-identical.' );
		$this->assertSame( array(), $this->views->rows );
	}

	public function test_days_the_backend_would_drop_are_pruned_not_sent(): void {
		$this->views->seed( self::day( 7 ), '/last-accepted-day', 'browser', 1 );
		$this->views->seed( self::day( 8 ), '/too-old', 'browser', 1 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertSame( array( '/last-accepted-day' ), array_column( $transport->envelope()['pageViews'], 'path' ) );
		$this->assertSame( array(), $this->views->rows );
	}

	public function test_page_views_are_split_at_the_record_cap(): void {
		for ( $i = 0; $i < IngestTransport::MAX_PAGE_VIEWS_PER_BATCH + 10; $i++ ) {
			$this->views->seed( self::day( 1 ), '/p' . $i );
		}
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertCount( 2, $transport->calls );
		$this->assertCount( IngestTransport::MAX_PAGE_VIEWS_PER_BATCH, $transport->envelope( 0 )['pageViews'] );
		$this->assertCount( 10, $transport->envelope( 1 )['pageViews'] );
		$this->assertSame( array(), $this->views->rows );
	}

	public function test_page_view_bytes_count_toward_the_body_cap(): void {
		// 2000 records at the path cap are ~1MB of JSON: far past the 256KB body
		// cap on their own, so the batch has to halve — and nothing is lost.
		for ( $i = 0; $i < IngestTransport::MAX_PAGE_VIEWS_PER_BATCH; $i++ ) {
			$this->views->seed( self::day( 1 ), '/' . str_pad( (string) $i, 511, 'x' ) );
		}
		$transport = new FakeIngestTransport();
		$flusher   = $this->flusher( $transport );

		// Split, not truncated: a run sends at most five batches, and whatever
		// does not fit waits for the next one.
		for ( $run = 0; $run < 3 && array() !== $this->views->rows; $run++ ) {
			$flusher->run();
		}

		$sent = 0;
		foreach ( $transport->calls as $index => $call ) {
			$this->assertLessThanOrEqual( IngestTransport::MAX_BODY_BYTES, strlen( $call['json'] ) );
			$sent += count( $transport->envelope( $index )['pageViews'] );
		}
		$this->assertGreaterThan( 1, count( $transport->calls ) );
		$this->assertSame( IngestTransport::MAX_PAGE_VIEWS_PER_BATCH, $sent );
		$this->assertSame( array(), $this->views->rows );
	}

	public function test_an_oversized_envelope_sheds_page_views_before_events(): void {
		// Events are time-sensitive (re-stamped past 90 minutes); closed-day
		// page views keep for a week. So page views give way first.
		$this->fill( 10 );
		for ( $i = 0; $i < IngestTransport::MAX_PAGE_VIEWS_PER_BATCH; $i++ ) {
			$this->views->seed( self::day( 1 ), '/' . str_pad( (string) $i, 511, 'x' ) );
		}
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$first = $transport->envelope( 0 );
		$this->assertCount( 10, $first['events'], 'Every event goes in the first batch.' );
		$this->assertLessThan( IngestTransport::MAX_PAGE_VIEWS_PER_BATCH, count( $first['pageViews'] ) );
		$this->assertGreaterThan( 0, count( $first['pageViews'] ) );
		$this->assertLessThanOrEqual( IngestTransport::MAX_BODY_BYTES, strlen( $transport->calls[0]['json'] ) );
	}

	public function test_an_unencodable_event_does_not_shrink_the_page_view_slice(): void {
		// The failure is the event's, so only events are halved to isolate it.
		$this->fill( 1 );
		$this->events->append(
			new Event( 'uuid-bad', '2026-08-10T09:14:22Z', '/llms.txt', "\xB1\x31 broken", '160.79.104.10', '', 200, 4211 )
		);
		for ( $i = 0; $i < 10; $i++ ) {
			$this->views->seed( self::day( 1 ), '/p' . $i );
		}
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$first = $transport->envelope( 0 );
		$this->assertCount( 1, $first['events'] );
		$this->assertCount( 10, $first['pageViews'] );
	}

	public function test_at_schema_version_1_events_flush_and_page_views_are_left_alone(): void {
		// The counter table arrives with v2. Until it does — an auto-update not
		// yet healed, or a CREATE that failed for that table only — agent
		// traffic must keep flowing and the counter path must touch nothing.
		$this->store[ Schema::VERSION_OPTION ] = 1;
		$this->fill( 2 );
		$this->views->seed( self::day( 1 ), '/yesterday' );
		$this->views->seed( self::day( 30 ), '/ancient' );
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$this->assertCount( 2, $transport->envelope()['events'] );
		$this->assertArrayNotHasKey( 'pageViews', $transport->envelope() );
		$this->assertCount( 2, $this->views->rows, 'Neither pruned nor deleted.' );
		$this->assertSame( 0, $this->views->sealed_count(), 'Nothing sealed.' );
	}

	public function test_a_count_past_the_backend_ceiling_is_clamped(): void {
		$this->views->seed( self::day( 1 ), '/viral', 'browser', 20000000 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertSame( 10000000, $transport->envelope()['pageViews'][0]['count'] );
	}

	public function test_an_empty_buffer_sends_nothing(): void {
		$transport = new FakeIngestTransport();

		$this->assertFalse( $this->flusher( $transport )->run() );

		$this->assertSame( array(), $transport->calls );
	}

	public function test_a_held_lock_makes_a_concurrent_run_a_no_op(): void {
		$this->fill( 2 );
		$this->store[ Flusher::LOCK_OPTION ] = time();
		$transport                           = new FakeIngestTransport();

		$this->assertFalse( $this->flusher( $transport )->run() );

		$this->assertSame( array(), $transport->calls );
		$this->assertSame( 2, $this->buffer->count() );
	}

	public function test_an_abandoned_lock_is_reclaimed(): void {
		$this->fill( 2 );
		$this->store[ Flusher::LOCK_OPTION ] = time() - 3600;
		$transport                           = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$this->assertCount( 1, $transport->calls );
	}

	public function test_the_lock_is_released_after_a_run(): void {
		$this->fill( 1 );

		$this->flusher( new FakeIngestTransport() )->run();

		$this->assertArrayNotHasKey( Flusher::LOCK_OPTION, $this->store );
	}

	public function test_nothing_is_sent_without_ingest_credentials(): void {
		$this->fill( 2 );
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => '',
			'ingest_token'    => '',
		);
		$transport = new FakeIngestTransport();

		$this->assertFalse( $this->flusher( $transport )->run() );

		$this->assertSame( array(), $transport->calls );
	}

	public function test_nothing_is_sent_when_the_buffer_table_is_missing(): void {
		$this->fill( 2 );
		unset( $this->store[ Schema::VERSION_OPTION ] );
		$transport = new FakeIngestTransport();

		$this->assertFalse( $this->flusher( $transport )->run() );

		$this->assertSame( array(), $transport->calls );
	}

	public function test_site_events_ride_under_their_own_key_without_an_ip(): void {
		$this->fill( 1 );
		$this->events->append( Event::site( 'site-1', '2026-10-15T10:00:00Z', '/visiter/musees', 'ChatGPT-User/1.0', 200, 15320, true, 'chatgpt' ) );
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$envelope = $transport->envelope();
		$this->assertCount( 1, $envelope['events'] );
		$this->assertSame(
			array(
				array(
					'id'       => 'site-1',
					'ts'       => '2026-10-15T10:00:00Z',
					'path'     => '/visiter/musees',
					'ua'       => 'ChatGPT-User/1.0',
					'status'   => 200,
					'bytes'    => 15320,
					'verified' => true,
					'vendor'   => 'chatgpt',
				),
			),
			$envelope['siteEvents']
		);
		$this->assertSame( 0, $this->buffer->count() );
	}

	public function test_an_envelope_without_site_events_carries_no_site_event_key(): void {
		$this->fill( 1 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertArrayNotHasKey( 'siteEvents', $transport->envelope() );
		$this->assertArrayNotHasKey( 'captureContext', $transport->envelope(), 'No context provider, no block.' );
	}

	public function test_capture_context_goes_with_every_batch(): void {
		$this->fill( 2 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport, $this->context() )->run();

		$this->assertSame(
			array(
				'siteCapture'        => true,
				'pageViewCounter'    => false,
				'pageCache'          => null,
				'pageCacheExclusion' => false,
				'cdnMarkers'         => array( 'cf-ray' ),
				'rangesVersion'      => null,
				'rangesFetchedAt'    => null,
				'servedVersion'      => null,
			),
			$transport->envelope()['captureContext']
		);
	}

	public function test_an_empty_buffer_sends_one_context_only_envelope(): void {
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport, $this->context() )->run() );

		$this->assertCount( 1, $transport->calls );
		$envelope = $transport->envelope();
		$this->assertSame( array(), $envelope['events'] );
		$this->assertArrayNotHasKey( 'siteEvents', $envelope );
		$this->assertArrayNotHasKey( 'pageViews', $envelope );
		$this->assertTrue( $envelope['captureContext']['siteCapture'] );
		$this->assertArrayNotHasKey( CaptureContext::MARKERS_OPTION, $this->store, 'Markers are cleared once accepted.' );
	}

	public function test_a_marker_recorded_during_the_post_survives_the_clear(): void {
		$transport              = new FakeIngestTransport();
		$context                = $this->context();
		$transport->during_post = static function () use ( $context ): void {
			$context->record_markers( array( 'HTTP_VIA' => '1.1 varnish' ) );
		};

		$this->assertTrue( $this->flusher( $transport, $context )->run() );

		$this->assertSame( array( 'cf-ray' ), $transport->envelope()['captureContext']['cdnMarkers'] );
		$this->assertSame( array( 'via' ), $this->store[ CaptureContext::MARKERS_OPTION ] );
	}

	public function test_a_rejected_context_only_envelope_keeps_the_markers(): void {
		$transport = new FakeIngestTransport( array( IngestResult::http( 500 ) ) );

		$this->assertFalse( $this->flusher( $transport, $this->context() )->run() );

		$this->assertCount( 1, $transport->calls );
		$this->assertSame( array( 'cf-ray' ), $this->store[ CaptureContext::MARKERS_OPTION ] );
	}

	public function test_a_rejected_batch_is_not_followed_by_a_context_only_envelope(): void {
		$this->fill( 1 );
		$transport = new FakeIngestTransport( array( IngestResult::http( 500 ) ) );

		$this->assertFalse( $this->flusher( $transport, $this->context() )->run() );

		$this->assertCount( 1, $transport->calls );
	}

	private function context(): CaptureContext {
		$this->store[ CaptureContext::MARKERS_OPTION ] = array( 'cf-ray' );
		return new CaptureContext( $this->options, new VendorRanges( $this->options ), static fn() => false );
	}

	/**
	 * @param IngestTransport     $transport Transport double.
	 * @param CaptureContext|null $context   Capture context provider.
	 */
	private function flusher( IngestTransport $transport, ?CaptureContext $context = null ): Flusher {
		return new Flusher( $this->buffer, $transport, $this->options, $this->views, $context );
	}

	/**
	 * A UTC day relative to today, as the counter stamps it.
	 *
	 * @param int $days_ago How many days back.
	 */
	private static function day( int $days_ago ): string {
		return gmdate( 'Y-m-d', time() - $days_ago * DAY_IN_SECONDS );
	}

	/**
	 * @param int    $count How many events.
	 * @param string $ua    User-Agent to use.
	 */
	private function fill( int $count, string $ua = 'ClaudeBot/1.0' ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->events->append(
				new Event( 'uuid-' . $i, '2026-08-10T09:14:22Z', '/llms.txt', $ua, '160.79.104.10', '', 200, 4211 )
			);
		}
	}

	/**
	 * Fill with events whose User-Agents barely compress.
	 *
	 * Seeded so the suite stays deterministic.
	 *
	 * @param int $count How many events.
	 */
	private function fill_incompressible( int $count ): void {
		mt_srand( 1234 );
		for ( $i = 0; $i < $count; $i++ ) {
			$noise = '';
			while ( strlen( $noise ) < Event::MAX_FIELD_CHARS ) {
				$noise .= base_convert( (string) mt_rand( 100000, 999999 ), 10, 36 );
			}
			$this->events->append(
				new Event(
					'uuid-' . $i,
					'2026-08-10T09:14:22Z',
					'/llms.txt',
					substr( $noise, 0, Event::MAX_FIELD_CHARS ),
					'160.79.104.10',
					'',
					200,
					4211
				)
			);
		}
		mt_srand();
	}
}
