<?php
/**
 * Integration: the generated API catalog against a live WordPress core.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\EventRecorder;
use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\ApiCatalog;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\Router;
use Globetrotters\AiPresence\Serving\Sitemap;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeFlushRequest;
use WP_UnitTestCase;

/**
 * ``/.well-known/api-catalog`` (RFC 9727): generated from the cache on every
 * request, anchored on this site's own origin, and counted as agent traffic.
 * Mirrors the Symfony bundle's ApiCatalogServingTest. The real 200 response is
 * asserted over HTTP by ``bin/smoke-routes.sh``.
 */
final class ApiCatalogServingTest extends WP_UnitTestCase {

	/**
	 * The request keys these cases set, and their values beforehand.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_server = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'REQUEST_METHOD', 'REQUEST_URI', 'HTTP_USER_AGENT', 'REMOTE_ADDR' ) as $key ) {
			$this->saved_server[ $key ] = $_SERVER[ $key ] ?? null;
		}
		// PHPUnit runs under CLI, where there is no request method; the router
		// serves GET/HEAD only.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/' . ApiCatalog::PATH;
	}

	public function tear_down(): void {
		// This class sorts first: leave no request state for the ones after it.
		foreach ( $this->saved_server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	public function test_serves_a_linkset_anchored_on_the_site_origin(): void {
		$this->cache_full_bundle();

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertCount( 1, $router->emitted );
		$origin  = Sitemap::site_origin();
		$linkset = json_decode( $router->emitted[0][1], true );
		$this->assertIsArray( $linkset );
		$this->assertSame( $origin . '/', $linkset['linkset'][0]['anchor'] );
		$this->assertSame( $origin . '/llms.txt', $linkset['linkset'][0]['service-doc'][0]['href'] );
		$this->assertCount( 3, $linkset['linkset'][0]['service-desc'] );
		$this->assertStringNotContainsString( 'globetrotters.ai', $router->emitted[0][1] );
	}

	public function test_carries_the_artefact_headers(): void {
		$this->cache_full_bundle();

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( ApiCatalog::CONTENT_TYPE, $router->headers['Content-Type'] );
		$this->assertSame( 'nosniff', $router->headers['X-Content-Type-Options'] );
		$this->assertSame( 'no-store', $router->headers['Cache-Control'] );
		$this->assertSame( 'no-store', $router->headers['Surrogate-Control'] );
		// A discovery document browser-context agents read, granted like the
		// artefacts it lists.
		$this->assertSame( '*', $router->headers['Access-Control-Allow-Origin'] );
	}

	/**
	 * Every link is replayed through the router, so the catalog and the served
	 * set cannot diverge.
	 */
	public function test_every_listed_target_is_served(): void {
		$this->cache_full_bundle();

		$router = $this->spy_router();
		$router->maybe_serve();
		$linkset = json_decode( $router->emitted[0][1], true );
		$hrefs   = array_column(
			array_merge( $linkset['linkset'][0]['service-doc'], $linkset['linkset'][0]['service-desc'] ),
			'href'
		);

		$this->assertCount( 4, $hrefs );
		$origin = Sitemap::site_origin();
		foreach ( $hrefs as $href ) {
			$_SERVER['REQUEST_URI'] = substr( $href, strlen( $origin ) );

			$probe = $this->spy_router();
			$probe->maybe_serve();

			$this->assertNotSame( array(), $probe->emitted, "{$href} is listed but not served." );
		}
	}

	public function test_is_counted_as_agent_traffic(): void {
		( new Schema() )->install();
		$options = new Options();
		$options->update_settings(
			array(
				'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
				'ingest_token'    => 'tok_integration',
			)
		);
		$store = new MysqlEventStore();
		$store->delete_oldest( 1000 );
		$this->cache_full_bundle();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
		$_SERVER['REMOTE_ADDR']     = '160.79.104.10';

		$router = $this->spy_router( new EventRecorder( new EventBuffer( $store ), $options, new FakeFlushRequest() ) );
		$router->maybe_serve();

		$rows = $store->oldest( 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( '/.well-known/api-catalog', $rows[0]['event']->path() );
		$this->assertSame( strlen( $router->emitted[0][1] ), $rows[0]['event']->bytes() );
		$store->delete_oldest( 1000 );
	}

	public function test_is_listed_in_the_sitemap(): void {
		$this->cache_full_bundle();
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertStringContainsString(
			'<loc>' . Sitemap::site_origin() . '/.well-known/api-catalog</loc>',
			$router->emitted[0][1]
		);
	}

	public function test_not_answered_when_no_bundle_is_cached(): void {
		$router = $this->spy_router();
		$router->maybe_serve();

		// WordPress's own 404: the plugin never claimed the path.
		$this->assertSame( array(), $router->emitted );
	}

	public function test_only_get_and_head_are_answered(): void {
		$this->cache_full_bundle();

		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$head                      = $this->spy_router();
		$head->maybe_serve();
		$this->assertCount( 1, $head->emitted );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$post                      = $this->spy_router();
		$post->maybe_serve();
		$this->assertSame( array(), $post->emitted );
	}

	/**
	 * Every served artefact, cached.
	 */
	private function cache_full_bundle(): void {
		$files = array();
		foreach ( ContentTypes::paths() as $path ) {
			$files[ $path ] = 'body-for-' . $path;
		}
		update_option( ArtefactCache::OPTION, array( 'files' => $files, 'version' => 'v1' ), false );
	}

	/**
	 * A Router that records emit() calls instead of sending headers + exiting.
	 *
	 * @param EventRecorder|null $recorder Optional live recorder.
	 */
	private function spy_router( ?EventRecorder $recorder = null ): Router {
		return new class( new ArtefactCache(), new Options(), $recorder ) extends Router {
			/**
			 * @var array<int, array{0:string,1:string}>
			 */
			public array $emitted = array();

			/**
			 * Headers of the most recent emit.
			 *
			 * @var array<string, string>
			 */
			public array $headers = array();

			protected function emit( string $path, string $body, array $headers ): void {
				$this->emitted[] = array( $path, $body );
				$this->headers   = $headers;
			}
		};
	}
}
