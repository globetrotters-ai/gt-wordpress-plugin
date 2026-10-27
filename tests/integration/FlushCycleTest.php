<?php
/**
 * Integration: the flush cycle against the real buffer table.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Analytics\Flusher;
use Globetrotters\AiPresence\Analytics\IngestResult;
use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Analytics\MysqlPageViewStore;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeIngestTransport;
use WP_UnitTestCase;

/**
 * Drives claim → send → settle against real rows and a real option store, with
 * only the HTTP call faked. The unit suite covers the same rules against an
 * in-memory store; this proves the SQL round-trip holds them up.
 */
final class FlushCycleTest extends WP_UnitTestCase {

	private Options $options;
	private EventBuffer $buffer;

	public function set_up(): void {
		parent::set_up();
		( new Schema() )->install();
		$this->clear_buffer();
		$this->clear_page_views();

		$this->options = new Options();
		$this->options->update_settings(
			array(
				'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
				'ingest_token'    => 'tok_integration',
			)
		);
		delete_option( Options::FLUSH_STATE );
		delete_option( MysqlEventStore::DROPPED_PENDING_OPTION );
		delete_option( MysqlEventStore::DROPPED_TOTAL_OPTION );
		$this->buffer = new EventBuffer( new MysqlEventStore() );
		delete_option( Flusher::LOCK_OPTION );
	}

	public function tear_down(): void {
		$this->clear_buffer();
		$this->clear_page_views();
		delete_option( Flusher::LOCK_OPTION );
		parent::tear_down();
	}

	public function test_the_flush_event_is_scheduled_when_credentials_are_present(): void {
		// boot() registers the scheduler; the schedule itself must exist too, or
		// wp_schedule_event silently refuses the recurrence.
		$this->assertNotFalse( has_action( FlushScheduler::HOOK ) );
		$this->assertArrayHasKey( FlushScheduler::SCHEDULE, wp_get_schedules() );
		$this->assertSame( 900, wp_get_schedules()[ FlushScheduler::SCHEDULE ]['interval'] );
	}

	public function test_an_accepted_batch_drains_the_table(): void {
		$this->fill( 5 );
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$this->assertSame( 0, $this->buffer->count() );
		$this->assertCount( 5, $transport->envelope()['events'] );
		$this->assertGreaterThan( 0, (int) $this->options->flush_state()['last_flush_ok'] );
	}

	public function test_a_failed_flush_retries_the_same_rows_with_the_same_uuids(): void {
		$this->fill( 4 );
		$transport = new FakeIngestTransport(
			array( IngestResult::error( 'cURL error 28' ), IngestResult::http( 202 ) )
		);
		$flusher = $this->flusher( $transport );

		$this->assertFalse( $flusher->run() );
		$this->assertSame( 4, $this->buffer->count(), 'A rejected batch must stay buffered.' );

		$this->assertTrue( $flusher->run() );

		$this->assertSame( $transport->calls[0]['json'], $transport->calls[1]['json'] );
		$this->assertSame( 0, $this->buffer->count() );
	}

	public function test_the_lock_is_taken_and_released_around_a_run(): void {
		$this->fill( 1 );

		$this->flusher( new FakeIngestTransport() )->run();
		$this->assertFalse( get_option( Flusher::LOCK_OPTION ) );

		// A lock held by another process makes this run a no-op.
		add_option( Flusher::LOCK_OPTION, time(), '', false );
		$this->fill( 1 );
		$transport = new FakeIngestTransport();

		$this->assertFalse( $this->flusher( $transport )->run() );
		$this->assertSame( array(), $transport->calls );
	}

	public function test_dropped_counts_ride_along_and_clear_on_acceptance(): void {
		$this->fill( 2 );
		( new MysqlEventStore() )->add_dropped( 4 );
		$transport = new FakeIngestTransport();

		$this->flusher( $transport )->run();

		$this->assertSame( 4, $transport->envelope()['dropped'] );
		$this->assertSame( 0, $this->buffer->dropped_pending() );
	}

