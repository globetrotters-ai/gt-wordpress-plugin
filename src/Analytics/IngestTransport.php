<?php
/**
 * Contract for POSTing a batch to the ingest endpoint.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Sends one JSON envelope to the server-log ingest endpoint.
 *
 * Extracted so {@see Flusher} — which owns batching, retry and the drop
 * accounting — can be unit-tested with a canned transport in place of live HTTP,
 * mirroring {@see \Globetrotters\AiPresence\Client\Fetcher} on the pull side.
 *
 * The size caps live here rather than in the flusher because only the transport
 * knows whether a body will be compressed, and the backend applies a different
 * wire cap to each case.
 */
interface IngestTransport {

	/**
	 * Largest batch the backend accepts. Excess events are discarded server-side,
	 * so a conforming producer splits instead and keeps the remainder buffered.
	 */
	public const MAX_EVENTS_PER_BATCH = 1000;

	/**
	 * Largest number of ``pageViews`` records the backend reads from one batch;
	 * the rest are discarded server-side, so they are split the same way.
	 */
	public const MAX_PAGE_VIEWS_PER_BATCH = 2000;

	/**
	 * Largest ``count`` the backend accepts on one record. A record past it is
	 * dropped rather than clipped, so the producer clamps it instead.
	 */
	public const MAX_PAGE_VIEW_COUNT = 10000000;

	/**
	 * Oldest page-view day the backend accepts, in days before today (UTC).
	 * Older records are dropped on arrival, so they are pruned locally instead.
	 */
	public const MAX_PAGE_VIEW_AGE_DAYS = 7;

	/**
	 * Largest decompressed body the backend accepts, in bytes.
	 */
	public const MAX_BODY_BYTES = 262144;

	/**
	 * Largest gzipped body the backend accepts, in bytes. Tighter than the
	 * decompressed cap: a compressed body is refused before inflation.
	 */
	public const MAX_COMPRESSED_BODY_BYTES = 65536;

	/**
	 * POST one envelope.
	 *
	 * @param string $url   Ingest endpoint URL, as issued alongside the token.
	 * @param string $token Raw ingest token (sent as a Bearer credential).
	 * @param string $json  Encoded envelope.
	 */
	public function post( string $url, string $token, string $json ): IngestResult;

	/**
	 * Size of the envelope as it would go on the wire, in bytes.
	 *
	 * @param string $json Encoded envelope.
	 */
	public function wire_size( string $json ): int;

	/**
	 * The backend's cap for the wire form this transport produces, in bytes.
	 */
	public function wire_cap(): int;
}
