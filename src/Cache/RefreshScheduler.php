<?php
/**
 * WP-Cron scheduling for the artefact refresh.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Cache;

use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Sync\ArtefactSync;

/**
 * Owns the scheduled refresh event and its cadence.
 *
 * The cadence is user-configurable (default daily), but the event itself always
 * recurs hourly: each run is a cheap *poll* that pulls only when a refresh is
 * {@see is_due() due}. Scheduling the pull itself at the configured cadence
 * tied two failures to a whole interval:
 *
 * - A failed pull (Globetrotters briefly unreachable, a deploy answering 5xx)
 *   was next retried one full interval later — a day or a week. On a fresh
 *   connection, whose first pull has no last good bundle to fall back on, the
 *   apex served nothing for that long.
 * - Artefacts lost while the schedule survived — the option deleted, a partial
 *   restore or migration that skipped it — stayed unserved until the next
 *   scheduled run, however far off.
 *
 * ``last_refresh`` is stamped by {@see ArtefactSync::run()} only on a fully
 * successful pull, so a failure leaves the refresh due and the next hourly poll
 * retries it. Mirrors the Symfony bundle's hourly poll + due check. Because
 * every pull re-fetches the full set, drift is self-healing within one window.
 */
final class RefreshScheduler {

	public const HOOK = 'gtaip_refresh';

	/**
	 * Recurrence of the poll, independent of the configured cadence.
	 */
	public const POLL_SCHEDULE = 'hourly';

	/**
	 * Slack allowed when deciding whether the interval has elapsed.
	 *
	 * ``last_refresh`` is stamped when a pull *finishes*, seconds after the
	 * poll that started it, so the poll one interval later reads an elapsed
	 * time a few seconds short and, without slack, would skip a whole hour —
	 * halving an hourly cadence. Well under the poll period, so a refresh is
	 * never pulled early by a full poll.
	 */
	private const DUE_SLACK_SECONDS = 5 * MINUTE_IN_SECONDS;

	private ArtefactSync $sync;
	private ArtefactCache $cache;
	private Options $options;

	public function __construct( ArtefactSync $sync, ArtefactCache $cache, Options $options ) {
		$this->sync    = $sync;
		$this->cache   = $cache;
		$this->options = $options;
	}

	/**
	 * Register the cron callback and custom schedules.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Add a weekly schedule alongside WordPress's built-ins.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'globetrotters-ai-presence' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure the hourly poll is scheduled.
	 *
	 * No-op until a destination is connected: an unconnected install has
	 * nothing to pull, so it gets no recurring event (settings-save reschedules
	 * once a slug arrives). An event left at another recurrence — every install
	 * upgrading from a release that scheduled the pull at the configured
	 * cadence — is replaced, so the upgrade needs no settings save.
	 */
	public function ensure_scheduled(): void {
		if ( ! $this->options->is_connected() ) {
			return;
		}
		$event = wp_get_scheduled_event( self::HOOK );
		if ( false === $event ) {
			$this->schedule();
		} elseif ( self::POLL_SCHEDULE !== $event->schedule ) {
			$this->reschedule();
		}
	}

	/**
	 * Re-schedule to a (possibly new) cadence. Safe to call on every settings
	 * save; clearing the connection tears the event down instead.
	 */
	public function reschedule(): void {
		$this->unschedule();
		if ( $this->options->is_connected() ) {
			$this->schedule();
		}
	}

	/**
	 * Remove the scheduled event.
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Cron callback: pull when a refresh is due, otherwise do nothing.
	 */
	public function run(): void {
		if ( $this->is_due() ) {
			$this->sync->run();
		}
	}

	/**
	 * Whether a pull is due.
	 *
	 * Due when there is nothing to serve, when no pull has ever succeeded for
	 * the current source (``last_refresh`` is 0: never connected before, or
	 * reset by a change of destination), or when the configured interval has
	 * elapsed since the last successful pull.
	 */
	public function is_due(): bool {
		if ( ! $this->cache->has_any() ) {
			return true;
		}
		$last_refresh = (int) $this->options->state()['last_refresh'];
		if ( $last_refresh <= 0 ) {
			return true;
		}
		return time() - $last_refresh >= $this->interval_seconds() - self::DUE_SLACK_SECONDS;
	}

	/**
	 * Schedule the recurring hourly poll.
	 *
	 * The first poll runs immediately, so activation and settings-save pull as
	 * soon as cron next fires whenever a pull is due — which a first connection
	 * or a new destination always is, its state having been reset. A separate
	 * one-off "run now" event can't be used for that:
	 * ``wp_schedule_single_event()`` drops any event within 10 minutes of this
	 * recurring one as a duplicate, silently defeating the immediate pull.
	 */
	private function schedule(): void {
		wp_schedule_event( time(), self::POLL_SCHEDULE, self::HOOK );
	}

	/**
	 * The configured cadence in seconds, falling back to daily when unrecognised.
	 *
	 * Resolved through ``wp_get_schedules()``, so the ``weekly`` schedule this
	 * class adds (and any a site defines) counts as recognised.
	 */
	private function interval_seconds(): int {
		$interval  = (string) $this->options->get( 'refresh_interval' );
		$schedules = wp_get_schedules();
		return isset( $schedules[ $interval ]['interval'] )
			? (int) $schedules[ $interval ]['interval']
			: DAY_IN_SECONDS;
	}
}
