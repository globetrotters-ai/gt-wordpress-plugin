<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\ClientIp;
use Globetrotters\AiPresence\Analytics\TrustedProxies;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\ClientIp
 */
final class ClientIpTest extends TestCase {

	/**
	 * An address inside a published AI-vendor range: what a forger would claim.
	 */
	private const AGENT = '160.79.104.10';

	/**
	 * A load balancer in front of the site.
	 */
	private const LB = '10.0.0.5';

	/**
	 * An address inside Cloudflare's published ranges.
	 */
	private const CF = '173.245.48.5';

	public function test_headers_are_ignored_when_no_proxy_is_trusted(): void {
		$server = array(
			'HTTP_CF_CONNECTING_IP' => self::AGENT,
			'HTTP_X_FORWARDED_FOR'  => self::AGENT,
			'REMOTE_ADDR'           => '203.0.113.7',
		);

		// The whole point of the default: both headers are forgeable on a
		// direct-to-origin site, and honouring them would let any client claim a
		// vendor IP and manufacture a verified hit.
		$this->assertSame( '203.0.113.7', ClientIp::resolve( $server, self::none() ) );
	}

	/**
	 * A site behind a load balancer whose origin is still reachable directly.
	 * A connection that did not come through the proxy carries the client's own
	 * headers, and trusting them because proxy mode is "on" is the forgery.
	 */
	public function test_headers_are_ignored_from_a_peer_that_is_not_a_trusted_proxy(): void {
		$server = array(
			'HTTP_X_FORWARDED_FOR' => self::AGENT,
			'REMOTE_ADDR'          => '203.0.113.7',
		);

		$this->assertSame( '203.0.113.7', ClientIp::resolve( $server, TrustedProxies::from( self::LB, false ) ) );
	}

	/**
	 * An append-style proxy adds the address it received the request from to
	 * whatever the client already sent. The leftmost entry is the client's own
	 * claim; the rightmost is the one the trusted proxy vouches for.
	 */
	public function test_forwarded_for_is_read_from_the_right(): void {
		$server = array(
			'HTTP_X_FORWARDED_FOR' => '198.51.100.99, ' . self::AGENT,
			'REMOTE_ADDR'          => self::LB,
		);

		$this->assertSame( self::AGENT, ClientIp::resolve( $server, TrustedProxies::from( self::LB, false ) ) );
	}

	public function test_trusted_hops_are_walked_past(): void {
		$server = array(
			'HTTP_X_FORWARDED_FOR' => '198.51.100.99, ' . self::AGENT . ', 10.0.0.1',
			'REMOTE_ADDR'          => self::LB,
		);

		$this->assertSame( self::AGENT, ClientIp::resolve( $server, TrustedProxies::from( '10.0.0.0/8', false ) ) );
	}

	public function test_a_chain_of_nothing_but_trusted_hops_resolves_to_its_far_end(): void {
		$server = array(
			'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 10.0.0.2',
			'REMOTE_ADDR'          => self::LB,
		);

		$this->assertSame( '10.0.0.1', ClientIp::resolve( $server, TrustedProxies::from( '10.0.0.0/8', false ) ) );
	}

	/**
	 * Past a hop that is not an address the chain cannot be followed, so nothing
	 * to its left is vouched for.
	 */
	public function test_an_unparseable_hop_ends_the_walk_at_the_last_trusted_address(): void {
		$server = array(
			'HTTP_X_FORWARDED_FOR' => self::AGENT . ', unknown',
			'REMOTE_ADDR'          => self::LB,
		);

		$this->assertSame( self::LB, ClientIp::resolve( $server, TrustedProxies::from( self::LB, false ) ) );
	}

	public function test_a_trusted_peer_without_forwarding_headers_is_the_client(): void {
		$this->assertSame(
			self::LB,
			ClientIp::resolve( array( 'REMOTE_ADDR' => self::LB ), TrustedProxies::from( self::LB, false ) )
		);
	}

	public function test_cloudflare_s_header_is_read_from_cloudflare(): void {
		$server = array(
			'HTTP_CF_CONNECTING_IP' => self::AGENT,
			'HTTP_X_FORWARDED_FOR'  => '198.51.100.4',
			'REMOTE_ADDR'           => self::CF,
		);

		$this->assertSame( self::AGENT, ClientIp::resolve( $server, TrustedProxies::from( '', true ) ) );
	}

	/**
	 * A generic load balancer passes CF-Connecting-IP through untouched, so
	 * behind one the header is whatever the client sent.
	 */
	public function test_cloudflare_s_header_is_ignored_from_any_other_trusted_proxy(): void {
		$server = array(
			'HTTP_CF_CONNECTING_IP' => self::AGENT,
			'HTTP_X_FORWARDED_FOR'  => '198.51.100.4',
			'REMOTE_ADDR'           => self::LB,
		);

		$this->assertSame( '198.51.100.4', ClientIp::resolve( $server, TrustedProxies::from( self::LB, true ) ) );
	}

	/**
	 * The preset trusts Cloudflare's addresses, not the header: a request that
	 * reached the origin directly is answered with its own address.
	 */
	public function test_cloudflare_s_header_is_ignored_on_a_request_that_bypassed_cloudflare(): void {
		$server = array(
			'HTTP_CF_CONNECTING_IP' => self::AGENT,
			'REMOTE_ADDR'           => '203.0.113.7',
		);

		$this->assertSame( '203.0.113.7', ClientIp::resolve( $server, TrustedProxies::from( '', true ) ) );
	}

	public function test_an_unparseable_cloudflare_header_falls_back_to_forwarded_for(): void {
		$server = array(
			'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
			'HTTP_X_FORWARDED_FOR'  => self::AGENT,
			'REMOTE_ADDR'           => self::CF,
		);

		$this->assertSame( self::AGENT, ClientIp::resolve( $server, TrustedProxies::from( '', true ) ) );
	}

	public function test_ipv6_peers_and_hops_are_handled(): void {
		$server = array(
			'HTTP_X_FORWARDED_FOR' => '2001:db8:1::7',
			'REMOTE_ADDR'          => '2001:db8:ffff::1',
		);

		$this->assertSame( '2001:db8:1::7', ClientIp::resolve( $server, TrustedProxies::from( '2001:db8:ffff::/48', false ) ) );
	}

	public function test_garbage_resolves_to_an_empty_string(): void {
		$this->assertSame( '', ClientIp::resolve( array( 'REMOTE_ADDR' => 'localhost' ), self::none() ) );
		$this->assertSame( '', ClientIp::resolve( array(), TrustedProxies::from( self::LB, true ) ) );
	}

	private static function none(): TrustedProxies {
		return TrustedProxies::from( '', false );
	}
}
