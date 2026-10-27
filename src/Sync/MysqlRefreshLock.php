<?php
/**
 * The refresh lock, as a row in the options table.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Sync;

/**
 * Holds ``gtaip_refresh_lock`` for the length of one pull.
 *
 * WP-Cron's loopback and a system cron can fire the same due refresh within
 * milliseconds of each other, and "Refresh now" can land on a running cron
 * pull: each fetched the whole set again. The lock is taken with a plain
 * ``INSERT IGNORE`` against the options table's unique key, never through
 * ``add_option()``: that consults this process's object cache first and then
 * writes ``INSERT … ON DUPLICATE KEY UPDATE``, so two processes behind
 * different caches would both "take" it. The table is the one thing every
 * lane shares.
 *
 * The row holds ``<unix time>:<token>``. A holder that died is reclaimed once
 * its time is older than {@see TTL_SECONDS}, by compare-and-swap on the exact
 * value it read, so two reclaimers cannot both win; and a release deletes the
 * row only while it still holds this process's token, so a slow holder whose
 * lock was reclaimed never frees its successor's. That holder is also fenced
 * off from publishing: {@see ArtefactSync} checks {@see still_held()} right
 * before it stores, and discards its pull when the lock has moved on.
 */
final class MysqlRefreshLock implements RefreshLock {

	public const OPTION = 'gtaip_refresh_lock';

	/**
	 * Longest a pull can plausibly take: ten fetches at a 5 s timeout, each
	 * through up to five redirects, is a little over four minutes.
	 */
	public const TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * The value this process wrote, while it holds the lock.
	 *
	 * @var string
	 */
	private string $held = '';

	public function acquire(): bool {
		global $wpdb;

		$value = time() . ':' . wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION,
				$value
			)
		);
		if ( 1 === $inserted ) {
			return $this->took( $value );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION )
		);
		if ( null === $current ) {
			// Released between the two statements: the next poll takes it.
			return false;
		}
		if ( time() - (int) $current < self::TTL_SECONDS ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$swapped = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				(string) $current
			)
		);
		return 1 === $swapped ? $this->took( $value ) : false;
	}

	public function still_held(): bool {
		if ( '' === $this->held ) {
			return false;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION )
		);
		return $this->held === $current;
	}

	public function release(): void {
		if ( '' === $this->held ) {
			return;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$this->held
			)
		);
		$this->held = '';
		wp_cache_delete( self::OPTION, 'options' );
	}

	/**
	 * Remember the value written, and report the lock taken.
	 *
	 * @param string $value Row value this process wrote.
	 */
	private function took( string $value ): bool {
		$this->held = $value;
		// Nothing reads the row through get_option(), but a cached copy would be
		// wrong from here on, so none is left behind.
		wp_cache_delete( self::OPTION, 'options' );
		return true;
	}
}