	public function test_at_schema_version_1_events_still_flush_and_page_views_are_untouched(): void {
		global $wpdb;
		$table     = Schema::page_views_table();
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (day, path, path_hash, bucket, views) VALUES (%s, '/x', %s, 'browser', 3)", $yesterday, md5( '/x' ) ) ); // phpcs:ignore
		$this->fill( 2 );
		update_option( Schema::VERSION_OPTION, 1 );
		$transport = new FakeIngestTransport();

		$this->assertTrue( $this->flusher( $transport )->run() );

		$this->assertCount( 2, $transport->envelope()['events'] );
		$this->assertArrayNotHasKey( 'pageViews', $transport->envelope() );
		$this->assertSame( 0, $this->buffer->count() );
		$this->assertNull( $wpdb->get_var( "SELECT record_id FROM `{$table}` WHERE path = '/x'" ), 'Not sealed.' ); // phpcs:ignore
		$this->assertSame( '3', $wpdb->get_var( "SELECT views FROM `{$table}` WHERE path = '/x'" ), 'Not deleted.' ); // phpcs:ignore
		update_option( Schema::VERSION_OPTION, Schema::VERSION );
	}

	public function test_closed_days_are_sent_once_sealed_and_retried_under_the_same_ids(): void {
		$store     = new MysqlPageViewStore();
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$store->increment( $yesterday, '/visiter/marche', 'browser' );
		$store->increment( $yesterday, '/visiter/marche', 'browser' );
		$store->increment( $yesterday, '/', 'ai_browser' );
		$store->increment( $yesterday, '/visiter/marche', 'browser@chatgpt' );
		$store->increment( $today, '/still-counting', 'browser' );
		$transport = new FakeIngestTransport(
			array( IngestResult::error( 'cURL error 28' ), IngestResult::http( 202 ) )
		);
		$flusher = $this->flusher( $transport );

		$this->assertFalse( $flusher->run() );
		$this->assertSame( 3, $store->sealed_count(), 'A failed flush keeps the sealed records.' );
		$this->assertTrue( $flusher->run() );

		$this->assertSame( $transport->calls[0]['json'], $transport->calls[1]['json'], 'Same ids, same bytes.' );
		$sent = $transport->envelope( 1 )['pageViews'];
		$this->assertSame( array( 'id', 'day', 'path', 'bucket', 'platform', 'count' ), array_keys( $sent[0] ) );
		$this->assertSame( array( '/visiter/marche', '/', '/visiter/marche' ), array_column( $sent, 'path' ) );
		$this->assertSame( array( 'browser', 'ai_browser', 'browser' ), array_column( $sent, 'bucket' ) );
		$this->assertSame( array( 'none', 'none', 'chatgpt' ), array_column( $sent, 'platform' ) );
		$this->assertSame( array( 2, 1, 1 ), array_column( $sent, 'count' ) );
		$this->assertSame( array( $yesterday, $yesterday, $yesterday ), array_column( $sent, 'day' ) );
		$this->assertSame( array(), $transport->envelope( 1 )['events'], 'A page-view-only flush is sent.' );
		$this->assertSame( 0, $store->sealed_count(), 'Accepted records are deleted.' );
		$this->assertSame( 2, $store->increment( $today, '/still-counting', 'browser' ), "Today's counter is untouched and still counting." );
	}

	/**
	 * @param FakeIngestTransport $transport Transport double.
	 */
	private function flusher( FakeIngestTransport $transport ): Flusher {
		return new Flusher( $this->buffer, $transport, $this->options, new MysqlPageViewStore() );
	}

	/**
	 * @param int $count How many events to buffer.
	 */
	private function fill( int $count ): void {
		$store = new MysqlEventStore();
		for ( $i = 0; $i < $count; $i++ ) {
			$store->append(
				new Event( wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), '/llms.txt', 'ClaudeBot/1.0', '160.79.104.10', '', 200, 4211 )
			);
		}
	}

	/**
	 * Empty the page-view table.
	 */
	private function clear_page_views(): void {
		global $wpdb;
		$table = Schema::page_views_table();
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore
	}

	/**
	 * Empty the buffer table.
	 */
	private function clear_buffer(): void {
		global $wpdb;
		$table = Schema::table();
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore
	}
}
