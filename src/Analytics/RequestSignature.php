<?php
/**
 * Web Bot Auth headers, forwarded unverified.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Reads a request's RFC 9421 HTTP Message Signature headers (ingest contract
 * § "Signed requests (v7)") into the ``signature`` object an event carries.
 *
 * Some AI agents (Meta Muse) drive an ordinary browser with a stock Chrome
 * User-Agent and sign their requests instead. **Nothing here verifies
 * anything**: the backend verifies at ingest, against the keys it ships, so
 * no request ever triggers a key fetch from this site. The producer's job is
 * to not drop the request, and to forward the headers exactly as received.
 */
final class RequestSignature {

	/**
	 * Longest member kept, in characters.
	 */
	public const MAX_CHARS = 1024;

	public const AGENT_KEY = 'HTTP_SIGNATURE_AGENT';
	public const INPUT_KEY = 'HTTP_SIGNATURE_INPUT';
	public const VALUE_KEY = 'HTTP_SIGNATURE';
	public const HOST_KEY  = 'HTTP_HOST';

	/**
	 * The ``$_SERVER`` keys {@see from_server()} reads.
	 *
	 * @return string[]
	 */
	public static function server_keys(): array {
		return array( self::AGENT_KEY, self::INPUT_KEY, self::VALUE_KEY, self::HOST_KEY );
	}

	/**
	 * Whether a ``Signature-Agent`` value marks the request as signed: present
	 * and non-empty. A string check, so the site-capture gate stays cheap.
	 *
	 * @param string $agent ``Signature-Agent`` header, or ''.
	 */
	public static function is_signed( string $agent ): bool {
		return '' !== trim( $agent );
	}

	/**
	 * The ``signature`` object for these request values, or null when any of
	 * the three signature headers is missing or empty.
	 *
	 * ``agent``, ``input`` and ``value`` are the headers as received, trimmed
	 * (quotes kept: the signature covers them byte for byte); ``authority`` is
	 * the request's ``Host``, the RFC 9421 ``@authority`` component. Each is
	 * clipped at {@see MAX_CHARS}. No IP.
	 *
	 * @param array<string, string> $server Request values (``$_SERVER`` keys).
	 * @return array{agent:string, input:string, value:string, authority:string}|null
	 */
	public static function from_server( array $server ): ?array {
		$agent = trim( (string) ( $server[ self::AGENT_KEY ] ?? '' ) );
		$input = trim( (string) ( $server[ self::INPUT_KEY ] ?? '' ) );
		$value = trim( (string) ( $server[ self::VALUE_KEY ] ?? '' ) );
		if ( '' === $agent || '' === $input || '' === $value ) {
			return null;
		}
		return array(
			'agent'     => self::clip( $agent ),
			'input'     => self::clip( $input ),
			'value'     => self::clip( $value ),
			'authority' => self::clip( trim( (string) ( $server[ self::HOST_KEY ] ?? '' ) ) ),
		);
	}

	/**
	 * A stored signature (the buffer's ``signature`` column) read back, or
	 * null when it is absent or not the four-string object {@see from_server()}
	 * writes.
	 *
	 * @param mixed $stored Column value.
	 * @return array{agent:string, input:string, value:string, authority:string}|null
	 */
	public static function from_stored( $stored ): ?array {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$decoded = json_decode( $stored, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$signature = array();
		foreach ( array( 'agent', 'input', 'value', 'authority' ) as $member ) {
			if ( ! isset( $decoded[ $member ] ) || ! is_string( $decoded[ $member ] ) ) {
				return null;
			}
			$signature[ $member ] = self::clip( $decoded[ $member ] );
		}
		return '' === $signature['agent'] || '' === $signature['input'] || '' === $signature['value'] ? null : $signature;
	}

	/**
	 * The buffer column value for a signature, or null for none.
	 *
	 * @param array{agent:string, input:string, value:string, authority:string}|null $signature Signature.
	 */
	public static function to_stored( ?array $signature ): ?string {
		if ( null === $signature ) {
			return null;
		}
		// An invalid UTF-8 byte is substituted rather than failing the encode:
		// such a header cannot verify anyway, and the event must still count.
		// That flag is all wp_json_encode()'s fallback would add.
		$json = json_encode( $signature, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return is_string( $json ) ? $json : null;
	}

	/**
	 * Clip a member at {@see MAX_CHARS} characters.
	 *
	 * @param string $value Member value.
	 */
	private static function clip( string $value ): string {
		if ( strlen( $value ) <= self::MAX_CHARS ) {
			return $value;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_CHARS, 'UTF-8' ) : substr( $value, 0, self::MAX_CHARS );
	}
}
