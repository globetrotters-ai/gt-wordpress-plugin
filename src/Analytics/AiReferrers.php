<?php
/**
 * The synced AI referrer list, and matching a page view's referral hints
 * against it.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Answers "did this visitor arrive from an AI answer, and from which
 * assistant?" for one page-view beacon, keeping only the platform.
 *
 * The list is the ``aiReferrers`` member of the ``GET <ingest endpoint>/ranges``
 * document — ``hosts`` (referrer host → platform), ``utmSources``
 * (``utm_source`` value → platform) and ``searchHosts`` (the mixed search hosts
 * behind which ``utm_source`` is never read), built upstream from the backend's
 * AI referrer registry. The daily ranges sync fetches it in the same request as
 * the vendor IP ranges and stores it here, in an option of its own:
 *
 * - The beacon endpoint reads only this option, and only when a beacon carries
 *   a hint ({@see match()}). It never loads the vendor range list, which is two
 *   orders of magnitude larger and has nothing to say about a person's click.
 *   That is why this class knows nothing about the ranges, and why the sync
 *   writes it rather than the other way round.
 * - A beacon with no hint — a direct visit, an internal click — does not read
 *   the option at all.
 *
 * Stored in one non-autoloaded option, ``gtaip_ai_referrers``:
 * ``{version, fetched_at, hosts, utmSources, searchHosts}``, where ``version`` is
 * the ranges document's, so the sync can tell when this list is missing or
 * behind it. A list stored before ``searchHosts`` existed still matches, with no
 * search hosts, but reports no version, so the next sync fetches the key.
 * Missing, unreadable or older than {@see STALE_AFTER_SECONDS}, the list
 * matches nothing and every view counts as {@see NONE}: a stale list degrades
 * attribution, never counting.
 *
 * The referrer host and ``utm_source`` value passed to {@see match()} are
 * compared and dropped. They are never stored, logged or sent; only the
 * platform they resolve to leaves this class.
 */
final class AiReferrers {

	public const OPTION = 'gtaip_ai_referrers';

	/**
	 * The platform of a view that matched nothing. Also the backend's value.
	 */
	public const NONE = 'none';

	/**
	 * Past this age the list no longer attributes anything; the same window
	 * as the vendor ranges.
	 */
	public const STALE_AFTER_SECONDS = 14 * DAY_IN_SECONDS;

	/**
	 * The backend's ``agent_platform`` grammar. An entry whose platform does not
	 * fit is skipped, never repaired.
	 */
	public const PLATFORM_PATTERN = '/^[a-z0-9_-]{1,32}$/';

	/**
	 * Longest key kept: a DNS name's limit. The beacon sends no ``utm_source``
	 * longer than 100 characters.
	 */
	private const MAX_KEY_BYTES = 253;

	/**
	 * What ``<name>.*`` stands for in a ``searchHosts`` pattern: one or two TLD
	 * labels of two or more letters, as the backend's search-host grammar has it.
	 */
	private const ANY_TLD = '[a-z]{2,}(?:\.[a-z]{2,})?';

	/**
	 * The platform a beacon's referral hints resolve to, or {@see NONE}.
	 *
	 * The referrer host is tried first and wins: it is set by the browser,
	 * while ``utm_source`` is whatever the link carried. ``utm_source`` is read
	 * only when the host is empty or none of ``searchHosts``: a tagged link
	 * clicked in a search engine's results is a search click. Neither is kept.
	 *
	 * @param string   $referrer_host The beacon's ``r``: the referrer's host.
	 * @param string   $utm_source    The beacon's ``u``: the page's ``utm_source``.
	 * @param int|null $now           Unix time (defaults to now).
	 */
	public function match( string $referrer_host, string $utm_source, ?int $now = null ): string {
		$host   = self::normalise_host( $referrer_host );
		$source = self::normalise_utm_source( $utm_source );
		// The common case — no hint at all — never touches the option.
		if ( '' === $host && '' === $source ) {
			return self::NONE;
		}

		$stored = $this->stored();
		if ( ! $this->is_fresh_list( $stored, $now ) ) {
			return self::NONE;
		}

		// A host entry decides, even one mapped to ``none``.
		$platform = self::lookup( $stored['hosts'], $host );
		if ( null !== $platform ) {
			return $platform;
		}
		if ( self::is_search_host( $stored['searchHosts'], $host ) ) {
			return self::NONE;
		}
		return self::lookup( $stored['utmSources'], $source ) ?? self::NONE;
	}

