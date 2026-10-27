<?php
/**
 * Integration: the refresh lock against the real options table.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Sync\MysqlRefreshLock;
use WP_UnitTestCase;

/**
 * Two ``MysqlRefreshLock`` instances stand for two processes — WP-Cron's
 * loopback and a system cron firing the same due refresh.
 */
final class RefreshLockTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( MysqlRefreshLock::OPTION );
	}

	public function test_a_second_refresher_is_refused_while_the_first_holds_it(): void {
		$first  = new MysqlRefreshLock();
		$second = new MysqlRefreshLock();

		$this->assertTrue( $first->acquire() );
		$this->assertTrue( $first->still_held() );
		$this->assertFalse( $second->acquire() );
		$this->assertFalse( $second->still_held() );

		$first->release();
		$this->assertTrue( $second->acquire() );
		$second->release();
	}

	public function test_a_lock_this_process_cache_has_never_seen_is_still_held(): void {
		// add_option() would consult the object cache, see no row, and write
		// INSERT … ON DUPLICATE KEY UPDATE over the other holder's lock.
		$other = time() . ':another-process';
		$this->write_row( $other );
		wp_cache_set( 'notoptions', array( MysqlRefreshLock::OPTION => true ), 'options' );

		$this->assertFalse( ( new MysqlRefreshLock() )->acquire() );
		$this->assertSame( $other, $this->stored() );
	}

	public function test_an_abandoned_lock_is_reclaimed(): void {
		$this->write_row( ( time() - MysqlRefreshLock::TTL_SECONDS - 1 ) . ':died' );

		$lock = new MysqlRefreshLock();
		$this->assertTrue( $lock->acquire() );
		$this->assertStringNotContainsString( 'died', (string) $this->stored() );
		$lock->release();
		$this->assertNull( $this->stored() );
	}

	public function test_a_slow_holder_never_releases_its_successors_lock(): void {
		$slow = new MysqlRefreshLock();
		$this->assertTrue( $slow->acquire() );

		// The slow holder outlived the TTL and someone else reclaimed the lock.
		$successor = time() . ':successor';
		$this->write_row( $successor );

		$this->assertFalse( $slow->still_held(), 'the slow holder must not publish' );
		$slow->release();
		$this->assertSame( $successor, $this->stored() );
	}

	public function test_the_lock_row_is_not_autoloaded(): void {
		$lock = new MysqlRefreshLock();
		$lock->acquire();

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", MysqlRefreshLock::OPTION )
		);
		$this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );
		$lock->release();
	}

	/**
	 * The lock row as stored, or null.
	 */
	private function stored(): ?string {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MysqlRefreshLock::OPTION )
		);
		return null === $value ? null : (string) $value;
	}

	/**
	 * Write the lock row the way another process would.
	 *
	 * @param string $value Row value.
	 */
	private function write_row( string $value ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				MysqlRefreshLock::OPTION,
				$value
			)
		);
	}
}
