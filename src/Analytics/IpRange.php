<?php
/**
 * Byte-level IP address and range matching.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Shared address arithmetic for the trusted-proxy allowlist (CIDR entries) and
 * the vendor range list (inclusive start/end spans).
 *
 * Everything works on the ``inet_pton()`` form, so IPv4 and IPv6 share one code
 * path, and an address never matches a range of the other family: their packed
 * lengths differ (4 vs 16 bytes).
 */
final class IpRange {

	/**
	 * An address in ``inet_pton()`` form, or null when it is not one.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function packed( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		return false === $packed ? null : $packed;
	}

	/**
	 * Whether a packed address falls in a CIDR range (or equals a single address).
	 *
	 * Compared as bytes, so a prefix that does not end on a byte boundary (a /13)
	 * masks only the bits it covers.
	 *
	 * @param string $address ``inet_pton()`` form.
	 * @param string $range   An IP, or an IP with a valid prefix length.
	 */
	public static function in_cidr( string $address, string $range ): bool {
		$parts  = explode( '/', $range, 2 );
		$subnet = inet_pton( $parts[0] );
		if ( false === $subnet || strlen( $subnet ) !== strlen( $address ) ) {
			return false;
		}

		$bits  = isset( $parts[1] ) ? (int) $parts[1] : 8 * strlen( $subnet );
		$bytes = intdiv( $bits, 8 );
		if ( substr( $address, 0, $bytes ) !== substr( $subnet, 0, $bytes ) ) {
			return false;
		}

		$remainder = $bits % 8;
		if ( 0 === $remainder ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $remainder ) ) & 0xFF;
		return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $subnet[ $bytes ] ) & $mask );
	}

	/**
	 * Whether a packed address lies between two packed bounds, inclusive.
	 *
	 * Big-endian byte strings of equal length order the same way the addresses
	 * do, so a plain string comparison is a numeric one. Bounds of another
	 * family never match.
	 *
	 * @param string $address ``inet_pton()`` form.
	 * @param string $start   Packed first address of the span.
	 * @param string $end     Packed last address of the span.
	 */
	public static function in_span( string $address, string $start, string $end ): bool {
		$length = strlen( $address );
		return strlen( $start ) === $length
			&& strlen( $end ) === $length
			&& strcmp( $address, $start ) >= 0
			&& strcmp( $address, $end ) <= 0;
	}
}
