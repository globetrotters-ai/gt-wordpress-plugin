<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\CaptureContext;
use Globetrotters\AiPresence\Analytics\PageCacheExclusion;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\CaptureContext
 */
final class CaptureContextTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok',
		);
	}

	public function test_snapshot_shape_with_nothing_detected(): void {
		$this->assertSame(
			array(
				'siteCapture'        => true,
				'pageViewCounter'    => false,
				'pageCache'          => null,
				'pageCacheExclusion' => false,
				'cdnMarkers'         => array(),
				'rangesVersion'      => null,
				'rangesFetchedAt'    => null,
				'servedVersion'      => null,
			),
			$this->context()->snapshot()
		);
	}

	public function test_snapshot_reports_the_range_list_and_the_capture_flag(): void {
		$this->store[ Options::SETTINGS ]['capture_site_bots'] = false;
		$this->store[ VendorRanges::OPTION ]                   = array(
			'version'    => '3f2a9c1e',
			'fetched_at' => gmmktime( 3, 0, 0, 10, 14, 2026 ),
			'checked_at' => gmmktime( 3, 0, 0, 10, 14, 2026 ),
			'ranges'     => array(),
		);

		$snapshot = $this->context()->snapshot();

		$this->assertFalse( $snapshot['siteCapture'] );
		$this->assertFalse( $snapshot['pageViewCounter'] );
		$this->assertSame( '3f2a9c1e', $snapshot['rangesVersion'] );
		$this->assertSame( '2026-10-14T03:00:00Z', $snapshot['rangesFetchedAt'] );
	}

	public function test_snapshot_reports_the_version_the_web_server_last_served(): void {
		// The heartbeat a web request leaves, not the bundle this (cron) process
		// pulled: the two differ exactly when the lanes stop sharing a cache.
		$this->store[ ArtefactCache::SERVED_OPTION ] = array(
			'version' => '2026-10-18-050325',
			'at'      => time(),
		);

		$this->assertSame( '2026-10-18-050325', $this->context()->snapshot()['servedVersion'] );
	}

	public function test_snapshot_reports_the_page_view_counter(): void {
		$this->store[ Options::SETTINGS ]['count_page_views'] = true;
		$this->assertTrue( $this->context()->snapshot()['pageViewCounter'] );

		// The setting alone is not enough: with no reporting configured, the
		// counter never runs, so it reads as off.
		unset( $this->store[ Options::SETTINGS ]['ingest_token'] );
		$this->assertFalse( $this->context()->snapshot()['pageViewCounter'] );
	}

	/**
	 * @dataProvider caches
	 *
	 * @param array<int, array{0:string, 1:string}> $present  Signals the probe answers true for.
	 * @param string|null                           $expected Detected cache.
	 */
	public function test_page_cache_detection( array $present, ?string $expected ): void {
		$probe = static fn( string $kind, string $name ): bool => in_array( array( $kind, $name ), $present, true );
		$this->assertSame( $expected, $this->context( $probe )->page_cache() );
	}

	/**
	 * @return array<string, array{0:array<int, array{0:string, 1:string}>, 1:string|null}>
	 */
	public static function caches(): array {
		return array(
			'none'             => array( array(), null ),
			'wp rocket'        => array( array( array( 'constant', 'WP_ROCKET_VERSION' ) ), 'wp-rocket' ),
			'litespeed'        => array( array( array( 'class', 'LiteSpeed\\Core' ) ), 'litespeed-cache' ),
			'wp super cache'   => array( array( array( 'function', 'wp_cache_phase2' ), array( 'constant_true', 'WP_CACHE' ) ), 'wp-super-cache' ),
			'w3 total cache'   => array( array( array( 'constant', 'W3TC' ) ), 'w3-total-cache' ),
			'wp fastest cache' => array( array( array( 'class', 'WpFastestCache' ) ), 'wp-fastest-cache' ),
			'unknown WP_CACHE' => array( array( array( 'constant_true', 'WP_CACHE' ) ), 'generic' ),
			'advanced-cache'   => array( array( array( 'file', 'advanced-cache.php' ) ), 'generic' ),
		);
	}

	public function test_markers_are_a_sticky_lowercase_set_cleared_on_demand(): void {
		$context = $this->context();
		$context->record_markers(
			array(
				'HTTP_CF_RAY' => 'abc',
				'HTTP_VIA'    => '1.1 varnish',
				'HTTP_HOST'   => 'example.org',
			)
		);
		$context->record_markers( array( 'HTTP_X_CACHE' => 'HIT' ) );
		$context->record_markers( array() );

		$this->assertSame( array( 'cf-ray', 'via', 'x-cache' ), $context->snapshot()['cdnMarkers'] );

		$context->clear_markers( array( 'cf-ray', 'via', 'x-cache' ) );
		$this->assertSame( array(), $context->snapshot()['cdnMarkers'] );
		$this->assertArrayNotHasKey( CaptureContext::MARKERS_OPTION, $this->store );
	}

	public function test_clearing_keeps_markers_recorded_after_the_snapshot(): void {
		$context = $this->context();
		$context->record_markers( array( 'HTTP_CF_RAY' => 'abc' ) );
		$sent = $context->snapshot()['cdnMarkers'];

		// Another request records a marker while the flush is posting.
		$context->record_markers( array( 'HTTP_VIA' => '1.1 varnish' ) );
		$context->clear_markers( $sent );

		$this->assertSame( array( 'via' ), $context->snapshot()['cdnMarkers'] );
	}

	/**
	 * @param callable|null $probe Signal probe.
	 */
	public function test_the_exclusion_reports_true_only_where_it_is_applied(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/FakeCachePlugins.php';
		\W3TC\Config::$values = array();
		$this->store[ Options::SETTINGS ]['exclude_ai_from_page_cache'] = true;
		$options   = new Options();
		$exclusion = new PageCacheExclusion( $options );
		$exclusion->replace( 'v1', array( 'ChatGPT-User' ), time() );
		$w3tc    = static fn( string $kind, string $name ): bool => 'constant' === $kind && 'W3TC' === $name;
		$context = new CaptureContext( $options, new VendorRanges( $options ), $w3tc, $exclusion );

		$this->assertFalse( $context->snapshot()['pageCacheExclusion'], 'Not applied yet.' );

		$exclusion->sync( $context->page_cache() );

		$this->assertSame( 'w3-total-cache', $context->snapshot()['pageCache'] );
		$this->assertTrue( $context->snapshot()['pageCacheExclusion'] );

		$none = new CaptureContext( $options, new VendorRanges( $options ), static fn() => false, $exclusion );
		$this->assertFalse( $none->snapshot()['pageCacheExclusion'], 'Applied to a cache no longer detected.' );
	}

	private function context( ?callable $probe = null ): CaptureContext {
		$options = new Options();
		return new CaptureContext( $options, new VendorRanges( $options ), $probe ?? static fn() => false );
	}
}
