<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\AiUserAgents;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * The synced AI crawler registry: validation, storage and freshness.
 *
 * @covers \Globetrotters\AiPresence\Analytics\AiUserAgents
 */
final class AiUserAgentsTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
	}

	public function test_parse_keeps_names_and_fallback_groups_in_order(): void {
		$this->assertSame(
			array(
				array( 'name' => 'GPTBot' ),
				array(
					'name'          => 'Applebot',
					'fallbackGroup' => 'Googlebot',
				),
				array( 'name' => 'NewAI-Bot' ),
			),
			AiUserAgents::parse(
				array(
					array( 'name' => 'GPTBot' ),
					array(
						'name'          => 'Applebot',
						'fallbackGroup' => 'Googlebot',
					),
					array( 'name' => 'NewAI-Bot' ),
				)
			)
		);
	}

	/**
	 * An invalid entry is skipped, never repaired.
	 */
	public function test_parse_skips_invalid_entries(): void {
		$this->assertSame(
			array( array( 'name' => 'GPTBot' ) ),
			AiUserAgents::parse(
				array(
					array( 'name' => 'GPTBot' ),
					array( 'name' => "Evil\nDisallow: /" ),
					array( 'name' => 'Bot/1.0' ),
					array( 'name' => 'Two Words' ),
					array( 'name' => '' ),
					array( 'name' => 42 ),
					array(),
					'GPTBot',
					array(
						'name'          => 'Applebot',
						'fallbackGroup' => 'Google bot',
					),
					array(
						'name'          => 'Applebot',
						'fallbackGroup' => null,
					),
				)
			)
		);
	}

	/**
	 * @dataProvider unusable_lists
	 *
	 * @param mixed $value Decoded ``aiUserAgents``.
	 */
	public function test_parse_reads_an_unusable_list_as_empty( $value ): void {
		$this->assertSame( array(), AiUserAgents::parse( $value ) );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function unusable_lists(): array {
		$too_many = array();
		for ( $i = 0; $i <= AiUserAgents::MAX_AGENTS; $i++ ) {
			$too_many[] = array( 'name' => 'Bot' );
		}
		return array(
			'absent'     => array( null ),
			'a string'   => array( 'GPTBot' ),
			'an object'  => array( array( 'name' => 'GPTBot' ) ),
			'over 64'    => array( $too_many ),
			'no entries' => array( array() ),
		);
	}

	public function test_exactly_64_entries_are_kept(): void {
		$list = array_fill( 0, AiUserAgents::MAX_AGENTS, array( 'name' => 'Bot' ) );

		$this->assertCount( AiUserAgents::MAX_AGENTS, AiUserAgents::parse( $list ) );
	}

	public function test_agents_is_null_when_nothing_is_stored(): void {
		$this->assertNull( ( new AiUserAgents() )->agents() );
		$this->assertNull( ( new AiUserAgents() )->version() );
	}

	public function test_a_replaced_list_is_served_and_versioned(): void {
		$agents = new AiUserAgents();
		$agents->replace( 'v1', array( array( 'name' => 'GPTBot' ) ), time() );

		$this->assertSame( array( array( 'name' => 'GPTBot' ) ), $agents->agents() );
		$this->assertSame( 'v1', $agents->version() );
	}

	public function test_a_list_older_than_14_days_is_not_served(): void {
		$agents = new AiUserAgents();
		$agents->replace( 'v1', array( array( 'name' => 'GPTBot' ) ), time() - AiUserAgents::STALE_AFTER_SECONDS - 1 );

		$this->assertNull( $agents->agents() );
		$this->assertSame( 'v1', $agents->version() );
	}

	public function test_restamp_makes_a_stale_list_current_again(): void {
		$agents = new AiUserAgents();
		$agents->replace( 'v1', array( array( 'name' => 'GPTBot' ) ), time() - 20 * DAY_IN_SECONDS );
		$agents->restamp( time() );

		$this->assertSame( array( array( 'name' => 'GPTBot' ) ), $agents->agents() );
	}

	public function test_restamp_without_a_list_stores_nothing(): void {
		( new AiUserAgents() )->restamp( time() );

		$this->assertArrayNotHasKey( AiUserAgents::OPTION, $this->store );
	}

	/**
	 * A backend that predates the registry stores an empty list at the ranges'
	 * version, so the ETag rule holds; robots.txt then uses the constant.
	 */
	public function test_an_empty_list_is_versioned_but_not_served(): void {
		$agents = new AiUserAgents();
		$agents->replace( 'v1', array(), time() );

		$this->assertSame( 'v1', $agents->version() );
		$this->assertNull( $agents->agents() );
	}

	/**
	 * ``wp option update`` bypasses the sync, so what is read back is validated
	 * again.
	 */
	public function test_a_tampered_option_is_validated_on_read(): void {
		$this->store[ AiUserAgents::OPTION ] = array(
			'version'    => 'v1',
			'fetched_at' => time(),
			'agents'     => array(
				array( 'name' => "GPTBot\nAllow: /" ),
				array( 'name' => 'ClaudeBot' ),
			),
		);

		$this->assertSame( array( array( 'name' => 'ClaudeBot' ) ), ( new AiUserAgents() )->agents() );
	}

	/**
	 * @dataProvider mistyped_records
	 *
	 * @param array<string, mixed> $record A stored record of the wrong shape.
	 */
	public function test_a_mistyped_record_reads_as_none( array $record ): void {
		$this->store[ AiUserAgents::OPTION ] = $record + array( 'agents' => array( array( 'name' => 'GPTBot' ) ) );

		$this->assertNull( ( new AiUserAgents() )->agents() );
		$this->assertNull( ( new AiUserAgents() )->version() );
	}

	/**
	 * @return array<string, array{0:array<string, mixed>}>
	 */
	public static function mistyped_records(): array {
		return array(
			'array version'    => array(
				array(
					'version'    => array( 'v1' ),
					'fetched_at' => time(),
				),
			),
			'array fetched_at' => array(
				array(
					'version'    => 'v1',
					'fetched_at' => array( 1 ),
				),
			),
			'text fetched_at'  => array(
				array(
					'version'    => 'v1',
					'fetched_at' => 'yesterday',
				),
			),
		);
	}

	public function test_the_option_is_not_autoloaded(): void {
		$autoload = null;
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( $name, $value, $auto = null ) use ( &$autoload ) {
				$this->store[ $name ] = $value;
				$autoload             = $auto;
				return true;
			}
		);

		( new AiUserAgents() )->replace( 'v1', array( array( 'name' => 'GPTBot' ) ), time() );

		$this->assertFalse( $autoload );
	}
}
