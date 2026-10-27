<?php
/**
 * HTTP transport for the server-log ingest endpoint.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Settings\Options;

/**
 * Thin wrapper over ``wp_remote_post`` for flushing a batch.
 *
 * Gzips the body when zlib is available. Compression is optional in the
 * contract, and the backend applies a different wire cap to each case — 64KB
 * compressed, 256KB uncompressed — so {@see wire_cap()} reports whichever one
 * actually applies rather than assuming the tighter of the two.
 *
 * The token is passed as a Bearer credential and never appears anywhere else:
 * not in a log line, not in an error message, not in the returned result.
 */
final class IngestClient implements IngestTransport {

	/**
	 * Longer than the pull side's 5s, and deliberately so: this runs on WP-Cron
	 * with nobody waiting, and the ingest endpoint is serverless — a cold start
	 * measured over 30 seconds during end-to-end verification. Too tight a
	 * timeout on a rarely-hit endpoint means a flush that only ever succeeds
	 * once something else has warmed it. Still bounded, because a hung endpoint
	 * should cost a skipped flush (the batch stays buffered and is retried
	 * verbatim), never a hung cron run.
	 */
	private const TIMEOUT_SECONDS = 20;

	/**
	 * POST one envelope.
	 *
	 * @param string $url   Ingest endpoint URL.
	 * @param string $token Raw ingest token.
	 * @param string $json  Encoded envelope.
	 */
	public function post( string $url, string $token, string $json ): IngestResult {
		// Options already reads a cleartext endpoint as unconfigured; this is the
		// last point before the token leaves, so it holds the line on its own.
		if ( ! Options::is_https_url( $url ) ) {
			return IngestResult::error( 'the ingest endpoint must be an https:// URL' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		$body       = self::to_wire( $json );
		$compressed = $body !== $json;
		if ( $compressed ) {
			$headers['Content-Encoding'] = 'gzip';
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'            => self::TIMEOUT_SECONDS,
				'headers'            => $headers,
				'body'               => $body,
				'user-agent'         => 'GlobetrottersAiPresence/' . \Globetrotters\AiPresence\VERSION,
				// The issued endpoint is final, as in the Symfony bundle. Core's
				// HTTP client re-sends every original header, Authorization
				// included, to whatever Location a 3xx names — an https→http
				// downgrade or another host alike. A redirect settles as a
				// non-2xx instead, and the batch stays buffered for the retry.
				'redirection'        => 0,
				// The endpoint is a Globetrotters host, but the value is pasted by
				// hand — keep the SSRF guard the pull side uses.
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return IngestResult::error( $response->get_error_message() );
		}

		return IngestResult::http( (int) wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * Size of the envelope as it would go on the wire.
	 *
	 * @param string $json Encoded envelope.
	 */
	public function wire_size( string $json ): int {
		return strlen( self::to_wire( $json ) );
	}

	/**
	 * The backend cap that applies to the wire form this transport produces.
	 */
	public function wire_cap(): int {
		return self::gzip_available() ? self::MAX_COMPRESSED_BODY_BYTES : self::MAX_BODY_BYTES;
	}

	/**
	 * The request body for an envelope: gzipped when zlib is available.
	 *
	 * @param string $json Encoded envelope.
	 */
	private static function to_wire( string $json ): string {
		if ( ! self::gzip_available() ) {
			return $json;
		}
		$compressed = gzencode( $json );
		return is_string( $compressed ) && '' !== $compressed ? $compressed : $json;
	}

	/**
	 * Whether this PHP build can gzip a request body.
	 */
	private static function gzip_available(): bool {
		return function_exists( 'gzencode' );
	}
}
