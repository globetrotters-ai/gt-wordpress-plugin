<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\EventBuffer
 */
final class EventBufferTest extends TestCase {

	use WpOptions;

	private FakeEventStore $events;
	private EventBuffer $buffer;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();

		$this->events = new FakeEventStore();
		$this->buffer = new EventBuffer( $this->events );
	}

	public function test_event_bound_drops_the_oldest_and_counts_them(): void {
		// Byte bound satisfied, so this isolates the count rule. In the field the
		// 512KB bound is the one that bites first — see FakeEventStore.
		$this->events->bytes_override = 1024;
		$this->fill( EventBuffer::MAX_EVENTS + 10 );

		$dropped = $this->buffer->prune();

		$this->assertSame( 10, $dropped );
		$this->assertSame( EventBuffer::MAX_EVENTS, $this->buffer->count() );
		// Oldest-first: the survivors are the *last* 5000 appended.
		$survivors = $this->buffer->claim( 1 );
		$this->assertSame( 'evt-10', $survivors[0]['event']->id() );
	}

	public function test_dropped_events_are_counted_pending_and_lifetime(): void {
		$this->events->bytes_override = 1024;
		$this->fill( EventBuffer::MAX_EVENTS + 3 );

		$this->buffer->prune();

		$this->assertSame( 3, $this->buffer->dropped_pending() );
		$this->assertSame( 3, $this->buffer->dropped_total() );
	}

	/**
	 * Drops are counted on the serve path, concurrently, in exactly the burst
	 * that produced them. A read-modify-write of the plugin's state option there
	 * loses increments and can put back whatever a sync wrote meanwhile, so the
	 * count lives in the store, which increments it in place.
	 */
	public function test_drops_are_counted_in_the_store_never_in_plugin_state(): void {
		$this->events->bytes_override = 1024;
		$this->fill( EventBuffer::MAX_EVENTS + 3 );

		$this->buffer->prune();

		$this->assertSame(
			array(
				'pending' => 3,
				'total'   => 3,
			),
			$this->events->dropped()
		);
		$this->assertArrayNotHasKey( Options::STATE, $this->store );
	}

	public function test_byte_bound_drops_the_oldest_and_counts_them(): void {
		// Fat events (max-length UA) so the 512KB bound bites long before 5000.
		$this->fill( 1200, str_repeat( 'u', Event::MAX_FIELD_CHARS ) );
		$this->assertGreaterThan( EventBuffer::MAX_BYTES, $this->events->stats()['bytes'] );

		$dropped = $this->buffer->prune();

		$this->assertGreaterThan( 0, $dropped );
		$this->assertLessThanOrEqual( EventBuffer::MAX_BYTES, $this->events->stats()['bytes'] );
		$this->assertSame( $dropped, $this->buffer->dropped_total() );
		$this->assertSame( 1200 - $dropped, $this->buffer->count() );
	}

	public function test_prune_is_a_no_op_inside_the_bounds(): void {
		$this->fill( 10 );

		$this->assertSame( 0, $this->buffer->prune() );
		$this->assertSame( 10, $this->buffer->count() );
		$this->assertSame( 0, $this->buffer->dropped_total() );
	}

	public function test_append_prunes_on_the_boundary_and_not_on_every_hit(): void {
		$this->events->bytes_override = 1024;
		$this->fill( EventBuffer::MAX_EVENTS + 4 );

		// An append that isn't on the 25-hit boundary leaves the buffer over the
		// bound: pruning on every hit would cost an aggregate query per request.
		$this->events->next_id = 6001;
		$this->assertFalse( $this->buffer->append( $this->event( 'off-boundary' ) ) );
		$this->assertSame( EventBuffer::MAX_EVENTS + 5, $this->buffer->count() );
		$this->assertSame( 0, $this->buffer->dropped_total() );

		// The next one that does land on it trims back to the bound.
		$this->events->next_id = 6025;
		$this->assertTrue( $this->buffer->append( $this->event( 'on-boundary' ) ) );

		$this->assertSame( EventBuffer::MAX_EVENTS, $this->buffer->count() );
		$this->assertSame( 6, $this->buffer->dropped_total() );
	}

	public function test_append_asks_for_a_flush_past_the_event_high_water_mark(): void {
		// The ceiling this defends: a site busy enough to fill the buffer inside
		// one 15-minute interval drops everything above it. Asking for a flush at
		// 60% is what keeps the cadence tracking the traffic.
		$this->events->bytes_override = 1024;
		$this->fill( 3000 );

		$this->events->next_id = 3025;

		$this->assertTrue( $this->buffer->append( $this->event( 'high' ) ) );
		$this->assertSame( 0, $this->buffer->dropped_total() );
	}

	public function test_append_stays_quiet_below_the_high_water_mark(): void {
		// A quiet site rides the 15-minute schedule; an option write per hit would
		// be pure cost.
		$this->events->bytes_override = 1024;
		$this->fill( 100 );

		$this->events->next_id = 125;

		$this->assertFalse( $this->buffer->append( $this->event( 'low' ) ) );
	}

	public function test_the_byte_bound_trips_the_high_water_mark_too(): void {
		// Fat events fill 512KB long before 5000 rows, so the count alone would
		// never notice this buffer is nearly full.
		$this->events->bytes_override = intdiv( EventBuffer::MAX_BYTES * 3, 4 );
		$this->fill( 10 );

		$this->events->next_id = 25;

		$this->assertTrue( $this->buffer->append( $this->event( 'fat' ) ) );
	}

	public function test_a_failed_append_never_asks_for_a_flush(): void {
		$this->events->bytes_override = EventBuffer::MAX_BYTES;
		$this->events->fail_append    = true;

		$this->assertFalse( $this->buffer->append( $this->event( 'lost' ) ) );
	}

	public function test_clear_dropped_only_clears_what_was_reported(): void {
		$this->events->bytes_override = 1024;
		$this->fill( EventBuffer::MAX_EVENTS + 5 );
		$this->buffer->prune();

		// A flush shipped 3 of the 5; two more were counted while it was in flight.
		$this->buffer->clear_dropped( 3 );

		$this->assertSame( 2, $this->buffer->dropped_pending() );
		// The lifetime total is never rewound — it is what the admin screen shows.
		$this->assertSame( 5, $this->buffer->dropped_total() );
	}

	public function test_discard_removes_and_counts_an_unsendable_event(): void {
		$this->fill( 2 );

		$this->buffer->discard( array( 1 ) );

		$this->assertSame( 1, $this->buffer->count() );
		$this->assertSame( 1, $this->buffer->dropped_pending() );
	}

	public function test_a_failed_append_never_triggers_a_prune(): void {
		$this->events->fail_append = true;

		$this->buffer->append( $this->event( 'lost' ) );

		$this->assertSame( 0, $this->buffer->count() );
		$this->assertSame( 0, $this->buffer->dropped_total() );
	}

	/**
	 * Append N events straight into the store, bypassing periodic pruning.
	 *
	 * @param int    $count How many events.
	 * @param string $ua    User-Agent to use (drives the byte bound).
	 */
	private function fill( int $count, string $ua = 'agent' ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->events->append( $this->event( 'evt-' . $i, $ua ) );
		}
	}

	/**
	 * @param string $id Event id.
	 * @param string $ua User-Agent.
	 */
	private function event( string $id, string $ua = 'agent' ): Event {
		return new Event( $id, '2026-08-10T09:14:22Z', '/llms.txt', $ua, '160.79.104.10', '', 200, 4211 );
	}
}
