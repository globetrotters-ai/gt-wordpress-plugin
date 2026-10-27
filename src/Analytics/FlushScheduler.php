<?php
/**
 * WP-Cron scheduling for the ingest flush.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Settings\Options;

/**
 * Owns the recurring flush event.
 *
 * Deliberately independent of the artefact-refresh schedule: refresh cadence is
 * a content-freshness choice the customer makes (down to weekly), while the
 * flush interval is fixed by the contract at 15 minutes. Sharing a schedule
 * would let a weekly refresh cadence sit the buffer for days, past the backend's
 * 90-minute staleness window, where hits get re-stamped to arrival time and
 * land in the wrong buckets.
 *
 * A traffic-triggered scheduler is fine here — no traffic means nothing to
 * flush — and it self-primes: ``wp_cron()`` runs on ``init``, before the
 * ``parse_request`` hook that serves the artefacts, so artefact traffic itself
 * drives the flushes. The cron spawn is a non-blocking loopback request, so the
 * served response is never waiting on it.
 *
 * {@see request_flush()} leans on exactly that. A site busy enough to fill the
 * buffer inside one interval would otherwise drop everything above ~1.7 hits a
 * second; letting a nearly-full buffer queue a one-off event means the *next*
 * artefact request spawns the loopback, so the flush cadence follows the traffic
 * instead of the clock. A quiet site never triggers it and keeps to the 15.
 */
final class FlushScheduler implements FlushRequest {

	public const HOOK     = 'gtaip_flush';
	public const SCHEDULE = 'gtaip_15min';

	/**
	 * The one-off "drain now" event.
	 *
	 * A hook of its own rather than a second {@see HOOK} at an earlier time:
	 * ``wp_schedule_single_event()`` refuses an event whose hook and args match
	 * one already scheduled within ten minutes, and the recurring flush would
	 * trip that guard nearly every time. Separating them also turns the same
	 * guard into the rate limit worth having — at most one pending immediate
	 * flush, re-armable the moment it fires.
	 */
	public const HOOK_NOW = 'gtaip_flush_now';

	private Flusher $flusher;
	private Options $options;

	public function __construct( Flusher $flusher, Options $options ) {
		$this->flusher = $flusher;
		$this->options = $options;
	}

	/**
	 * Register the cron callback and the 15-minute schedule.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::HOOK_NOW, array( $this, 'run' ) );
	}

	/**
	 * Add the flush interval alongside WordPress's built-ins.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (Globetrotters reporting)', 'globetrotters-ai-presence' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure the flush event is scheduled when reporting is configured.
	 *
	 * No-op otherwise: an install with no ingest credentials captures nothing, so
	 * it gets no recurring event either.
	 */
	public function ensure_scheduled(): void {
		if ( ! $this->options->is_reporting_configured() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Ask for a flush at the earliest opportunity.
	 *
	 * Queues a one-off event and returns; the actual flush happens in the cron
	 * loopback the next request spawns, never inline. Called from the serve path,
	 * so the cost has to stay at one autoloaded option read on the common path —
	 * hence the pending-event check before the write.
	 */
	public function request_flush(): void {
		if ( ! $this->options->is_reporting_configured() ) {
			return;
		}
		if ( false !== wp_next_scheduled( self::HOOK_NOW ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK_NOW );
	}

	/**
	 * Re-arm (or tear down) the event after a settings change.
	 */
	public function reschedule(): void {
		$this->unschedule();
		$this->ensure_scheduled();
	}

	/**
	 * Remove the scheduled events, recurring and pending one-offs alike.
	 */
	public function unschedule(): void {
		$this->clear( self::HOOK );
		$this->clear( self::HOOK_NOW );
	}

	/**
	 * Unschedule every occurrence of one hook.
	 *
	 * @param string $hook Cron hook name.
	 */
	private function clear( string $hook ): void {
		$timestamp = wp_next_scheduled( $hook );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
			$timestamp = wp_next_scheduled( $hook );
		}
	}

	/**
	 * Cron callback: flush one batch.
	 */
	public function run(): void {
		$this->flusher->run();
	}
}
