<?php
/**
 * One served artefact request, as the producer observed it.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Immutable value object for a single capture event.
 *
 * Mirrors the event shape of the server-log ingest contract. String fields are
 * clipped locally at {@see MAX_FIELD_CHARS}: the backend clips too and still
 * counts the event, but clipping here is what keeps the buffer's byte bound
 * honest — one pathological User-Agent must not be able to consume the whole
 * 512KB allowance.
 */
final class Event {

	/**
	 * A served artefact (discovery file). Flushed under ``events``, with an ip.
	 */
	public const KIND_ARTEFACT = 'artefact';

	/**
	 * A site page fetched by a bot (design B). Flushed under ``siteEvents``,
	 * never with an ip: a site event has no ip at all, in memory, in the buffer
	 * or on the wire.
	 */
	public const KIND_SITE = 'site';

	/**
	 * Longest string field the backend keeps before clipping.
	 */
	public const MAX_FIELD_CHARS = 512;

	/**
	 * Per-event JSON overhead (keys, braces, quotes, numeric fields) added to the
	 * string lengths to estimate the wire footprint of a buffered row.
	 */
	private const ENVELOPE_OVERHEAD_BYTES = 140;

	/**
	 * The ``signature`` object's key, member keys, quotes and braces.
	 */
	private const SIGNATURE_OVERHEAD_BYTES = 60;

	private string $id;
	private string $ts;
	private string $path;
	private string $ua;
	private string $ip;
	private string $referer;
	private int $status;
	private int $bytes;
	private string $kind      = self::KIND_ARTEFACT;
	private ?bool $verified   = null;
	private ?string $vendor   = null;
	private bool $bytes_known = true;

	/**
	 * The request's Web Bot Auth headers (contract v7), unverified.
	 *
	 * @var array{agent:string, input:string, value:string, authority:string}|null
	 */
	private ?array $signature = null;

	public function __construct(
		string $id,
		string $ts,
		string $path,
		string $ua,
		string $ip,
		string $referer,
		int $status,
		int $bytes
	) {
		$this->id      = $id;
		$this->ts      = $ts;
		$this->path    = $path;
		$this->ua      = self::clip( $ua );
		$this->ip      = self::clip( $ip );
		$this->referer = self::clip( $referer );
		$this->status  = $status;
		$this->bytes   = $bytes;
	}

	/**
	 * A site event: no ip, a client-computed verification instead.
	 *
	 * @param string      $id       UUID v4.
	 * @param string      $ts       UTC timestamp.
	 * @param string      $path     Normalised request path.
	 * @param string      $ua       User-Agent.
	 * @param int         $status   Final HTTP status.
	 * @param int|null    $bytes    Body size when cheaply known.
	 * @param bool|null   $verified Vendor range verdict (null: no fresh list).
	 * @param string|null $vendor   Matched range platform when verified.
	 */
	public static function site( string $id, string $ts, string $path, string $ua, int $status, ?int $bytes, ?bool $verified, ?string $vendor ): self {
		$event              = new self( $id, $ts, self::clip( $path ), $ua, '', '', $status, (int) $bytes );
		$event->kind        = self::KIND_SITE;
		$event->bytes_known = null !== $bytes;
		$event->verified    = $verified;
		$event->vendor      = true === $verified && null !== $vendor && '' !== $vendor ? substr( $vendor, 0, 32 ) : null;
		return $event;
	}

	/**
	 * This event carrying a request's signature headers, as
	 * {@see RequestSignature::from_server()} built them (null: none).
	 *
	 * @param array{agent:string, input:string, value:string, authority:string}|null $signature Signature.
	 */
	public function with_signature( ?array $signature ): self {
		$event            = clone $this;
		$event->signature = $signature;
		return $event;
	}

	/**
	 * Rebuild an event from a buffer row.
	 *
	 * @param array<string, mixed> $row Row as read from the buffer table.
	 */
	public static function from_row( array $row ): self {
		// Rows buffered before schema 3 carry no kind: they are artefact events.
		// Rows buffered before schema 5 carry no signature.
		$signature = RequestSignature::from_stored( $row['signature'] ?? null );
		if ( self::KIND_SITE === ( $row['kind'] ?? null ) ) {
			$verified = $row['verified'] ?? null;
			return self::site(
				(string) ( $row['event_id'] ?? '' ),
				(string) ( $row['ts'] ?? '' ),
				(string) ( $row['path'] ?? '' ),
				(string) ( $row['ua'] ?? '' ),
				(int) ( $row['status'] ?? 0 ),
				null === ( $row['bytes'] ?? null ) ? null : (int) $row['bytes'],
				null === $verified ? null : (bool) (int) $verified,
				isset( $row['vendor'] ) ? (string) $row['vendor'] : null
			)->with_signature( $signature );
		}
		return ( new self(
			(string) ( $row['event_id'] ?? '' ),
			(string) ( $row['ts'] ?? '' ),
			(string) ( $row['path'] ?? '' ),
			(string) ( $row['ua'] ?? '' ),
			(string) ( $row['ip'] ?? '' ),
			(string) ( $row['referer'] ?? '' ),
			(int) ( $row['status'] ?? 0 ),
			(int) ( $row['bytes'] ?? 0 )
		) )->with_signature( $signature );
	}

