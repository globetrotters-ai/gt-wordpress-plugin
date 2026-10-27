<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Cache;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Cache\RefreshScheduler;
use Globetrotters\AiPresence\Client\FetchResult;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Sync\ArtefactSync;
use Globetrotters\AiPresence\Tests\Support\FakeFetcher;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * The refresh is an hourly poll that pulls only when due.
 *
 * @covers \Globetrotters\AiPresence\Cache\RefreshScheduler
 */
final class RefreshSchedulerTest extends TestCase {

	use WpOptions;

	/**
	 * Fake cron: hook → [timestamp, recurrence].
	 *
	 * @var array<string, array{0:int, 1:string}>
	 */
	private array $cron = array();

	private FakeFetcher $fetcher;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		$this->fake_wp_cron();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'sanitize_title' )->alias( fn( $title ) => strtolower( (string) $title ) );
		Functions\when( 'maybe_serialize' )->alias(
			fn( $value ) => is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value
		);

		$this->store[ Options::SETTINGS ] = array(
			'website_url'      => 'https://acme.globetrotters.ai',
			'refresh_interval' => 'weekly',
		);

		$this->fetcher = new FakeFetcher();
		$this->fetcher->fallback( FetchResult::http( 200, '{}' ) );
		$this->fetcher->on( '/llms.txt', FetchResult::http( 200, '# Acme' ) );
		$this->fetcher->on( '/' . ContentTypes::VERSION_MARKER, FetchResult::http( 404, '' ) );
	}

	public function test_the_poll_recurs_hourly_whatever_the_configured_cadence(): void {
		$this->scheduler()->ensure_scheduled();

		$this->assertSame( RefreshScheduler::POLL_SCHEDULE, $this->cron[ RefreshScheduler::HOOK ][1] );
		$this->assertSame( 'hourly', RefreshScheduler::POLL_SCHEDULE );
	}

	/**
	 * Every install upgrading from a release that scheduled the pull itself at
	 * the configured cadence moves onto the poll with no settings save.
	 */
	public function test_an_event_left_at_the_old_cadence_is_replaced_by_the_poll(): void {
		$this->cron[ RefreshScheduler::HOOK ] = array( time() + 6 * DAY_IN_SECONDS, 'weekly' );

		$this->scheduler()->ensure_scheduled();

		$this->assertSame( 'hourly', $this->cron[ RefreshScheduler::HOOK ][1] );
		$this->assertLessThanOrEqual( time(), $this->cron[ RefreshScheduler::HOOK ][0], 'The first poll is due at once.' );
	}

	public function test_an_hourly_poll_already_scheduled_is_left_alone(): void {
		$next                                 = time() + 1800;
		$this->cron[ RefreshScheduler::HOOK ] = array( $next, 'hourly' );

		$this->scheduler()->ensure_scheduled();

		$this->assertSame( $next, $this->cron[ RefreshScheduler::HOOK ][0] );
	}

	public function test_a_poll_inside_the_interval_fetches_nothing(): void {
		$this->sync_at( time() - DAY_IN_SECONDS );

		$this->scheduler()->run();

		$this->assertSame( array(), $this->fetcher->requested );
	}

	public function test_a_poll_once_the_interval_has_elapsed_pulls(): void {
		$this->sync_at( time() - WEEK_IN_SECONDS - 60 );

		$this->scheduler()->run();

		$this->assertNotSame( array(), $this->fetcher->requested );
		$this->assertGreaterThan( time() - 60, ( new Options() )->state()['last_refresh'] );
	}

	/**
	 * ``last_refresh`` is stamped when a pull finishes, seconds after the poll
	 * that started it, so the poll one interval later reads slightly short. It
	 * must still count as due, or every refresh slips by a whole poll.
	 */
	public function test_a_poll_a_few_seconds_short_of_the_interval_still_pulls(): void {
		$this->store[ Options::SETTINGS ]['refresh_interval'] = 'hourly';
		$this->sync_at( time() - HOUR_IN_SECONDS + 10 );

		$this->scheduler()->run();

		$this->assertNotSame( array(), $this->fetcher->requested );
	}

	/**
	 * The artefacts are gone — the option deleted, a restore that skipped it —
	 * while the last refresh was recent. Waiting out the interval would leave
	 * the apex unserved for up to a week.
	 */
	public function test_a_poll_with_nothing_cached_pulls_however_recent_the_last_refresh(): void {
		$this->sync_at( time() - 60 );
		unset( $this->store[ ArtefactCache::OPTION ] );

		$this->scheduler()->run();

		$this->assertNotSame( array(), $this->fetcher->requested );
		$this->assertNotSame( array(), ( new ArtefactCache() )->files() );
	}

	public function test_a_poll_that_has_never_seen_a_successful_pull_pulls(): void {
		$this->sync_at( time() - 60 );
		$this->store[ Options::STATE ]['last_refresh'] = 0;

		$this->scheduler()->run();

		$this->assertNotSame( array(), $this->fetcher->requested );
	}

	/**
	 * A failed pull leaves ``last_refresh`` alone, so the refresh stays due and
	 * the very next poll — an hour later, not a week — retries it.
	 */
	public function test_a_failed_pull_is_retried_on_the_next_poll(): void {
		$this->sync_at( time() - WEEK_IN_SECONDS - 60 );
		$stamp = ( new Options() )->state()['last_refresh'];

		$this->fetcher->on( '/schema.json', FetchResult::http( 503, '' ) );
		$this->scheduler()->run();
		$this->assertSame( $stamp, ( new Options() )->state()['last_refresh'], 'A failure must not stamp the refresh.' );

		$this->fetcher->requested = array();
		$this->fetcher->on( '/schema.json', FetchResult::http( 200, '{}' ) );
		$this->scheduler()->run();

		$this->assertNotSame( array(), $this->fetcher->requested, 'The next poll must retry.' );
		$this->assertSame( '', ( new Options() )->state()['last_error'] );
	}

	/**
	 * Store a good bundle and stamp its refresh at ``$when``.
	 *
	 * @param int $when Unix time of the last successful pull.
	 */
	private function sync_at( int $when ): void {
		$this->assertTrue( $this->sync()->run()->is_success() );
		$this->store[ Options::STATE ]['last_refresh'] = $when;
		$this->fetcher->requested                      = array();
	}

	private function sync(): ArtefactSync {
		return new ArtefactSync( $this->fetcher, new ArtefactCache(), new Options() );
	}

	private function scheduler(): RefreshScheduler {
		return new RefreshScheduler( $this->sync(), new ArtefactCache(), new Options() );
	}

	private function fake_wp_cron(): void {
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly'     => array( 'interval' => HOUR_IN_SECONDS ),
				'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS ),
				'daily'      => array( 'interval' => DAY_IN_SECONDS ),
				'weekly'     => array( 'interval' => WEEK_IN_SECONDS ),
			)
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			fn( $hook ) => isset( $this->cron[ $hook ] ) ? $this->cron[ $hook ][0] : false
		);
		Functions\when( 'wp_get_scheduled_event' )->alias(
			fn( $hook ) => isset( $this->cron[ $hook ] )
				? (object) array(
					'hook'      => $hook,
					'timestamp' => $this->cron[ $hook ][0],
					'schedule'  => $this->cron[ $hook ][1],
					'args'      => array(),
				)
				: false
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->cron[ $hook ] = array( $timestamp, $recurrence );
				return true;
			}
		);
		Functions\when( 'wp_unschedule_event' )->alias(
			function ( $timestamp, $hook ) {
				unset( $this->cron[ $hook ] );
				return true;
			}
		);
	}
}
