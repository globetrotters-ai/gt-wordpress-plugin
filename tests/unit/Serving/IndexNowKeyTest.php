<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Globetrotters\AiPresence\Serving\IndexNowKey;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Serving\IndexNowKey
 */
final class IndexNowKeyTest extends TestCase {

	/**
	 * @dataProvider usable_key_provider
	 */
	public function test_a_well_formed_key_survives_sanitizing( string $key ): void {
		$this->assertSame( $key, IndexNowKey::sanitize( $key ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function usable_key_provider(): array {
		return array(
			'ours (32 hex)' => array( 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14' ),
			'shortest'      => array( 'abcdefgh' ),
			'longest'       => array( str_repeat( 'a', 128 ) ),
			'mixed case'    => array( 'Abc-DEF-123' ),
		);
	}

	/**
	 * A key outside IndexNow's own grammar cannot verify a host, so it reads as
	 * unconfigured rather than as something to serve — the same posture the
	 * backend takes in its IndexNow key grammar.
	 *
	 * @dataProvider unusable_key_provider
	 * @param mixed $raw Value stored or received.
	 */
	public function test_an_unusable_key_sanitizes_to_empty( $raw ): void {
		$this->assertSame( '', IndexNowKey::sanitize( $raw ) );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function unusable_key_provider(): array {
		return array(
			'empty'           => array( '' ),
			'too short'       => array( 'abcdefg' ),
			'too long'        => array( str_repeat( 'a', 129 ) ),
			'underscore'      => array( 'abcdefg_h' ),
			'dot'             => array( 'abcdefg.h' ),
			// A slash would let a stored key match an interior path such as
			// '.well-known/x.txt', and is not part of IndexNow's grammar.
			'slash'           => array( 'abcd/efgh' ),
			// PHP's '$' also matches before a trailing newline, so an anchored
			// pattern without \z would accept a tfvars value with a stray one.
			'trailing newline' => array( "abcdefgh\n" ),
			'leading space'   => array( ' abcdefgh' ),
			'not a string'    => array( array( 'abcdefgh' ) ),
			'null'            => array( null ),
		);
	}

	/**
	 * @dataProvider candidate_provider
	 */
	public function test_candidate_from_path( string $path, string $expected ): void {
		$this->assertSame( $expected, IndexNowKey::candidate_from_path( $path ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function candidate_provider(): array {
		return array(
			'key file'          => array( 'abcdefgh.txt', 'abcdefgh' ),
			// Every served artefact: none can ever be read as a key candidate,
			// which is what keeps /llms.txt resolving to the artefact.
			'llms.txt'          => array( 'llms.txt', '' ),
			'ai.json'           => array( 'ai.json', '' ),
			'robots.txt'        => array( 'robots.txt', '' ),
			'well-known key'    => array( '.well-known/abcdefgh.txt', '' ),
			'wrong extension'   => array( 'abcdefgh.text', '' ),
			'bare .txt'         => array( '.txt', '' ),
			'empty'             => array( '', '' ),
		);
	}
}
