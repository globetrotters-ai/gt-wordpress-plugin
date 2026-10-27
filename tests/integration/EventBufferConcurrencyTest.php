<?php
/**
 * Integration: the buffer under concurrent writers.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Analytics\Schema;
use WP_UnitTestCase;
use wpdb;

/**
 * Concurrent served requests are the *normal* case for this buffer — which is
 * exactly why the contract forbids a single serialised blob: two requests
 * read-modify-writing one option lose each other's events.
 *
 * The property under test is that an append is one INSERT and nothing else, so
 * writers cannot clobber one another. It is exercised two ways: interleaved
 * writes over genuinely distinct database connections (deterministic, runs
 * everywhere) and, where the platform provides ``pcntl``, real parallel
 * processes.
 *
 * Every statement here runs on its own autocommitting connection rather than
 * the shared one. The WordPress test harness wraps each test in a transaction
 * on the global ``$wpdb``, and under REPEATABLE READ that snapshot would not
 * see rows committed by another connection mid-test — the test would fail for
 * reasons that have nothing to do with the code.
 */
final class EventBufferConcurrencyTest extends WP_UnitTestCase {

	private const WRITERS        = 4;
	private const WRITES_PER_JOB = 50;

	private wpdb $verifier;

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase::start_transaction() installs a `query` filter that
		// rewrites CREATE TABLE to CREATE TEMPORARY TABLE, and a temporary table
		// belongs to the connection that made it — which is exactly what this
		// test cannot use, since its whole point is writers on *other*
		// connections. (It stays invisible to them while, on MariaDB, still being
		// listed by SHOW TABLES, so Schema::install() reports success and every
		// single-connection test passes: the failure surfaces only here.) Drop the
		// filter so the plugin's real CREATE runs.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		( new Schema() )->install();

		$this->verifier = self::connect();
		$this->clear();
	}

	public function tear_down(): void {
		$this->clear();
		parent::tear_down();
	}

	public function test_interleaved_writers_on_separate_connections_lose_nothing(): void {
		$connections = array();
		for ( $writer = 0; $writer < self::WRITERS; $writer++ ) {
			$connections[] = self::connect();
		}

		$original = $GLOBALS['wpdb'];
		$store    = new MysqlEventStore();
		for ( $write = 0; $write < self::WRITES_PER_JOB; $write++ ) {
			foreach ( $connections as $writer => $connection ) {
				// Round-robin across live connections: the interleaving a blob-backed
				// buffer cannot survive.
				$GLOBALS['wpdb'] = $connection;
				$store->append( $this->event( "w{$writer}-{$write}" ) );
			}
		}
		$GLOBALS['wpdb'] = $original;

		$expected = self::WRITERS * self::WRITES_PER_JOB;
		$this->assertSame( $expected, $this->row_count() );
		$this->assertSame( $expected, $this->distinct_event_ids() );
	}

	public function test_parallel_processes_lose_nothing(): void {
		if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'posix_kill' ) ) {
			$this->markTestSkipped( 'pcntl/posix unavailable — the interleaved-connection case above covers the same property without true parallelism.' );
		}

		$pids = array();
		for ( $writer = 0; $writer < self::WRITERS; $writer++ ) {
			$pid = pcntl_fork();
			if ( -1 === $pid ) {
				$this->markTestSkipped( 'fork() failed on this platform.' );
			}
			if ( 0 === $pid ) {
				$this->write_in_child( $writer );
			}
			$pids[] = $pid;
		}

		foreach ( $pids as $pid ) {
			pcntl_waitpid( $pid, $status );
		}

		$expected = self::WRITERS * self::WRITES_PER_JOB;
		$this->assertSame( $expected, $this->row_count() );
		$this->assertSame( $expected, $this->distinct_event_ids() );
	}

	/**
	 * Child process body: write on its own connection, then leave hard.
	 *
	 * A forked child inherits the parent's MySQL socket, and two processes
	 * writing down one socket corrupt the protocol — hence the fresh connection.
	 * The exit is deliberately abrupt (SIGKILL rather than ``exit``) so the child
	 * never runs PHPUnit's shutdown handlers and never emits a second copy of the
	 * run's output.
	 *
	 * @param int $writer Writer index.
	 */
	private function write_in_child( int $writer ): void {
		$GLOBALS['wpdb'] = self::connect();
		$store           = new MysqlEventStore();
		for ( $write = 0; $write < self::WRITES_PER_JOB; $write++ ) {
			$store->append( $this->event( "p{$writer}-{$write}" ) );
		}
		posix_kill( posix_getpid(), SIGKILL );
	}

	/**
	 * A fresh, autocommitting connection to the test database.
	 *
	 * A bare ``new wpdb()`` has no table prefix — that is set during WordPress's
	 * own boot — so it has to be copied across, or every query here silently
	 * targets an unprefixed table that does not exist.
	 */
	private static function connect(): wpdb {
		$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$connection->set_prefix( $GLOBALS['wpdb']->prefix );
		return $connection;
	}

	/**
	 * Rows in the buffer, read outside the harness transaction.
	 */
	private function row_count(): int {
		$table = Schema::table();
		// phpcs:ignore
		return (int) $this->verifier->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Distinct event ids in the buffer, read outside the harness transaction.
	 */
	private function distinct_event_ids(): int {
		$table = Schema::table();
		// phpcs:ignore
		return (int) $this->verifier->get_var( "SELECT COUNT(DISTINCT event_id) FROM `{$table}`" );
	}

	/**
	 * @param string $id Event id.
	 */
	private function event( string $id ): Event {
		return new Event( $id, '2026-08-10T09:14:22Z', '/llms.txt', 'ClaudeBot/1.0', '160.79.104.10', '', 200, 4211 );
	}

	/**
	 * Empty the buffer table on the out-of-transaction connection.
	 */
	private function clear(): void {
		$table = Schema::table();
		// phpcs:ignore
		$this->verifier->query( "DELETE FROM `{$table}`" );
	}
}
