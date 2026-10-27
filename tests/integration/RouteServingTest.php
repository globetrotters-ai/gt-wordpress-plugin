<?php
/**
 * Integration: apex route serving through a live WordPress core.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\Router;
use Globetrotters\AiPresence\Serving\Sitemap;
use Globetrotters\AiPresence\Settings\Options;
use WP_UnitTestCase;

/**
 * Exercises {@see Router} against real ``get_option`` / ``wp_parse_url`` with a
 * primed cache. The full 200 + Content-Type response (which ``emit()`` ends with
 * ``exit``) is covered end-to-end by the CI curl smoke; here we assert the
 * routing decision and body selection for every served path.
 */
final class RouteServingTest extends WP_UnitTestCase {

	/**
	 * A key shaped like the ones the presence stack issues (32 hex chars).
	 */
	private const INDEXNOW_KEY = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';

	public function set_up(): void {
		parent::set_up();
		// PHPUnit runs under CLI, where there is no request method; the router
		// serves GET/HEAD only.
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_parse_request_hook_is_registered(): void {
		$this->assertNotFalse(
			has_action( 'parse_request' ),
			'Plugin boot() must hook parse_request for apex routing.'
		);
	}

	public function test_each_served_path_is_emitted_with_its_content_type(): void {
		$files = array();
		foreach ( ContentTypes::paths() as $path ) {
			$files[ $path ] = 'body-for-' . $path;
		}
		update_option( ArtefactCache::OPTION, array( 'files' => $files, 'version' => 'v1' ), false );

		foreach ( ContentTypes::paths() as $path ) {
			$_SERVER['REQUEST_URI'] = '/' . $path;

			$router = $this->spy_router();
			$router->maybe_serve();

			$this->assertSame(
				array( array( $path, 'body-for-' . $path ) ),
				$router->emitted,
				"Router should serve {$path} from the cache."
			);
			$this->assertNotNull(
				ContentTypes::for_path( $path ),
				"{$path} must have a Content-Type mapping."
			);
		}
	}

	public function test_unserved_path_is_ignored(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'llms.txt' => '# hello' ),
				'version' => 'v1',
			),
			false
		);
		$_SERVER['REQUEST_URI'] = '/wp-login.php';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	public function test_served_path_with_no_cached_body_is_ignored(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array(),
				'version' => '',
			),
			false
		);
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * @dataProvider write_method_provider
	 */
	public function test_write_methods_are_not_served( string $method ): void {
		$this->seed_bundle();
		$_SERVER['REQUEST_URI']    = '/llms.txt';
		$_SERVER['REQUEST_METHOD'] = $method;

		$router = $this->spy_router();
		$router->maybe_serve();

		// The artefacts are read-only public documents. Serving is also the only
		// place agent traffic to an apex install is counted, so answering a write
		// method would fold probing traffic into the reported fetch counts.
		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function write_method_provider(): array {
		return array(
			'POST'    => array( 'POST' ),
			'PUT'     => array( 'PUT' ),
			'DELETE'  => array( 'DELETE' ),
			'PATCH'   => array( 'PATCH' ),
			'OPTIONS' => array( 'OPTIONS' ),
		);
	}

	public function test_head_is_served_like_get(): void {
		$this->seed_bundle();
		$_SERVER['REQUEST_URI']    = '/llms.txt';
		$_SERVER['REQUEST_METHOD'] = 'HEAD';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array( array( 'llms.txt', '# hello' ) ), $router->emitted );
	}

	/**
	 * @dataProvider non_canonical_uri_provider
	 */
	public function test_only_the_canonical_url_serves_an_artefact( string $uri ): void {
		$this->seed_bundle();
		$_SERVER['REQUEST_URI'] = $uri;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted, "{$uri} must not serve an artefact." );
	}

	/**
	 * Request targets a live Apache hands WordPress verbatim — none of these are
	 * normalised before PHP sees them, as the double-slash forms below prove.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function non_canonical_uri_provider(): array {
		return array(
			// parse_url() reads the segment after a leading '//' as an authority
			// and discards it, so '//anything/llms.txt' parsed to '/llms.txt' and
			// served the artefact under a URL naming a host that is not this site.
			'faked authority'    => array( '//anything/llms.txt' ),
			'faked + well-known' => array( '//anything/.well-known/mcp.json' ),
			'leading //'         => array( '//llms.txt' ),
			'leading ///'        => array( '///schema.json' ),
			'interior //'        => array( '/.well-known//mcp.json' ),
			'trailing slash'     => array( '/llms.txt/' ),
			'dot segment'        => array( '/./llms.txt' ),
		);
	}

	public function test_the_indexnow_key_is_served_at_its_own_path(): void {
		$this->seed_bundle();
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI'] = '/' . self::INDEXNOW_KEY . '.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		// Body byte-equal to the key, with no trailing newline: IndexNow compares
		// the file's contents to the key it was handed.
		$this->assertSame(
			array( array( self::INDEXNOW_KEY . '.txt', self::INDEXNOW_KEY ) ),
			$router->emitted
		);
		$this->assertSame( 'text/plain; charset=utf-8', $router->headers['Content-Type'] );
		$this->assertSame( 'no-store', $router->headers['Cache-Control'] );
		$this->assertSame( 'nosniff', $router->headers['X-Content-Type-Options'] );
	}

	public function test_the_key_path_falls_through_when_no_key_is_stored(): void {
		$this->seed_bundle();
		$_SERVER['REQUEST_URI'] = '/' . self::INDEXNOW_KEY . '.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		// Falls through to WordPress, which answers its normal 404. Never a 200
		// with an empty body — that would fail verification for whoever fetched it.
		$this->assertSame( array(), $router->emitted );
	}

	public function test_llms_txt_still_resolves_to_the_artefact_with_a_key_stored(): void {
		$this->seed_bundle();
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array( array( 'llms.txt', '# hello' ) ), $router->emitted );
	}

	public function test_the_generated_sitemap_is_served_at_its_own_path(): void {
		$this->seed_bundle();
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertCount( 1, $router->emitted );
		$this->assertSame( Sitemap::PATH, $router->emitted[0][0] );
		$this->assertSame( Sitemap::CONTENT_TYPE, $router->headers['Content-Type'] );

		// Valid XML, and every <loc> on this site's own host — a sitemap may
		// only list same-host URLs, which is exactly why it is generated here
		// rather than pulled from the GT-host copy.
		$xml = simplexml_load_string( $router->emitted[0][1] );
		$this->assertNotFalse( $xml, 'the sitemap must be well-formed XML' );

		$origin = Sitemap::site_origin();
		$locs   = array();
		foreach ( $xml->url as $url ) {
			$locs[] = (string) $url->loc;
		}
		$this->assertNotEmpty( $locs );
		foreach ( $locs as $loc ) {
			$this->assertStringStartsWith( $origin . '/', $loc );
		}

		// Every artefact listed is one this router actually answers, and the
		// ones the bundle does not carry are absent.
		$this->assertContains( $origin . '/llms.txt', $locs );
		$this->assertContains( $origin . '/.well-known/mcp.json', $locs );
		$this->assertNotContains( $origin . '/ai.json', $locs );
	}

	public function test_the_sitemap_path_falls_through_before_a_bundle_is_installed(): void {
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * Every URL the sitemap lists must be one this router serves — asserted by
	 * replaying each one through the router rather than by inspection.
	 */
	public function test_every_listed_url_is_actually_served(): void {
		$this->seed_bundle();
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;

		$router = $this->spy_router();
		$router->maybe_serve();
		$xml = simplexml_load_string( $router->emitted[0][1] );

		$origin = Sitemap::site_origin();
		foreach ( $xml->url as $url ) {
			$path = substr( (string) $url->loc, strlen( $origin ) );
			if ( '/' === $path ) {
				// The site root is WordPress's own, not an artefact route.
				continue;
			}
			$_SERVER['REQUEST_URI'] = $path;

			$probe = $this->spy_router();
			$probe->maybe_serve();

			$this->assertNotSame( array(), $probe->emitted, "{$path} is listed but not served." );
		}
	}

	/**
	 * @dataProvider alias_provider
	 */
	public function test_an_alias_is_served_with_its_canonical_file( string $uri, string $canonical ): void {
		update_option( ArtefactCache::OPTION, array( 'files' => array( $canonical => 'body-of-' . $canonical ), 'version' => 'v1' ), false );
		$_SERVER['REQUEST_URI'] = $uri;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array( array( ltrim( $uri, '/' ), 'body-of-' . $canonical ) ), $router->emitted );
		$this->assertSame( Router::response_headers( $canonical ), $router->headers );
	}

	/**
	 * Before a bundle is cached an alias is not ours to answer: the request
	 * runs through the plugin's own ``parse_request`` hook and on to
	 * WordPress's normal 404.
	 *
	 * @dataProvider alias_provider
	 */
	public function test_an_alias_reaches_the_wordpress_404_while_nothing_is_cached( string $uri ): void {
		$this->set_permalink_structure( '/%postname%/' );

		$this->go_to( home_url( $uri ) );

		$this->assertTrue( is_404(), "{$uri} must fall through to WordPress's 404." );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function alias_provider(): array {
		return array(
			'agents.md'           => array( '/agents.md', 'AGENTS.md' ),
			'agent-skills'        => array( '/.well-known/agent-skills', '.well-known/agent-skills/index.json' ),
			'agent-skills, slash' => array( '/.well-known/agent-skills/', '.well-known/agent-skills/index.json' ),
		);
	}

	public function test_the_sitemap_lists_canonical_paths_only(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array(
					'AGENTS.md'                           => '# hello',
					'.well-known/agent-skills/index.json' => '{}',
				),
				'version' => 'v1',
			),
			false
		);
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;

		$router = $this->spy_router();
		$router->maybe_serve();
		$body = $router->emitted[0][1];

		$this->assertStringContainsString( '/AGENTS.md</loc>', $body );
		$this->assertStringContainsString( '/.well-known/agent-skills/index.json</loc>', $body );
		$this->assertStringNotContainsString( '/agents.md<', $body );
		$this->assertStringNotContainsString( '/.well-known/agent-skills<', $body );
		$this->assertStringNotContainsString( '/.well-known/agent-skills/<', $body );
	}

	/**
	 * Put a key in the plugin's runtime state, as a successful sync would.
	 *
	 * @param string $key Key to store.
	 */
	private function store_key( string $key ): void {
		$state = get_option( Options::STATE, array() );
		update_option( Options::STATE, array_merge( is_array( $state ) ? $state : array(), array( 'indexnow_key' => $key ) ) );
	}

	/**
	 * Seed a one-file bundle for the guard tests.
	 */
	private function seed_bundle(): void {
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array(
					'llms.txt'             => '# hello',
					'schema.json'          => '{}',
					'.well-known/mcp.json' => '{}',
				),
				'version' => 'v1',
			),
			false
		);
	}

	/**
	 * A Router that records emit() calls instead of sending headers + exiting.
	 */
	private function spy_router(): Router {
		return new class( new ArtefactCache(), new Options() ) extends Router {
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
