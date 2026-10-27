<?php
/**
 * Contract for the local event buffer's storage.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Append-only, FIFO storage for buffered capture events.
 *
 * Extracted from the SQL so bound enforcement ({@see EventBuffer}) and flush
 * behaviour ({@see Flusher}) — the parts with interesting logic — are unit
 * testable against an in-memory double, leaving only the queries themselves to
 * the integration suite.
 */
interface EventStore {

	/**
	 * Append one event. Returns its row id, or 0 when the write failed.
	 *
	 * Implementations must write in a single statement: concurrent served
	 * requests are the normal case, and a read-modify-write loses events.
	 *
	 * @param Event $event Event to buffer.
	 */
	public function append( Event $event ): int;

	/**
	 * Row count and total estimated wire size of the buffer.
	 *
	 * @return array{count:int, bytes:int}
	 */
	public function stats(): array;

	/**
	 * The oldest buffered events, in insertion order.
	 *
	 * @param int $limit Maximum number of events to return.
	 * @return array<int, array{id:int, event:Event}>
	 */
	public function oldest( int $limit ): array;

	/**
	 * Delete the given row ids. Returns the number of rows removed.
	 *
	 * @param int[] $ids Row ids.
	 */
	public function delete( array $ids ): int;

	/**
	 * Delete the oldest N rows. Returns the number of rows removed.
	 *
	 * @param int $count How many rows to drop.
	 */
	public function delete_oldest( int $count ): int;

	/**
	 * Count events lost to overflow against both the pending and the lifetime
	 * totals.
	 *
	 * Must change the stored count in place, never read-modify-write it: drops
	 * are counted on the serve path by concurrent requests, in exactly the burst
	 * that produced them.
	 *
	 * @param int $count Events dropped.
	 */
	public function add_dropped( int $count ): void;

	/**
	 * Clear the drops an accepted envelope reported: subtract from the pending
	 * count, never below zero. The lifetime total is not rewound.
	 *
	 * @param int $shipped Drops the envelope reported.
	 */
	public function settle_dropped( int $shipped ): void;

	/**
	 * The pending and lifetime drop counts.
	 *
	 * @return array{pending:int, total:int}
	 */
	public function dropped(): array;
}
