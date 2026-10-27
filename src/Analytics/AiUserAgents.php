<?php
/**
 * The synced AI crawler registry robots.txt names.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * The ``aiUserAgents`` member of the ``GET <ingest endpoint>/ranges`` document:
 * the AI crawlers robots.txt names, in emission order, each optionally carrying
 * a ``fallbackGroup`` (the agent whose group it reads when the site names none
 * of its own). The daily ranges sync stores it here, so a new crawler reaches
 * every reporting install with no plugin release.
 *
 * Stored in one non-autoloaded option, ``gtaip_ai_user_agents``:
 * ``{version, fetched_at, agents}``, where ``version`` is the ranges
 * document's. Kept apart from the ranges because ``/robots.txt`` reads it and
 * must never load the vendor IP list. The sync writes it; this class knows
 * nothing about the ranges.
 *
 * Missing, older than {@see STALE_AFTER_SECONDS}, or empty after validation,
 * {@see agents()} is null and {@see \Globetrotters\AiPresence\Serving\RobotsFilter}
 * uses its compiled list. An install without reporting configured never syncs
 * and always uses the compiled list.
 */
final class AiUserAgents {

	public const OPTION = 'gtaip_ai_user_agents';

	/**
	 * Past this age the list is no longer used; the same window as the vendor
	 * ranges.
	 */
	public const STALE_AFTER_SECONDS = 14 * DAY_IN_SECONDS;

	/**
	 * More entries than this and the whole list is rejected.
	 */
	public const MAX_AGENTS = 64;

	/**
	 * A robots.txt product token, the grammar {@see \Globetrotters\AiPresence\Serving\RobotsPolicy}
	 * reads. An entry whose name or fallback group does not fit is skipped,
	 * never repaired.
	 */
	public const TOKEN_PATTERN = '/^[A-Za-z_-]+$/D';

	/**
	 * The fresh, validated registry, or null when robots.txt should use the
	 * compiled list.
	 *
	 * @param int|null $now Unix time (defaults to now).
	 * @return array<int, array{name:string, fallbackGroup?:string}>|null
	 */
	public function agents( ?int $now = null ): ?array {
		$stored = $this->stored();
		if ( array() === $stored || $stored['fetched_at'] <= 0 || ( $now ?? time() ) - $stored['fetched_at'] > self::STALE_AFTER_SECONDS ) {
			return null;
		}
		// Validated again: ``wp option update`` bypasses the sync.
		$agents = self::parse( $stored['agents'] );
		return array() === $agents ? null : $agents;
	}

	/**
	 * The stored list's version, or null when there is none.
	 */
	public function version(): ?string {
		$stored = $this->stored();
		return array() === $stored ? null : $stored['version'];
	}

	/**
	 * Replace the list with a freshly fetched one.
	 *
	 * @param string                                                $version The ranges document's version.
	 * @param array<int, array{name:string, fallbackGroup?:string}> $agents  Output of {@see parse()}.
	 * @param int                                                   $now     Unix time.
	 */
	public function replace( string $version, array $agents, int $now ): void {
		update_option(
			self::OPTION,
			array(
				'version'    => $version,
				'fetched_at' => $now,
				'agents'     => $agents,
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
	 * Validate the ``aiUserAgents`` member of a ranges document.
	 *
	 * Not a list, or more than {@see MAX_AGENTS} entries, reads as empty. Each
	 * entry needs a ``name`` matching {@see TOKEN_PATTERN}; a ``fallbackGroup``,
	 * when present, must match it too. Anything else skips the entry.
	 *
	 * @param mixed $value Decoded ``aiUserAgents``.
	 * @return array<int, array{name:string, fallbackGroup?:string}>
	 */
	public static function parse( $value ): array {
		if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) > self::MAX_AGENTS ) {
			return array();
		}
		$agents = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || ! self::is_token( $entry['name'] ?? null ) ) {
				continue;
			}
			$agent = array( 'name' => $entry['name'] );
			if ( array_key_exists( 'fallbackGroup', $entry ) ) {
				if ( ! self::is_token( $entry['fallbackGroup'] ) ) {
					continue;
				}
				$agent['fallbackGroup'] = $entry['fallbackGroup'];
			}
			$agents[] = $agent;
		}
		return $agents;
	}

	/**
	 * Whether a value is a robots.txt product token.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function is_token( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::TOKEN_PATTERN, $value );
	}

	/**
	 * The stored record. Empty array when there is none or it is unreadable.
	 *
	 * @return array{version:string, fetched_at:int, agents:mixed}|array{}
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		// Typed before any cast: an array ``version`` (``wp option update``)
		// would otherwise raise a warning on every /robots.txt request.
		if (
			! is_array( $stored )
			|| ! isset( $stored['version'], $stored['fetched_at'] )
			|| ! is_string( $stored['version'] )
			|| ! is_int( $stored['fetched_at'] ) && ! ( is_string( $stored['fetched_at'] ) && ctype_digit( $stored['fetched_at'] ) )
			|| ! array_key_exists( 'agents', $stored )
		) {
			return array();
		}
		return array(
			'version'    => (string) $stored['version'],
			'fetched_at' => (int) $stored['fetched_at'],
			'agents'     => $stored['agents'],
		);
	}
}
