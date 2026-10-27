<?php
/**
 * The proxies whose forwarding headers may be believed.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * An allowlist of proxy addresses — single IPs or CIDR ranges, IPv4 and IPv6 —
 * plus an optional Cloudflare preset.
 *
 * The WordPress counterpart of Symfony's ``framework.trusted_proxies``, which
 * the Symfony bundle builds on: a forwarding header is evidence only when the
 * connection itself came from a listed proxy. See {@see ClientIp}.
 */
final class TrustedProxies {

	/**
	 * Cloudflare's published edge ranges: https://www.cloudflare.com/ips-v4 and
	 * /ips-v6, the same list ``api.cloudflare.com/client/v4/ips`` serves.
	 * Verified 2026-09-10.
	 *
	 * They change rarely, and a stale copy fails safe: a request from a range
	 * missing here reads as coming from Cloudflare's own address, an unverified
	 * hit — never as a forgeable one.
	 */
	private const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Valid list entries, in the order given.
	 *
	 * @var string[]
	 */
	private array $entries;

	private bool $cloudflare;

	/**
	 * Built through {@see from()}.
	 *
	 * @param string[] $entries    Valid entries.
	 * @param bool     $cloudflare Whether the Cloudflare preset is on.
	 */
	private function __construct( array $entries, bool $cloudflare ) {
		$this->entries    = $entries;
		$this->cloudflare = $cloudflare;
	}

	/**
	 * Build from a stored or submitted list. Invalid entries are ignored.
	 *
	 * @param string $text       IPs or CIDR ranges, separated by commas, spaces or line breaks.
	 * @param bool   $cloudflare Whether the Cloudflare preset is on.
	 */
	public static function from( string $text, bool $cloudflare ): self {
		return new self( self::parse( $text )['valid'], $cloudflare );
	}

	/**
	 * Split a list into its valid entries and a count of the rest.
	 *
	 * @param string $text IPs or CIDR ranges, separated by commas, spaces or line breaks.
	 * @return array{valid: string[], invalid: int}
	 */
	public static function parse( string $text ): array {
		$valid   = array();
		$invalid = 0;

		$entries = preg_split( '/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( self::is_valid_entry( $entry ) ) {
				$valid[] = $entry;
			} else {
				++$invalid;
			}
		}

		return array(
			'valid'   => array_values( array_unique( $valid ) ),
			'invalid' => $invalid,
		);
	}

	/**
	 * The listed entries, without the Cloudflare preset.
	 *
	 * @return string[]
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Whether the Cloudflare preset is on.
	 */
	public function trusts_cloudflare(): bool {
		return $this->cloudflare;
	}

	/**
	 * Whether no proxy at all is trusted.
	 */
	public function is_empty(): bool {
		return array() === $this->entries && ! $this->cloudflare;
	}

	/**
	 * Whether an address is a trusted proxy — listed, or Cloudflare's under the
	 * preset.
	 *
	 * @param string $ip Candidate address.
	 */
	public function contains( string $ip ): bool {
		$address = IpRange::packed( $ip );
		if ( null === $address ) {
			return false;
		}
		foreach ( $this->entries as $range ) {
			if ( IpRange::in_cidr( $address, $range ) ) {
				return true;
			}
		}
		return $this->is_cloudflare_address( $address );
	}

	/**
	 * Whether an address is Cloudflare's *and* the preset is on.
	 *
	 * Distinct from {@see contains()}: only a request from Cloudflare itself
	 * vouches for the ``CF-Connecting-IP`` header. Listing Cloudflare's ranges by
	 * hand trusts the ``X-Forwarded-For`` chain they append to, and no more.
	 *
	 * @param string $ip Candidate address.
	 */
	public function is_cloudflare( string $ip ): bool {
		$address = IpRange::packed( $ip );
		return null !== $address && $this->is_cloudflare_address( $address );
	}

	/**
	 * Under the preset, whether a packed address is in Cloudflare's ranges.
	 *
	 * @param string $address ``inet_pton()`` form.
	 */
	private function is_cloudflare_address( string $address ): bool {
		if ( ! $this->cloudflare ) {
			return false;
		}
		foreach ( self::CLOUDFLARE as $range ) {
			if ( IpRange::in_cidr( $address, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an entry is an IP address, or one with a prefix length its family
	 * allows.
	 *
	 * @param string $entry Candidate entry.
	 */
	private static function is_valid_entry( string $entry ): bool {
		$parts = explode( '/', $entry );
		if ( count( $parts ) > 2 || false === filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( 1 === count( $parts ) ) {
			return true;
		}
		$max = false !== strpos( $parts[0], ':' ) ? 128 : 32;
		return 1 === preg_match( '/^\d{1,3}$/', $parts[1] ) && (int) $parts[1] <= $max;
	}
}
