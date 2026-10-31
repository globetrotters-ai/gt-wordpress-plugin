<?php
/**
 * Integration: the page-view counters against the real table.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Analytics\MysqlPageViewStore;
use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Analytics\PageViewStore;
use Globetrotters\AiPresence\Analytics\Schema;
use WP_UnitTestCase;

/**
 * The unit suite covers the counting rules against an in-memory store; this
 * proves the SQL holds them up: one-statement upserts that report whether a
 * path is new, the daily path cap, record ids assigned once, age pruning, and a
 * table with nowhere to put visitor data.
 */
final class PageViewStoreTest extends WP_UnitTestCase {

	private MysqlPageViewStore $store;

	public function set_up(): void {
		parent::set_up();
		( new Schema() )->install();
		$this->clear();
		$this->store = new MysqlPageViewStore();
	}

	public function tear_down(): void {
		$this->clear();
		parent::tear_down();
	}

	public function test_the_schema_installs_both_tables_at_version_5(): void {
		global $wpdb;
		$table = Schema::page_views_table();

		$this->assertSame( 5, Schema::VERSION );
		$this->assertTrue( Schema::site_events_ready() );
		$this->assertTrue( Schema::page_view_platforms_ready() );
		$this->assertTrue( Schema::signatures_ready() );
		$this->assertSame( 'signature', $wpdb->get_var( 'SHOW COLUMNS FROM `' . Schema::table() . "` LIKE 'signature'" ) ); // phpcs:ignore
		$this->assertSame( 'YES', $wpdb->get_var( 'SHOW COLUMNS FROM `' . Schema::table() . "` LIKE 'signature'", 2 ) ); // phpcs:ignore
		$this->assertSame( 'varchar(48)', $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'bucket'", 1 ) ); // phpcs:ignore
		$this->assertTrue( Schema::is_ready() );
		$this->assertSame(
			Schema::page_views_table(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::page_views_table() ) )
		);
		$this->assertSame(
			Schema::table(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table() ) )
		);
	}

	public function test_the_table_has_no_column_for_visitor_data(): void {
		global $wpdb;
		$table = Schema::page_views_table();

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore

		$this->assertSame( array( 'id', 'day', 'path', 'path_hash', 'bucket', 'views', 'record_id' ), $columns );
	}

	public function test_an_upgrade_from_version_1_adds_the_counter_table_and_keeps_the_buffer(): void {
		global $wpdb;
		$events = Schema::table();
		$views  = Schema::page_views_table();

		// WP_UnitTestCase rewrites CREATE/DROP TABLE into their TEMPORARY forms,
		// which would leave the real counter table in place and make this test
		// pass without upgrading anything. Take the rewrites out for this test so
		// the table is really dropped and really recreated.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( "INSERT INTO `{$events}` (event_id, ts, path) VALUES ('keep-me', '2026-09-26T10:00:00Z', '/llms.txt')" ); // phpcs:ignore
			$wpdb->query( "DROP TABLE IF EXISTS `{$views}`" ); // phpcs:ignore
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $views ) ), 'The counter table must really be gone.' );
			update_option( Schema::VERSION_OPTION, 1 );

			// Mid-upgrade: agent-traffic capture carries on, page views are inert.
			$this->assertTrue( Schema::is_ready() );
			$this->assertFalse( Schema::page_views_ready() );
			$this->assertSame( 0, $this->store->increment( '2026-09-26', '/a', 'browser' ) );
			$this->assertSame( 0, $this->store->sealed_count() );

			( new Schema() )->ensure_installed();

			$this->assertTrue( Schema::page_views_ready() );
			$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
			$this->assertSame( $views, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $views ) ) );
			$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `{$events}` WHERE event_id = 'keep-me'" ) ); // phpcs:ignore
			$this->assertSame( 1, $this->store->increment( '2026-09-26', '/a', 'browser' ) );
		} finally {
			$wpdb->query( "DELETE FROM `{$events}`" ); // phpcs:ignore
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_an_upgrade_from_version_2_makes_ip_and_bytes_nullable_for_site_events(): void {
		global $wpdb;
		$events = Schema::table();

		// Same reason as the version-1 upgrade: work on the real table, not a
		// TEMPORARY shadow dbDelta cannot see.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			// Put the buffer back in its version-2 shape: no site columns, and
			// ip/bytes NOT NULL, which dbDelta alone never relaxes.
			$wpdb->query( "ALTER TABLE `{$events}` DROP COLUMN kind, DROP COLUMN verified, DROP COLUMN vendor, MODIFY ip varchar(45) NOT NULL DEFAULT '', MODIFY bytes int(10) unsigned NOT NULL DEFAULT 0" ); // phpcs:ignore
			update_option( Schema::VERSION_OPTION, 2 );
			$this->assertFalse( Schema::site_events_ready() );

			( new Schema() )->ensure_installed();

			$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
			$this->assertTrue( Schema::site_events_ready() );
			$this->assertSame( 'YES', $wpdb->get_var( "SHOW COLUMNS FROM `{$events}` LIKE 'ip'", 2 ) ); // phpcs:ignore
			$this->assertSame( 'YES', $wpdb->get_var( "SHOW COLUMNS FROM `{$events}` LIKE 'bytes'", 2 ) ); // phpcs:ignore

			$site = Event::site( 'site-unknown-bytes', '2026-10-15T10:00:00Z', '/visiter', 'GPTBot/1.2', 200, null, null, null );
			$this->assertGreaterThan( 0, ( new MysqlEventStore() )->append( $site ) );
			$row = $wpdb->get_row( "SELECT ip, bytes, kind FROM `{$events}` WHERE event_id = 'site-unknown-bytes'", ARRAY_A ); // phpcs:ignore
			$this->assertSame(
				array(
					'ip'    => null,
					'bytes' => null,
					'kind'  => Event::KIND_SITE,
				),
				$row
			);
		} finally {
			$wpdb->query( "DELETE FROM `{$events}`" ); // phpcs:ignore
			( new Schema() )->install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_an_upgrade_from_version_3_widens_the_bucket_and_keeps_the_open_day(): void {
		global $wpdb;
		$views = Schema::page_views_table();
		$today = gmdate( 'Y-m-d' );

		// Same reason as the version-1 upgrade: work on the real table.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			// The counters as 0.8.x left them: a 16-character bucket and an open
			// day already counting.
			$wpdb->query( "ALTER TABLE `{$views}` MODIFY bucket varchar(16) NOT NULL" ); // phpcs:ignore
			$this->store->increment( $today, '/x', 'browser' );
			update_option( Schema::VERSION_OPTION, 3 );
			$this->assertFalse( Schema::page_view_platforms_ready() );

			( new Schema() )->ensure_installed();

			$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
			$this->assertTrue( Schema::page_view_platforms_ready() );
			$this->assertSame( 'varchar(48)', $wpdb->get_var( "SHOW COLUMNS FROM `{$views}` LIKE 'bucket'", 1 ) ); // phpcs:ignore
			// The 0.8.x row keeps counting under its key, and the longest
			// platform key fits whole beside it.
			$this->assertSame( 2, $this->store->increment( $today, '/x', 'browser' ) );
			$this->assertSame( 1, $this->store->increment( $today, '/x', 'browser@perplexity' ) );
			$this->assertSame( 2, $this->views( $today, '/x', 'browser' ) );
			$this->assertSame( 1, $this->views( $today, '/x', 'browser@perplexity' ) );
		} finally {
			$wpdb->query( "DELETE FROM `{$views}`" ); // phpcs:ignore
			( new Schema() )->install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_an_upgrade_from_version_4_adds_the_signature_column_and_keeps_the_buffer(): void {
		global $wpdb;
		$events = Schema::table();
		$signed = ( new Event( 'signed-after-upgrade', '2026-10-31T10:00:00Z', '/llms.txt', 'Mozilla/5.0 Chrome/141', '', '', 200, 10 ) )->with_signature(
			array(
				'agent'     => '"https://www.meta.com"',
				'input'     => 'sig1=("@authority" "signature-agent");tag="web-bot-auth"',
				'value'     => 'sig1=:YWJj:',
				'authority' => 'visit-acme.example',
			)
		);

		// Same reason as the version-1 upgrade: work on the real table.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( "ALTER TABLE `{$events}` DROP COLUMN signature" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM `{$events}`" ); // phpcs:ignore
			$wpdb->query( "INSERT INTO `{$events}` (event_id, ts, path) VALUES ('keep-me-v4', '2026-10-31T09:00:00Z', '/llms.txt')" ); // phpcs:ignore
			update_option( Schema::VERSION_OPTION, 4 );
			$this->assertFalse( Schema::signatures_ready() );

			// Mid-upgrade: a signed event is still buffered, without the headers.
			$this->assertGreaterThan( 0, ( new MysqlEventStore() )->append( $signed ) );
			$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `{$events}` WHERE event_id = 'signed-after-upgrade'" ) ); // phpcs:ignore
			$this->assertSame( (string) $signed->with_signature( null )->size_bytes(), $wpdb->get_var( "SELECT size_bytes FROM `{$events}` WHERE event_id = 'signed-after-upgrade'" ), 'Sized unsigned.' ); // phpcs:ignore
			$wpdb->query( "DELETE FROM `{$events}` WHERE event_id = 'signed-after-upgrade'" ); // phpcs:ignore

			( new Schema() )->ensure_installed();

			$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
			$this->assertTrue( Schema::signatures_ready() );
			$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `{$events}` WHERE event_id = 'keep-me-v4'" ) ); // phpcs:ignore
			$this->assertGreaterThan( 0, ( new MysqlEventStore() )->append( $signed ) );
			$this->assertSame(
				$signed->signature(),
				json_decode( (string) $wpdb->get_var( "SELECT signature FROM `{$events}` WHERE event_id = 'signed-after-upgrade'" ), true ) // phpcs:ignore
			);
		} finally {
			$wpdb->query( "DELETE FROM `{$events}`" ); // phpcs:ignore
			( new Schema() )->install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_a_referred_view_is_its_own_counter_and_never_a_new_path(): void {
		$today = gmdate( 'Y-m-d' );
		$this->seed_paths( $today, PageViewStore::MAX_PATHS_PER_DAY );
		$counter = new PageViewCounter( $this->store );

		$this->assertTrue( $counter->count( '/p5', 'browser', 'chatgpt' ) );
		$this->assertTrue( $counter->count( '/new', 'browser', 'claude' ) );

		$this->assertSame( 1, $this->views( $today, '/p5', 'browser@chatgpt' ) );
		$this->assertSame( 1, $this->views( $today, PageViewStore::OTHER_PATH, 'browser@claude' ) );
		$this->assertSame( 0, $this->views( $today, '/new', 'browser@claude' ) );
		$this->assertSame( PageViewStore::MAX_PATHS_PER_DAY, $this->store->distinct_paths( $today ) );
	}

	public function test_an_increment_reports_a_new_counter_then_an_existing_one(): void {
		$this->assertSame( 1, $this->store->increment( '2026-09-26', '/visiter', 'browser' ) );
		$this->assertSame( 2, $this->store->increment( '2026-09-26', '/visiter', 'browser' ) );
		$this->assertSame( 1, $this->store->increment( '2026-09-26', '/visiter', 'ai_browser' ) );
		$this->assertSame( 1, $this->store->increment( '2026-09-27', '/visiter', 'browser' ) );

		$this->assertSame( 2, $this->views( '2026-09-26', '/visiter', 'browser' ) );
		$this->assertSame( 1, $this->views( '2026-09-26', '/visiter', 'ai_browser' ) );
	}

	public function test_paths_differing_only_in_case_are_different_pages(): void {
		$this->store->increment( '2026-09-26', '/Visiter', 'browser' );
		$this->store->increment( '2026-09-26', '/visiter', 'browser' );

		$this->assertSame( 1, $this->views( '2026-09-26', '/Visiter', 'browser' ) );
		$this->assertSame( 1, $this->views( '2026-09-26', '/visiter', 'browser' ) );
	}

	public function test_a_sealed_counter_is_not_incremented(): void {
		$this->store->increment( '2026-09-25', '/visiter', 'browser' );
		$this->store->seal( '2026-09-26', 10 );

		$this->assertSame( 0, $this->store->increment( '2026-09-25', '/visiter', 'browser' ) );
		$this->assertSame( 1, $this->views( '2026-09-25', '/visiter', 'browser' ) );
	}

	public function test_past_the_daily_path_cap_views_go_to_other(): void {
		global $wpdb;
		$today = gmdate( 'Y-m-d' );
		$this->seed_paths( $today, PageViewStore::MAX_PATHS_PER_DAY );
		$counter = new PageViewCounter( $this->store );

		$this->assertTrue( $counter->count( '/one-too-many', 'browser' ) );
		$this->assertTrue( $counter->count( '/another', 'browser' ) );
		$this->assertTrue( $counter->count( '/p5', 'browser' ) );

		$table = Schema::page_views_table();
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE path IN (%s, %s)", '/one-too-many', '/another' ) ) ); // phpcs:ignore
		$this->assertSame( 2, $this->views( $today, PageViewStore::OTHER_PATH, 'browser' ) );
		$this->assertSame( 2, $this->views( $today, '/p5', 'browser' ) );
		$this->assertSame( PageViewStore::MAX_PATHS_PER_DAY, $this->store->distinct_paths( $today ) );
	}

	public function test_remove_takes_only_a_counter_still_holding_one_view(): void {
		$this->store->increment( '2026-09-26', '/single', 'browser' );
		$this->store->increment( '2026-09-26', '/raced', 'browser' );
		$this->store->increment( '2026-09-26', '/raced', 'browser' );

		$this->assertTrue( $this->store->remove( '2026-09-26', '/single', 'browser' ) );
		// A counter a concurrent beacon already added to is kept, not lost.
		$this->assertFalse( $this->store->remove( '2026-09-26', '/raced', 'browser' ) );
		$this->assertSame( 2, $this->views( '2026-09-26', '/raced', 'browser' ) );
	}

	public function test_sealing_assigns_each_closed_day_an_id_once(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$this->store->increment( $yesterday, '/a', 'browser' );
		$this->store->increment( $yesterday, '/b', 'ai_browser' );
		$this->store->increment( $today, '/c', 'browser' );

		$this->assertSame( 2, $this->store->seal( $today, 100 ) );
		$first = array_column( $this->store->sealed( 100 ), 'record_id', 'path' );
		$this->assertSame( 0, $this->store->seal( $today, 100 ), 'A sealed record is never re-stamped.' );
		$second = array_column( $this->store->sealed( 100 ), 'record_id', 'path' );

		$this->assertSame( array( '/a', '/b' ), array_keys( $first ) );
		$this->assertSame( $first, $second );
		$this->assertNotSame( $first['/a'], $first['/b'] );
		foreach ( $first as $id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
		}
		$this->assertSame( 2, $this->store->sealed_count() );
	}

	public function test_prune_drops_days_older_than_the_limit_only(): void {
		$this->store->increment( '2026-09-18', '/old', 'browser' );
		$this->store->increment( '2026-09-19', '/kept', 'browser' );

		$this->assertSame( 1, $this->store->prune( '2026-09-19' ) );

		$this->assertSame( 0, $this->views( '2026-09-18', '/old', 'browser' ) );
		$this->assertSame( 1, $this->views( '2026-09-19', '/kept', 'browser' ) );
	}

	public function test_delete_removes_only_the_given_records(): void {
		$this->store->increment( '2026-09-25', '/a', 'browser' );
		$this->store->increment( '2026-09-25', '/b', 'browser' );
		$this->store->seal( '2026-09-26', 10 );
		$sealed = $this->store->sealed( 10 );

		$this->assertSame( 1, $this->store->delete( array( $sealed[0]['id'] ) ) );

		$this->assertSame( array( '/b' ), array_column( $this->store->sealed( 10 ), 'path' ) );
	}

	public function test_nothing_is_queried_when_the_table_is_not_installed(): void {
		delete_option( Schema::VERSION_OPTION );

		$this->assertSame( 0, $this->store->increment( '2026-09-26', '/a', 'browser' ) );
		$this->assertSame( array(), $this->store->sealed( 10 ) );
		$this->assertSame( 0, $this->store->sealed_count() );
	}

	/**
	 * Seed ``$count`` distinct paths on a day in one statement.
	 *
	 * @param string $day   UTC day.
	 * @param int    $count How many paths.
	 */
	private function seed_paths( string $day, int $count ): void {
		global $wpdb;
		$table  = Schema::page_views_table();
		$values = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$path     = '/p' . $i;
			$values[] = $wpdb->prepare( '(%s, %s, %s, %s, 1)', $day, $path, md5( $path ), 'browser' );
		}
		$wpdb->query( "INSERT INTO `{$table}` (day, path, path_hash, bucket, views) VALUES " . implode( ',', $values ) ); // phpcs:ignore
	}

	/**
	 * A counter's views, or 0 when it does not exist.
	 *
	 * @param string $day    UTC day.
	 * @param string $path   Path.
	 * @param string $bucket Bucket.
	 */
	private function views( string $day, string $path, string $bucket ): int {
		global $wpdb;
		$table = Schema::page_views_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT views FROM `{$table}` WHERE day = %s AND path = %s AND bucket = %s", $day, $path, $bucket ) // phpcs:ignore
		);
	}

	/**
	 * Empty the counter table.
	 */
	private function clear(): void {
		global $wpdb;
		$table = Schema::page_views_table();
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore
	}
}
