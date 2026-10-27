<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\TrustedProxies;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\TrustedProxies
 */
final class TrustedProxiesTest extends TestCase {

	public function test_nothing_is_trusted_by_default(): void {
		$trusted = TrustedProxies::from( '', false );

		$this->assertTrue( $trusted->is_empty() );
		$this->assertFalse( $trusted->contains( '10.0.0.1' ) );
	}

	public function test_single_addresses_and_ranges_are_matched(): void {
		$trusted = TrustedProxies::from( "10.0.0.0/8\n192.0.2.7\n2001:db8::/32", false );

		$this->assertTrue( $trusted->contains( '10.0.0.1' ) );
		$this->assertTrue( $trusted->contains( '10.255.255.255' ) );
		$this->assertTrue( $trusted->contains( '192.0.2.7' ) );
		$this->assertTrue( $trusted->contains( '2001:db8:ffff::1' ) );

		$this->assertFalse( $trusted->contains( '11.0.0.1' ) );
		$this->assertFalse( $trusted->contains( '192.0.2.8' ) );
		$this->assertFalse( $trusted->contains( '2001:db9::1' ) );
		$this->assertFalse( $trusted->contains( '' ) );
		$this->assertFalse( $trusted->contains( 'garbage' ) );
	}

	public function test_a_prefix_that_does_not_end_on_a_byte_is_respected(): void {
		$trusted = TrustedProxies::from( '172.64.0.0/13', false );

		$this->assertTrue( $trusted->contains( '172.64.0.0' ) );
		$this->assertTrue( $trusted->contains( '172.71.255.255' ) );
		$this->assertFalse( $trusted->contains( '172.72.0.0' ) );
		$this->assertFalse( $trusted->contains( '172.63.255.255' ) );
	}

	public function test_an_address_never_matches_a_range_of_the_other_family(): void {
		$this->assertFalse( TrustedProxies::from( '::/0', false )->contains( '192.0.2.1' ) );
		$this->assertFalse( TrustedProxies::from( '0.0.0.0/0', false )->contains( '2001:db8::1' ) );
	}

	public function test_entries_may_be_separated_by_commas_spaces_or_lines(): void {
		$trusted = TrustedProxies::from( " 10.0.0.1 ,10.0.0.2\r\n\t10.0.0.3 ", false );

		$this->assertSame( array( '10.0.0.1', '10.0.0.2', '10.0.0.3' ), $trusted->entries() );
	}

	/**
	 * @dataProvider invalid_entry_provider
	 * @param string $entry A list entry that is not an address or a range.
	 */
	public function test_an_invalid_entry_is_never_trusted( string $entry ): void {
		$parsed = TrustedProxies::parse( $entry );

		$this->assertSame( array(), $parsed['valid'] );
		$this->assertSame( 1, $parsed['invalid'] );
		$this->assertTrue( TrustedProxies::from( $entry, false )->is_empty() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function invalid_entry_provider(): array {
		return array(
			'hostname'          => array( 'proxy.example.com' ),
			'wildcard'          => array( '*' ),
			'out-of-range ipv4' => array( '300.1.1.1' ),
			'ipv4 prefix > 32'  => array( '10.0.0.0/33' ),
			'ipv6 prefix > 128' => array( '2001:db8::/129' ),
			'empty prefix'      => array( '10.0.0.0/' ),
			'negative prefix'   => array( '10.0.0.0/-1' ),
			'double prefix'     => array( '10.0.0.0/8/8' ),
		);
	}

	public function test_the_cloudflare_preset_trusts_cloudflare_s_published_ranges(): void {
		$on  = TrustedProxies::from( '', true );
		$off = TrustedProxies::from( '', false );

		// One address from each end of the published list, both families.
		foreach ( array( '173.245.48.1', '131.0.75.255', '2400:cb00::1', '2a06:98c7:ffff::1' ) as $ip ) {
			$this->assertTrue( $on->contains( $ip ), $ip );
			$this->assertTrue( $on->is_cloudflare( $ip ), $ip );
			$this->assertFalse( $off->contains( $ip ), $ip );
			$this->assertFalse( $off->is_cloudflare( $ip ), $ip );
		}
		$this->assertFalse( $on->contains( '203.0.113.7' ) );
		$this->assertTrue( $on->trusts_cloudflare() );
	}

	/**
	 * Listing Cloudflare's ranges by hand trusts the X-Forwarded-For chain they
	 * append to. Only the preset — a statement that the proxy *is* Cloudflare —
	 * vouches for the CF-Connecting-IP header.
	 */
	public function test_a_hand_listed_cloudflare_range_is_trusted_but_not_as_cloudflare(): void {
		$trusted = TrustedProxies::from( '173.245.48.0/20', false );

		$this->assertTrue( $trusted->contains( '173.245.48.1' ) );
		$this->assertFalse( $trusted->is_cloudflare( '173.245.48.1' ) );
	}
}
