<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Cache\RefreshScheduler;
use Globetrotters\AiPresence\Plugin;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Plugin
 */
final class PluginTest extends TestCase {

	use WpOptions;

	/**
	 * Pending cron events, keyed by hook.
	 *
	 * @var array<string, int>
	 */
	private array $cron = array();

	/**
	 * Callbacks registered on ``init``, as ``[callback, priority]`` pairs.
	 *
	 * @var array<int, array{0: callable, 1: int}>
	 */
	private array $init = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		$this->fake_wp_cron();

		Functions\when( 'is_admin' )->justReturn( false );

		// An install that is both connected and reporting: every scheduler has
		// something to schedule, so nothing is skipped for the wrong reason.
		$this->store[ Options::SETTINGS ] = array(
			'website_url'     => 'https://acme.example',
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok_live',
		);

		Actions\expectAdded( 'init' )->whenHappen(
			function ( $callback, $priority ) {
				$this->init[] = array( $callback, $priority );
			}
		);
	}

	public function test_boot_does_not_schedule_before_init(): void {
		// Scheduling reaches the ``cron_schedules`` filter, whose labels are
		// translated. Doing that on ``plugins_loaded`` loads the text domain
		// before ``init``, which WordPress 6.7+ reports as
		// ``_load_textdomain_just_in_time`` on every install that still has an
		// event to create.
		Plugin::instance()->boot();

		$this->assertSame( array(), $this->cron );
	}

	public function test_boot_schedules_both_events_once_init_runs(): void {
		Plugin::instance()->boot();

		foreach ( $this->init as list( $callback ) ) {
			$callback();
		}

		$this->assertArrayHasKey( RefreshScheduler::HOOK, $this->cron );
		$this->assertArrayHasKey( FlushScheduler::HOOK, $this->cron );
	}

	public function test_scheduling_runs_before_core_spawns_cron(): void {
		// Core hooks ``wp_cron`` to ``init`` at priority 10 from default-filters,
		// so an equal priority would let it run first and leave the events — due
		// immediately — waiting for the next request to spawn.
		Plugin::instance()->boot();

		$this->assertNotSame( array(), $this->init );
		foreach ( $this->init as list( , $priority ) ) {
			$this->assertLessThan( 10, $priority );
		}
	}

	private function fake_wp_cron(): void {
		// The real one runs the ``cron_schedules`` filter — the seam this test is
		// about. Returning the built-ins is enough for the schedulers to resolve
		// a cadence.
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly' => array( 'interval' => 3600 ),
				'daily'  => array( 'interval' => 86400 ),
			)
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			fn( $hook ) => $this->cron[ $hook ] ?? false
		);
		Functions\when( 'wp_get_scheduled_event' )->alias(
			fn( $hook ) => isset( $this->cron[ $hook ] )
				? (object) array(
					'hook'      => $hook,
					'timestamp' => $this->cron[ $hook ],
					'schedule'  => 'hourly',
				)
				: false
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->cron[ $hook ] = $timestamp;
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
