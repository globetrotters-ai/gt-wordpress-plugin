<?php
/**
 * The cache-topology self-report sent with every flush.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Settings\Options;

/**
 * Builds the ``captureContext`` envelope block (contract section 1, design E):
 * whether site capture and the page-view counter are on, which full-page cache
 * is installed, which CDN or proxy markers recent requests carried, and the age
 * of the vendor range list. The backend turns it into a "complete" or "floor"
 * confidence badge, and reads ``pageViewCounter`` to tell a counter that is off
 * from one that counted nobody. ``pageCacheExclusion`` says the page cache
 * no longer answers AI agents ({@see PageCacheExclusion}), which lifts the
 * "floor" a page cache alone would set.
 *
 * CDN markers are a sticky set in a small autoloaded option,
 * ``gtaip_cdn_markers``: reading it is free on the serve path, and it is only
 * written when a request carries a marker the set does not hold yet. It is
 * cleared, as far as the flush carried them, after the endpoint accepted it.
 */
final class CaptureContext {

	public const MARKERS_OPTION = 'gtaip_cdn_markers';

	/**
	 * Contract marker name => ``$_SERVER`` key.
	 */
	private const MARKERS = array(
		'cf-ray'               => 'HTTP_CF_RAY',
		'x-varnish'            => 'HTTP_X_VARNISH',
		'via'                  => 'HTTP_VIA',
		'x-cache'              => 'HTTP_X_CACHE',
		'x-fastly-request-id'  => 'HTTP_X_FASTLY_REQUEST_ID',
		'x-akamai-transformed' => 'HTTP_X_AKAMAI_TRANSFORMED',
		'x-sucuri-id'          => 'HTTP_X_SUCURI_ID',
		'x-served-by'          => 'HTTP_X_SERVED_BY',
	);

	/**
	 * Page-cache plugin => signals, checked in order. Each signal is
	 * ``[kind, name]`` with kind ``constant``, ``function`` or ``class``.
	 */
	private const PAGE_CACHES = array(
		'wp-rocket'        => array( array( 'constant', 'WP_ROCKET_VERSION' ), array( 'function', 'rocket_clean_domain' ) ),
		'litespeed-cache'  => array( array( 'constant', 'LSCWP_V' ), array( 'class', 'LiteSpeed\\Core' ) ),
		'wp-super-cache'   => array( array( 'constant', 'WPCACHEHOME' ), array( 'function', 'wp_cache_phase2' ) ),
		'w3-total-cache'   => array( array( 'constant', 'W3TC' ), array( 'class', 'W3TC\\Root_Loader' ) ),
		'wp-fastest-cache' => array( array( 'constant', 'WPFC_WP_CONTENT_DIR' ), array( 'class', 'WpFastestCache' ) ),
	);

	private Options $options;
	private VendorRanges $ranges;
	private ?PageCacheExclusion $exclusion;

	/**
	 * Signal probe ``(kind, name) => bool``; the runtime one unless a test
	 * injects its own.
	 *
	 * @var callable
	 */
	private $probe;

	/**
	 * Build the context provider.
	 *
	 * @param Options                 $options   Plugin options.
	 * @param VendorRanges            $ranges    Vendor range list.
	 * @param callable|null           $probe     Signal probe, for tests.
	 * @param PageCacheExclusion|null $exclusion The page-cache exclusion.
	 */
	public function __construct( Options $options, VendorRanges $ranges, ?callable $probe = null, ?PageCacheExclusion $exclusion = null ) {
		$this->options   = $options;
		$this->ranges    = $ranges;
		$this->probe     = $probe ?? array( self::class, 'runtime_probe' );
		$this->exclusion = $exclusion;
	}

