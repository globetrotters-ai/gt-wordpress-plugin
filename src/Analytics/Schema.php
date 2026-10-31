<?php
/**
 * Buffer table schema + install/upgrade.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Owns the ``{prefix}gtaip_events`` buffer table and the
 * ``{prefix}gtaip_page_views`` counter table.
 *
 * The buffer is a dedicated table rather than a serialised option: served
 * artefact requests are concurrent by nature, and a read-modify-write against a
 * single blob loses events under exactly the traffic this feature exists to
 * measure. One row per event means one INSERT per hit, which races safely. The
 * counters follow the same rule: one single-statement upsert per page view.
 *
 * The tables are created on demand rather than at activation: capture is gated
 * on ingest credentials being present, so an install that never pastes them
 * would carry empty tables forever. {@see \Globetrotters\AiPresence\Plugin::ensure_reporting_storage()}
 * owns that decision and calls in from four places — the settings save (or a
 * WP-CLI ``wp option update``), ``admin_init`` as a safety net, the flush cron
 * just before each flush, and activation for a deactivate/reactivate cycle on
 * an install that already reports. The ``admin_init`` and cron paths matter
 * because the plugin ships its own update channel: a plugin auto-update does
 * not fire ``register_activation_hook``, so a schema bump would otherwise never
 * reach an upgraded install — and the cron path reaches one nobody logs into.
 *
 * Installation is version-gated through the ``gtaip_db_version`` option, and
 * readiness is **per table**, from the version that created it:
 * {@see is_ready()} (the event buffer, version ≥ 1) gates every event read and
 * write, {@see page_views_ready()} (the counters, version ≥ 2) every page-view
 * one. A database user without CREATE rights therefore degrades to "no
 * reporting", never to a broken site — and a counter table that is missing,
 * mid-upgrade or because its CREATE failed, never stops agent-traffic
 * reporting, which only ever needed the buffer.
 *
 * Both tables are installed together: a site that reports gets the counter
 * table too, empty unless page-view counting is on, so switching counting on
 * needs no schema step of its own.
 */
final class Schema {

	/**
	 * 1: the event buffer. 2: adds the page-view counters. 3: site events in the
	 * buffer (``kind``, ``verified``, ``vendor``; ``path`` widened to 512,
	 * ``ip`` and ``bytes`` nullable). 4: the counters' ``bucket`` widened to 48,
	 * to hold ``bucket@platform`` for a view referred by an AI answer. 5: the
	 * buffer's ``signature``, a signed request's Web Bot Auth headers.
	 */
	public const VERSION        = 5;
	public const VERSION_OPTION = 'gtaip_db_version';

	/**
	 * The counters' ``bucket`` column from version 4: ``ai_browser`` (10), ``@``
	 * and a 32-character platform (43), with room to spare.
	 */
	private const BUCKET_COLUMN_TYPE = 'varchar(48)';

	/**
	 * Fully-qualified buffer table name for the current site.
	 *
	 * A table identifier cannot go through ``$wpdb->prepare()``: ``%s`` quotes it
	 * as a string literal, and the ``%i`` identifier placeholder needs WP 6.2
	 * while this plugin supports 6.0. So the name is constrained here instead — a
	 * hard-coded suffix on a prefix reduced to MySQL's unquoted-identifier
	 * charset. Nothing external ever feeds it, and this is the guarantee every
	 * call site that interpolates the result relies on.
	 */
	public static function table(): string {
		return self::prefixed( 'gtaip_events' );
	}

	/**
	 * Fully-qualified page-view counter table name for the current site.
	 *
	 * Constrained exactly as {@see table()} is, for the same reason.
	 */
	public static function page_views_table(): string {
		return self::prefixed( 'gtaip_page_views' );
	}

	/**
	 * Whether the event buffer table is installed — it has existed since
	 * version 1.
	 *
	 * Static because every layer that touches the buffer consults it —
	 * including {@see MysqlEventStore}, so no query can ever be aimed at a table
	 * that isn't there. The option is autoloaded, so this costs nothing on the
	 * serve path.
	 *
	 * Deliberately not tied to the current version: an install that auto-updated
	 * to a new schema, or whose counter table failed to create, still has a
	 * working buffer, and agent-traffic capture and flushing carry on with it.
	 */
	public static function is_ready(): bool {
		return self::stored_version() >= 1;
	}

