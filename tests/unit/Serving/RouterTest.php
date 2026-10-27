<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\EventRecorder;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\ApiCatalog;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\Router;
use Globetrotters\AiPresence\Serving\Sitemap;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\FakeFlushRequest;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Serving\Router
 */
final class RouterTest extends TestCase {

	use WpOptions;

	/**
	 * A key shaped like the ones the presence stack issues (32 hex chars).
	 */
	private const INDEXNOW_KEY = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			fn( $key ) => strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) )
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * @dataProvider path_provider
	 */
	public function test_only_the_canonical_uri_resolves_to_a_served_file( string $uri, ?string $expected ): void {
		$path = Router::request_path( $uri );
		$served = ( null !== $path && ContentTypes::has( $path ) ) ? $path : null;

		$this->assertSame( $expected, $served );
	}

	/**
	 * @return array<string, array{0:string, 1:?string}>
	 */
	public static function path_provider(): array {
		return array(
			'llms.txt'          => array( '/llms.txt', 'llms.txt' ),
			'with query string' => array( '/ai.json?v=2', 'ai.json' ),
			'well-known'        => array( '/.well-known/mcp.json', '.well-known/mcp.json' ),
			'not served'        => array( '/wp-login.php', null ),
			'home'              => array( '/', null ),
			'empty'             => array( '', null ),
			// Non-canonical forms. A second leading slash makes the request
			// target a protocol-relative URL, so parse_url() reads the next
			// segment as an *authority* and drops it: '//anything/llms.txt'
			// would otherwise parse to '/llms.txt' and serve the artefact under
			// a URL naming a host that is not this site. Each variant that
			// resolved would be one more duplicate URL for the same bytes.
			'leading //'        => array( '//llms.txt', null ),
			'faked authority'   => array( '//anything/llms.txt', null ),
			'faked + well-known' => array( '//anything/.well-known/mcp.json', null ),
			'leading ///'       => array( '///schema.json', null ),
			'interior //'       => array( '/.well-known//mcp.json', null ),
			'trailing slash'    => array( '/llms.txt/', null ),
			'dot segment'       => array( '/./llms.txt', null ),
			'absolute form'     => array( 'https://example.com/llms.txt', null ),
		);
	}

	/**
	 * @dataProvider write_method_provider
	 */
	public function test_maybe_serve_ignores_write_methods( string $method ): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI']    = '/llms.txt';
		$_SERVER['REQUEST_METHOD'] = $method;
		$events                    = $this->capturing_store();

		$router = $this->spy_router( $events );
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted, "{$method} must fall through to WordPress." );
		// The artefacts are read-only documents, and serving is the only place
		// agent traffic to an apex install is counted: a write method answered
		// here would fold probing traffic into the reported fetch counts.
		$this->assertSame( array(), $events->rows );
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

	public function test_maybe_serve_answers_head_like_get(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI']    = '/llms.txt';
		$_SERVER['REQUEST_METHOD'] = 'HEAD';

		$router = $this->spy_router();
		$router->maybe_serve();

		// A HEAD is how most agent clients probe for freshness; PHP drops the
		// body itself, so the same code path is correct.
		$this->assertSame( array( array( 'llms.txt', '# hello' ) ), $router->emitted );
	}

	public function test_maybe_serve_emits_cached_body_for_a_served_path(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array( array( 'llms.txt', '# hello' ) ), $router->emitted );
	}

	/**
	 * The four agent discovery files 0.7.0 serves: each is the published copy,
	 * byte for byte, under the content type the backend's content types give
	 * it.
	 *
	 * @dataProvider agent_discovery_file_provider
	 */
	public function test_an_agent_discovery_file_is_served_verbatim( string $path, string $content_type, string $body ): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( $path => $body ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/' . $path;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array( array( $path, $body ) ), $router->emitted );
		$this->assertSame( $content_type, $router->headers['Content-Type'] );
		$this->assertSame( '*', $router->headers['Access-Control-Allow-Origin'] );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function agent_discovery_file_provider(): array {
		return array(
			'agent.json'         => array( 'agent.json', 'application/json; charset=utf-8', '{"name":"Acme"}' ),
			'AGENTS.md'          => array( 'AGENTS.md', 'text/markdown; charset=utf-8', "# Acme\n\nOfficial agent guide.\n" ),
			'ai-catalog.json'    => array( '.well-known/ai-catalog.json', 'application/json; charset=utf-8', '{"specVersion":"1.0","host":{"identifier":"acme-city.example"},"entries":[]}' ),
			'agent-skills index' => array( '.well-known/agent-skills/index.json', 'application/json; charset=utf-8', '{"$schema":"https://schemas.agentskills.io/discovery/0.2.0/schema.json","skills":[]}' ),
		);
	}

	/**
	 * The spellings readiness scanners probe for the discovery files answer with
	 * the canonical file's bytes and headers, in place: no redirect.
	 *
	 * @dataProvider alias_provider
	 */
	public function test_an_alias_is_served_with_its_canonical_file( string $uri, string $canonical, string $content_type ): void {
		$body                                 = 'body-of-' . $canonical;
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( $canonical => $body ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = $uri;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array( array( ltrim( $uri, '/' ), $body ) ), $router->emitted );
		$this->assertSame( Router::response_headers( $canonical ), $router->headers );
		$this->assertSame( $content_type, $router->headers['Content-Type'] );
	}

	/**
	 * @dataProvider alias_provider
	 */
	public function test_an_alias_falls_through_while_nothing_is_cached( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;
		$events                 = $this->capturing_store();

		$router = $this->spy_router( $events );
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted, "{$uri} must reach WordPress's own 404." );
		$this->assertSame( array(), $events->rows );
	}

	/**
	 * @dataProvider alias_provider
	 */
	public function test_an_alias_is_captured_under_the_path_requested( string $uri, string $canonical ): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( $canonical => '{}' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = $uri . '?ref=scan';
		$events                 = $this->capturing_store();

		$this->spy_router( $events )->maybe_serve();

		// The backend maps each alias to its canonical file's asset format.
		$this->assertCount( 1, $events->rows );
		$this->assertSame( $uri, $events->oldest( 1 )[0]['event']->path() );
	}

	/**
	 * @dataProvider alias_provider
	 */
	public function test_an_alias_answers_read_methods_only( string $uri, string $canonical ): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( $canonical => '{}' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function alias_provider(): array {
		return array(
			'agents.md'                 => array( '/agents.md', 'AGENTS.md', 'text/markdown; charset=utf-8' ),
			'agent-skills'              => array( '/.well-known/agent-skills', '.well-known/agent-skills/index.json', 'application/json; charset=utf-8' ),
			'agent-skills, slash'       => array( '/.well-known/agent-skills/', '.well-known/agent-skills/index.json', 'application/json; charset=utf-8' ),
		);
	}

	public function test_only_the_listed_spellings_are_aliases(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array(
				'AGENTS.md'                           => '# Acme',
				'.well-known/agent-skills/index.json' => '{}',
			),
			'version' => 'v1',
		);

		foreach ( array( '/Agents.md', '/agents.md/', '/.well-known/agent-skills//', '/.well-known//agent-skills', '/agent-skills' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;

			$router = $this->spy_router();
			$router->maybe_serve();

			$this->assertSame( array(), $router->emitted, "{$uri} must not be an alias." );
		}
	}

	public function test_maybe_serve_ignores_unserved_paths(): void {
		$_SERVER['REQUEST_URI'] = '/wp-admin/';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	public function test_maybe_serve_does_nothing_when_body_absent(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array(),
			'version' => '',
		);
		$_SERVER['REQUEST_URI'] = '/llms.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * @dataProvider served_path_provider
	 */
	public function test_served_artefacts_are_uncacheable_everywhere( string $path ): void {
		$headers = Router::response_headers( $path );

		// Both, on every path. DONOTCACHEPAGE stops WP Super Cache and W3TC but
		// means nothing to Cloudflare, Varnish, Fastly or a managed-host edge —
		// and behind a shared TTL, PHP never runs and the hit count is silently
		// low by an unknowable amount.
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertSame( 'no-store', $headers['Surrogate-Control'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertNotSame( '', $headers['Content-Type'] );
	}

	/**
	 * @dataProvider served_path_provider
	 */
	public function test_served_artefacts_are_cross_origin_readable( string $path ): void {
		// Public, read-only agent metadata that anyone can already fetch. Without
		// this header an AI assistant running in a browser can't read it at all.
		$headers = Router::response_headers( $path );

		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'] );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function served_path_provider(): array {
		$paths = array();
		foreach ( ContentTypes::paths() as $path ) {
			$paths[ $path ] = array( $path );
		}
		return $paths;
	}

	public function test_a_served_request_is_captured_under_its_canonical_path(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/llms.txt?v=2';
		$events                 = $this->capturing_store();

		$this->spy_router( $events )->maybe_serve();

		$this->assertCount( 1, $events->rows );
		$captured = $events->oldest( 1 )[0]['event'];
		// The canonical path, not REQUEST_URI: the backend matches the served paths
		// exactly and drops '/llms.txt?v=2' at ingest.
		$this->assertSame( '/llms.txt', $captured->path() );
		$this->assertSame( 200, $captured->status() );
		$this->assertSame( 7, $captured->bytes() );
	}

	public function test_an_unserved_request_is_not_captured(): void {
		$_SERVER['REQUEST_URI'] = '/index.html';
		$events                 = $this->capturing_store();

		$this->spy_router( $events )->maybe_serve();

		$this->assertSame( array(), $events->rows );
	}

	public function test_the_indexnow_key_is_served_at_its_own_path(): void {
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI'] = '/' . self::INDEXNOW_KEY . '.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		// Byte-equal to the key and nothing else — no trailing newline. IndexNow
		// compares the file's contents to the key it was given, and the
		// Globetrotters edge proxy's key route returns the key verbatim.
		$this->assertSame(
			array( array( self::INDEXNOW_KEY . '.txt', self::INDEXNOW_KEY ) ),
			$router->emitted
		);
	}

	public function test_the_key_path_is_not_served_when_no_key_is_stored(): void {
		// A keyless environment (dev, and staging permanently). Falling through
		// is what makes it the site's normal 404 rather than a 200 with a body
		// that would fail verification for whoever fetched it.
		$_SERVER['REQUEST_URI'] = '/' . self::INDEXNOW_KEY . '.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	public function test_a_key_file_for_some_other_key_is_not_served(): void {
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI'] = '/4498b441c07d4e2fa9b31c8e6d02f5a7.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * @dataProvider served_path_provider
	 */
	public function test_no_served_artefact_can_be_shadowed_by_a_stored_key( string $path ): void {
		$this->store_key( self::INDEXNOW_KEY );
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( $path => 'artefact body' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/' . $path;

		$router = $this->spy_router();
		$router->maybe_serve();

		// Driven off ContentTypes::paths(), not a hand-listed sample, because the
		// guarantee is the matching *order* in maybe_serve() rather than a
		// coincidence of the key grammar — `llms-full.txt` would parse as a
		// well-formed key file, and it is one decision away from being served
		// here. A path added to the map is covered by this test on its own.
		$this->assertSame( array( array( $path, 'artefact body' ) ), $router->emitted );
	}

	public function test_the_key_response_is_uncacheable_and_typed_as_text(): void {
		$headers = Router::key_response_headers();

		// The key is mutable state: a rotation reaches an install only on its
		// next sync, and a cached stale copy fails verification for as long as
		// it lives — on top of the measurement reason the artefacts have.
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertSame( 'no-store', $headers['Surrogate-Control'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'text/plain; charset=utf-8', $headers['Content-Type'] );
	}

	public function test_the_plugin_grants_no_cors_on_a_served_key(): void {
		// Deliberate: CORS is granted to the artefacts because a browser-context
		// agent client cannot read a discovery document without it. The key file
		// is fetched server-side by a search engine and needs no such grant, and
		// this is the apex of a site we do not own.
		//
		// A claim about what the plugin *sends*, not about what survives the rest
		// of the stack: a security plugin or a server config adding CORS to every
		// response is not undone here. That costs nothing — the key is public by
		// construction, its whole job being to be readable off the host it
		// verifies — and the end-to-end absence on a real install is asserted by
		// bin/smoke-routes.sh instead.
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', Router::key_response_headers() );
	}

	public function test_the_key_is_served_with_its_own_headers(): void {
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI'] = '/' . self::INDEXNOW_KEY . '.txt';

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( Router::key_response_headers(), $router->headers );
	}

	public function test_serving_the_key_is_not_counted_as_agent_traffic(): void {
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI'] = '/' . self::INDEXNOW_KEY . '.txt';
		$events                 = $this->capturing_store();
		$this->store_key( self::INDEXNOW_KEY );

		$router = $this->spy_router( $events );
		$router->maybe_serve();

		// Presence Analytics counts agent fetches of the artefact set. A search
		// engine reading the key to verify host control is neither an agent nor
		// an artefact fetch, and folding it in would inflate the reported counts.
		$this->assertNotSame( array(), $router->emitted, 'the key must actually be served here' );
		$this->assertSame( array(), $events->rows );
	}

	/**
	 * @dataProvider write_method_provider
	 */
	public function test_the_key_answers_read_methods_only( string $method ): void {
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI']    = '/' . self::INDEXNOW_KEY . '.txt';
		$_SERVER['REQUEST_METHOD'] = $method;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * @dataProvider non_canonical_key_uri_provider
	 */
	public function test_the_key_is_served_at_one_url_only( string $uri ): void {
		$this->store_key( self::INDEXNOW_KEY );
		$_SERVER['REQUEST_URI'] = $uri;

		$router = $this->spy_router();
		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted, "{$uri} must not serve the key." );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function non_canonical_key_uri_provider(): array {
		$key = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';
		return array(
			'faked authority' => array( '//anything/' . $key . '.txt' ),
			'leading //'      => array( '//' . $key . '.txt' ),
			'trailing slash'  => array( '/' . $key . '.txt/' ),
			// A key file only verifies the directory it sits in, so a copy under
			// a subdirectory would be worse than useless: it scopes submissions
			// to that directory and covers none of the apex artefacts.
			'in a directory'  => array( '/.well-known/' . $key . '.txt' ),
		);
	}

	/**
	 * Put a key in the plugin's runtime state, as a successful sync would.
	 *
	 * @param string $key Key to store.
	 */
	private function store_key( string $key ): void {
		$this->store[ Options::STATE ] = array_merge(
			is_array( $this->store[ Options::STATE ] ?? null ) ? $this->store[ Options::STATE ] : array(),
			array( 'indexnow_key' => $key )
		);
	}

	/**
	 * A store wired behind a fully configured recorder.
	 */
	private function capturing_store(): FakeEventStore {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok_live',
		);
		$this->store[ Schema::VERSION_OPTION ] = Schema::VERSION;
		Functions\when( 'wp_generate_uuid4' )->justReturn( '9f2c8a1e-5f3b-4a6d-9c2e-7b1d0f4a8c33' );

		return new FakeEventStore();
	}

	public function test_maybe_serve_renders_the_sitemap_from_the_cache(): void {
		$this->stub_site();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array(
				'llms.txt' => '# hello',
				'ai.json'  => '{}',
			),
			'version' => 'v1',
		);
		$this->store[ Options::STATE ] = array( 'content_changed_at' => 1788912000 );
		$_SERVER['REQUEST_URI']        = '/' . Sitemap::PATH;
		$router                        = $this->spy_router();

		$router->maybe_serve();

		$this->assertCount( 1, $router->emitted );
		$this->assertSame( Sitemap::PATH, $router->emitted[0][0] );
		$this->assertStringContainsString( '<loc>https://acme.example/llms.txt</loc>', $router->emitted[0][1] );
		$this->assertStringContainsString( '<loc>https://acme.example/ai.json</loc>', $router->emitted[0][1] );
		$this->assertSame( Sitemap::CONTENT_TYPE, $router->headers['Content-Type'] );
		$this->assertSame( 'no-store', $router->headers['Cache-Control'] );
		$this->assertSame( 'no-store', $router->headers['Surrogate-Control'] );
		// Server-side crawl fetch, not a browser-context agent read — the CORS
		// grant stays scoped to the artefacts, as it is for the key file.
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $router->headers );
	}

	/**
	 * Nothing cached means nothing to list, so the path falls through to
	 * WordPress's own 404 rather than answering with an empty urlset.
	 */
	public function test_maybe_serve_does_not_answer_the_sitemap_with_an_empty_cache(): void {
		$this->stub_site();
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;
		$router                 = $this->spy_router();

		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * A bundle holding nothing the listing admits must not answer with a urlset
	 * naming only the homepage — that says less than the site's own sitemap
	 * already does, and the plugin refuses to publish it.
	 */
	public function test_maybe_serve_does_not_answer_a_marker_only_bundle(): void {
		$this->stub_site();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( ContentTypes::VERSION_MARKER => '{"version":"v1"}' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;
		$router                 = $this->spy_router();

		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * Serving the sitemap is a search engine scheduling a crawl, not an agent
	 * fetching the presence, so it must not land in Presence Analytics.
	 */
	public function test_serving_the_sitemap_is_not_recorded_as_agent_traffic(): void {
		$this->stub_site();
		$events = $this->capturing_store();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/' . Sitemap::PATH;
		$router                 = $this->spy_router( $events );

		$router->maybe_serve();

		$this->assertNotSame( array(), $router->emitted, 'the sitemap must actually be served here' );
		$this->assertSame( array(), $events->rows );
	}

	/**
	 * ``ai-sitemap.xml`` is itself a well-formed key filename, so the sitemap
	 * must be matched first — otherwise an install whose key happened to be
	 * ``ai-sitemap`` would shadow it.
	 */
	public function test_the_sitemap_is_matched_before_the_indexnow_key(): void {
		$this->stub_site();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$this->store[ Options::STATE ] = array( 'indexnow_key' => 'ai-sitemap' );
		$_SERVER['REQUEST_URI']        = '/' . Sitemap::PATH;
		$router                        = $this->spy_router();

		$router->maybe_serve();

		$this->assertCount( 1, $router->emitted );
		$this->assertStringContainsString( '<urlset', $router->emitted[0][1] );
	}

	/**
	 * The generated sitemap is not a synced artefact, so it must not appear in
	 * the map that drives ``ArtefactSync::required_paths()`` — adding it there
	 * would make every sync try to fetch a file the backend does not serve at
	 * this name.
	 */
	public function test_the_sitemap_is_not_a_content_types_entry(): void {
		$this->assertFalse(
			ContentTypes::has( Sitemap::PATH ),
			'the generated sitemap must not be a ContentTypes entry — it is not synced.'
		);
	}

	/**
	 * The artefact map is matched before the sitemap route, so no served file
	 * can be shadowed by it. Asserted over every path in the map rather than a
	 * hand-listed sample, so a file added to the served set is covered on its
	 * own.
	 */
	public function test_no_served_artefact_is_shadowed_by_the_sitemap_route(): void {
		$this->stub_site();
		$files = array();
		foreach ( ContentTypes::paths() as $path ) {
			$files[ $path ] = 'body-for-' . $path;
		}
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => $files,
			'version' => 'v1',
		);

		foreach ( ContentTypes::paths() as $path ) {
			$_SERVER['REQUEST_URI'] = '/' . $path;

			$router = $this->spy_router();
			$router->maybe_serve();

			$this->assertSame(
				array( array( $path, 'body-for-' . $path ) ),
				$router->emitted,
				"{$path} must reach the artefact handler, not the sitemap route."
			);
		}
	}

	/**
	 * ``/.well-known/api-catalog`` (RFC 9727) is rendered from the cache on this
	 * site's origin and sent with the artefacts' headers. Mirrors
	 * the Symfony bundle's ApiCatalogServingTest.
	 */
	public function test_maybe_serve_renders_the_api_catalog_from_the_cache(): void {
		$this->stub_site();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array(
				'llms.txt'             => '# hello',
				'.well-known/mcp.json' => '{}',
			),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/' . ApiCatalog::PATH;
		$router                 = $this->spy_router();

		$router->maybe_serve();

		$this->assertCount( 1, $router->emitted );
		$this->assertSame( ApiCatalog::PATH, $router->emitted[0][0] );
		$linkset = json_decode( $router->emitted[0][1], true );
		$this->assertSame( 'https://acme.example/', $linkset['linkset'][0]['anchor'] );
		$this->assertSame( 'https://acme.example/llms.txt', $linkset['linkset'][0]['service-doc'][0]['href'] );
		$this->assertSame( 'https://acme.example/.well-known/mcp.json', $linkset['linkset'][0]['service-desc'][0]['href'] );
		$this->assertSame(
			array(
				'Content-Type'                => 'application/linkset+json',
				'X-Content-Type-Options'      => 'nosniff',
				'Cache-Control'               => 'no-store',
				'Surrogate-Control'           => 'no-store',
				// A discovery document browser-context agents read, granted like
				// the artefacts it lists.
				'Access-Control-Allow-Origin' => '*',
			),
			$router->headers
		);
	}

	/**
	 * Unlike the sitemap and the key, an agent reading the catalog is asking for
	 * the presence by name, so it is counted, under its own path.
	 */
	public function test_serving_the_api_catalog_is_recorded_as_agent_traffic(): void {
		$this->stub_site();
		$events = $this->capturing_store();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/' . ApiCatalog::PATH . '?v=2';
		$router                 = $this->spy_router( $events );

		$router->maybe_serve();

		$this->assertCount( 1, $events->rows );
		$captured = $events->oldest( 1 )[0]['event'];
		$this->assertSame( '/.well-known/api-catalog', $captured->path() );
		$this->assertSame( 200, $captured->status() );
		$this->assertSame( strlen( $router->emitted[0][1] ), $captured->bytes() );
	}

	/**
	 * With none of its targets cached the path is left to WordPress, whose own
	 * 404 answers it — never a 200 with an empty linkset.
	 *
	 * @dataProvider api_catalog_without_a_target_provider
	 *
	 * @param array<string, string> $files Cached files.
	 */
	public function test_the_api_catalog_is_not_answered_without_a_target( array $files ): void {
		$this->stub_site();
		$events = $this->capturing_store();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => $files,
			'version' => array() === $files ? '' : 'v1',
		);
		$_SERVER['REQUEST_URI'] = '/' . ApiCatalog::PATH;
		$router                 = $this->spy_router( $events );

		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
		$this->assertSame( array(), $events->rows );
	}

	/**
	 * @return array<string, array{0:array<string, string>}>
	 */
	public static function api_catalog_without_a_target_provider(): array {
		return array(
			'cold cache'           => array( array() ),
			'marker only'          => array( array( ContentTypes::VERSION_MARKER => '{"version":"v1"}' ) ),
			'no target among them' => array(
				array(
					'schema.json' => '{}',
					'ai.json'     => '{}',
				),
			),
		);
	}

	/**
	 * @dataProvider write_method_provider
	 */
	public function test_the_api_catalog_answers_read_methods_only( string $method ): void {
		$this->stub_site();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI']    = '/' . ApiCatalog::PATH;
		$_SERVER['REQUEST_METHOD'] = $method;
		$router                    = $this->spy_router();

		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	public function test_the_api_catalog_answers_head_like_get(): void {
		$this->stub_site();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI']    = '/' . ApiCatalog::PATH;
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$router                    = $this->spy_router();

		$router->maybe_serve();

		$this->assertCount( 1, $router->emitted );
		$this->assertSame( ApiCatalog::CONTENT_TYPE, $router->headers['Content-Type'] );
	}

	/**
	 * One URL, like every artefact: a near-miss spelling falls through.
	 *
	 * @dataProvider non_canonical_api_catalog_uri_provider
	 */
	public function test_the_api_catalog_is_served_at_one_url_only( string $uri ): void {
		$this->stub_site();
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# hello' ),
			'version' => 'v1',
		);
		$_SERVER['REQUEST_URI'] = $uri;
		$router                 = $this->spy_router();

		$router->maybe_serve();

		$this->assertSame( array(), $router->emitted );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function non_canonical_api_catalog_uri_provider(): array {
		return array(
			'trailing slash'  => array( '/.well-known/api-catalog/' ),
			'faked authority' => array( '//anything/.well-known/api-catalog' ),
			'with extension'  => array( '/.well-known/api-catalog.json' ),
		);
	}

	/**
	 * Stub the WordPress site-URL helpers the sitemap origin is derived from.
	 */
	private function stub_site(): void {
		Functions\when( 'home_url' )->justReturn( 'https://acme.example/' );
		Functions\when( 'wp_json_encode' )->alias( fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
		Functions\when( 'esc_xml' )->alias( fn( $text ) => htmlspecialchars( (string) $text, ENT_XML1, 'UTF-8' ) );
	}

	/**
	 * A Router that records emit() calls instead of sending + exiting.
	 *
	 * @param FakeEventStore|null $events Optional capture backing store.
	 */
	private function spy_router( ?FakeEventStore $events = null ): Router {
		$recorder = null;
		if ( null !== $events ) {
			$options  = new Options();
			$recorder = new EventRecorder( new EventBuffer( $events ), $options, new FakeFlushRequest() );
		}

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
