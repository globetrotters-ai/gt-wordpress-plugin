<?php
/**
 * ``$wpdb``-backed page-view counters.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Stores the per-page daily counters in ``{prefix}gtaip_page_views``.
 *
 * The same rules as {@see MysqlEventStore}: every write is a single statement,
 * nothing goes through the object cache, and every method short-circuits when
 * the table is not installed, so a database user without CREATE rights never
 * sees a query against a missing table on a page view.
 *
 * Every table identifier below is {@see Schema::page_views_table()}, which is
 * constrained to MySQL's unquoted-identifier charset for the reason given at
 * {@see Schema::table()}; every value is prepared.
 */
final class MysqlPageViewStore implements PageViewStore {

	/**
	 * Add one view in one statement.
	 *
	 * ``INSERT … ON DUPLICATE KEY UPDATE`` on the ``(day, bucket, path_hash)``
	 * key, so concurrent beacons for the same page each land. MySQL reports 1
	 * affected row for an insert and 2 for an update — which is how the caller
	 * learns a new path appeared — and 0 when the update changed nothing: the
	 * counter was already sealed, which only a beacon racing midnight can hit.
	 *
	 * @param string $day    UTC day.
	 * @param string $path   Page path.
	 * @param string $bucket Stored bucket.
	 */
	public function increment( string $day, string $path, string $bucket ): int {
		global $wpdb;

		if ( ! Schema::page_views_ready() ) {
			return 0;
		}

		$table = esc_sql( Schema::page_views_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO `{$table}` (day, path, path_hash, bucket, views) VALUES (%s, %s, %s, %s, 1)
				 ON DUPLICATE KEY UPDATE views = IF( record_id IS NULL, views + 1, views )",
				$day,
				$path,
				md5( $path ),
				$bucket
			)
		);

		return false === $written ? 0 : (int) $written;
	}

	/**
	 * Distinct real paths counted on a day.
	 *
	 * @param string $day UTC day.
	 */
	public function distinct_paths( string $day ): int {
		global $wpdb;

		if ( ! Schema::page_views_ready() ) {
			return 0;
		}

		$table = esc_sql( Schema::page_views_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT path_hash) FROM `{$table}` WHERE day = %s AND path_hash <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$day,
				md5( self::OTHER_PATH )
			)
		);

		return (int) $count;
	}

	/**
	 * Remove an unsealed counter.
	 *
	 * @param string $day    UTC day.
	 * @param string $path   Page path.
	 * @param string $bucket Stored bucket.
	 */
	public function remove( string $day, string $path, string $bucket ): bool {
		global $wpdb;

		if ( ! Schema::page_views_ready() ) {
			return false;
		}

		$table = esc_sql( Schema::page_views_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE day = %s AND bucket = %s AND path_hash = %s AND record_id IS NULL AND views = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$day,
				$bucket,
				md5( $path )
			)
		);

		return 1 === $removed;
	}

	/**
	 * Assign a record id to each closed day's counter that has none.
	 *
	 * The id is minted in PHP (``wp_generate_uuid4()``) rather than by MySQL's
	 * ``UUID()``, which is unsafe under statement-based replication. Each row is
	 * stamped by its own ``UPDATE … WHERE record_id IS NULL``, so two flushes
	 * racing each other cannot both stamp one row: whichever lands second
	 * changes nothing, and the first id stands for every later retry.
	 *
	 * @param string $today Today's UTC day.
	 * @param int    $limit Most counters to seal.
	 */
	public function seal( string $today, int $limit ): int {
		global $wpdb;

		if ( $limit < 1 || ! Schema::page_views_ready() ) {
			return 0;
		}

		$table = esc_sql( Schema::page_views_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE day < %s AND record_id IS NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today,
				$limit
			)
		);

		$sealed = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sealed += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET record_id = %s WHERE id = %d AND record_id IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					wp_generate_uuid4(),
					(int) $id
				)
			);
		}

		return $sealed;
	}

	/**
	 * Delete counters older than a day.
	 *
	 * @param string $oldest_day Oldest UTC day to keep.
	 */
	public function prune( string $oldest_day ): int {
		global $wpdb;

		if ( ! Schema::page_views_ready() ) {
			return 0;
		}

		$table = esc_sql( Schema::page_views_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE day < %s", $oldest_day ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Sealed records waiting to be sent, oldest first.
	 *
	 * @param int $limit Maximum number of records.
	 * @return array<int, array{id:int, record_id:string, day:string, path:string, bucket:string, views:int}>
	 */
	public function sealed( int $limit ): array {
		global $wpdb;

		if ( $limit < 1 || ! Schema::page_views_ready() ) {
			return array();
		}

		$table = esc_sql( Schema::page_views_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, record_id, day, path, bucket, views FROM `{$table}` WHERE record_id IS NOT NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$records = array();
		foreach ( $rows as $row ) {
			$records[] = array(
				'id'        => (int) $row['id'],
				'record_id' => (string) $row['record_id'],
				'day'       => (string) $row['day'],
				'path'      => (string) $row['path'],
				'bucket'    => (string) $row['bucket'],
				'views'     => (int) $row['views'],
			);
		}

		return $records;
	}

	/**
	 * How many sealed records are waiting to be sent.
	 */
	public function sealed_count(): int {
		global $wpdb;

		if ( ! Schema::page_views_ready() ) {
			return 0;
		}

		$table = esc_sql( Schema::page_views_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE record_id IS NOT NULL" );
	}

	/**
	 * Delete records by row id.
	 *
	 * @param int[] $ids Row ids.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids || ! Schema::page_views_ready() ) {
			return 0;
		}

		$table        = esc_sql( Schema::page_views_table() );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		);

		return false === $removed ? 0 : (int) $removed;
	}
}
