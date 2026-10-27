<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\HeadInjector;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Serving\HeadInjector
 */
final class HeadInjectorTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_json_encode' )->alias( fn( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth ) );
	}

	public function test_render_wraps_valid_json_in_a_script_tag(): void {
		$markup = HeadInjector::render( '{"@type":"Place","name":"Acme"}' );

		$this->assertStringStartsWith( '<script type="application/ld+json">', $markup );
		$this->assertStringContainsString( '"@type":"Place"', $markup );
		$this->assertStringContainsString( '</script>', $markup );
	}

	public function test_render_escapes_script_breakout(): void {
		$markup = HeadInjector::render( '{"x":"</script><script>alert(1)"}' );

		$this->assertSame( 1, substr_count( $markup, '</script>' ) );
		$this->assertSame( '</script><script>alert(1)', self::payload( $markup )->x );
	}

	/**
	 * Escaping only ``</`` is not enough. A value opening an HTML comment and a
	 * script tag (``<!--<script>``) moves the tokenizer into the double-escaped
	 * script state, where the real closing tag no longer ends the element and
	 * the rest of the homepage is swallowed into the script.
	 *
	 * Script data only leaves its state on a ``<``, so a payload with none in it
	 * cannot reach any escaped state at all — on every parser, on every PHP.
	 *
	 * @dataProvider hostile_values_provider
	 * @param string $value A schema.json string value.
	 */
	public function test_render_leaves_no_tag_delimiter_in_the_payload( string $value ): void {
		$markup = HeadInjector::render( (string) json_encode( array( 'name' => $value ) ) );

		$inner = self::inner( $markup );
		$this->assertStringNotContainsString( '<', $inner );
		$this->assertStringNotContainsString( '>', $inner );
		// Still the same document once a JSON-LD consumer decodes it.
		$this->assertSame( $value, self::payload( $markup )->name );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function hostile_values_provider(): array {
		return array(
			'script breakout'      => array( '</script><script>alert(1)</script>' ),
			'double-escaped state' => array( '<!--<script>' ),
			'comment close'        => array( '--><!--' ),
			'mixed case'           => array( '</SCRIPT ><SCRIPT>' ),
		);
	}

	public function test_render_returns_empty_for_invalid_json(): void {
		$this->assertSame( '', HeadInjector::render( 'not json' ) );
		$this->assertSame( '', HeadInjector::render( '' ) );
	}

	public function test_inject_prints_on_front_page(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'schema.json' => '{"@type":"Place"}' ),
			'version' => 'v1',
		);
		Functions\when( 'is_front_page' )->justReturn( true );

		$output = $this->capture( new HeadInjector( new ArtefactCache() ) );

		$this->assertStringContainsString( 'application/ld+json', $output );
	}

	public function test_inject_is_silent_off_front_page(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'schema.json' => '{"@type":"Place"}' ),
			'version' => 'v1',
		);
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertSame( '', $this->capture( new HeadInjector( new ArtefactCache() ) ) );
	}

	public function test_inject_is_silent_without_schema(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array(),
			'version' => '',
		);
		Functions\when( 'is_front_page' )->justReturn( true );

		$this->assertSame( '', $this->capture( new HeadInjector( new ArtefactCache() ) ) );
	}

	/**
	 * What sits between the script tags.
	 *
	 * @param string $markup Rendered tag.
	 */
	private static function inner( string $markup ): string {
		return substr( $markup, strlen( '<script type="application/ld+json">' ), -strlen( "</script>\n" ) );
	}

	/**
	 * The payload, decoded as a JSON-LD consumer would.
	 *
	 * @param string $markup Rendered tag.
	 */
	private static function payload( string $markup ): \stdClass {
		$decoded = json_decode( self::inner( $markup ) );
		self::assertInstanceOf( \stdClass::class, $decoded );
		return $decoded;
	}

	private function capture( HeadInjector $injector ): string {
		ob_start();
		$injector->inject();
		return (string) ob_get_clean();
	}
}
