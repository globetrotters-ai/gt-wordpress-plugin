<?php
/**
 * Derives the canonical Globetrotters-hosted origin from a fetched ai.json.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Sync;

/**
 * Resolves *where the Globetrotters-hosted copy of the presence actually lives*
 * from the artefacts the plugin already pulls.
 *
 * The ``subdomain_breadcrumb`` profile links back to that host by absolute URL,
 * so the plugin has to know it. It cannot be a settings field: the canonical
 * host flips — ``<slug>.globetrotters.ai`` until a managed hostname activates,
 * ``ai.<their-domain>`` afterwards, and back again on detach — and a pasted
 * value would keep pointing at the previous host with nothing to say it had
 * gone stale.
 *
 * So it is derived, on every refresh, from ``ai.json``. The backend renders that
 * file's ``metadata.relatedFiles`` links against the *canonical origin the
 * bundle was published to* (the active custom host when there is one, else the
 * slug host). Reading them back gives exactly the value that flips, one refresh
 * behind the flip and with no customer action.
 *
 * The two alternatives were checked and rejected:
 *
 * - The Globetrotters edge proxy's ``Link: <…>; rel="canonical"`` header is
 *   gated to HTML 200s. Every artefact this
 *   plugin fetches is JSON or plain text, so the header is never on a response
 *   it sees — and {@see \Globetrotters\AiPresence\Client\FetchResult} carries no
 *   headers anyway.
 * - ``schema.json``'s ``url`` is the *destination's own* website (the customer
 *   apex), not the host the bundle is served from. It would name the wrong site.
 *
 * ``sitemap.xml``'s ``<loc>`` entries would work, but the plugin does not fetch
 * that file and has no other reason to.
 */
final class PresenceOrigin {

	/**
	 * Bundle files the minimal-footprint profiles hand to the Globetrotters
	 * copy by absolute URL. Mirrors the backend's minimal-footprint offload
	 * list.
	 *
	 * Kept as an *offload* list rather than a keep-list for the same reason it
	 * is one upstream: an artefact nobody has classified must default to
	 * staying local at the apex, never to being silently treated as living on a
	 * Globetrotters URL. Here that default is what makes a future
	 * ``relatedFiles`` entry count as evidence of the canonical origin instead
	 * of being quietly ignored. The names below are the only ones that can
	 * legitimately sit on a *different* origin from their siblings, which is
	 * precisely why they must not vote.
	 *
	 * @var string[]
	 */
	private const OFFLOADED = array(
		'llms-full.txt',
		'content.md',
		'index.html',
		'ai/index.md',
		'ai/destination.md',
		'schema/url-map.json',
	);

	/**
	 * Read the canonical origin out of an ``ai.json``.
	 *
	 * Returns an empty string whenever the answer is not unambiguous — a body
	 * that is not the expected shape, links that were rendered relative (no
	 * canonical origin was baked in), or links that disagree about the origin. A
	 * wrong host would be published on the customer's pages, so "nothing" is the
	 * only safe degradation.
	 *
	 * @param string $ai_json Raw ``ai.json`` body.
	 */
	public static function resolve( string $ai_json ): string {
		$decoded = json_decode( $ai_json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$related = $decoded['metadata']['relatedFiles'] ?? null;
		if ( ! is_array( $related ) ) {
			return '';
		}

		$origin = '';
		foreach ( $related as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['url'] ) || ! is_string( $entry['url'] ) ) {
				continue;
			}
			$candidate = self::origin_of( $entry['url'] );
			if ( '' === $candidate ) {
				continue;
			}
			if ( '' === $origin ) {
				$origin = $candidate;
				continue;
			}
			if ( $origin !== $candidate ) {
				return '';
			}
		}

		return $origin;
	}

	/**
	 * The scheme+host(+port) a single ``relatedFiles`` link resolves to, or an
	 * empty string when the link is not usable as evidence.
	 *
	 * @param string $url Link from ``metadata.relatedFiles``.
	 */
	private static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );
		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || '' === $host ) {
			// ``./llms.txt`` — rendered with no canonical origin, so it says
			// nothing about where the bundle is published.
			return '';
		}

		if ( in_array( ltrim( (string) ( $parts['path'] ?? '' ), '/' ), self::OFFLOADED, true ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . strtolower( $host ) . $port;
	}
}
