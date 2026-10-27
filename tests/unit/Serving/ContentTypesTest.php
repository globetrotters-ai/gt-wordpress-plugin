<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Serving\ContentTypes
 */
final class ContentTypesTest extends TestCase {

	/**
	 * The whole map, in order: the backend's apex discovery set plus the version
	 * marker, with the backend's content types. Matches the Symfony bundle's
	 * ContentTypesTest.
	 */
	public function test_the_served_set_matches_the_backend_exactly(): void {
		$this->assertSame(
			array(
				'llms.txt'                                    => 'text/plain; charset=utf-8',
				'ai.json'                                     => 'application/json; charset=utf-8',
				'schema.json'                                 => 'application/ld+json; charset=utf-8',
				'.well-known/mcp.json'                        => 'application/json; charset=utf-8',
				'.well-known/agent-card.json'                 => 'application/json; charset=utf-8',
				'.well-known/ai-catalog.json'                 => 'application/json; charset=utf-8',
				'.well-known/agent-skills/index.json'         => 'application/json; charset=utf-8',
				'agent.json'                                  => 'application/json; charset=utf-8',
				'AGENTS.md'                                   => 'text/markdown; charset=utf-8',
				'.well-known/globetrotters-apex-version.json' => 'application/json; charset=utf-8',
			),
			array_combine(
				ContentTypes::paths(),
				array_map( array( ContentTypes::class, 'for_path' ), ContentTypes::paths() )
			)
		);
	}

	public function test_serves_the_minimal_apex_set_only(): void {
		$paths = ContentTypes::paths();

		$this->assertContains( 'llms.txt', $paths );
		$this->assertContains( 'ai.json', $paths );
		$this->assertContains( 'schema.json', $paths );
		$this->assertContains( '.well-known/mcp.json', $paths );
		$this->assertContains( '.well-known/agent-card.json', $paths );
		$this->assertContains( '.well-known/globetrotters-apex-version.json', $paths );

		// Heavy files are linked to GT, never served locally.
		$this->assertNotContains( 'llms-full.txt', $paths );
		$this->assertNotContains( 'content.md', $paths );
		$this->assertNotContains( 'index.html', $paths );
	}

	public function test_content_types_match_the_backend_contract(): void {
		$this->assertSame( 'text/plain; charset=utf-8', ContentTypes::for_path( 'llms.txt' ) );
		$this->assertSame( 'application/json; charset=utf-8', ContentTypes::for_path( 'ai.json' ) );
		$this->assertSame( 'application/ld+json; charset=utf-8', ContentTypes::for_path( 'schema.json' ) );
		$this->assertSame( 'application/json; charset=utf-8', ContentTypes::for_path( '.well-known/mcp.json' ) );
	}

	public function test_unknown_paths_are_not_served(): void {
		$this->assertFalse( ContentTypes::has( 'wp-config.php' ) );
		$this->assertNull( ContentTypes::for_path( 'wp-config.php' ) );
		$this->assertTrue( ContentTypes::has( 'llms.txt' ) );
	}

	public function test_version_marker_constant_is_part_of_the_set(): void {
		$this->assertTrue( ContentTypes::has( ContentTypes::VERSION_MARKER ) );
	}

	/**
	 * The spellings readiness scanners probe resolve to the canonical file, and
	 * stay out of the served set: no sync fetches them, and the sitemap, the API
	 * catalog and the ``Link`` header list canonical paths only. Matches
	 * the Symfony bundle and the backend's server-log asset formats.
	 */
	public function test_aliases_resolve_to_their_canonical_file(): void {
		$this->assertSame( 'AGENTS.md', ContentTypes::resolve( 'agents.md' ) );
		$this->assertSame( '.well-known/agent-skills/index.json', ContentTypes::resolve( '.well-known/agent-skills' ) );
		$this->assertSame( '.well-known/agent-skills/index.json', ContentTypes::resolve( '.well-known/agent-skills/' ) );

		foreach ( array( 'agents.md', '.well-known/agent-skills', '.well-known/agent-skills/' ) as $alias ) {
			$this->assertFalse( ContentTypes::has( $alias ), $alias );
			$this->assertNotContains( $alias, ContentTypes::paths(), $alias );
		}
	}

	public function test_a_served_path_resolves_to_itself(): void {
		foreach ( ContentTypes::paths() as $path ) {
			$this->assertSame( $path, ContentTypes::resolve( $path ) );
		}
		$this->assertNull( ContentTypes::resolve( 'wp-config.php' ) );
		$this->assertNull( ContentTypes::resolve( 'Agents.md' ) );
	}
}