	/**
	 * Whether the page-view counter table is installed (version 2 onwards).
	 *
	 * Gates every page-view read and write — {@see MysqlPageViewStore},
	 * {@see PageViewCounter} and the flusher's page-view path — and nothing else.
	 */
	public static function page_views_ready(): bool {
		return self::stored_version() >= 2;
	}

	/**
	 * Whether the buffer can hold site events (version 3 onwards). Gates every
	 * site-event write; artefact capture never depends on it.
	 */
	public static function site_events_ready(): bool {
		return self::stored_version() >= 3;
	}

	/**
	 * Whether the counters can hold a referral platform (version 4 onwards).
	 *
	 * Before it, ``bucket`` is 16 characters and ``browser@perplexity`` would be
	 * refused or truncated, so {@see PageViewCounter} counts a referred view as
	 * ``none`` until the upgrade lands rather than lose or corrupt it.
	 */
	public static function page_view_platforms_ready(): bool {
		return self::stored_version() >= 4;
	}

	/**
	 * Whether the buffer can hold a signed request's headers (version 5
	 * onwards). Before it an event is buffered without them.
	 */
	public static function signatures_ready(): bool {
		return self::stored_version() >= 5;
	}

	/**
	 * Whether the stored schema is the one this code installs.
	 */
	public static function is_current(): bool {
		return self::VERSION === self::stored_version();
	}

	/**
	 * Create or upgrade the tables when the recorded version is out of date.
	 *
	 * Cheap enough to call on every admin request: one autoloaded option read
	 * when already current.
	 */
	public function ensure_installed(): void {
		if ( self::is_current() ) {
			return;
		}
		$this->install();
	}

