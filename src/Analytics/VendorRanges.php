<?php
/**
 * The synced AI vendor IP range list, and matching a client IP against it.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Settings\Options;

/**
 * Pulls ``GET <ingest endpoint>/ranges`` (contract section 2) at most once a
 * day and answers "did this request come from a published vendor address?" in
 * memory, so a site event carries ``verified`` + ``vendor`` and never the ip.
 *
 * The fetch is independent of the artefact sync and of any published bundle:
 * it only needs the ingest credentials. It runs from the hourly refresh tick and
 * from the flush tick, whichever comes first; the daily gate makes the second
 * a no-op. A failed fetch keeps the previous list.
 *
 * Stored in one non-autoloaded option, ``gtaip_vendor_ranges``, written only by
 * {@see refresh()}. Missing or older than {@see STALE_AFTER_SECONDS}, the list
 * answers ``verified = null``: a stale list degrades verification, never
 * capture.
 *
 * The same document carries the AI referrer list (``aiReferrers``), which the
 * same fetch hands to {@see AiReferrers} to store in an option of its own: the
 * page-view beacon reads that small list and must never load this one. The two
 * move together — a ``200`` replaces both, a ``304`` re-stamps both — and the
 * ``If-None-Match`` is sent only while the referrer list is stored at this
 * list's version. Otherwise a ``304`` could never fill it: an install upgrading
 * from 0.8.x holds current ranges and no referrer list at all.
 *
 * The cache exclusion list (``cacheExclusionUserAgents``) rides along the same
 * way, into {@see PageCacheExclusion}'s own option, under the same rule: the
 * ``If-None-Match`` is sent only while it too is stored at this version, so an
 * install upgrading from 0.9.x gets the list on its next fetch.
 *
 * The AI crawler registry (``aiUserAgents``) rides along under the same rule,
 * into {@see AiUserAgents}' own option: ``/robots.txt`` reads it and must never
 * load this list.
 */
final class VendorRanges {

	public const OPTION = 'gtaip_vendor_ranges';

	/**
	 * At most one fetch attempt per this many seconds.
	 */
	public const FETCH_INTERVAL_SECONDS = DAY_IN_SECONDS;

	/**
	 * Past this age the list no longer vouches for anything.
	 */
	public const STALE_AFTER_SECONDS = 14 * DAY_IN_SECONDS;

	/**
	 * Cap on the body read; the real list is tens of KB.
	 */
	private const MAX_BODY_BYTES = 2097152;

	private const TIMEOUT_SECONDS = 20;

	/**
	 * The IPv4-mapped IPv6 prefix (::ffff:0:0/96).
	 */
	private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

	private Options $options;

	private AiReferrers $referrers;

	private ?PageCacheExclusion $exclusion;

	private ?AiUserAgents $user_agents;

	/**
	 * Packed spans for this request, built on first use.
	 *
	 * @var array<int, array{0:string, 1:string, 2:string}>|null
	 */
	private ?array $compiled = null;

	/**
	 * Wire the settings and the store for the document's AI referrer list.
	 *
	 * @param Options                 $options   Plugin settings.
	 * @param AiReferrers|null        $referrers Where the document's AI referrer list is stored.
	 * @param PageCacheExclusion|null $exclusion Where the document's cache exclusion list is stored.
	 * @param AiUserAgents|null       $user_agents Where the document's AI crawler registry is stored.
	 */
	public function __construct( Options $options, ?AiReferrers $referrers = null, ?PageCacheExclusion $exclusion = null, ?AiUserAgents $user_agents = null ) {
		$this->options     = $options;
		$this->referrers   = $referrers ?? new AiReferrers();
		$this->exclusion   = $exclusion;
		$this->user_agents = $user_agents;
	}

