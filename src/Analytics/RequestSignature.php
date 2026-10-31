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
	 * Symfony's host grammar (``Request::getHost()``): what is left after
	 * removing every match must be empty.
	 */
	private const HOST_PATTERN = '/(?:^\[)?[a-zA-Z0-9-:\]_]+\.?/';

	/**
	 * ``X-Forwarded-Proto`` values Symfony reads as https.
	 */
	private const SECURE_PROTOS = array( 'https', 'on', 'ssl', '1' );

	/**
	 * The ``$_SERVER`` keys {@see from_server()} reads.
	 *
	 * @return string[]
	 */
	public static function server_keys(): array {
		return array( self::AGENT_KEY, self::INPUT_KEY, self::VALUE_KEY, self::HOST_KEY, 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_PROTO', 'HTTPS', 'SERVER_NAME', 'SERVER_PORT', 'REMOTE_ADDR' );
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
	 * the RFC 9421 ``@authority`` component, see {@see authority()}. Each is
	 * clipped at {@see MAX_CHARS}. No IP.
	 *
	 * @param array<string, string> $server  Request values (``$_SERVER`` keys).
	 * @param TrustedProxies|null   $trusted The client-IP trusted proxies; null trusts none.
	 * @return array{agent:string, input:string, value:string, authority:string}|null
	 */
	public static function from_server( array $server, ?TrustedProxies $trusted = null ): ?array {
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
			'authority' => self::clip( self::authority( $server, $trusted ?? TrustedProxies::from( '', false ) ) ),
		);
	}

	/**
	 * The request's authority exactly as the Symfony bundle sends it
	 * (``Request::getHttpHost()``): the host, lower-cased, plus ``:port`` only
	 * when the port is not the scheme's default. ``X-Forwarded-Host``,
	 * ``-Port`` and ``-Proto`` are read only when the connection came from a
	 * proxy the client-IP rules trust ({@see ClientIp}); otherwise ``Host``.
	 * A host outside Symfony's grammar falls back to the raw ``Host`` header,
	 * trimmed and lower-cased.
	 *
	 * @param array<string, string> $server  Request values.
	 * @param TrustedProxies        $trusted The client-IP trusted proxies.
	 */
	public static function authority( array $server, TrustedProxies $trusted ): string {
		$peer      = self::value( $server, 'REMOTE_ADDR' );
		$proxied   = '' !== $peer && $trusted->contains( $peer );
		$raw       = self::value( $server, self::HOST_KEY );
		$forwarded = $proxied ? self::first( $server, 'HTTP_X_FORWARDED_HOST' ) : '';

		$source = '' !== $forwarded ? $forwarded : ( '' !== $raw ? $raw : self::value( $server, 'SERVER_NAME' ) );
		$host   = strtolower( (string) preg_replace( '/:\d+$/', '', $source ) );
		if ( '' === $host || '' !== preg_replace( self::HOST_PATTERN, '', $host ) ) {
			return strtolower( $raw );
		}

		$https = self::is_https( $server, $proxied );
		$port  = self::port( $server, $proxied, $forwarded, $raw, $https );
		return null === $port || ( $https ? 443 : 80 ) === $port ? $host : $host . ':' . $port;
	}

	/**
	 * Symfony's ``Request::isSecure()``: a trusted ``X-Forwarded-Proto`` decides
	 * when one came, else the ``HTTPS`` server value.
	 *
	 * @param array<string, string> $server  Request values.
	 * @param bool                  $proxied Whether the peer is a trusted proxy.
	 */
	private static function is_https( array $server, bool $proxied ): bool {
		$proto = $proxied ? strtolower( self::first( $server, 'HTTP_X_FORWARDED_PROTO' ) ) : '';
		if ( '' !== $proto ) {
			return in_array( $proto, self::SECURE_PROTOS, true );
		}
		$https = strtolower( self::value( $server, 'HTTPS' ) );
		return '' !== $https && 'off' !== $https;
	}

	/**
	 * Symfony's ``Request::getPort()``: a trusted ``X-Forwarded-Port``, else the
	 * port in the trusted forwarded host, else the ``Host`` header's, else the
	 * scheme's default. Without a ``Host`` header, ``SERVER_PORT``.
	 *
	 * @param array<string, string> $server    Request values.
	 * @param bool                  $proxied   Whether the peer is a trusted proxy.
	 * @param string                $forwarded Trusted ``X-Forwarded-Host``, or ''.
	 * @param string                $raw       ``Host`` header, or ''.
	 * @param bool                  $https     Whether the request is secure.
	 */
	private static function port( array $server, bool $proxied, string $forwarded, string $raw, bool $https ): ?int {
		$forwarded_port = $proxied ? self::first( $server, 'HTTP_X_FORWARDED_PORT' ) : '';
		if ( '' !== $forwarded_port ) {
			$candidate = ':' . $forwarded_port;
		} elseif ( '' !== $forwarded ) {
			$candidate = $forwarded;
		} elseif ( '' !== $raw ) {
			$candidate = $raw;
		} else {
			$server_port = self::value( $server, 'SERVER_PORT' );
			return ctype_digit( $server_port ) ? (int) $server_port : null;
		}

		$bracket = strrpos( $candidate, ']' );
		$colon   = '[' === $candidate[0] ? strpos( $candidate, ':', false === $bracket ? 0 : $bracket ) : strrpos( $candidate, ':' );
		$port    = false === $colon ? '' : substr( $candidate, $colon + 1 );
		if ( '' !== $port && '0' !== $port ) {
			return (int) $port;
		}
		return $https ? 443 : 80;
	}

	/**
	 * A request value, trimmed.
	 *
	 * @param array<string, string> $server Request values.
	 * @param string                $key    ``$_SERVER`` key.
	 */
	private static function value( array $server, string $key ): string {
		return trim( (string) ( $server[ $key ] ?? '' ) );
	}

	/**
	 * The first entry of a comma-separated forwarding header, trimmed.
	 *
	 * @param array<string, string> $server Request values.
	 * @param string                $key    ``$_SERVER`` key.
	 */
	private static function first( array $server, string $key ): string {
		return trim( explode( ',', self::value( $server, $key ) )[0] );
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
