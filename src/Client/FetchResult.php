<?php
/**
 * Normalised result of a single HTTP fetch.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Client;

/**
 * Immutable value object describing one fetch outcome.
 *
 * A result is one of: a transport error (``$error`` set, ``$status`` 0), or an
 * HTTP response (``$status`` >= 100 with a body).
 */
final class FetchResult {

	private int $status;
	private string $body;
	private string $error;

	private function __construct( int $status, string $body, string $error ) {
		$this->status = $status;
		$this->body   = $body;
		$this->error  = $error;
	}

	/**
	 * Build a transport-error result.
	 *
	 * @param string $message Error message.
	 */
	public static function error( string $message ): self {
		return new self( 0, '', $message );
	}

	/**
	 * Build an HTTP-response result.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body.
	 */
	public static function http( int $status, string $body ): self {
		return new self( $status, $body, '' );
	}

	/**
	 * Whether the fetch returned HTTP 200.
	 *
	 * An empty body is still a success: a required artefact may legitimately be
	 * empty (e.g. an empty llms.txt), and treating a 200 as a failure would abort
	 * the whole sync with a confusing "failed (200)".
	 */
	public function is_ok(): bool {
		return 200 === $this->status;
	}

	/**
	 * Whether the fetch failed at the transport layer (DNS, timeout, TLS).
	 */
	public function is_transport_error(): bool {
		return '' !== $this->error;
	}

	public function status(): int {
		return $this->status;
	}

	public function body(): string {
		return $this->body;
	}

	public function error_message(): string {
		return $this->error;
	}
}
