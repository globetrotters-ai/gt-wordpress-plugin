<?php
/**
 * In-memory event store for unit tests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Support;

use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\EventStore;

/**
 * Array-backed {@see EventStore} with the same FIFO + auto-increment semantics
 * as the real table, so buffer bounds and flush behaviour can be exercised
 * without a database.
 */
final class FakeEventStore implements EventStore {

	/**
	 * @var array<int, Event>
	 */
	public array $rows = array();

	public int $next_id = 1;

	/**
	 * When true, every append reports failure (a missing table, a dead DB).
	 */
	public bool $fail_append = false;

	/**
	 * Forces the reported byte total, so the event bound can be exercised in
	 * isolation. In practice the 512KB bound is the one that bites first — a
	 * realistic event is ~250 bytes on the wire, so 5000 of them are ~1.2MB —
	 * and the count bound is the secondary guard.
	 */
	public ?int $bytes_override = null;

	/**
	 * Drop counters, kept beside the rows as MysqlEventStore keeps them beside
	 * its table.
	 */
	public int $dropped_pending = 0;
	public int $dropped_total   = 0;

	public function add_dropped( int $count ): void {
		if ( $count < 1 ) {
			return;
		}
		$this->dropped_pending += $count;
		$this->dropped_total   += $count;
	}

	public function settle_dropped( int $shipped ): void {
		if ( $shipped < 1 ) {
			return;
		}
		$this->dropped_pending = max( 0, $this->dropped_pending - $shipped );
	}

	/**
	 * @return array{pending:int, total:int}
	 */
	public function dropped(): array {
		return array(
			'pending' => $this->dropped_pending,
			'total'   => $this->dropped_total,
		);
	}

	public function append( Event $event ): int {
		if ( $this->fail_append ) {
			return 0;
		}
		$id                = $this->next_id;
		$this->rows[ $id ] = $event;
		$this->next_id++;
		return $id;
	}

	/**
	 * @return array{count:int, bytes:int}
	 */
	public function stats(): array {
		$bytes = 0;
		foreach ( $this->rows as $event ) {
			$bytes += $event->size_bytes();
		}
		return array(
			'count' => count( $this->rows ),
			'bytes' => null === $this->bytes_override ? $bytes : $this->bytes_override,
		);
	}

	/**
	 * @param int $limit Maximum number of events to return.
	 * @return array<int, array{id:int, event:Event}>
	 */
	public function oldest( int $limit ): array {
		$claimed = array();
		foreach ( $this->rows as $id => $event ) {
			if ( count( $claimed ) >= $limit ) {
				break;
			}
			$claimed[] = array(
				'id'    => $id,
				'event' => $event,
			);
		}
		return $claimed;
	}

	/**
	 * @param int[] $ids Row ids.
	 */
	public function delete( array $ids ): int {
		$removed = 0;
		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ (int) $id ] ) ) {
				unset( $this->rows[ (int) $id ] );
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * @param int $count How many rows to drop.
	 */
	public function delete_oldest( int $count ): int {
		$removed = 0;
		foreach ( array_keys( $this->rows ) as $id ) {
			if ( $removed >= $count ) {
				break;
			}
			unset( $this->rows[ $id ] );
			$removed++;
		}
		return $removed;
	}
}
