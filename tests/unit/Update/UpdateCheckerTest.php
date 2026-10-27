<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Update;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Client\FetchResult;
use Globetrotters\AiPresence\Client\Fetcher;
use Globetrotters\AiPresence\Tests\Support\FakeFetcher;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;
use Globetrotters\AiPresence\Update\UpdateChecker;

/**
 * @covers \Globetrotters\AiPresence\Update\UpdateChecker
 */
final class UpdateCheckerTest extends TestCase {

	use WpOptions;

	private const TRANSIENT = 'gtaip_update_meta';
	private const ENDPOINT  = 'https://globetrotters.ai/wp-plugin/update.json';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'delete_site_transient' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
	}

	/**
	 * A metadata JSON string advertising the given version.
	 */
	private function meta_json( string $version, string $sha256 = 'deadbeef', string $scheme = 'https' ): string {
		return (string) json_encode(
			array(
				'version'      => $version,
				'package'      => $scheme . '://globetrotters.ai/wp-plugin/globetrotters-ai-presence-' . $version . '.zip',
				'sha256'       => $sha256,
				'url'          => 'https://globetrotters.ai/wp-plugin',
				'requires'     => '6.0',
				'requires_php' => '7.4',
				'tested'       => '6.8',
			)
		);
	}

	private function checker_serving( string $body, int $status = 200 ): UpdateChecker {
		$fetcher = ( new FakeFetcher() )->on( 'update.json', FetchResult::http( $status, $body ) );
		return new UpdateChecker( $fetcher );
	}

	// --- check() -----------------------------------------------------------

	public function test_check_returns_update_object_when_newer(): void {
		$update = $this->checker_serving( $this->meta_json( '0.2.0' ) )->check( false );

		$this->assertIsObject( $update );
		// ``version`` is the field core requires (wp_update_plugins() skips a
		// response without it); ``new_version`` is the one it then displays.
		$this->assertSame( '0.2.0', $update->version );
		$this->assertSame( '0.2.0', $update->new_version );
		$this->assertSame( 'globetrotters-ai-presence', $update->slug );
		$this->assertSame( 'globetrotters-ai-presence/globetrotters-ai-presence.php', $update->plugin );
		$this->assertSame( 'globetrotters.ai/wp-plugin', $update->id );
		$this->assertSame( 'https://globetrotters.ai/wp-plugin/globetrotters-ai-presence-0.2.0.zip', $update->package );
		$this->assertSame( '7.4', $update->requires_php );
	}

	public function test_check_returns_input_unchanged_when_equal(): void {
		$this->assertFalse( $this->checker_serving( $this->meta_json( '0.1.0' ) )->check( false ) );
	}

	public function test_check_returns_input_unchanged_when_older(): void {
		$this->assertFalse( $this->checker_serving( $this->meta_json( '0.0.9' ) )->check( false ) );
	}

	public function test_check_returns_input_unchanged_on_garbage_json(): void {
		$this->assertFalse( $this->checker_serving( 'not json {' )->check( false ) );
	}

	public function test_check_returns_input_unchanged_when_unreachable(): void {
		$fetcher = ( new FakeFetcher() )->on( 'update.json', FetchResult::error( 'timeout' ) );
		$this->assertFalse( ( new UpdateChecker( $fetcher ) )->check( false ) );
	}

	public function test_check_preserves_a_non_false_input_on_failure(): void {
		$existing = (object) array( 'new_version' => '9.9.9' );
		$fetcher  = ( new FakeFetcher() )->on( 'update.json', FetchResult::http( 404, '' ) );
		$this->assertSame( $existing, ( new UpdateChecker( $fetcher ) )->check( $existing ) );
	}

	public function test_check_ignores_lookalike_plugins_sharing_the_host(): void {
		// The update_plugins_{host} filter is shared by every plugin whose Update
		// URI resolves to our host; we must not hand our package to another plugin.
		$update = $this->checker_serving( $this->meta_json( '0.2.0' ) )
			->check( false, array(), 'some-other-plugin/some-other-plugin.php' );

		$this->assertFalse( $update );
	}

	// --- metadata() --------------------------------------------------------

	public function test_metadata_hit_uses_transient_without_fetching(): void {
		$this->transients[ self::TRANSIENT ] = json_decode( $this->meta_json( '0.5.0' ), true );
		$fetcher                             = new FakeFetcher();

		$meta = ( new UpdateChecker( $fetcher ) )->metadata();

		$this->assertSame( '0.5.0', $meta['version'] );
		$this->assertSame( array(), $fetcher->requested, 'should not hit the network on a cache hit' );
	}

	public function test_metadata_refetches_when_cached_payload_is_invalid(): void {
		// A partial/poisoned transient (missing sha256) must not be trusted; the
		// checker re-validates the cache and falls through to a fresh fetch.
		$this->transients[ self::TRANSIENT ] = array( 'version' => '0.9.0', 'package' => 'x' );
		$fetcher                             = ( new FakeFetcher() )->on( 'update.json', FetchResult::http( 200, $this->meta_json( '0.2.0' ) ) );

		$meta = ( new UpdateChecker( $fetcher ) )->metadata();

		$this->assertSame( '0.2.0', $meta['version'] );
		$this->assertCount( 1, $fetcher->requested );
	}

	public function test_metadata_rejects_cross_origin_package(): void {
		$body = (string) json_encode(
			array(
				'version' => '0.2.0',
				'package' => 'https://evil.example.com/globetrotters-ai-presence-0.2.0.zip',
				'sha256'  => 'deadbeef',
			)
		);
		$this->assertNull( $this->checker_serving( $body )->metadata() );
	}

	public function test_metadata_caches_a_valid_payload(): void {
		$fetcher = ( new FakeFetcher() )->on( 'update.json', FetchResult::http( 200, $this->meta_json( '0.2.0' ) ) );
		$checker = new UpdateChecker( $fetcher );

		$checker->metadata();
		$checker->metadata();

		$this->assertCount( 1, $fetcher->requested, 'second call should read the transient' );
		$this->assertSame( self::ENDPOINT, $fetcher->requested[0] );
		$this->assertSame( '0.2.0', $this->transients[ self::TRANSIENT ]['version'] );
	}

	public function test_metadata_rejects_http_package_and_does_not_cache(): void {
		$checker = $this->checker_serving( $this->meta_json( '0.2.0', 'deadbeef', 'http' ) );

		$this->assertNull( $checker->metadata() );
		$this->assertArrayNotHasKey( self::TRANSIENT, $this->transients );
	}

	public function test_metadata_rejects_payload_missing_sha256(): void {
		$body = (string) json_encode(
			array( 'version' => '0.2.0', 'package' => 'https://globetrotters.ai/wp-plugin/x.zip' )
		);
		$this->assertNull( $this->checker_serving( $body )->metadata() );
	}

	public function test_metadata_rejects_an_oversize_body(): void {
		$body    = str_repeat( 'a', Fetcher::MAX_BODY_BYTES + 1 );
		$this->assertNull( $this->checker_serving( $body )->metadata() );
	}

	public function test_latest_version_reads_through_metadata(): void {
		$this->assertSame( '0.2.0', $this->checker_serving( $this->meta_json( '0.2.0' ) )->latest_version() );
	}

	public function test_latest_version_is_empty_when_metadata_unavailable(): void {
		$this->assertSame( '', $this->checker_serving( 'garbage' )->latest_version() );
	}

	public function test_cached_latest_version_never_fetches(): void {
		$fetcher = ( new FakeFetcher() )->on( 'update.json', FetchResult::http( 200, $this->meta_json( '0.2.0' ) ) );
		$checker = new UpdateChecker( $fetcher );

		// Cold cache → unknown, and crucially no network hit on a page render.
		$this->assertSame( '', $checker->cached_latest_version() );
		$this->assertSame( array(), $fetcher->requested );

		// Once the cache is warm (e.g. WP's periodic check ran), it reads through.
		$this->transients[ self::TRANSIENT ] = json_decode( $this->meta_json( '0.2.0' ), true );
		$this->assertSame( '0.2.0', $checker->cached_latest_version() );
		$this->assertSame( array(), $fetcher->requested );
	}

	// --- flush() -----------------------------------------------------------

	public function test_flush_busts_the_transient(): void {
		$this->transients[ self::TRANSIENT ] = array( 'version' => '1.0.0' );

		( new UpdateChecker( new FakeFetcher() ) )->flush();

		$this->assertArrayNotHasKey( self::TRANSIENT, $this->transients );
	}

	// --- verify_sha256() ---------------------------------------------------

	public function test_verify_sha256_passes_on_match(): void {
		$file = $this->temp_file( 'the plugin zip bytes' );
		$hash = hash( 'sha256', 'the plugin zip bytes' );

		$this->assertNull( ( new UpdateChecker( new FakeFetcher() ) )->verify_sha256( $file, $hash ) );

		unlink( $file );
	}

	public function test_verify_sha256_fails_on_mismatch(): void {
		$file = $this->temp_file( 'tampered bytes' );

		$error = ( new UpdateChecker( new FakeFetcher() ) )->verify_sha256( $file, hash( 'sha256', 'original bytes' ) );

		$this->assertIsString( $error );
		$this->assertStringContainsString( 'mismatch', strtolower( $error ) );

		unlink( $file );
	}

	public function test_verify_sha256_fails_when_expected_is_empty(): void {
		$file  = $this->temp_file( 'anything' );
		$error = ( new UpdateChecker( new FakeFetcher() ) )->verify_sha256( $file, '' );

		$this->assertIsString( $error );

		unlink( $file );
	}

	// --- guard_download() (scoping + pre-download branches) ----------------

	public function test_guard_download_passes_through_a_foreign_package(): void {
		$reply = ( new UpdateChecker( new FakeFetcher() ) )
			->guard_download( false, 'https://evil.example.com/plugin.zip' );

		$this->assertFalse( $reply );
	}

	public function test_guard_download_passes_through_non_https_package(): void {
		$reply = ( new UpdateChecker( new FakeFetcher() ) )
			->guard_download( false, 'http://globetrotters.ai/wp-plugin/x.zip' );

		$this->assertFalse( $reply );
	}

	public function test_guard_download_errors_when_metadata_unavailable(): void {
		$fetcher = ( new FakeFetcher() )->on( 'update.json', FetchResult::http( 404, '' ) );

		$reply = ( new UpdateChecker( $fetcher ) )
			->guard_download( false, 'https://globetrotters.ai/wp-plugin/globetrotters-ai-presence-0.2.0.zip' );

		$this->assertInstanceOf( \WP_Error::class, $reply );
		$this->assertSame( 'gtaip_no_metadata', $reply->get_error_code() );
	}

	public function test_guard_download_returns_the_file_on_hash_match(): void {
		$contents = 'the genuine package bytes';
		$file     = $this->temp_file( $contents );
		$this->transients[ self::TRANSIENT ] = json_decode( $this->meta_json( '0.2.0', hash( 'sha256', $contents ) ), true );

		Functions\when( 'download_url' )->justReturn( $file );
		Functions\when( 'is_wp_error' )->alias( fn( $x ) => $x instanceof \WP_Error );

		$reply = ( new UpdateChecker( new FakeFetcher() ) )
			->guard_download( false, 'https://globetrotters.ai/wp-plugin/globetrotters-ai-presence-0.2.0.zip' );

		$this->assertSame( $file, $reply );

		unlink( $file );
	}

	public function test_guard_download_blocks_and_deletes_on_hash_mismatch(): void {
		$file = $this->temp_file( 'tampered bytes' );
		$this->transients[ self::TRANSIENT ] = json_decode( $this->meta_json( '0.2.0', hash( 'sha256', 'the genuine bytes' ) ), true );

		$deleted = array();
		Functions\when( 'download_url' )->justReturn( $file );
		Functions\when( 'is_wp_error' )->alias( fn( $x ) => $x instanceof \WP_Error );
		Functions\when( 'wp_delete_file' )->alias(
			function ( $path ) use ( &$deleted ) {
				$deleted[] = $path;
			}
		);

		$reply = ( new UpdateChecker( new FakeFetcher() ) )
			->guard_download( false, 'https://globetrotters.ai/wp-plugin/globetrotters-ai-presence-0.2.0.zip' );

		$this->assertInstanceOf( \WP_Error::class, $reply );
		$this->assertSame( 'gtaip_hash_mismatch', $reply->get_error_code() );
		$this->assertContains( $file, $deleted, 'the tampered download must be deleted' );

		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}

	/**
	 * Write a temp file with the given contents and return its path.
	 */
	private function temp_file( string $contents ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'gtaip' );
		file_put_contents( $path, $contents );
		return $path;
	}
}
