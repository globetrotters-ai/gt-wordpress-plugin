<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Analytics\Flusher;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\FlushScheduler
 */
final class FlushSchedulerTest extends TestCase {

	use WpOptions;

	private FlushScheduler $scheduler;

	/**
	 * Pending cron events, keyed by hook.
	 *
	 * @var array<string, int>
	 */
	private array $cron = array();

	/**
	 * Hooks passed to wp_schedule_single_event(), in order.
	 *
	 * @var array<int, string>
	 */
	private array $scheduled_single = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		$this->fake_wp_cron();

		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok_live',
		);

		$options         = new Options();
		$this->scheduler = new FlushScheduler(
			new Flusher( new EventBuffer( new FakeEventStore() ), new FakeIngestTransport(), $options ),
			$options
		);
	}

	public function test_request_flush_queues_a_one_off_event(): void {
		$this->scheduler->request_flush();

		$this->assertSame( array( FlushScheduler::HOOK_NOW ), $this->scheduled_single );
	}

	public function test_a_second_request_does_not_pile_up_another_event(): void {
		// The rate limit: one pending immediate flush at a time. Without it a busy
		// site queues a cron write on every hit past the high-water mark.
		$this->scheduler->request_flush();
		$this->scheduler->request_flush();
		$this->scheduler->request_flush();

		$this->assertCount( 1, $this->scheduled_single );
	}

	public function test_the_recurring_event_does_not_suppress_an_immediate_one(): void {
		// The reason HOOK_NOW is a hook of its own: wp_schedule_single_event()
		// refuses a same-hook duplicate within ten minutes, and the recurring flush
		// is almost always inside that window.
		$this->scheduler->ensure_scheduled();
		$this->assertNotEmpty( $this->cron[ FlushScheduler::HOOK ] );

		$this->scheduler->request_flush();

		$this->assertSame( array( FlushScheduler::HOOK_NOW ), $this->scheduled_single );
	}

	public function test_request_flush_is_a_no_op_without_ingest_credentials(): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => '',
			'ingest_token'    => '',
		);

		$this->scheduler->request_flush();

		$this->assertSame( array(), $this->scheduled_single );
	}

	public function test_unschedule_clears_the_recurring_and_the_pending_event(): void {
		$this->scheduler->ensure_scheduled();
		$this->scheduler->request_flush();
		$this->assertArrayHasKey( FlushScheduler::HOOK_NOW, $this->cron );

		$this->scheduler->unschedule();

		$this->assertSame( array(), $this->cron );
	}

	/**
	 * Back the cron API with an in-memory hook => timestamp map.
	 */
	private function fake_wp_cron(): void {
		Functions\when( 'wp_next_scheduled' )->alias(
			fn( $hook ) => $this->cron[ $hook ] ?? false
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->cron[ $hook ] = $timestamp;
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->scheduled_single[] = $hook;
				$this->cron[ $hook ]      = $timestamp;
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
