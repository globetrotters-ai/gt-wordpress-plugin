<?php
/**
 * Client IP resolution through the trusted-proxy chain.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Resolves the client IP for a captured event.
 *
 * The contract's chain is ``CF-Connecting-IP`` → leftmost ``X-Forwarded-For``
 * → ``REMOTE_ADDR``, but a forwarding header is attacker-controlled unless the
 * connection it arrived on came from a proxy the site admin listed. Honouring
 * one otherwise would let any client assert an IP inside a published vendor
 * CIDR range and manufacture ``verified=true`` hits, which is the one thing
 * making self-reported counts credible. So, as the Symfony bundle does with
 * Symfony's ``trusted_proxies``:
 *
 * - **The peer decides.** A request whose ``REMOTE_ADDR`` is not a trusted
 *   proxy is answered with that address, whatever it carries. "The site is
 *   behind a proxy" is not enough: an origin still reachable directly receives
 *   requests that never went through it.
 * - **``X-Forwarded-For`` is walked from the right.** Each proxy appends the
 *   address it received the request from to whatever the client sent, so the
 *   leftmost entry is the client's own claim. The walk skips trusted hops and
 *   stops at the first address that is not one. Where every hop is trusted
 *   that is the contract's leftmost entry; where one is not, it is the
 *   leftmost entry that cannot have been forged.
 * - **``CF-Connecting-IP`` only from Cloudflare.** A generic load balancer
 *   passes the header through untouched, so it is read only when the peer is
 *   in Cloudflare's own ranges and the admin turned the preset on.
 *
 * The cost of the safe default is the opposite failure: behind a proxy nobody
 * listed, every hit reports the proxy's IP and the backend's forward-confirm
 * fails for the whole install. That is why the setting is surfaced prominently
 * rather than buried — see {@see \Globetrotters\AiPresence\Settings\SettingsPage}.
 */
final class ClientIp {

	/**
	 * Resolve the client IP, or an empty string when nothing valid is available.
	 *
	 * @param array<string, mixed> $server  Request superglobal (``$_SERVER``).
	 * @param TrustedProxies       $trusted Proxies whose headers may be believed.
	 */
	public static function resolve( array $server, TrustedProxies $trusted ): string {
		$peer = self::validate( self::header( $server, 'REMOTE_ADDR' ) );
		if ( '' === $peer || ! $trusted->contains( $peer ) ) {
			return $peer;
		}

		if ( $trusted->is_cloudflare( $peer ) ) {
			$cloudflare = self::validate( self::header( $server, 'HTTP_CF_CONNECTING_IP' ) );
			if ( '' !== $cloudflare ) {
				return $cloudflare;
			}
		}

		// Past an entry that is not an address the chain cannot be followed, so
		// the walk ends at the last address it could vouch for.
		$client = $peer;
		$hops   = explode( ',', self::header( $server, 'HTTP_X_FORWARDED_FOR' ) );
		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			$hop = self::validate( trim( $hops[ $i ] ) );
			if ( '' === $hop ) {
				break;
			}
			$client = $hop;
			if ( ! $trusted->contains( $hop ) ) {
				break;
			}
		}

		return $client;
	}

	/**
	 * A request header/server value as a trimmed string.
	 *
	 * @param array<string, mixed> $server Request superglobal.
	 * @param string               $key    Server key.
	 */
	private static function header( array $server, string $key ): string {
		return isset( $server[ $key ] ) ? trim( (string) $server[ $key ] ) : '';
	}

	/**
	 * Return the value when it is a well-formed IP address, otherwise ''.
	 *
	 * The backend refuses a non-IP string anyway; dropping it here keeps garbage
	 * out of the buffer and off the wire.
	 *
	 * @param string $value Candidate address.
	 */
	private static function validate( string $value ): string {
		return false === filter_var( $value, FILTER_VALIDATE_IP ) ? '' : $value;
	}
}
