<?php
/**
 * HTTP client for pulling artefacts from Globetrotters.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Client;

use Globetrotters\AiPresence\Settings\Options;

/**
 * Thin wrapper over ``wp_remote_get`` for fetching public apex artefacts.
 *
 * Every response is normalised to a {@see FetchResult} so the sync layer can
 * treat transport errors, 404s and 200s uniformly.
 */
final class GtClient implements Fetcher {

	private const TIMEOUT_SECONDS = 5;

	/**
	 * Redirect hops followed before giving up — core's own default.
	 */
	public const MAX_REDIRECTS = 5;

	/**
	 * Statuses whose Location is followed. All of them repeat a GET as a GET.
	 */
	private const REDIRECT_STATUSES = array( 301, 302, 303, 307, 308 );

	/**
	 * Fetch a single URL, following https redirects only.
	 *
	 * Core is never left to follow a redirect itself: its validate_redirects()
	 * accepts an ``http://`` Location, so an https source answering 3xx to
	 * http would be read in cleartext and published at the customer's apex.
	 * Each hop is followed here instead, and only to an absolute https target
	 * ({@see Options::is_https_url()}); any other target fails the fetch before
	 * it is requested, so the last good bundle keeps serving. Every hop is a
	 * fresh request with the same options, ``reject_unsafe_urls`` included,
	 * so a redirect cannot reach a private address either.
	 *
	 * @param string $url Absolute URL to fetch.
	 */
	public function fetch( string $url ): FetchResult {
		// One timeout budget for the whole chain, not one per hop: a fetch is
		// bounded by TIMEOUT_SECONDS however many redirects it follows.
		$deadline = microtime( true ) + self::TIMEOUT_SECONDS;
		for ( $hop = 0; ; $hop++ ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0 ) {
				return FetchResult::error( __( 'timed out following redirects', 'globetrotters-ai-presence' ) );
			}
			$response = $this->get( $url, $remaining );
			if ( is_wp_error( $response ) ) {
				return FetchResult::error( $response->get_error_message() );
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = self::location( $response );
			if ( ! in_array( $status, self::REDIRECT_STATUSES, true ) || '' === $location ) {
				return FetchResult::http( $status, (string) wp_remote_retrieve_body( $response ) );
			}

			if ( $hop >= self::MAX_REDIRECTS ) {
				/* translators: %d: maximum number of redirects followed. */
				return FetchResult::error( sprintf( __( 'more than %d redirects', 'globetrotters-ai-presence' ), self::MAX_REDIRECTS ) );
			}

			$target = self::resolve( $location, $url );
			if ( ! Options::is_https_url( $target ) ) {
				return FetchResult::error( __( 'refused a redirect to a URL that is not https', 'globetrotters-ai-presence' ) );
			}
			$url = $target;
		}
	}

	/**
	 * One request, never followed through a redirect by core.
	 *
	 * @param string $url     Absolute URL.
	 * @param float  $timeout Seconds left in the fetch's budget.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function get( string $url, float $timeout ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'user-agent'          => 'GlobetrottersAiPresence/' . \Globetrotters\AiPresence\VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => '*/*' ),
				// Block requests that resolve to private/reserved IPs — a defence
				// against SSRF via the admin "Base URL override" setting.
				'reject_unsafe_urls'  => true,
				// Redirects are followed by fetch(), which checks each target.
				'redirection'         => 0,
				// Stop reading one byte past the accepted maximum: an in-limit
				// body arrives intact, an oversize one arrives truncated to
				// MAX + 1 bytes, which the sync layer detects and rejects.
				'limit_response_size' => Fetcher::MAX_BODY_BYTES + 1,
			)
		);
	}

	/**
	 * The response's Location header, or ''.
	 *
	 * @param array<string, mixed> $response Response from wp_remote_get().
	 */
	private static function location( array $response ): string {
		$location = wp_remote_retrieve_header( $response, 'location' );
		// Repeated headers arrive as a list; the first one is what a browser uses.
		if ( is_array( $location ) ) {
			$location = reset( $location );
		}
		return is_string( $location ) ? trim( $location ) : '';
	}

	/**
	 * Resolve a Location against the URL that answered with it (RFC 3986 §5.2,
	 * without dot-segment removal, which only rewrites a path on the same host).
	 *
	 * @param string $location Location header value.
	 * @param string $base     Absolute URL of the redirecting request.
	 */
	private static function resolve( string $location, string $base ): string {
		// A fragment is never sent; dropping it keeps '#x' from being read as
		// a path segment. What remains empty refers to the base itself.
		$hash = strpos( $location, '#' );
		if ( false !== $hash ) {
			$location = substr( $location, 0, $hash );
		}
		if ( '' === $location ) {
			return $base;
		}
		if ( 1 === preg_match( '/^[a-z][a-z0-9+.-]*:/i', $location ) ) {
			return $location;
		}

		$parts  = wp_parse_url( $base );
		$parts  = is_array( $parts ) ? $parts : array();
		$scheme = $parts['scheme'] ?? '';
		if ( 0 === strpos( $location, '//' ) ) {
			return $scheme . ':' . $location;
		}

		$origin = $scheme . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path   = $parts['path'] ?? '/';
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		if ( 0 === strpos( $location, '?' ) ) {
			return $origin . $path . $location;
		}
		return $origin . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
	}
}
