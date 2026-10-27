<?php
/**
 * Integration: the homepage Link header against a live WordPress core.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\LinkHeader;
use WP_UnitTestCase;

/**
 * Drives {@see LinkHeader} with the real query state from ``go_to``. The CLI
 * SAPI sends no headers, so the decision is asserted through a spy here; the
 * real response — ours beside core's own ``Link`` header — is asserted over
 * HTTP by ``bin/smoke-routes.sh``.
 */
final class LinkHeaderTest extends WP_UnitTestCase {

	private const LINK = 'Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"';

	public function test_it_is_hooked_on_template_redirect_beside_cores_link_headers(): void {
		$found = false;
		foreach ( $GLOBALS['wp_filter']['template_redirect']->callbacks[ LinkHeader::PRIORITY ] ?? array() as $callback ) {
			$fn = $callback['function'];
			if ( is_array( $fn ) && $fn[0] instanceof LinkHeader && 'maybe_send' === $fn[1] ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'Plugin boot() must hook LinkHeader::maybe_send on template_redirect.' );
		$this->assertSame( LinkHeader::PRIORITY, has_action( 'template_redirect', 'rest_output_link_header' ) );
	}

	public function test_the_front_page_carries_the_header(): void {
		$this->cache_bundle();
		$this->go_to( home_url( '/' ) );

		$header = $this->spy();
		$header->maybe_send();

		$this->assertSame( array( array( self::LINK, false ) ), $header->sent );
	}

	public function test_an_interior_page_has_none(): void {
		$this->cache_bundle();
		$this->go_to( (string) get_permalink( self::factory()->post->create() ) );
		$this->assertFalse( is_front_page() );

		$header = $this->spy();
		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	public function test_a_cold_cache_advertises_nothing(): void {
		$this->go_to( home_url( '/' ) );

		$header = $this->spy();
		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	private function cache_bundle(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array(
					'llms.txt'                    => '# Acme',
					'.well-known/mcp.json'        => '{"m":1}',
					'.well-known/agent-card.json' => '{"c":2}',
					'.well-known/ai-catalog.json' => '{"specVersion":"1.0"}',
				),
				'version' => 'v1',
			),
			false
		);
	}

	/**
	 * A LinkHeader that records what it would send.
	 */
	private function spy(): LinkHeader {
		return new class( new ArtefactCache() ) extends LinkHeader {
			/**
			 * @var array<int, array{0:string,1:bool}>
			 */
			public array $sent = array();

			protected function status(): int {
				// The CLI SAPI has no response; the query state decides here.
				return 200;
			}

			protected function headers_so_far(): array {
				return array( 'Content-Type: text/html; charset=UTF-8' );
			}

			protected function send_header( string $line, bool $replace ): void {
				$this->sent[] = array( $line, $replace );
			}
		};
	}
}
