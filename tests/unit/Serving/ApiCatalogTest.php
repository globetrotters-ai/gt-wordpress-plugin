<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\ApiCatalog;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * ``/.well-known/api-catalog`` (RFC 9727), rendered from the cache. Mirrors
 * the Symfony bundle's ApiCatalogTest.
 *
 * @covers \Globetrotters\AiPresence\Serving\ApiCatalog
 */
final class ApiCatalogTest extends TestCase {

	use WpOptions;

	/**
	 * An MCP manifest shaped like the backend's.
	 */
	private const MCP_MANIFEST = '{"name":"riviera","remotes":[{"type":"streamable-http","url":"https://riviera.globetrotters.ai/mcp"}]}';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_json_encode' )->alias( fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
	}

	public function test_renders_an_rfc_9727_linkset_anchored_on_the_site_origin(): void {
		$this->cache_files( $this->full_set() );

		$this->assertSame(
			array(
				'linkset' => array(
					array(
						'anchor'       => 'https://www.riviera-guide.example/',
						'service-doc'  => array(
							array(
								'href' => 'https://www.riviera-guide.example/llms.txt',
								'type' => 'text/plain',
							),
						),
						'service-desc' => array(
							array(
								'href' => 'https://www.riviera-guide.example/.well-known/mcp.json',
								'type' => 'application/json',
							),
							array(
								'href' => 'https://www.riviera-guide.example/.well-known/agent-card.json',
								'type' => 'application/json',
							),
							array(
								'href' => 'https://www.riviera-guide.example/.well-known/ai-catalog.json',
								'type' => 'application/json',
							),
						),
					),
				),
			),
			$this->decode( ApiCatalog::render( 'https://www.riviera-guide.example', new ArtefactCache() ) )
		);
	}

	/**
	 * RFC 9727 ``item``: one link per API the host exposes, today the MCP server
	 * named by the cached manifest.
	 */
	public function test_lists_the_mcp_server_as_an_item(): void {
		$files                         = $this->full_set();
		$files['.well-known/mcp.json'] = self::MCP_MANIFEST;
		$this->cache_files( $files );

		$entry = $this->decode( ApiCatalog::render( 'https://www.riviera-guide.example', new ArtefactCache() ) )['linkset'][0];

		$this->assertSame(
			array(
				array(
					'href' => 'https://riviera.globetrotters.ai/mcp',
					'type' => 'application/json',
				),
			),
			$entry['item']
		);
		$this->assertSame( array( 'anchor', 'service-doc', 'service-desc', 'item' ), array_keys( $entry ) );
	}

	/**
	 * @dataProvider no_mcp_server_provider
	 *
	 * @param array<string, string> $files Cached files.
	 */
	public function test_item_is_omitted_without_an_mcp_server( array $files ): void {
		$this->cache_files( $files );

		$entry = $this->decode( ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) )['linkset'][0];

		$this->assertArrayNotHasKey( 'item', $entry );
	}

	/**
	 * @return array<string, array{0:array<string, string>}>
	 */
	public static function no_mcp_server_provider(): array {
		return array(
			'mcp.json not cached' => array( array( 'llms.txt' => 'llms' ) ),
			'no remotes'          => array( array( '.well-known/mcp.json' => '{"name":"riviera"}' ) ),
			'empty remotes'       => array( array( '.well-known/mcp.json' => '{"remotes":[]}' ) ),
			'remote without url'  => array( array( '.well-known/mcp.json' => '{"remotes":[{"type":"streamable-http"}]}' ) ),
			'empty url'           => array( array( '.well-known/mcp.json' => '{"remotes":[{"url":""}]}' ) ),
			'remotes a string'    => array( array( '.well-known/mcp.json' => '{"remotes":"https://x.example/mcp"}' ) ),
			'remote a scalar'     => array( array( '.well-known/mcp.json' => '{"remotes":[5]}' ) ),
			'url not a string'    => array( array( '.well-known/mcp.json' => '{"remotes":[{"url":["https://x.example/mcp"]}]}' ) ),
			'a json list'         => array( array( '.well-known/mcp.json' => '[{"url":"https://x.example/mcp"}]' ) ),
			'not json'            => array( array( '.well-known/mcp.json' => '<html>' ) ),
		);
	}

	public function test_an_mcp_server_alone_does_not_make_it_servable(): void {
		$this->cache_files( array( 'schema.json' => '{}' ) );

		$this->assertSame( '', ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) );
	}

	public function test_is_pretty_printed_with_readable_slashes(): void {
		$this->cache_files( $this->full_set() );

		$body = ApiCatalog::render( 'https://www.riviera-guide.example', new ArtefactCache() );

		$this->assertStringNotContainsString( 'globetrotters.ai', $body );
		// Slashes stay readable: the document is read by agents and people alike.
		$this->assertStringNotContainsString( '\/', $body );
		$this->assertStringStartsWith( "{\n    \"linkset\": [", $body );
		$this->assertStringEndsWith( "}\n", $body );
	}

	public function test_a_trailing_slash_on_the_origin_is_not_doubled(): void {
		$this->cache_files( $this->full_set() );

		$linkset = $this->decode( ApiCatalog::render( 'https://apex.example/', new ArtefactCache() ) );

		$this->assertSame( 'https://apex.example/', $linkset['linkset'][0]['anchor'] );
	}

	public function test_lists_only_the_targets_the_cache_holds(): void {
		$files = $this->full_set();
		unset( $files['.well-known/agent-card.json'], $files['.well-known/ai-catalog.json'] );
		$this->cache_files( $files );

		$linkset = $this->decode( ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) );

		$this->assertSame(
			array(
				array(
					'href' => 'https://apex.example/.well-known/mcp.json',
					'type' => 'application/json',
				),
			),
			$linkset['linkset'][0]['service-desc']
		);
	}

	public function test_a_relation_with_nothing_served_is_omitted(): void {
		$this->cache_files( array( 'llms.txt' => 'llms' ) );

		$linkset = $this->decode( ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) );

		$this->assertArrayHasKey( 'service-doc', $linkset['linkset'][0] );
		$this->assertArrayNotHasKey( 'service-desc', $linkset['linkset'][0] );
	}

	public function test_nothing_is_rendered_without_a_target(): void {
		$this->assertSame( '', ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) );

		// Artefacts the catalog does not name do not make it servable.
		$this->cache_files( array( 'schema.json' => '{}' ) );
		$this->assertSame( '', ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) );
	}

	public function test_any_one_target_makes_it_servable(): void {
		$this->cache_files( array( 'llms.txt' => 'llms' ) );
		$this->assertNotSame( '', ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) );

		$this->cache_files( array( '.well-known/mcp.json' => '{}' ) );
		$this->assertNotSame( '', ApiCatalog::render( 'https://apex.example', new ArtefactCache() ) );
	}

	/**
	 * The rule {@see \Globetrotters\AiPresence\Serving\LinkHeader} and
	 * {@see \Globetrotters\AiPresence\Serving\Sitemap} apply to their own cache
	 * reads must agree with whether render() produces a document.
	 */
	public function test_is_served_with_agrees_with_render(): void {
		$cases = array(
			array(),
			array( 'schema.json' ),
			array( ContentTypes::VERSION_MARKER ),
			array( 'llms.txt' ),
			array( 'schema.json', ContentTypes::AI_CATALOG ),
			array_keys( $this->full_set() ),
		);
		foreach ( $cases as $paths ) {
			$this->cache_files( array_fill_keys( $paths, '{}' ) );

			$this->assertSame(
				'' !== ApiCatalog::render( 'https://apex.example', new ArtefactCache() ),
				ApiCatalog::is_served_with( $paths ),
				implode( ', ', $paths )
			);
		}
	}

	public function test_every_target_is_an_artefact_this_plugin_serves(): void {
		foreach ( ApiCatalog::RELS as $path => list( $rel ) ) {
			$this->assertTrue( ContentTypes::has( $path ), $path );
			$this->assertContains( $rel, array( 'service-doc', 'service-desc' ), $path );
		}
	}

	/**
	 * Generated, not synced: a ContentTypes entry would make every sync try to
	 * fetch it.
	 */
	public function test_the_catalog_is_not_a_content_types_entry(): void {
		$this->assertFalse( ContentTypes::has( ApiCatalog::PATH ) );
	}

	/**
	 * Every served artefact, cached.
	 *
	 * @return array<string, string>
	 */
	private function full_set(): array {
		return array_fill_keys( ContentTypes::paths(), '{}' );
	}

	/**
	 * Put a bundle in the artefact cache option.
	 *
	 * @param array<string, string> $files Path → body.
	 */
	private function cache_files( array $files ): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => $files,
			'version' => 'v1',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode( string $body ): array {
		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}
}
