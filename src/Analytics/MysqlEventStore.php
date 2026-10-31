<?php
/**
 * ``$wpdb``-backed event buffer storage.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Settings\Options;

/**
 * Stores buffered events in the ``{prefix}gtaip_events`` table.
 *
 * Every method is a single statement against a dedicated table — no object
 * cache, by design: the buffer is write-mostly transient data whose whole point
 * is that concurrent requests can append to it without racing, and a cached read
 * would only serve a stale count to the settings screen.
 *
 * Every event method also short-circuits when the table isn't installed, so a
 * site whose database user cannot CREATE never issues a query against a missing
 * table (and never emits a WP_DEBUG error on a served artefact request).
 *
 * The drop counters follow the same rule for the same reason. They are two
 * numbers rather than events, so they live beside the table, in two option rows
 * that are only ever changed by a single statement each.
 */
final class MysqlEventStore implements EventStore {

	/**
	 * Drop counter rows. Not autoloaded: only the flusher and the settings screen
	 * read them.
	 */
	public const DROPPED_PENDING_OPTION = 'gtaip_dropped_pending';
	public const DROPPED_TOTAL_OPTION   = 'gtaip_dropped_total';

	/**
	 * Append one event as a single INSERT.
	 *
	 * @param Event $event Event to buffer.
	 */
	public function append( Event $event ): int {
		global $wpdb;

		if ( ! Schema::is_ready() ) {
			return 0;
		}

		$row     = $event->to_row();
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' );
		if ( $event->is_site() ) {
			// A site row needs the schema-3 columns; an older table has nowhere
			// to put it, and must never receive one with an ip column filled.
			if ( ! Schema::site_events_ready() ) {
				return 0;
			}
			// wpdb writes a PHP null as SQL NULL whatever its format.
			$formats = array_merge( $formats, array( '%s', '%d', '%s' ) );
		}
		if ( array_key_exists( 'signature', $row ) ) {
			// The last column, from schema 5. Before it the event is still worth
			// buffering, without the headers: the backend counts it unsigned.
			if ( Schema::signatures_ready() ) {
				$formats[] = '%s';
			} else {
				unset( $row['signature'] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = $wpdb->insert( Schema::table(), $row, $formats );

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Row count and total estimated wire size of the buffer.
	 *
	 * @return array{count:int, bytes:int}
	 */
	public function stats(): array {
		global $wpdb;

		if ( ! Schema::is_ready() ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		// Identifier, not a value: see Schema::table() for why it cannot be a
		// placeholder and how the name is constrained instead. esc_sql() is a
		// no-op on that charset — it is here so static analysis can see it.
		$table = esc_sql( Schema::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT COUNT(*) AS c, COALESCE(SUM(size_bytes), 0) AS b FROM `{$table}`", ARRAY_A );

		if ( ! is_array( $row ) ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		return array(
			'count' => (int) $row['c'],
			'bytes' => (int) $row['b'],
		);
	}

	/**
	 * The oldest buffered events, in insertion order.
	 *
	 * @param int $limit Maximum number of events to return.
	 * @return array<int, array{id:int, event:Event}>
	 */
	public function oldest( int $limit ): array {
		global $wpdb;

		if ( $limit < 1 || ! Schema::is_ready() ) {
			return array();
		}

		// Identifier, not a value: see Schema::table() for why it cannot be a
		// placeholder and how the name is constrained instead. The value is
		// prepared; esc_sql() is a no-op here, kept so static analysis can see it.
		$table = esc_sql( Schema::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id ASC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$claimed = array();
		foreach ( $rows as $row ) {
			$claimed[] = array(
				'id'    => (int) $row['id'],
				'event' => Event::from_row( $row ),
			);
		}

		return $claimed;
	}

	/**
	 * Delete the given row ids.
	 *
	 * @param int[] $ids Row ids.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids || ! Schema::is_ready() ) {
			return 0;
		}

		// Identifier, not a value: see Schema::table(). The ids are prepared.
		$table        = esc_sql( Schema::table() );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		);

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Delete the oldest N rows.
	 *
	 * @param int $count How many rows to drop.
	 */
	public function delete_oldest( int $count ): int {
		global $wpdb;

		if ( $count < 1 || ! Schema::is_ready() ) {
			return 0;
		}

		// Identifier, not a value: see Schema::table(). The value is prepared.
		$table = esc_sql( Schema::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` ORDER BY id ASC LIMIT %d", $count ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Count dropped events against both the pending and the lifetime totals.
	 *
	 * @param int $count Events dropped.
	 */
	public function add_dropped( int $count ): void {
		if ( $count < 1 ) {
			return;
		}
		self::adjust( self::DROPPED_PENDING_OPTION, $count );
		self::adjust( self::DROPPED_TOTAL_OPTION, $count );
	}

	/**
	 * Clear the drops an accepted envelope reported.
	 *
	 * @param int $shipped Drops the envelope reported.
	 */
	public function settle_dropped( int $shipped ): void {
		if ( $shipped < 1 ) {
			return;
		}
		self::adjust( self::DROPPED_PENDING_OPTION, -$shipped );
	}

	/**
	 * The pending and lifetime drop counts, read from the rows themselves.
	 *
	 * @return array{pending:int, total:int}
	 */
	public function dropped(): array {
		return array(
			'pending' => self::counter( self::DROPPED_PENDING_OPTION ),
			'total'   => self::counter( self::DROPPED_TOTAL_OPTION ),
		);
	}

	/**
	 * Add ``$delta`` to a counter row in one statement, flooring at zero.
	 *
	 * The row is created first when absent — not with ``add_option()``, which in
	 * core is an ``INSERT … ON DUPLICATE KEY UPDATE``: two first writers racing
	 * would each overwrite the other's value. ``INSERT IGNORE`` creates the row
	 * once and is a no-op for every writer after, and the ``UPDATE`` then adds
	 * to whatever is stored, whoever wrote it.
	 *
	 * @param string $name  Counter option name.
	 * @param int    $delta Signed change.
	 */
	private static function adjust( string $name, int $delta ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				(string) self::legacy_count( $name )
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = GREATEST( 0, CAST( option_value AS SIGNED ) + %d ) WHERE option_name = %s",
				$delta,
				$name
			)
		);

		// Nothing reads these rows through get_option(), but a cached copy would be
		// wrong from here on, so none is left behind.
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * A counter's stored value, or what 0.4.0 left for it when it has no row.
	 *
	 * @param string $name Counter option name.
	 */
	private static function counter( string $name ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);

		return null === $value ? self::legacy_count( $name ) : max( 0, (int) $value );
	}

	/**
	 * The count 0.4.0 kept in the plugin's state option.
	 *
	 * Pending drops there have not been reported yet, so a counter row starts
	 * from them rather than from zero. Once the row exists it is never read
	 * again.
	 *
	 * @param string $name Counter option name.
	 */
	private static function legacy_count( string $name ): int {
		$state = get_option( Options::STATE, array() );
		$key   = self::DROPPED_PENDING_OPTION === $name ? 'dropped_pending' : 'dropped_total';
		return is_array( $state ) && isset( $state[ $key ] ) ? max( 0, (int) $state[ $key ] ) : 0;
	}
}