	/**
	 * Run ``dbDelta`` for both tables and record the schema version.
	 *
	 * Stamps the highest version whose tables are actually there afterwards, so
	 * a failed CREATE never points a query at a missing table: no buffer means
	 * no version at all (reporting off), a buffer without the counter table
	 * means version 1 (agent traffic reported, page views inert), both means the
	 * current version. A version short of current is retried on the next
	 * {@see ensure_installed()}. ``dbDelta`` only ever adds: an upgrade from
	 * version 1 creates the counter table and leaves the buffer, and whatever it
	 * holds, alone.
	 */
	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$events  = self::table();
		$views   = self::page_views_table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace- and case-sensitive: two spaces after PRIMARY KEY,
		// lowercase types, one field per line, no spaces inside a key's column
		// list. Do not reformat.
		$sql = array(
			"CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) NOT NULL,
			ts char(20) NOT NULL,
			path varchar(512) NOT NULL,
			ua varchar(512) NOT NULL DEFAULT '',
			ip varchar(45) DEFAULT NULL,
			referer varchar(512) NOT NULL DEFAULT '',
			status smallint(5) unsigned NOT NULL DEFAULT 200,
			bytes int(10) unsigned DEFAULT NULL,
			size_bytes smallint(5) unsigned NOT NULL DEFAULT 0,
			kind varchar(8) NOT NULL DEFAULT 'artefact',
			verified tinyint(1) DEFAULT NULL,
			vendor varchar(32) DEFAULT NULL,
			signature text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id)
		) {$collate};",
			// One row per (UTC day, bucket, path). ``bucket`` is ``browser`` or
			// ``ai_browser``, or ``browser@<platform>`` for a view referred by an
			// AI answer, so a day's rows written before the platform existed keep
			// counting under the same key. The unique key is on the path's
			// md5 rather than the path: a 512-character utf8mb4 column is past
			// InnoDB's index-prefix limit on older row formats, and a prefix index
			// would merge two long paths that share one. md5 is also
			// case-sensitive, as paths are. No column for an IP, a User-Agent, a
			// referrer or any identifier — there is nowhere to put one.
			// ``record_id`` stays NULL while the day is open and is assigned once,
			// when it closes; see MysqlPageViewStore::seal().
			"CREATE TABLE {$views} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			path varchar(512) NOT NULL,
			path_hash char(32) NOT NULL,
			bucket varchar(48) NOT NULL,
			views int(10) unsigned NOT NULL DEFAULT 0,
			record_id char(36) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY day_bucket_path (day,bucket,path_hash),
			KEY record_id (record_id)
		) {$collate};",
		);

		dbDelta( $sql );

		if ( ! $this->table_exists( $events ) ) {
			return;
		}
		// Autoloaded: read on every served artefact request to decide whether
		// capture is possible at all.
		$version = 1;
		if ( $this->table_exists( $views ) ) {
			$version = 2;
			$this->relax_site_event_columns( $events );
			// Version 3 only once the site-event columns really exist and accept
			// a NULL: an ALTER that failed must leave site capture off, not aim
			// inserts at a column that is not there or refuses the row.
			if (
				$this->column_exists( $events, 'kind' )
				&& $this->column_is_nullable( $events, 'ip' )
				&& $this->column_is_nullable( $events, 'bytes' )
			) {
				$version = 3;
				$this->widen_bucket_column( $views );
				// Version 4 only once the counters' bucket really is wide: a
				// referred view must never be aimed at a column that truncates it.
				if ( self::BUCKET_COLUMN_TYPE === $this->column_type( $views, 'bucket' ) ) {
					$version = 4;
					// Version 5 only once dbDelta() really added the column: a row
					// is never aimed at a column that is not there.
					if ( $this->column_exists( $events, 'signature' ) ) {
						$version = 5;
					}
				}
			}
		}
		update_option( self::VERSION_OPTION, $version, true );
	}

	/**
	 * The recorded schema version, 0 when nothing is installed.
	 */
	private static function stored_version(): int {
		$stored = get_option( self::VERSION_OPTION, 0 );
		return is_numeric( $stored ) ? (int) $stored : 0;
	}

	/**
	 * Drop both tables and forget the recorded version.
	 */
	public function uninstall(): void {
		global $wpdb;
		foreach ( array( self::table(), self::page_views_table() ) as $name ) {
			// Identifier, not a value: see table() for why it cannot be a
			// placeholder. esc_sql() is a no-op on that charset, kept so static
			// analysis can see it.
			$table = esc_sql( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * A table name on this site's prefix, reduced to MySQL's unquoted-identifier
	 * charset.
	 *
	 * @param string $suffix Hard-coded table suffix.
	 */
	private static function prefixed( string $suffix ): string {
		global $wpdb;
		return preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix ) . $suffix;
	}

	/**
	 * Make ``ip`` and ``bytes`` nullable on a buffer created before version 3.
	 *
	 * The dbDelta() call only compares a column's type and quoted default, so it never
	 * drops a ``NOT NULL``: without this, an upgraded buffer refuses every site
	 * row (``ip`` is always NULL, ``bytes`` whenever Content-Length is unknown).
	 *
	 * @param string $table Fully-qualified table name (constrained, see table()).
	 */
	private function relax_site_event_columns( string $table ): void {
		if ( $this->column_is_nullable( $table, 'ip' ) && $this->column_is_nullable( $table, 'bytes' ) ) {
			return;
		}
		global $wpdb;
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY ip varchar(45) DEFAULT NULL, MODIFY bytes int(10) unsigned DEFAULT NULL" );
	}

	/**
	 * Widen the counters' ``bucket`` on a table created before version 4.
	 *
	 * The dbDelta() call already changes a column whose type differs; this is
	 * the explicit fallback for an install where it did not, as for
	 * {@see relax_site_event_columns()}. Widening a varchar keeps every row.
	 *
	 * @param string $table Fully-qualified table name (constrained, see table()).
	 */
	private function widen_bucket_column( string $table ): void {
		if ( self::BUCKET_COLUMN_TYPE === $this->column_type( $table, 'bucket' ) ) {
			return;
		}
		global $wpdb;
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY bucket varchar(48) NOT NULL" );
	}

	/**
	 * A column's lower-cased type (``varchar(48)``), or '' when it is missing.
	 *
	 * @param string $table  Fully-qualified table name (constrained, see table()).
	 * @param string $column Hard-coded column name.
	 */
	private function column_type( string $table, string $column ): string {
		global $wpdb;
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A );
		return is_array( $row ) ? strtolower( (string) ( $row['Type'] ?? '' ) ) : '';
	}

	/**
	 * Whether a column exists on a table and accepts NULL.
	 *
	 * @param string $table  Fully-qualified table name (constrained, see table()).
	 * @param string $column Hard-coded column name.
	 */
	private function column_is_nullable( string $table, string $column ): bool {
		global $wpdb;
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A );
		return is_array( $row ) && 'YES' === ( $row['Null'] ?? '' );
	}

	/**
	 * Whether a column exists on a table.
	 *
	 * @param string $table  Fully-qualified table name (constrained, see table()).
	 * @param string $column Hard-coded column name.
	 */
	private function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
		return is_string( $found ) && $found === $column;
	}

	/**
	 * Whether a table exists in the database.
	 *
	 * @param string $table Fully-qualified table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return is_string( $found ) && $found === $table;
	}
}
