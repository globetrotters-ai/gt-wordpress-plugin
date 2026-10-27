<?php
/**
 * Canned ingest transport for unit tests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Support;

use Globetrotters\AiPresence\Analytics\IngestResult;
use Globetrotters\AiPresence\Analytics\IngestTransport;

/**
 * Records every POST and replays a scripted sequence of results.
 *
 * ``$gzip`` mirrors the real client's two modes: with zlib the wire form is
 * compressed and held to the 64KB cap, without it the raw JSON is held to the
 * 256KB one.
 */
final class FakeIngestTransport implements IngestTransport {

	/**
	 * @var array<int, array{url:string, token:string, json:string}>
	 */
	public array $calls = array();

	/**
	 * @var array<int, IngestResult>
	 */
	private array $responses;

	public bool $gzip;

	/**
	 * Run during each POST, to interleave work with an in-flight flush.
	 *
	 * @var callable|null
	 */
	public $during_post = null;

	/**
	 * @param array<int, IngestResult> $responses Results to return, in order.
	 * @param bool                     $gzip      Whether to simulate compression.
	 */
	public function __construct( array $responses = array(), bool $gzip = false ) {
		$this->responses = $responses;
		$this->gzip      = $gzip;
	}

	public function post( string $url, string $token, string $json ): IngestResult {
		$this->calls[] = array(
			'url'   => $url,
			'token' => $token,
			'json'  => $json,
		);
		if ( null !== $this->during_post ) {
			( $this->during_post )();
		}

		$next = array_shift( $this->responses );
		return $next instanceof IngestResult ? $next : IngestResult::http( 202 );
	}

	public function wire_size( string $json ): int {
		return $this->compresses() ? strlen( (string) gzencode( $json ) ) : strlen( $json );
	}

	public function wire_cap(): int {
		return $this->compresses() ? self::MAX_COMPRESSED_BODY_BYTES : self::MAX_BODY_BYTES;
	}

	/**
	 * Whether this double is compressing, mirroring the real client's guard.
	 *
	 * Compression is optional in the contract and {@see IngestClient} falls back
	 * to a raw body without zlib, so the double must not assume more than the
	 * code it stands in for.
	 */
	public function compresses(): bool {
		return $this->gzip && function_exists( 'gzencode' );
	}

	/**
	 * The decoded envelope of a recorded call.
	 *
	 * @param int $index Call index.
	 * @return array<string, mixed>
	 */
	public function envelope( int $index = 0 ): array {
		$decoded = json_decode( $this->calls[ $index ]['json'], true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
