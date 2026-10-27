<?php
/**
 * Integration: runtime state under writers in other requests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Settings\Options;
use WP_UnitTestCase;

/**
 * ``get_option()`` answers from a per-request cache filled when the request
 * began. A request that reads state, blocks for a while — the settings page
 * fetches the version marker before it writes ``latest_version`` — and then
 * rewrites the whole option from that copy puts back whatever another request
 * wrote in between: the installed version, the content hash, the IndexNow key.
 *
 * "Another request" is simulated by writing the row directly, behind this
 * request's option cache, which is exactly what a second PHP process does.
 */
final class StateConcurrencyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Options::STATE );
		delete_option( MysqlEventStore::DROPPED_PENDING_OPTION );
		delete_option( MysqlEventStore::DROPPED_TOTAL_OPTION );
	}

	public function test_a_state_write_merges_over_the_stored_row_not_this_requests_copy(): void {
		$options = new Options();
		$options->update_state(
			array(
				'indexnow_key'   => 'old-key-12345',
				'latest_version' => 'v1',
			)
		);

		// A sync in another request rotates the key after this one read state.
		$row                 = $this->stored( Options::STATE );
		$row['indexnow_key'] = 'new-key-67890';
		$this->write_behind_cache( Options::STATE, $row );

		$options->update_state( array( 'latest_version' => 'v2' ) );

		$stored = $this->stored( Options::STATE );
		$this->assertSame( 'new-key-67890', $stored['indexnow_key'], 'a stale copy put the rotated key back' );
		$this->assertSame( 'v2', $stored['latest_version'] );
	}

	public function test_a_drop_increment_adds_to_the_stored_count_not_this_requests_copy(): void {
		$store = new MysqlEventStore();
		$store->add_dropped( 3 );
		$this->assertSame( 3, $store->dropped()['pending'] );

		// Another request's prune lands between this one's read and its write.
		$this->write_behind_cache( MysqlEventStore::DROPPED_PENDING_OPTION, '10' );

		$store->add_dropped( 2 );

		$this->assertSame( 12, $store->dropped()['pending'] );
		$this->assertSame( 5, $store->dropped()['total'] );
	}

	public function test_settling_subtracts_and_never_goes_below_zero(): void {
		$store = new MysqlEventStore();
		$store->add_dropped( 2 );

		$store->settle_dropped( 1 );
		$this->assertSame( 1, $store->dropped()['pending'] );

		$store->settle_dropped( 5 );
		$this->assertSame( 0, $store->dropped()['pending'] );
		// The lifetime total is never rewound — it is what the admin screen shows.
		$this->assertSame( 2, $store->dropped()['total'] );
	}

	public function test_counts_left_in_plugin_state_by_0_4_0_are_carried_over(): void {
		// 0.4.0 kept both counts in gtaip_state. Pending drops there have not been
		// reported yet, so an upgrade must not reset them to zero.
		update_option(
			Options::STATE,
			array(
				'dropped_pending' => 4,
				'dropped_total'   => 9,
			)
		);
		$store = new MysqlEventStore();

		$this->assertSame(
			array(
				'pending' => 4,
				'total'   => 9,
			),
			$store->dropped()
		);

		$store->add_dropped( 1 );

		$this->assertSame(
			array(
				'pending' => 5,
				'total'   => 10,
			),
			$store->dropped()
		);
	}

	public function test_the_counters_are_not_autoloaded(): void {
		( new MysqlEventStore() )->add_dropped( 1 );

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", MysqlEventStore::DROPPED_PENDING_OPTION )
		);
		$this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );
	}

	/**
	 * The row as stored, bypassing every cache.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private function stored( string $name ) {
		global $wpdb;
		return maybe_unserialize(
			$wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) )
		);
	}

	/**
	 * Write the row the way another PHP process would: straight to the table,
	 * leaving this request's option cache holding what it read before.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value New value.
	 */
	private function write_behind_cache( string $name, $value ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $value ) ),
			array( 'option_name' => $name )
		);
	}
}