	/**
	 * The stored list, normalised. Empty array when there is none.
	 *
	 * @return array{version:string, fetched_at:int, checked_at:int, ranges:array<int, array{platform:string, start:string, end:string}>}|array{}
	 */
	public function stored(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['version'], $stored['ranges'] ) || ! is_array( $stored['ranges'] ) ) {
			return array();
		}
		return array(
			'version'    => (string) $stored['version'],
			'fetched_at' => (int) ( $stored['fetched_at'] ?? 0 ),
			'checked_at' => (int) ( $stored['checked_at'] ?? 0 ),
			'ranges'     => $stored['ranges'],
		);
	}

	/**
	 * The cached list's version, or null when there is none.
	 */
	public function version(): ?string {
		$stored = $this->stored();
		return array() === $stored ? null : $stored['version'];
	}

	/**
	 * When the list was last confirmed current (fetched or 304), or 0.
	 */
	public function fetched_at(): int {
		$stored = $this->stored();
		return array() === $stored ? 0 : $stored['fetched_at'];
	}

	/**
	 * Whether the list exists and is at most 14 days old.
	 *
	 * @param int|null $now Unix time (defaults to now).
	 */
	public function is_fresh( ?int $now = null ): bool {
		$fetched = $this->fetched_at();
		return $fetched > 0 && ( $now ?? time() ) - $fetched <= self::STALE_AFTER_SECONDS;
	}

	/**
	 * Verify a client IP against the list.
	 *
	 * ``true`` + platform when the ip is inside a range; ``false`` when the list
	 * is fresh and the ip is in none (or is not an address); ``null`` when the
	 * list is missing or stale.
	 *
	 * @param string $ip Resolved client IP.
	 * @return array{verified: bool|null, vendor: string|null}
	 */
	public function verify( string $ip ): array {
		if ( ! $this->is_fresh() ) {
			return array(
				'verified' => null,
				'vendor'   => null,
			);
		}
		$address = IpRange::packed( $ip );
		// A dual-stack listener reports an IPv4 peer as ::ffff:a.b.c.d; the
		// vendor ranges list it as plain IPv4.
		if ( null !== $address && 16 === strlen( $address ) && 0 === strpos( $address, self::IPV4_MAPPED_PREFIX ) ) {
			$address = substr( $address, 12 );
		}
		if ( null !== $address ) {
			foreach ( $this->compiled() as $span ) {
				if ( IpRange::in_span( $address, $span[0], $span[1] ) ) {
					return array(
						'verified' => true,
						'vendor'   => $span[2],
					);
				}
			}
		}
		return array(
			'verified' => false,
			'vendor'   => null,
		);
	}

	/**
	 * Whether a fetch attempt is due: none in the last day, or none in the
	 * last hour while the AI referrer list is behind this one.
	 *
	 * The second case is the upgrade from 0.8.x: the ranges were checked
	 * hours ago and no referrer list exists yet. Waiting out the day would
	 * leave every AI referral counted as ``none`` until then; the hourly floor
	 * keeps a failing endpoint from being asked on every 15-minute flush.
	 *
	 * @param int|null $now Unix time (defaults to now).
	 */
	public function is_due( ?int $now = null ): bool {
		$stored  = get_option( self::OPTION, array() );
		$checked = is_array( $stored ) ? (int) ( $stored['checked_at'] ?? 0 ) : 0;
		$elapsed = ( $now ?? time() ) - $checked;
		if ( $elapsed >= self::FETCH_INTERVAL_SECONDS ) {
			return true;
		}
		return $elapsed >= HOUR_IN_SECONDS && ! $this->companions_current( $this->version() );
	}

	/**
	 * Cron callback: fetch the list when reporting is configured and a fetch is
	 * due. Never throws.
	 */
	public function refresh_if_due(): void {
		try {
			if ( $this->options->is_reporting_configured() && $this->is_due() ) {
				$this->refresh();
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Fetch the list now. Returns true when the stored list is current
	 * afterwards (200 stored, or 304).
	 *
	 * Every attempt stamps ``checked_at``, so a failing endpoint is tried once a
	 * day rather than once an hour; the previous list is kept untouched, and so
	 * is the AI referrer list.
	 */
	public function refresh(): bool {
		$now     = time();
		$stored  = $this->stored();
		$headers = array(
			'Authorization' => 'Bearer ' . $this->options->ingest_token(),
			'Accept'        => 'application/json',
		);
		if ( array() !== $stored && '' !== $stored['version'] && $this->companions_current( $stored['version'] ) ) {
			$headers['If-None-Match'] = '"' . $stored['version'] . '"';
		}

		$url = self::url( $this->options->ingest_endpoint() );
		if ( '' === $url ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT_SECONDS,
				'headers'             => $headers,
				'user-agent'          => 'GlobetrottersAiPresence/' . \Globetrotters\AiPresence\VERSION,
				// Same rules as the ingest POST: the bearer token never follows a
				// redirect, and the pasted host keeps the SSRF guard.
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_BODY_BYTES,
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 304 === $code && array() !== $stored ) {
			$stored['fetched_at'] = $now;
			$stored['checked_at'] = $now;
			$this->write( $stored );
			$this->referrers->restamp( $now );
			if ( null !== $this->exclusion ) {
				$this->exclusion->restamp( $now );
			}
			if ( null !== $this->user_agents ) {
				$this->user_agents->restamp( $now );
			}
			return true;
		}

		$parsed = 200 === $code ? self::parse( (string) wp_remote_retrieve_body( $response ) ) : null;
		if ( null === $parsed ) {
			$this->mark_checked( $stored, $now );
			return false;
		}

		$this->write(
			array(
				'version'    => $parsed['version'],
				'fetched_at' => $now,
				'checked_at' => $now,
				'ranges'     => $parsed['ranges'],
			)
		);
		$this->referrers->replace( $parsed['version'], $parsed['ai_referrers'], $now );
		if ( null !== $this->exclusion ) {
			$this->exclusion->replace( $parsed['version'], $parsed['cache_exclusion'], $now );
		}
		if ( null !== $this->user_agents ) {
			$this->user_agents->replace( $parsed['version'], $parsed['ai_user_agents'], $now );
		}
		return true;
	}

	/**
	 * Whether the lists stored beside this one (the AI referrer list, and the
	 * cache exclusion list and AI crawler registry when wired) are at ``$version``. A ``304`` can only
	 * keep a list current, never fill it, so until they are, no
	 * ``If-None-Match`` is sent.
	 *
	 * @param string|null $version This list's version.
	 */
	private function companions_current( ?string $version ): bool {
		return $this->referrers->version() === $version
			&& ( null === $this->exclusion || $this->exclusion->version() === $version )
			&& ( null === $this->user_agents || $this->user_agents->version() === $version );
	}

	/**
	 * The ranges URL for an ingest endpoint, or '' when there is none.
	 *
	 * @param string $endpoint Configured ingest endpoint (no trailing slash).
	 */
	public static function url( string $endpoint ): string {
		return '' === $endpoint ? '' : rtrim( $endpoint, '/' ) . '/ranges';
	}

	/**
	 * Validate a 200 body. Null when it is not a usable list; invalid entries are
	 * skipped, an entry whose bounds differ in family or run backwards too.
	 *
	 * ``ai_referrers`` is the document's ``aiReferrers``, validated by
	 * {@see AiReferrers::parse()}: two empty lists when the backend sends none.
	 *
	 * ``cache_exclusion`` is its ``cacheExclusionUserAgents``, validated by
	 * {@see PageCacheExclusion::parse()}: empty when the backend sends none.
	 *
	 * ``ai_user_agents`` is its ``aiUserAgents``, validated by
	 * {@see AiUserAgents::parse()}: empty when the backend sends none.
	 *
	 * @param string $body Response body.
	 * @return array{version:string, ranges:array<int, array{platform:string, start:string, end:string}>, ai_referrers:array{hosts:array<string, string>, utmSources:array<string, string>}, cache_exclusion:string[], ai_user_agents:array<int, array{name:string, fallbackGroup?:string}>}|null
	 */
	public static function parse( string $body ): ?array {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['version'], $data['ranges'] ) || ! is_string( $data['version'] ) || ! is_array( $data['ranges'] ) ) {
			return null;
		}
		$version = substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $data['version'] ), 0, 64 );
		if ( '' === $version ) {
			return null;
		}

		$ranges = array();
		foreach ( $data['ranges'] as $range ) {
			if ( ! is_array( $range ) || ! isset( $range['platform'], $range['start'], $range['end'] ) ) {
				continue;
			}
			$platform = is_string( $range['platform'] ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $range['platform'] ) ), 0, 32 ) : '';
			$start    = IpRange::packed( (string) $range['start'] );
			$end      = IpRange::packed( (string) $range['end'] );
			if ( '' === $platform || null === $start || null === $end || strlen( $start ) !== strlen( $end ) || strcmp( $start, $end ) > 0 ) {
				continue;
			}
			$ranges[] = array(
				'platform' => $platform,
				'start'    => (string) $range['start'],
				'end'      => (string) $range['end'],
			);
		}

		return array(
			'version'         => $version,
			'ranges'          => $ranges,
			'ai_referrers'    => AiReferrers::parse( $data['aiReferrers'] ?? null ),
			'cache_exclusion' => PageCacheExclusion::parse( $data['cacheExclusionUserAgents'] ?? null ),
			'ai_user_agents'  => AiUserAgents::parse( $data['aiUserAgents'] ?? null ),
		);
	}

	/**
	 * Stamp an attempt without touching the list.
	 *
	 * @param array<string, mixed> $stored Current stored list (may be empty).
	 * @param int                  $now    Unix time.
	 */
	private function mark_checked( array $stored, int $now ): void {
		if ( array() === $stored ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
		}
		$stored['checked_at'] = $now;
		$this->write( $stored );
	}

	/**
	 * The one writer of the option. Not autoloaded: only captured bot requests,
	 * the flush and the settings screen read it.
	 *
	 * @param array<string, mixed> $value Option value.
	 */
	private function write( array $value ): void {
		update_option( self::OPTION, $value, false );
		$this->compiled = null;
	}

	/**
	 * The ranges in packed form, built once per request.
	 *
	 * @return array<int, array{0:string, 1:string, 2:string}>
	 */
	private function compiled(): array {
		if ( null !== $this->compiled ) {
			return $this->compiled;
		}
		$this->compiled = array();
		$stored         = $this->stored();
		foreach ( array() === $stored ? array() : $stored['ranges'] as $range ) {
			if ( ! is_array( $range ) ) {
				continue;
			}
			$start = IpRange::packed( (string) ( $range['start'] ?? '' ) );
			$end   = IpRange::packed( (string) ( $range['end'] ?? '' ) );
			if ( null !== $start && null !== $end ) {
				$this->compiled[] = array( $start, $end, (string) ( $range['platform'] ?? '' ) );
			}
		}
		return $this->compiled;
	}
}
