<?php
/**
 * Bounded local buffer of captured events.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Appends events and keeps the buffer inside the contract's bounds.
 *
 * Bounded at 5000 events or 512KB, whichever comes first. On overflow the
 * **oldest** events are dropped and counted: incompleteness has to be measured
 * rather than invisible, because a gap in the numbers is otherwise
 * indistinguishable from an absence of traffic. The count ships in the next
 * flush envelope and is only cleared once that envelope is accepted.
 *
 * Pruning is deliberately not run on every append. It fires on every 25th insert
 * (keyed off the auto-increment id, so it is deterministic rather than sampled)
 * and unconditionally at the start of a flush. Between prunes the buffer can sit
 * marginally over the bound; the bound is a safety valve against unbounded
 * growth, not an accounting figure.
 *
 * Those same prunes double as the fill gauge. Once one finds the buffer past
 * {@see HIGH_WATER_PERCENT}, ``append()`` says so, and the caller can ask for a
 * flush ahead of the recurring 15-minute one. Without that the buffer's own
 * bound is the site's throughput ceiling — roughly 1,500 events per interval,
 * about 1.7 requests a second — and everything above it is dropped. The gauge
 * costs nothing: the prune has already run the aggregate the answer comes from.
 */
final class EventBuffer {

	public const MAX_EVENTS = 5000;
	public const MAX_BYTES  = 524288;

	/**
	 * Prune every Nth insert.
	 */
	private const PRUNE_EVERY = 25;

	/**
	 * How full either bound has to be before a flush is worth asking for.
	 *
	 * Low enough to leave headroom for the traffic that arrives while the
	 * loopback is still in flight, high enough that an ordinary trickle of hits
	 * never asks: a quiet site keeps flushing on the 15-minute schedule alone.
	 */
	private const HIGH_WATER_PERCENT = 60;

	/**
	 * Iteration cap for byte-bound trimming.
	 *
	 * Rows are removed using an average-size estimate rather than a running
	 * total: WordPress supports MySQL 5.7, which has no window functions, and a
	 * cumulative-sum scan of the whole buffer on the serve path would cost more
	 * than the bound saves. Two or three passes converge comfortably.
	 */
	private const TRIM_PASSES = 3;

	private EventStore $store;

	/**
	 * Fill state as of the last prune. See {@see append()}.
	 *
	 * @var bool
	 */
	private bool $over_high_water = false;

	public function __construct( EventStore $store ) {
		$this->store = $store;
	}

	/**
	 * Buffer one event, pruning periodically.
	 *
	 * Returns true when this append landed on a prune that found the buffer past
	 * the high-water mark — the caller's cue to ask for an early flush. False on
	 * every other append, including failed ones, so the common path stays a
	 * single INSERT and nothing else.
	 *
	 * @param Event $event Event to append.
	 */
	public function append( Event $event ): bool {
		$id = $this->store->append( $event );
		if ( 0 === $id || 0 !== $id % self::PRUNE_EVERY ) {
			return false;
		}

		$this->prune();

		return $this->over_high_water;
	}

	/**
	 * Enforce both bounds, dropping oldest. Returns how many events were dropped.
	 */
	public function prune(): int {
		$stats   = $this->store->stats();
		$dropped = 0;

		if ( $stats['count'] > self::MAX_EVENTS ) {
			$dropped += $this->store->delete_oldest( $stats['count'] - self::MAX_EVENTS );
			$stats    = $this->store->stats();
		}

		for ( $pass = 0; $pass < self::TRIM_PASSES; $pass++ ) {
			if ( $stats['bytes'] <= self::MAX_BYTES || $stats['count'] < 1 ) {
				break;
			}
			$average  = max( 1, (int) ceil( $stats['bytes'] / $stats['count'] ) );
			$excess   = $stats['bytes'] - self::MAX_BYTES;
			$remove   = min( $stats['count'], max( 1, (int) ceil( $excess / $average ) ) );
			$dropped += $this->store->delete_oldest( $remove );
			$stats    = $this->store->stats();
		}

		if ( $dropped > 0 ) {
			$this->record_dropped( $dropped );
		}

		$this->over_high_water = self::is_over_high_water( $stats );

		return $dropped;
	}

	/**
	 * Whether a set of buffer stats is past the high-water mark on either bound.
	 *
	 * Integer arithmetic rather than a percentage of the bound: the byte bound
	 * does not divide evenly, and a float comparison here would be a rounding
	 * question with no upside.
	 *
	 * @param array{count:int, bytes:int} $stats Buffer stats.
	 */
	private static function is_over_high_water( array $stats ): bool {
		return $stats['count'] * 100 >= self::MAX_EVENTS * self::HIGH_WATER_PERCENT
			|| $stats['bytes'] * 100 >= self::MAX_BYTES * self::HIGH_WATER_PERCENT;
	}

	/**
	 * The oldest buffered events, in insertion order.
	 *
	 * @param int $limit Maximum number of events to return.
	 * @return array<int, array{id:int, event:Event}>
	 */
	public function claim( int $limit ): array {
		return $this->store->oldest( $limit );
	}

	/**
	 * Remove events that have been accepted by the ingest endpoint.
	 *
	 * @param int[] $ids Row ids.
	 */
	public function release( array $ids ): int {
		return $this->store->delete( $ids );
	}

	/**
	 * Remove events that cannot be sent, counting them as dropped.
	 *
	 * The escape hatch for an event that no batch size can fit under the wire
	 * cap. Clipped fields make that effectively impossible, but without it such
	 * an event would sit at the head of the buffer and block every later flush.
	 *
	 * @param int[] $ids Row ids.
	 */
	public function discard( array $ids ): void {
		$removed = $this->store->delete( $ids );
		if ( $removed > 0 ) {
			$this->record_dropped( $removed );
		}
	}

	/**
	 * How many events are currently buffered.
	 */
	public function count(): int {
		$stats = $this->store->stats();
		return $stats['count'];
	}

	/**
	 * Drops not yet reported to the backend.
	 */
	public function dropped_pending(): int {
		return $this->store->dropped()['pending'];
	}

	/**
	 * Drops over the life of the install — what the settings screen shows.
	 */
	public function dropped_total(): int {
		return $this->store->dropped()['total'];
	}

	/**
	 * Clear the drops carried by an accepted envelope.
	 *
	 * Subtracts rather than zeroes: a prune may have incremented the counter
	 * while the flush was in flight, and those drops still need reporting.
	 *
	 * @param int $shipped Number of drops the accepted envelope reported.
	 */
	public function clear_dropped( int $shipped ): void {
		$this->store->settle_dropped( $shipped );
	}

	/**
	 * Count dropped events against both the pending and the lifetime totals.
	 *
	 * In the store, not the plugin's state option: prunes run on the serve path,
	 * concurrently, and a read-modify-write of a shared option there loses
	 * increments in exactly the burst that produced them — and can put back
	 * whatever a sync in another request wrote meanwhile.
	 *
	 * @param int $count Events dropped.
	 */
	private function record_dropped( int $count ): void {
		$this->store->add_dropped( $count );
	}
}