	/**
	 * Column map for an INSERT into the buffer table.
	 *
	 * ``signature`` is the last key, and present only when the event carries
	 * one: the buffer has that column only from schema 5.
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$row = $this->base_row();
		if ( null !== $this->signature ) {
			$row['signature'] = RequestSignature::to_stored( $this->signature );
		}
		return $row;
	}

	/**
	 * The columns every buffer schema since 3 has.
	 *
	 * @return array<string, mixed>
	 */
	private function base_row(): array {
		if ( self::KIND_SITE === $this->kind ) {
			return array(
				'event_id'   => $this->id,
				'ts'         => $this->ts,
				'path'       => $this->path,
				'ua'         => $this->ua,
				'ip'         => null,
				'referer'    => '',
				'status'     => $this->status,
				'bytes'      => $this->bytes_known ? $this->bytes : null,
				'size_bytes' => $this->size_bytes(),
				'kind'       => self::KIND_SITE,
				'verified'   => null === $this->verified ? null : (int) $this->verified,
				'vendor'     => $this->vendor,
			);
		}
		return array(
			'event_id'   => $this->id,
			'ts'         => $this->ts,
			'path'       => $this->path,
			'ua'         => $this->ua,
			'ip'         => $this->ip,
			'referer'    => $this->referer,
			'status'     => $this->status,
			'bytes'      => $this->bytes,
			'size_bytes' => $this->size_bytes(),
		);
	}

	/**
	 * Wire representation for the flush envelope.
	 *
	 * Empty optional fields go out as ``null`` rather than ``""`` — the contract
	 * models them as nullable, and a null is smaller on the wire.
	 *
	 * @return array<string, mixed>
	 */
	public function to_payload(): array {
		$payload = $this->base_payload();
		if ( null !== $this->signature ) {
			// Contract v7: on either lane, only when all three headers came.
			$payload['signature'] = $this->signature;
		}
		return $payload;
	}

	/**
	 * The wire fields every event carries.
	 *
	 * @return array<string, mixed>
	 */
	private function base_payload(): array {
		if ( self::KIND_SITE === $this->kind ) {
			// Contract section 1: no ip key, ever.
			return array(
				'id'       => $this->id,
				'ts'       => $this->ts,
				'path'     => $this->path,
				'ua'       => '' === $this->ua ? null : $this->ua,
				'status'   => $this->status,
				'bytes'    => $this->bytes_known ? $this->bytes : null,
				'verified' => $this->verified,
				'vendor'   => $this->vendor,
			);
		}
		return array(
			'id'      => $this->id,
			'ts'      => $this->ts,
			'path'    => $this->path,
			'ua'      => '' === $this->ua ? null : $this->ua,
			'ip'      => '' === $this->ip ? null : $this->ip,
			'referer' => '' === $this->referer ? null : $this->referer,
			'status'  => $this->status,
			'bytes'   => $this->bytes,
		);
	}

	/**
	 * Estimated JSON footprint of this event, in bytes.
	 *
	 * Stored per row so the buffer's 512KB bound can be enforced with one
	 * aggregate query instead of re-encoding the whole buffer.
	 */
	public function size_bytes(): int {
		$length = strlen( $this->id )
			+ strlen( $this->ts )
			+ strlen( $this->path )
			+ strlen( $this->ua )
			+ strlen( $this->ip )
			+ strlen( $this->referer )
			+ self::ENVELOPE_OVERHEAD_BYTES;
		if ( null !== $this->signature ) {
			// A quote, backslash or slash is escaped on the wire: one byte more each.
			$joined  = implode( '', $this->signature );
			$length += strlen( $joined ) + strlen( $joined ) - strlen( str_replace( array( '"', '\\', '/' ), '', $joined ) ) + self::SIGNATURE_OVERHEAD_BYTES;
		}

		// The column is a SMALLINT; clipped fields keep a real row well under this,
		// and saturating is safer than overflowing the bound accounting.
		return min( $length, 65535 );
	}

	public function id(): string {
		return $this->id;
	}

	public function ts(): string {
		return $this->ts;
	}

	public function path(): string {
		return $this->path;
	}

	public function ua(): string {
		return $this->ua;
	}

	public function ip(): string {
		return $this->ip;
	}

	public function referer(): string {
		return $this->referer;
	}

	public function status(): int {
		return $this->status;
	}

	public function bytes(): int {
		return $this->bytes;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function is_site(): bool {
		return self::KIND_SITE === $this->kind;
	}

	public function verified(): ?bool {
		return $this->verified;
	}

	public function vendor(): ?string {
		return $this->vendor;
	}

	/**
	 * The signature headers this event forwards, or null.
	 *
	 * @return array{agent:string, input:string, value:string, authority:string}|null
	 */
	public function signature(): ?array {
		return $this->signature;
	}

	/**
	 * Clip a string field to the contract's maximum length.
	 *
	 * @param string $value Raw field value.
	 */
	private static function clip( string $value ): string {
		return strlen( $value ) > self::MAX_FIELD_CHARS
			? substr( $value, 0, self::MAX_FIELD_CHARS )
			: $value;
	}
}
