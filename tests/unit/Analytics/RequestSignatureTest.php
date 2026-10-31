<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\RequestSignature;
use Globetrotters\AiPresence\Analytics\TrustedProxies;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * The ``authority`` member must be byte-identical to the Symfony bundle's
 * (``Request::getHttpHost()``): the backend verifies the signature over it.
 *
 * @covers \Globetrotters\AiPresence\Analytics\RequestSignature
 */
final class RequestSignatureTest extends TestCase {

	private const PROXY = '10.0.0.5';

	/**
	 * @dataProvider direct_requests
	 *
	 * @param array<string, string> $server   Request values.
	 * @param string                $expected Authority.
	 */
	public function test_a_direct_request_reads_the_host_header( array $server, string $expected ): void {
		$this->assertSame( $expected, RequestSignature::authority( $server + array( 'REMOTE_ADDR' => '203.0.113.9' ), self::trusting() ) );
	}

	/**
	 * @return array<string, array{0:array<string, string>, 1:string}>
	 */
	public static function direct_requests(): array {
		return array(
			'mixed case is lower-cased'         => array( array( 'HTTP_HOST' => 'WWW.Visit-Acme.Example' ), 'www.visit-acme.example' ),
			'https default port is dropped'     => array(
				array(
					'HTTP_HOST' => 'visit-acme.example:443',
					'HTTPS'     => 'on',
				),
				'visit-acme.example',
			),
			'http default port is dropped'      => array( array( 'HTTP_HOST' => 'visit-acme.example:80' ), 'visit-acme.example' ),
			'a non-default port is kept'        => array(
				array(
					'HTTP_HOST' => 'visit-acme.example:8443',
					'HTTPS'     => 'on',
				),
				'visit-acme.example:8443',
			),
			'443 over http is not the default'  => array( array( 'HTTP_HOST' => 'visit-acme.example:443' ), 'visit-acme.example:443' ),
			'HTTPS=off is http'                 => array(
				array(
					'HTTP_HOST' => 'visit-acme.example:80',
					'HTTPS'     => 'off',
				),
				'visit-acme.example',
			),
			'IPv6 literal keeps its port'       => array( array( 'HTTP_HOST' => '[2001:db8::1]:8080' ), '[2001:db8::1]:8080' ),
			'IPv6 literal without a port'       => array( array( 'HTTP_HOST' => '[2001:db8::1]' ), '[2001:db8::1]' ),
			'no Host: SERVER_NAME + SERVER_PORT' => array(
				array(
					'SERVER_NAME' => 'Visit-Acme.example',
					'SERVER_PORT' => '8080',
				),
				'visit-acme.example:8080',
			),
			'an invalid host falls back raw'    => array( array( 'HTTP_HOST' => ' Bad Host/Ü ' ), 'bad host/Ü' ),
		);
	}

	public function test_forwarded_headers_from_an_untrusted_peer_are_ignored(): void {
		$server = array(
			'REMOTE_ADDR'            => '203.0.113.9',
			'HTTP_HOST'              => 'origin.internal:8080',
			'HTTP_X_FORWARDED_HOST'  => 'www.visit-acme.example',
			'HTTP_X_FORWARDED_PORT'  => '443',
			'HTTP_X_FORWARDED_PROTO' => 'https',
		);

		$this->assertSame( 'origin.internal:8080', RequestSignature::authority( $server, self::trusting() ) );
		$this->assertSame( 'origin.internal:8080', RequestSignature::authority( $server, TrustedProxies::from( '', false ) ), 'Nothing listed trusts nothing.' );
	}

	/**
	 * @dataProvider proxied_requests
	 *
	 * @param array<string, string> $server   Request values.
	 * @param string                $expected Authority.
	 */
	public function test_a_trusted_proxy_forwards_host_port_and_scheme( array $server, string $expected ): void {
		$server += array(
			'REMOTE_ADDR' => self::PROXY,
			'HTTP_HOST'   => 'origin.internal:8080',
		);

		$this->assertSame( $expected, RequestSignature::authority( $server, self::trusting() ) );
	}

	/**
	 * @return array<string, array{0:array<string, string>, 1:string}>
	 */
	public static function proxied_requests(): array {
		return array(
			'forwarded https on its default port' => array(
				array(
					'HTTP_X_FORWARDED_HOST'  => 'WWW.Visit-Acme.example, origin.internal',
					'HTTP_X_FORWARDED_PROTO' => 'https',
				),
				'www.visit-acme.example',
			),
			'forwarded port wins and is kept'     => array(
				array(
					'HTTP_X_FORWARDED_HOST'  => 'www.visit-acme.example',
					'HTTP_X_FORWARDED_PORT'  => '8443',
					'HTTP_X_FORWARDED_PROTO' => 'https',
				),
				'www.visit-acme.example:8443',
			),
			'forwarded proto overrides HTTPS'     => array(
				array(
					'HTTP_X_FORWARDED_HOST'  => 'www.visit-acme.example:80',
					'HTTP_X_FORWARDED_PROTO' => 'http',
					'HTTPS'                  => 'on',
				),
				'www.visit-acme.example',
			),
			'no forwarded host: the Host header'  => array( array(), 'origin.internal:8080' ),
		);
	}

	public function test_from_server_uses_the_resolved_authority_clipped(): void {
		$signature = RequestSignature::from_server(
			array(
				'REMOTE_ADDR'           => self::PROXY,
				'HTTP_HOST'             => 'origin.internal',
				'HTTP_X_FORWARDED_HOST' => 'WWW.Visit-Acme.example',
				'HTTP_SIGNATURE_AGENT'  => '"https://www.meta.com"',
				'HTTP_SIGNATURE_INPUT'  => 'sig1=("@authority")',
				'HTTP_SIGNATURE'        => 'sig1=:YWJj:',
			),
			self::trusting()
		);
		$this->assertSame( 'www.visit-acme.example', $signature['authority'] );

		$long = RequestSignature::from_server(
			array(
				'HTTP_HOST'            => str_repeat( 'h', 1500 ),
				'HTTP_SIGNATURE_AGENT' => 'a',
				'HTTP_SIGNATURE_INPUT' => 'i',
				'HTTP_SIGNATURE'       => 'v',
			)
		);
		$this->assertSame( RequestSignature::MAX_CHARS, strlen( $long['authority'] ) );
	}

	private static function trusting(): TrustedProxies {
		return TrustedProxies::from( self::PROXY, false );
	}
}
