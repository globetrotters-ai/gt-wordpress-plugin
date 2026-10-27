<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Sync\PresenceOrigin;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Sync\PresenceOrigin
 */
final class PresenceOriginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias(
			fn( $url, $component = -1 ) => parse_url( $url, $component )
		);
	}

	public function test_reads_the_published_origin(): void {
		$this->assertSame(
			'https://ai.visit-acme.example',
			PresenceOrigin::resolve( $this->ai_json( 'https://ai.visit-acme.example' ) )
		);
	}

	public function test_reads_the_slug_host_before_a_custom_hostname_activates(): void {
		$this->assertSame(
			'https://acme.globetrotters.ai',
			PresenceOrigin::resolve( $this->ai_json( 'https://acme.globetrotters.ai' ) )
		);
	}

	public function test_offloaded_links_never_decide_the_origin(): void {
		// The apex-profile bundle points the heavy files at the Globetrotters
		// slug host while everything else sits on the apex. Only the non-offloaded
		// links may speak for the canonical origin.
		$body = $this->encode(
			array(
				'name'     => 'Acme',
				'metadata' => array(
					'relatedFiles' => array(
						array( 'url' => 'https://ai.visit-acme.example/llms.txt' ),
						array( 'url' => 'https://acme.globetrotters.ai/llms-full.txt' ),
						array( 'url' => 'https://ai.visit-acme.example/schema.json' ),
						array( 'url' => 'https://acme.globetrotters.ai/content.md' ),
					),
				),
			)
		);

		$this->assertSame( 'https://ai.visit-acme.example', PresenceOrigin::resolve( $body ) );
	}

	public function test_an_unclassified_new_file_still_counts_as_local(): void {
		// The offload list is an offload list, not a keep-list: a bundle file
		// nobody has classified defaults to living at the apex, so it is read as
		// evidence rather than skipped.
		$body = $this->related( array( 'https://ai.visit-acme.example/brand-new.json' ) );

		$this->assertSame( 'https://ai.visit-acme.example', PresenceOrigin::resolve( $body ) );
	}

	public function test_disagreeing_links_resolve_to_nothing(): void {
		$body = $this->related(
			array(
				'https://ai.visit-acme.example/llms.txt',
				'https://elsewhere.example/schema.json',
			)
		);

		$this->assertSame( '', PresenceOrigin::resolve( $body ) );
	}

	public function test_relative_links_resolve_to_nothing(): void {
		// Rendered with no canonical origin baked in — says nothing about where
		// the bundle is published.
		$body = $this->related( array( './llms.txt', './schema.json' ) );

		$this->assertSame( '', PresenceOrigin::resolve( $body ) );
	}

	public function test_non_http_schemes_are_ignored(): void {
		$body = $this->related( array( 'ftp://ai.visit-acme.example/llms.txt' ) );

		$this->assertSame( '', PresenceOrigin::resolve( $body ) );
	}

	public function test_host_case_and_port_are_normalised(): void {
		$body = $this->related(
			array(
				'https://AI.Visit-Acme.example:8443/llms.txt',
				'https://ai.visit-acme.example:8443/schema.json',
			)
		);

		$this->assertSame( 'https://ai.visit-acme.example:8443', PresenceOrigin::resolve( $body ) );
	}

	public function test_malformed_or_unexpected_bodies_resolve_to_nothing(): void {
		$this->assertSame( '', PresenceOrigin::resolve( 'not json' ) );
		$this->assertSame( '', PresenceOrigin::resolve( '' ) );
		$this->assertSame( '', PresenceOrigin::resolve( '{"name":"Acme"}' ) );
		$this->assertSame( '', PresenceOrigin::resolve( '{"metadata":{"relatedFiles":"nope"}}' ) );
		// A scalar `metadata` reaches the chained offset read. `??` suppresses the
		// illegal-offset diagnostic on both string and int, so this degrades to
		// "none" without a warning — phpunit.xml.dist sets failOnWarning, and CI
		// runs the suite on 7.4 through 8.3, so this case proves it on each.
		$this->assertSame( '', PresenceOrigin::resolve( '{"metadata":"oops"}' ) );
		$this->assertSame( '', PresenceOrigin::resolve( '{"metadata":5}' ) );
		$this->assertSame( '', PresenceOrigin::resolve( '[1,2,3]' ) );
		$this->assertSame( '', PresenceOrigin::resolve( '{"metadata":{"relatedFiles":[{"type":"text/plain"}]}}' ) );
	}

	/**
	 * A published ai.json, shaped like the backend's renderer emits it.
	 *
	 * @param string $origin Canonical origin the links were rendered against.
	 */
	private function ai_json( string $origin ): string {
		return $this->encode(
			array(
				'name'     => 'Acme',
				'url'      => 'https://www.visit-acme.example',
				'metadata' => array(
					'relatedFiles' => array(
						array(
							'url'  => $origin . '/llms.txt',
							'type' => 'text/plain',
						),
						array(
							'url'  => $origin . '/llms-full.txt',
							'type' => 'text/plain',
						),
						array(
							'url'  => $origin . '/schema.json',
							'type' => 'application/ld+json',
						),
						array(
							'url'  => $origin . '/content.md',
							'type' => 'text/markdown',
						),
					),
				),
			)
		);
	}

	/**
	 * An ai.json carrying exactly the given relatedFiles URLs.
	 *
	 * @param string[] $urls Link URLs.
	 */
	private function related( array $urls ): string {
		return $this->encode(
			array(
				'metadata' => array(
					'relatedFiles' => array_map(
						static fn( string $url ): array => array( 'url' => $url ),
						$urls
					),
				),
			)
		);
	}

	/**
	 * JSON-encode a fixture. The class under test only ever decodes.
	 *
	 * @param array<string, mixed> $data Fixture data.
	 */
	private function encode( array $data ): string {
		return (string) json_encode( $data );
	}
}
