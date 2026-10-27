<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Client;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Client\GtClient;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Client\GtClient
 */
final class GtClientTest extends TestCase {

	private const URL = 'https://acme.globetrotters.ai/llms.txt';

	/**
	 * Every ``wp_remote_get`` call, as ``[url, args]``.
	 *
	 * @var array<int, array{0:string, 1:array<string, mixed>}>
	 */
	private array $gets = array();

	/**
	 * Canned responses keyed by URL; anything unlisted answers 200 ``# Acme``.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $responses = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'home_url' )->justReturn( 'https://acme.example/' );
		Functions\when( 'is_wp_error' )->alias( fn( $x ) => $x instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( $response, $name ) => $response['headers'][ strtolower( $name ) ] ?? ''
		);
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->gets[] = array( $url, $args );
				return $this->responses[ $url ] ?? array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => '# Acme',
				);
			}
		);
	}

	public function test_fetches_the_url_and_returns_status_and_body(): void {
		$result = ( new GtClient() )->fetch( self::URL );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( '# Acme', $result->body() );
		$this->assertSame( self::URL, $this->gets[0][0] );
		$this->assertTrue( $this->gets[0][1]['reject_unsafe_urls'] );
	}

	/**
	 * Core is never left to follow a redirect itself: its validate_redirects()
	 * accepts an ``http://`` Location, so each hop is followed here instead.
	 */
	public function test_core_never_follows_a_redirect_itself(): void {
		$this->redirect( self::URL, 'https://ai.visit-acme.example/llms.txt' );

		( new GtClient() )->fetch( self::URL );

		$this->assertCount( 2, $this->gets );
		foreach ( $this->gets as list( , $args ) ) {
			$this->assertSame( 0, $args['redirection'] );
			$this->assertLessThanOrEqual( 5, $args['timeout'], 'The chain shares one timeout budget.' );
			$this->assertTrue( $args['reject_unsafe_urls'], 'Every hop keeps the SSRF guard.' );
		}
	}

	/**
	 * The canonical host can move — ``<slug>.globetrotters.ai`` to
	 * ``ai.<their-domain>`` — and the old one may answer with a redirect.
	 *
	 * @dataProvider redirect_status_provider
	 * @param int $status Redirect status.
	 */
	public function test_follows_an_https_redirect_to_its_target( int $status ): void {
		$this->redirect( self::URL, 'https://ai.visit-acme.example/llms.txt', $status );

		$result = ( new GtClient() )->fetch( self::URL );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( '# Acme', $result->body() );
		$this->assertSame( 'https://ai.visit-acme.example/llms.txt', $this->gets[1][0] );
	}

	/**
	 * @return array<string, array{0:int}>
	 */
	public static function redirect_status_provider(): array {
		return array(
			'301' => array( 301 ),
			'302' => array( 302 ),
			'303' => array( 303 ),
			'307' => array( 307 ),
			'308' => array( 308 ),
		);
	}

	/**
	 * @dataProvider relative_location_provider
	 * @param string $location Location header as sent.
	 * @param string $expected The URL it must resolve to.
	 */
	public function test_resolves_a_relative_location_against_the_current_url( string $location, string $expected ): void {
		$this->redirect( 'https://acme.globetrotters.ai:8443/a/llms.txt', $location );

		( new GtClient() )->fetch( 'https://acme.globetrotters.ai:8443/a/llms.txt' );

		$this->assertSame( $expected, $this->gets[1][0] );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function relative_location_provider(): array {
		return array(
			'root-relative'     => array( '/b/llms.txt', 'https://acme.globetrotters.ai:8443/b/llms.txt' ),
			'path-relative'     => array( 'v2/llms.txt', 'https://acme.globetrotters.ai:8443/a/v2/llms.txt' ),
			'protocol-relative' => array( '//ai.visit-acme.example/llms.txt', 'https://ai.visit-acme.example/llms.txt' ),
			'query only'        => array( '?v=2', 'https://acme.globetrotters.ai:8443/a/llms.txt?v=2' ),
			'fragment dropped'  => array( '/b/llms.txt#top', 'https://acme.globetrotters.ai:8443/b/llms.txt' ),
		);
	}

	/**
	 * What is fetched is published at the customer's apex: a redirect off
	 * https is refused before anything is requested from its target.
	 *
	 * @dataProvider non_https_location_provider
	 * @param string $location Location header as sent.
	 */
	public function test_refuses_a_redirect_whose_target_is_not_https( string $location ): void {
		$this->redirect( self::URL, $location );

		$result = ( new GtClient() )->fetch( self::URL );

		$this->assertFalse( $result->is_ok() );
		$this->assertTrue( $result->is_transport_error() );
		$this->assertStringContainsString( 'https', $result->error_message() );
		$this->assertCount( 1, $this->gets, 'The non-https target must never be requested.' );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function non_https_location_provider(): array {
		return array(
			'http'       => array( 'http://acme.globetrotters.ai/llms.txt' ),
			'HTTP'       => array( 'HTTP://acme.globetrotters.ai/llms.txt' ),
			'other'      => array( 'ftp://acme.globetrotters.ai/llms.txt' ),
			'javascript' => array( 'javascript:alert(1)' ),
		);
	}

	public function test_an_https_hop_after_an_https_hop_is_still_checked(): void {
		$this->redirect( self::URL, 'https://ai.visit-acme.example/llms.txt' );
		$this->redirect( 'https://ai.visit-acme.example/llms.txt', 'http://ai.visit-acme.example/llms.txt' );

		$result = ( new GtClient() )->fetch( self::URL );

		$this->assertTrue( $result->is_transport_error() );
		$this->assertCount( 2, $this->gets );
	}

	public function test_gives_up_after_the_redirect_limit(): void {
		$url = self::URL;
		for ( $hop = 1; $hop <= GtClient::MAX_REDIRECTS + 1; $hop++ ) {
			$next = 'https://acme.globetrotters.ai/hop' . $hop;
			$this->redirect( $url, $next );
			$url = $next;
		}

		$result = ( new GtClient() )->fetch( self::URL );

		$this->assertTrue( $result->is_transport_error() );
		$this->assertCount( GtClient::MAX_REDIRECTS + 1, $this->gets );
	}

	public function test_a_redirect_without_a_location_is_a_failed_fetch(): void {
		$this->responses[ self::URL ] = array(
			'response' => array( 'code' => 301 ),
			'headers'  => array(),
			'body'     => '',
		);

		$result = ( new GtClient() )->fetch( self::URL );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 301, $result->status() );
		$this->assertCount( 1, $this->gets );
	}

	public function test_a_not_modified_is_not_followed(): void {
		$this->redirect( self::URL, 'https://ai.visit-acme.example/llms.txt', 304 );

		$result = ( new GtClient() )->fetch( self::URL );

		$this->assertSame( 304, $result->status() );
		$this->assertCount( 1, $this->gets );
	}

	/**
	 * Answer ``$from`` with a redirect to ``$location``.
	 *
	 * @param string $from     Requested URL.
	 * @param string $location Location header.
	 * @param int    $status   Redirect status.
	 */
	private function redirect( string $from, string $location, int $status = 301 ): void {
		$this->responses[ $from ] = array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'location' => $location ),
			'body'     => '',
		);
	}
}
