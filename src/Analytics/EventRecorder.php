<?php
/**
 * Builds and buffers one capture event per served artefact request.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Settings\Options;
use Throwable;

/**
 * The capture seam on the serve path.
 *
 * Two rules govern everything here:
 *
 * 1. **Nothing may affect the served response.** ``record()`` swallows every
 *    Throwable and returns; a missing table, a dead database or a fatal in the
 *    buffer degrades reporting, never serving.
 * 2. **Never ``current_time()``.** It returns *site-local* time, and
 *    ``current_time('mysql')`` returns a naive string with no offset. The
 *    backend reads a bare timestamp as UTC, so a Europe/Paris site would report
 *    every hit two hours off with no error anywhere — the backend re-stamps
 *    anything more than 90 minutes stale to arrival time, so it degrades quietly
 *    rather than loudly. ``gmdate()`` is UTC by definition and the format ends
 *    in an explicit ``Z``.
 */
final class EventRecorder {

	private EventBuffer $buffer;
	private Options $options;
	private FlushRequest $flushes;
	private ?CaptureContext $context;

	public function __construct( EventBuffer $buffer, Options $options, FlushRequest $flushes, ?CaptureContext $context = null ) {
		$this->buffer  = $buffer;
		$this->options = $options;
		$this->flushes = $flushes;
		$this->context = $context;
	}

	/**
	 * Capture one served artefact request.
	 *
	 * @param string $path   Canonical artefact path, with a leading slash.
	 * @param int    $status HTTP status served.
	 * @param int    $bytes  Response body size in bytes.
	 */
	public function record( string $path, int $status, int $bytes ): void {
		try {
			if ( ! $this->is_capturing() ) {
				return;
			}
			if ( null !== $this->context ) {
				// An artefact hit is as good a CDN witness as a page fetch.
				$this->context->record_markers( self::request_values() );
			}
			if ( $this->buffer->append( $this->build( $path, $status, $bytes ) ) ) {
				// A nearly-full buffer on a site busy enough to fill one inside the
				// interval. Queue an early flush — this only schedules, so the served
				// response still isn't waiting on anything.
				$this->flushes->request_flush();
			}
		} catch ( Throwable $error ) {
			// Deliberately swallowed: capture must not affect a served response on
			// any code path, including failure paths.
			unset( $error );
		}
	}

	/**
	 * Whether this request should be captured at all.
	 *
	 * Capture is off until the customer pastes their ingest credentials: an
	 * install that will never flush should not be paying for a row per hit.
	 */
	public function is_capturing(): bool {
		return $this->options->is_reporting_configured() && Schema::is_ready();
	}

	/**
	 * Build the event for the current request.
	 *
	 * @param string $path   Canonical artefact path, with a leading slash.
	 * @param int    $status HTTP status served.
	 * @param int    $bytes  Response body size in bytes.
	 */
	public function build( string $path, int $status, int $bytes ): Event {
		$server = self::request_values();

		return ( new Event(
			wp_generate_uuid4(),
			// UTC with an explicit offset. See the class docblock — this is the
			// single most likely thing to get silently wrong.
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$path,
			$server['HTTP_USER_AGENT'] ?? '',
			ClientIp::resolve( $server, $this->options->trusted_proxies() ),
			$server['HTTP_REFERER'] ?? '',
			$status,
			$bytes
		) )->with_signature( RequestSignature::from_server( $server ) );
	}

	/**
	 * The handful of request values an event is built from.
	 *
	 * Copied key by key rather than unslashing all of ``$_SERVER``: this runs on
	 * every served artefact request.
	 *
	 * @return array<string, string>
	 */
	private static function request_values(): array {
		$keys   = array_merge( array( 'HTTP_USER_AGENT', 'HTTP_REFERER', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ), CaptureContext::server_keys(), RequestSignature::server_keys() );
		$values = array();

		foreach ( $keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_scalar( $_SERVER[ $key ] ) ) {
				// Recorded verbatim: this is evidence about what the agent actually
				// sent, and sanitising it would misreport it. It is clipped by Event,
				// parameter-bound on write, and escaped wherever it is displayed.
				$values[ $key ] = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		return $values;
	}
}
