<?php
/**
 * Integration: a pull another process made, behind this one's object cache.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use WP_UnitTestCase;

/**
 * An APCu ``object-cache.php`` drop-in is per PHP process pool: a refresh run
 * from system cron through WP-CLI writes the options table and never reaches
 * PHP-FPM's cache, which went on serving the bundle it held. The suite runs
 * without a persistent cache, so this request's own option cache stands in for
 * FPM's, ``wp_using_ext_object_cache( true )`` makes the plugin treat it as
 * one, and the other process's pull is written straight to the table.
 */
final class CrossProcessCacheTest extends WP_UnitTestCase {

	private bool $was_external = false;

	public function set_up(): void {
		parent::set_up();
		delete_option( ArtefactCache::OPTION );
		delete_option( ArtefactCache::STAMP_OPTION );
		delete_option( ArtefactCache::SERVED_OPTION );
		$this->was_external = (bool) wp_using_ext_object_cache( true );
	}

	public function tear_down(): void {
		wp_using_ext_object_cache( $this->was_external );
		parent::tear_down();
	}

	public function test_a_pull_written_behind_this_process_cache_is_served(): void {
		( new ArtefactCache() )->store( array( 'llms.txt' => 'OLD' ), 'v1' );
		$this->assertSame( 'OLD', ( new ArtefactCache() )->get( 'llms.txt' ), 'precondition: v1 is cached here' );

		$this->pull_in_another_process( array( 'llms.txt' => 'NEW' ), 'v2' );

		$cache = new ArtefactCache();
		$this->assertSame( 'NEW', $cache->get( 'llms.txt' ) );
		$this->assertSame( 'v2', $cache->version() );
		$this->assertSame( 'v2', ArtefactCache::served_version(), 'the heartbeat follows what is served' );
	}

	public function test_the_stamp_names_the_lane_that_pulled(): void {
		( new ArtefactCache() )->store( array( 'llms.txt' => 'GOOD' ), 'v1' );

		$stamp = ArtefactCache::pull_stamp();
		$this->assertSame( 'v1', $stamp['version'] );
		$this->assertContains( $stamp['pulled_by']['lane'], array( 'web', 'wp-cron', 'wp-cli' ) );
	}

	/**
	 * Store a bundle and its stamp the way a WP-CLI process would: straight to
	 * the table, leaving this request's option cache holding what it had.
	 *
	 * @param array<string, string> $files   Apex-relative path → body.
	 * @param string                $version Bundle version.
	 */
	private function pull_in_another_process( array $files, string $version ): void {
		$generation = wp_generate_uuid4();
		$this->write_behind_cache(
			ArtefactCache::OPTION,
			array(
				'files'      => $files,
				'version'    => $version,
				'stored_at'  => time(),
				'generation' => $generation,
			)
		);
		$this->write_behind_cache(
			ArtefactCache::STAMP_OPTION,
			array(
				'generation' => $generation,
				'version'    => $version,
				'stored_at'  => time(),
				'pulled_by'  => array(
					'lane' => 'wp-cli',
					'user' => 'deploy',
				),
			)
		);
	}

	/**
	 * Write a row straight to the table.
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
