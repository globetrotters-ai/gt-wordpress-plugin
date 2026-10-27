<?php
/**
 * Normalised result of one ingest POST.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Immutable value object describing one flush outcome.
 *
 * Carries a status and a message only — never the request body and never the
 * ingest token, so a result can be stored in plugin state and rendered on the
 * settings screen without leaking a credential.
 *
 * Note that ``is_accepted()`` means *handed off*, not *correct*: the endpoint
 * answers ``202`` for a good batch and equally for a bad token, an unknown
 * install or a malformed body, deliberately revealing nothing about which
 * tokens exist. The plugin can therefore never claim reporting "works" — only
 * that its flushes are being accepted.
 */
final class IngestResult {

	private int $status;
	private string $error;

	private function __construct( int $status, string $error ) {
		$this->status = $status;
		$this->error  = $error;
	}

	/**
	 * Build a transport-error result (DNS, timeout, TLS).
	 *
	 * @param string $message Error message.
	 */
	public static function error( string $message ): self {
		return new self( 0, $message );
	}

	/**
	 * Build an HTTP-response result.
	 *
	 * @param int $status HTTP status code.
	 */
	public static function http( int $status ): self {
		return new self( $status, '' );
	}

	/**
	 * Whether the batch was handed off (any 2xx).
	 */
	public function is_accepted(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether the producer is being rate limited and should back off.
	 */
	public function is_rate_limited(): bool {
		return 429 === $this->status;
	}

	public function status(): int {
		return $this->status;
	}

	/**
	 * A short, credential-free description of the failure ('' when accepted).
	 */
	public function error_message(): string {
		if ( $this->is_accepted() ) {
			return '';
		}
		if ( '' !== $this->error ) {
			return $this->error;
		}
		/* translators: %d: HTTP status code returned by the ingest endpoint. */
		return sprintf( __( 'ingest endpoint returned HTTP %d', 'globetrotters-ai-presence' ), $this->status );
	}
}
