<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Cache;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Cache\ArtefactCache
 */
final class ArtefactCacheTest extends TestCase {

	use WpOptions;

	/**
	 * The options table, as every process sees it.
	 *
	 * @var array<string, mixed>
	 */
	private array $db = array();

	/**
	 * This process's object cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $lane = array();

	/**
	 * Option names whose writes fail.
	 *
	 * @var string[]
	 */
	private array $failing_writes = array();

	/**
	 * Option names read through get_option().
	 *
	 * @var string[]
	 */
	private array $reads = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'maybe_serialize' )->alias(
			fn( $value ) => is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value
		);
	}

	public function test_store_reports_success_when_the_option_is_written(): void {
		$cache = new ArtefactCache();

		$this->assertTrue( $cache->store( array( 'llms.txt' => '# hello' ), 'v1' ) );
		$this->assertSame( '# hello', $cache->get( 'llms.txt' ) );
		$this->assertSame( 'v1', $cache->version() );
	}

	public function test_store_reports_failure_when_the_write_does_not_land(): void {
		$cache = new ArtefactCache();
		$cache->store( array( 'llms.txt' => 'GOOD' ), 'good-1' );

		// A write that reports false and changes nothing: a full disk, a
		// read-only replica, an option-length limit, a filter vetoing the value.
		$this->fail_option_writes();

		$this->assertFalse( $cache->store( array( 'llms.txt' => 'NEW' ), 'v2' ) );
		// Stale-serve: neither the option nor this request's memo moved on.
		$this->assertSame( 'GOOD', $cache->get( 'llms.txt' ) );
		$this->assertSame( 'good-1', $cache->version() );
		$this->assertSame( 'GOOD', ( new ArtefactCache() )->get( 'llms.txt' ) );
	}

	public function test_store_reports_success_when_the_write_was_an_unchanged_no_op(): void {
		// update_option() returns false for an unchanged value just as it does
		// for a failed write. Conflating the two would turn a republish of a
		// byte-identical bundle — a same-second retry, or a re-run of a pull
		// whose content did not move — into a spurious refresh failure.
		$cache = new ArtefactCache();
		$this->write_but_report_false();

		$this->assertTrue( $cache->store( array( 'llms.txt' => '# hello' ), 'v1' ) );
		$this->assertSame( '# hello', $cache->get( 'llms.txt' ) );
	}

	public function test_a_bundle_another_process_stored_is_served_over_this_process_stale_copy(): void {
		// WP-CLI cron and PHP-FPM share the database but not an APCu object
		// cache: the CLI pull lands in the table while this process keeps
		// answering from the copy it cached before.
		$this->fake_two_layer_options( true );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'OLD' ), 'v1' );
		$stale = $this->lane;
		( new ArtefactCache() )->store( array( 'llms.txt' => 'NEW' ), 'v2' );
		$this->lane = $stale;

		$cache = new ArtefactCache();

		$this->assertSame( 'NEW', $cache->get( 'llms.txt' ) );
		$this->assertSame( 'v2', $cache->version() );
		$this->assertSame( 'v2', $this->lane[ ArtefactCache::OPTION ]['version'], 'the stale copy is replaced, not re-read every request' );
	}

	public function test_a_bundle_another_process_cleared_stops_being_served(): void {
		$this->fake_two_layer_options( true );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'OLD' ), 'v1' );
		$stale = $this->lane;
		( new ArtefactCache() )->clear();
		$this->lane = $stale;

		$this->assertFalse( ( new ArtefactCache() )->has_any() );
	}

	public function test_a_pre_generation_bundle_another_process_deleted_stops_being_served(): void {
		// Cached before 0.9.4 (no generation), so there is no stamp either;
		// then a disconnect in another process deleted the row.
		$this->fake_two_layer_options( true );
		$this->lane[ ArtefactCache::OPTION ] = array(
			'files'     => array( 'llms.txt' => 'WITHDRAWN' ),
			'version'   => 'v0',
			'stored_at' => time(),
		);

		$this->assertFalse( ( new ArtefactCache() )->has_any() );
	}

	public function test_a_pull_is_seen_where_this_process_remembered_the_options_missing(): void {
		// PHP-FPM looked before the first pull and cached both rows as absent;
		// WP-CLI then created them without reaching that negative cache.
		$this->fake_two_layer_options( true );
		$this->assertFalse( ( new ArtefactCache() )->has_any() );
		$this->assertArrayHasKey( ArtefactCache::OPTION, $this->lane['notoptions'] );
		$fpm = $this->lane;
		( new ArtefactCache() )->store( array( 'llms.txt' => 'FIRST' ), 'v1' );
		$this->lane = $fpm;

		$this->assertSame( 'FIRST', ( new ArtefactCache() )->get( 'llms.txt' ) );
	}

	public function test_a_stamp_that_does_not_land_rolls_the_bundle_back(): void {
		$this->fake_two_layer_options( true );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'GOOD' ), 'good-1' );
		$this->failing_writes = array( ArtefactCache::STAMP_OPTION );

		$this->assertFalse( ( new ArtefactCache() )->store( array( 'llms.txt' => 'NEW' ), 'v2' ) );

		$this->assertSame( 'good-1', $this->db[ ArtefactCache::OPTION ]['version'] );
		$this->assertSame( 'good-1', ArtefactCache::pull_stamp()['version'] );
		$this->assertSame( 'GOOD', ( new ArtefactCache() )->get( 'llms.txt' ) );
	}

	public function test_a_first_pull_whose_stamp_does_not_land_leaves_nothing_stored(): void {
		$this->fake_two_layer_options( false );
		$this->failing_writes = array( ArtefactCache::STAMP_OPTION );

		$this->assertFalse( ( new ArtefactCache() )->store( array( 'llms.txt' => 'NEW' ), 'v1' ) );

		$this->assertArrayNotHasKey( ArtefactCache::OPTION, $this->db );
		$this->assertFalse( ( new ArtefactCache() )->has_any() );
	}

	public function test_without_a_persistent_object_cache_the_stamp_is_never_read(): void {
		// The non-persistent cache lives for one request, so get_option() already
		// read the table: the freshness check would be a second query for nothing.
		$this->fake_two_layer_options( false );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'GOOD' ), 'v1' );
		$this->reads = array();

		$this->assertSame( 'GOOD', ( new ArtefactCache() )->get( 'llms.txt' ) );
		$this->assertNotContains( ArtefactCache::STAMP_OPTION, $this->reads );
	}

	public function test_store_stamps_which_lane_pulled_the_bundle(): void {
		$this->fake_two_layer_options( true );
		Functions\when( 'wp_doing_cron' )->justReturn( true );

		( new ArtefactCache() )->store( array( 'llms.txt' => 'GOOD' ), 'v1' );

		$stamp = ArtefactCache::pull_stamp();
		$this->assertNotNull( $stamp );
		$this->assertSame( 'v1', $stamp['version'] );
		$this->assertSame( 'wp-cron', $stamp['pulled_by']['lane'] );
		$this->assertIsString( $stamp['pulled_by']['user'] );
		$this->assertGreaterThan( 0, $stamp['stored_at'] );
		$this->assertSame( $this->db[ ArtefactCache::OPTION ]['generation'], $stamp['generation'] );
	}

	public function test_pull_stamp_reads_past_this_process_cache(): void {
		$this->fake_two_layer_options( true );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'OLD' ), 'v1' );
		$stale = $this->lane;
		( new ArtefactCache() )->store( array( 'llms.txt' => 'NEW' ), 'v2' );
		$this->lane = $stale;

		$this->assertSame( 'v2', ArtefactCache::pull_stamp()['version'] );
	}

	public function test_a_web_request_records_the_version_it_serves(): void {
		$this->fake_two_layer_options( true );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'GOOD' ), 'v1' );
		$this->assertNull( ArtefactCache::served_version(), 'a pull is not a serve' );

		Functions\when( 'wp_doing_cron' )->justReturn( false );
		( new ArtefactCache() )->get( 'llms.txt' );

		$this->assertSame( 'v1', ArtefactCache::served_version() );
		$this->assertSame( 'v1', ArtefactCache::served()['version'] );
	}

	public function test_a_cron_read_records_nothing(): void {
		$this->fake_two_layer_options( true );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'GOOD' ), 'v1' );

		( new ArtefactCache() )->get( 'llms.txt' );

		$this->assertArrayNotHasKey( ArtefactCache::SERVED_OPTION, $this->db );
	}

	public function test_the_heartbeat_is_rewritten_hourly_not_per_request(): void {
		$this->fake_two_layer_options( true );
		( new ArtefactCache() )->store( array( 'llms.txt' => 'GOOD' ), 'v1' );
		$recent = array(
			'version' => 'v1',
			'at'      => time() - 60,
		);
		$this->db[ ArtefactCache::SERVED_OPTION ]   = $recent;
		$this->lane[ ArtefactCache::SERVED_OPTION ] = $recent;

		( new ArtefactCache() )->get( 'llms.txt' );
		$this->assertSame( $recent, $this->db[ ArtefactCache::SERVED_OPTION ] );

		$old = array(
			'version' => 'v1',
			'at'      => time() - HOUR_IN_SECONDS - 1,
		);
		$this->db[ ArtefactCache::SERVED_OPTION ]   = $old;
		$this->lane[ ArtefactCache::SERVED_OPTION ] = $old;

		( new ArtefactCache() )->get( 'llms.txt' );
		$this->assertGreaterThanOrEqual( time() - 5, $this->db[ ArtefactCache::SERVED_OPTION ]['at'] );
	}

	public function test_served_version_reads_past_this_process_cache(): void {
		// The flush runs from cron, possibly WP-CLI: its own cached copy of the
		// heartbeat says nothing about what the web server last served.
		$this->fake_two_layer_options( true );
		$this->lane[ ArtefactCache::SERVED_OPTION ] = array(
			'version' => 'v1',
			'at'      => time(),
		);
		$this->db[ ArtefactCache::SERVED_OPTION ]   = array(
			'version' => 'v2',
			'at'      => time(),
		);

		$this->assertSame( 'v2', ArtefactCache::served_version() );
	}

	public function test_clear_forgets_the_stamp_and_the_served_version(): void {
		$this->fake_two_layer_options( true );
		$cache = new ArtefactCache();
		$cache->store( array( 'llms.txt' => 'GOOD' ), 'v1' );
		$cache->get( 'llms.txt' );

		$cache->clear();

		$this->assertNull( ArtefactCache::pull_stamp() );
		$this->assertNull( ArtefactCache::served_version() );
	}

	/**
	 * Options as a table behind a per-process object cache.
	 *
	 * ``$db`` is the options table every process shares; ``$lane`` is this
	 * process's object cache, which another process's write never reaches.
	 *
	 * @param bool $persistent What wp_using_ext_object_cache() answers.
	 */
	private function fake_two_layer_options( bool $persistent ): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( $persistent );
		// Like core, a miss is remembered in ``notoptions`` and answered from
		// there, before the table, until something in this process clears it.
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				$this->reads[] = $name;
				if ( isset( $this->lane['notoptions'][ $name ] ) ) {
					return $default;
				}
				if ( array_key_exists( $name, $this->lane ) ) {
					return $this->lane[ $name ];
				}
				if ( array_key_exists( $name, $this->db ) ) {
					$this->lane[ $name ] = $this->db[ $name ];
					return $this->db[ $name ];
				}
				$this->lane['notoptions'][ $name ] = true;
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( in_array( $name, $this->failing_writes, true ) ) {
					return false;
				}
				$this->db[ $name ]   = $value;
				$this->lane[ $name ] = $value;
				unset( $this->lane['notoptions'][ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias( fn( $name ) => $this->lane[ $name ] ?? false );
		Functions\when( 'wp_cache_set' )->alias(
			function ( $name, $value ) {
				$this->lane[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->db[ $name ], $this->lane[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $name ) {
				unset( $this->lane[ $name ] );
				return true;
			}
		);
	}

	/**
	 * Make every option write fail without touching the stored value.
	 */
	private function fail_option_writes(): void {
		Functions\when( 'update_option' )->justReturn( false );
		Functions\when( 'add_option' )->justReturn( false );
	}

	/**
	 * Store the value but report false, exactly as core does when it finds the
	 * new value already identical to the stored one.
	 */
	private function write_but_report_false(): void {
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return false;
			}
		);
	}
}
