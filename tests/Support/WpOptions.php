<?php
/**
 * In-memory option + transient store for tests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Aliases the WordPress option/transient API to an in-memory array so classes
 * backed by ``get_option`` / ``update_option`` behave realistically in tests.
 */
trait WpOptions {

	/**
	 * @var array<string, mixed>
	 */
	protected array $store = array();

	/**
	 * @var array<string, mixed>
	 */
	protected array $transients = array();

	/**
	 * Wire the option/transient/helper functions to the in-memory arrays.
	 */
	protected function fake_wp_options(): void {
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->store[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		// add_option only writes when the option is absent — the property the
		// flush lock relies on (it is an INSERT against a unique index in core).
		Functions\when( 'add_option' )->alias(
			function ( $name, $value ) {
				if ( array_key_exists( $name, $this->store ) ) {
					return false;
				}
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( $name ) => $this->transients[ $name ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults = array() ) {
				return array_merge( $defaults, is_array( $args ) ? $args : array() );
			}
		);
		// Options validates the URLs it reads back (the ingest endpoint must be
		// https), so any test reading settings needs the parser.
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		// Options evicts its cached row before a merge-write so it merges over the
		// stored value. The in-memory store has no cache to go stale, so this is a
		// no-op here; tests/integration/StateConcurrencyTest covers the real one.
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// ArtefactCache checks its bundle against the stamp only behind a
		// persistent object cache, and records the served version only from a
		// web request. Tests of those paths override these.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_generate_uuid4' )->alias( static fn() => bin2hex( random_bytes( 16 ) ) );
	}
}