	/**
	 * The stored list. Empty array when there is none or it is unreadable.
	 *
	 * A list stored before ``searchHosts`` existed reads with none.
	 *
	 * @return array{version:string, fetched_at:int, hosts:array<string, mixed>, utmSources:array<string, mixed>, searchHosts:array<int, string>}|array{}
	 */
	public function stored(): array {
		$stored = get_option( self::OPTION, array() );
		if (
			! is_array( $stored )
			|| ! isset( $stored['version'], $stored['fetched_at'], $stored['hosts'], $stored['utmSources'] )
			|| ! is_array( $stored['hosts'] )
			|| ! is_array( $stored['utmSources'] )
		) {
			return array();
		}
		return array(
			'version'     => (string) $stored['version'],
			'fetched_at'  => (int) $stored['fetched_at'],
			'hosts'       => $stored['hosts'],
			'utmSources'  => $stored['utmSources'],
			'searchHosts' => is_array( $stored['searchHosts'] ?? null )
				? array_values( array_filter( $stored['searchHosts'], 'is_string' ) )
				: array(),
		);
	}

	/**
	 * The stored list's version, or null when there is none.
	 *
	 * Also null for a list stored before ``searchHosts`` existed: the ranges
	 * sync then sends no ``If-None-Match``, and a ``200`` brings the key. A
	 * ``304`` would keep the old shape until the backend's version next moved.
	 */
	public function version(): ?string {
		$stored = $this->stored();
		if ( array() === $stored ) {
			return null;
		}
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) && array_key_exists( 'searchHosts', $raw ) ? $stored['version'] : null;
	}

	/**
	 * Whether a list is stored and at most 14 days old.
	 *
	 * @param int|null $now Unix time (defaults to now).
	 */
	public function is_fresh( ?int $now = null ): bool {
		return $this->is_fresh_list( $this->stored(), $now );
	}

	/**
	 * Replace the list with a freshly fetched one.
	 *
	 * Not autoloaded: only a beacon carrying a hint, the sync and the settings
	 * screen read it.
	 *
	 * @param string                      $version The ranges document's version.
	 * @param array<string, array<mixed>> $lists   Output of {@see parse()}.
	 * @param int                         $now     Unix time.
	 */
	public function replace( string $version, array $lists, int $now ): void {
		update_option(
			self::OPTION,
			array(
				'version'     => $version,
				'fetched_at'  => $now,
				'hosts'       => $lists['hosts'],
				'utmSources'  => $lists['utmSources'],
				'searchHosts' => $lists['searchHosts'],
			),
			false
		);
	}

	/**
	 * Mark the stored list current again, after a ``304`` on the ranges
	 * document. Nothing to stamp when there is no list.
	 *
	 * @param int $now Unix time.
	 */
	public function restamp( int $now ): void {
		$stored = $this->stored();
		if ( array() === $stored ) {
			return;
		}
		$stored['fetched_at'] = $now;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Validate the ``aiReferrers`` member of a ranges document.
	 *
	 * Absent or malformed reads as empty lists, so a backend that predates
	 * the list still syncs and every view counts as {@see NONE}. Keys are
	 * normalised the way {@see match()} normalises a hint; an entry with an
	 * empty or oversized key, or a platform outside {@see PLATFORM_PATTERN}, is
	 * skipped. ``searchHosts`` (optional, added after the other two) keeps its
	 * non-empty string patterns, trimmed and lower-cased; absent, it is empty
	 * and ``utm_source`` is read behind every host, as before it existed.
	 *
	 * @param mixed $value Decoded ``aiReferrers``.
	 * @return array{hosts:array<string, string>, utmSources:array<string, string>, searchHosts:array<int, string>}
	 */
	public static function parse( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'hosts'       => self::parse_list( $value['hosts'] ?? null, array( self::class, 'normalise_host' ) ),
			'utmSources'  => self::parse_list( $value['utmSources'] ?? null, array( self::class, 'normalise_utm_source' ) ),
			'searchHosts' => self::parse_patterns( $value['searchHosts'] ?? null ),
		);
	}

	/**
	 * A referrer host in the list's form: lower-cased, without a ``:port`` or
	 * a trailing dot.
	 *
	 * @param string $host Referrer host as the beacon sent it.
	 */
	public static function normalise_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '/:\d*$/', '', $host );
		return rtrim( $host, '.' );
	}

	/**
	 * A ``utm_source`` value in the list's form: trimmed, lower-cased and cut
	 * at its first ``?`` — Copilot sends ``copilot.com?form=MG0AUO``. The cut
	 * value is then looked up exactly, never as a prefix.
	 *
	 * @param string $source ``utm_source`` as the beacon sent it.
	 */
	public static function normalise_utm_source( string $source ): string {
		$source = strtolower( trim( $source ) );
		$cut    = strpos( $source, '?' );
		return false === $cut ? $source : substr( $source, 0, $cut );
	}

	/**
	 * The platform a normalised key resolves to in one list, or null when the
	 * list has no valid entry for it.
	 *
	 * @param array<string, mixed> $entries Stored ``{key: platform}`` list.
	 * @param string               $key     Normalised hint.
	 */
	private static function lookup( array $entries, string $key ): ?string {
		$platform = '' === $key ? null : ( $entries[ $key ] ?? null );
		return is_string( $platform ) && 1 === preg_match( self::PLATFORM_PATTERN, $platform ) ? $platform : null;
	}

	/**
	 * Whether a normalised referrer host is one of the mixed search hosts.
	 *
	 * A bare pattern matches exactly; ``<name>.*`` matches ``<name>.`` plus
	 * {@see ANY_TLD} (``google.*`` is ``google.fr`` and ``google.co.uk``, never
	 * ``gemini.google.com``). An empty list matches nothing, not even the empty
	 * host. The backend's search-host grammar.
	 *
	 * @param array<int, string> $patterns Stored ``searchHosts``.
	 * @param string             $host     Normalised referrer host.
	 */
	private static function is_search_host( array $patterns, string $host ): bool {
		if ( array() === $patterns ) {
			return false;
		}
		$parts = array();
		foreach ( $patterns as $pattern ) {
			$parts[] = '.*' === substr( $pattern, -2 )
				? preg_quote( substr( $pattern, 0, -1 ), '/' ) . self::ANY_TLD
				: preg_quote( $pattern, '/' );
		}
		return 1 === preg_match( '/^(?:' . implode( '|', $parts ) . ')$/D', $host );
	}

	/**
	 * The validated ``searchHosts`` patterns.
	 *
	 * @param mixed $patterns Decoded ``searchHosts``.
	 * @return array<int, string>
	 */
	private static function parse_patterns( $patterns ): array {
		if ( ! is_array( $patterns ) ) {
			return array();
		}
		$parsed = array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			$pattern = strtolower( trim( $pattern ) );
			if ( '' !== $pattern && strlen( $pattern ) <= self::MAX_KEY_BYTES ) {
				$parsed[] = $pattern;
			}
		}
		return $parsed;
	}

	/**
	 * One validated ``{key: platform}`` list.
	 *
	 * @param mixed    $entries   Decoded list.
	 * @param callable $normalise Key normaliser.
	 * @return array<string, string>
	 */
	private static function parse_list( $entries, callable $normalise ): array {
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$parsed = array();
		foreach ( $entries as $key => $platform ) {
			$key = $normalise( (string) $key );
			if (
				'' === $key
				|| strlen( $key ) > self::MAX_KEY_BYTES
				|| ! is_string( $platform )
				|| 1 !== preg_match( self::PLATFORM_PATTERN, $platform )
			) {
				continue;
			}
			$parsed[ $key ] = $platform;
		}
		return $parsed;
	}

	/**
	 * Whether a stored list exists and is at most 14 days old.
	 *
	 * @param array<string, mixed> $stored Output of {@see stored()}.
	 * @param int|null             $now    Unix time (defaults to now).
	 */
	private function is_fresh_list( array $stored, ?int $now ): bool {
		$fetched = (int) ( $stored['fetched_at'] ?? 0 );
		return $fetched > 0 && ( $now ?? time() ) - $fetched <= self::STALE_AFTER_SECONDS;
	}
}
