<?php
/**
 * Contract for the per-page daily page-view counters.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * One counter per ``(UTC day, path, bucket)``, and nothing else.
 *
 * ``bucket`` is the stored bucket {@see PageViewCounter::stored_bucket()}
 * builds: ``browser`` or ``ai_browser``, or ``browser@<platform>`` for a view
 * referred by an AI answer. The store treats it as an opaque key; the flusher
 * splits it back apart.
 *
 * Extracted from the SQL for the same reason as {@see EventStore}: the rules
 * with interesting logic — the daily path cap in {@see PageViewCounter}, and
 * sealing, sending and settling in {@see Flusher} — are unit testable against an
 * in-memory double, leaving the queries to the integration suite.
 *
 * A row carries no visitor data by construction: there is no column for an IP
 * address, a User-Agent string, a referrer or an identifier of any kind.
 */
interface PageViewStore {

	/**
	 * The path every increment past the daily cap is counted under. The backend
	 * accepts this literal in place of a real path.
	 */
	public const OTHER_PATH = '(other)';

	/**
	 * Distinct real paths one UTC day may count, across every bucket and
	 * platform.
	 */
	public const MAX_PATHS_PER_DAY = 2000;

	/**
	 * Add one view to a counter, creating it at 1 if absent.
	 *
	 * Must be a single statement: the counter endpoint is hit concurrently, and
	 * a read-modify-write loses views. A counter that has already been sealed
	 * for sending is left alone.
	 *
	 * @param string $day    UTC day, ``Y-m-d``.
	 * @param string $path   Normalised page path, or {@see OTHER_PATH}.
	 * @param string $bucket Stored bucket.
	 * @return int 1 when the counter was created, 2 when an existing one was
	 *             incremented, 0 when nothing was written.
	 */
	public function increment( string $day, string $path, string $bucket ): int;

	/**
	 * Distinct real paths counted on a day, {@see OTHER_PATH} excluded.
	 *
	 * @param string $day UTC day.
	 */
	public function distinct_paths( string $day ): int;

	/**
	 * Remove the counter an increment past the cap just created, so its view
	 * can be counted under {@see OTHER_PATH} instead — but only while it still
	 * holds that single view. Returns whether it was removed.
	 *
	 * The ``views = 1`` condition is what makes the overflow atomic: if a
	 * concurrent beacon has already added to the new counter, deleting it would
	 * lose that view, so the counter is kept instead (one path over the cap,
	 * no view lost).
	 *
	 * @param string $day    UTC day.
	 * @param string $path   Page path.
	 * @param string $bucket Stored bucket.
	 */
	public function remove( string $day, string $path, string $bucket ): bool;

	/**
	 * Give every counter of a closed day a record id, if it has none yet.
	 *
	 * A closed day is final, so its counter becomes a record that is sent — and,
	 * after a failure, re-sent — under that one id. An id is never replaced.
	 *
	 * @param string $today Today's UTC day; every earlier day is closed.
	 * @param int    $limit Most counters to seal in one call.
	 * @return int How many were sealed.
	 */
	public function seal( string $today, int $limit ): int;

	/**
	 * Delete counters older than a day, sealed or not.
	 *
	 * @param string $oldest_day Oldest UTC day to keep.
	 * @return int How many were deleted.
	 */
	public function prune( string $oldest_day ): int;

	/**
	 * Sealed records waiting to be sent, oldest first.
	 *
	 * @param int $limit Maximum number of records.
	 * @return array<int, array{id:int, record_id:string, day:string, path:string, bucket:string, views:int}>
	 */
	public function sealed( int $limit ): array;

	/**
	 * How many sealed records are waiting to be sent.
	 */
	public function sealed_count(): int;

	/**
	 * Delete records by row id. Returns how many were removed.
	 *
	 * @param int[] $ids Row ids.
	 */
	public function delete( array $ids ): int;
}