	/**
	 * The block, camelCase, exactly as it goes on the wire.
	 *
	 * ``servedVersion`` is the bundle version a web request last served (the
	 * heartbeat {@see ArtefactCache} leaves), not the one the flushing process
	 * would read: the flush runs from cron, which may be WP-CLI behind a
	 * different object cache, and the backend compares this with what it
	 * published. Same key and format as the Symfony bundle 0.9.4: the apex
	 * version id (``2026-10-18-050325``), or null.
	 *
	 * @return array{siteCapture:bool, pageViewCounter:bool, pageCache:string|null, pageCacheExclusion:bool, cdnMarkers:string[], rangesVersion:string|null, rangesFetchedAt:string|null, servedVersion:string|null}
	 */
	public function snapshot(): array {
		$fetched    = $this->ranges->fetched_at();
		$page_cache = $this->page_cache();
		return array(
			'siteCapture'        => $this->options->captures_site_bots(),
			'pageViewCounter'    => $this->options->counts_page_views(),
			'pageCache'          => $page_cache,
			'pageCacheExclusion' => null !== $this->exclusion && $this->exclusion->is_active( $page_cache ),
			'cdnMarkers'         => $this->markers(),
			'rangesVersion'      => $this->ranges->version(),
			'rangesFetchedAt'    => $fetched > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $fetched ) : null,
			'servedVersion'      => ArtefactCache::served_version(),
		);
	}

	/**
	 * The detected full-page cache, ``generic`` when ``WP_CACHE`` is on but the
	 * plugin behind it is unknown, or null.
	 */
	public function page_cache(): ?string {
		$probe = $this->probe;
		foreach ( self::PAGE_CACHES as $name => $signals ) {
			foreach ( $signals as $signal ) {
				if ( $probe( $signal[0], $signal[1] ) ) {
					return $name;
				}
			}
		}
		return $probe( 'constant_true', 'WP_CACHE' ) || $probe( 'file', 'advanced-cache.php' ) ? 'generic' : null;
	}

	/**
	 * Remember the CDN/proxy markers a request carries. Writes only when one is
	 * new. Never throws.
	 *
	 * @param array<string, mixed> $server Request superglobal.
	 */
	public function record_markers( array $server ): void {
		try {
			$seen = array();
			foreach ( self::MARKERS as $name => $key ) {
				if ( isset( $server[ $key ] ) && '' !== $server[ $key ] ) {
					$seen[] = $name;
				}
			}
			if ( array() === $seen ) {
				return;
			}
			$known = $this->markers();
			$new   = array_diff( $seen, $known );
			if ( array() === $new ) {
				return;
			}
			$all = array_values( array_unique( array_merge( $known, $new ) ) );
			sort( $all );
			update_option( self::MARKERS_OPTION, $all, true );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * The ``$_SERVER`` keys the markers are read from, for the request-value
	 * copies that feed {@see record_markers()}.
	 *
	 * @return string[]
	 */
	public static function server_keys(): array {
		return array_values( self::MARKERS );
	}

	/**
	 * The markers seen since the last accepted flush, sorted.
	 *
	 * @return string[]
	 */
	public function markers(): array {
		$stored = get_option( self::MARKERS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$markers = array_values( array_intersect( array_keys( self::MARKERS ), $stored ) );
		sort( $markers );
		return $markers;
	}

	/**
	 * Forget the markers a flush carried once it was accepted.
	 *
	 * Only those: a marker recorded while the flush was posting was not in its
	 * snapshot and must survive to the next one. The cached copy, filled when
	 * this request began, is evicted first so the stored set is read back.
	 *
	 * @param string[] $sent Markers the accepted flush carried.
	 */
	public function clear_markers( array $sent ): void {
		if ( array() === $sent ) {
			return;
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::MARKERS_OPTION, 'options' );
		$remaining = array_values( array_diff( $this->markers(), $sent ) );
		if ( array() === $remaining ) {
			delete_option( self::MARKERS_OPTION );
			return;
		}
		update_option( self::MARKERS_OPTION, $remaining, true );
	}

	/**
	 * The runtime signal probe.
	 *
	 * @param string $kind ``constant``, ``constant_true``, ``function``, ``class`` or ``file`` (in wp-content).
	 * @param string $name Symbol name.
	 */
	public static function runtime_probe( string $kind, string $name ): bool {
		switch ( $kind ) {
			case 'constant':
				return defined( $name );
			case 'constant_true':
				return defined( $name ) && true === filter_var( constant( $name ), FILTER_VALIDATE_BOOLEAN );
			case 'function':
				return function_exists( $name );
			case 'class':
				return class_exists( $name, false );
			case 'file':
				return defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/' . $name );
		}
		return false;
	}
}
