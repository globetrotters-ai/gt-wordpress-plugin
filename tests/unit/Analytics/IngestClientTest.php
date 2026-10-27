<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\IngestClient;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\IngestClient
 */
final class IngestClientTest extends TestCase {

	private const ENDPOINT = 'https://api.globetrotters.ai/presence/analytics/server-log';

	/**
	 * Every ``wp_remote_post`` call, as ``[url, args]``.
	 *
	 * @var array<int, array{0:string, 1:array<string, mixed>}>
	 */
	private array $posts = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'is_wp_error' )->alias( fn( $x ) => $x instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array( $url, $args );
				return array( 'response' => array( 'code' => 202 ) );
			}
		);
	}

	public function test_posts_the_envelope_with_the_bearer_token(): void {
		$result = ( new IngestClient() )->post( self::ENDPOINT, 'tok', '{}' );

		$this->assertTrue( $result->is_accepted() );
		$this->assertCount( 1, $this->posts );
		$this->assertSame( self::ENDPOINT, $this->posts[0][0] );
		$this->assertSame( 'Bearer tok', $this->posts[0][1]['headers']['Authorization'] );
	}

	/**
	 * Core's HTTP client re-sends the original headers — ``Authorization``
	 * included — to whatever ``Location`` a 3xx names, https→http downgrades
	 * and foreign hosts alike. The issued endpoint is final, as in
	 * the Symfony bundle, so a redirect settles as a non-2xx and is retried.
	 */
	public function test_never_follows_a_redirect(): void {
		( new IngestClient() )->post( self::ENDPOINT, 'tok', '{}' );

		$this->assertSame( 0, $this->posts[0][1]['redirection'] );
	}

	public function test_refuses_a_cleartext_endpoint_without_sending(): void {
		$result = ( new IngestClient() )->post( 'http://api.globetrotters.ai/presence/analytics/server-log', 'tok', '{}' );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( array(), $this->posts, 'the token must not leave over http' );
		$this->assertStringNotContainsString( 'tok', $result->error_message() );
	}
}
