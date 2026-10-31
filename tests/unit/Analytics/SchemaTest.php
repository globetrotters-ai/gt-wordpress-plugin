<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * Readiness is per table: the event buffer since v1, the counters since v2,
 * site events since v3, referral platforms on the counters since v4.
 *
 * @covers \Globetrotters\AiPresence\Analytics\Schema
 */
final class SchemaTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
	}

	/**
	 * @dataProvider version_provider
	 *
	 * @param mixed $stored Stored option value (absent when null).
	 */
	public function test_each_table_is_ready_from_the_version_that_created_it( $stored, bool $events, bool $views, bool $site, bool $platforms, bool $signatures, bool $current ): void {
		if ( null !== $stored ) {
			$this->store[ Schema::VERSION_OPTION ] = $stored;
		}

		$this->assertSame( $events, Schema::is_ready(), 'events' );
		$this->assertSame( $views, Schema::page_views_ready(), 'page views' );
		$this->assertSame( $site, Schema::site_events_ready(), 'site events' );
		$this->assertSame( $platforms, Schema::page_view_platforms_ready(), 'page-view platforms' );
		$this->assertSame( $signatures, Schema::signatures_ready(), 'signatures' );
		$this->assertSame( $current, Schema::is_current(), 'current' );
	}

	/**
	 * @return array<string, array{0:mixed, 1:bool, 2:bool, 3:bool, 4:bool, 5:bool, 6:bool}>
	 */
	public static function version_provider(): array {
		return array(
			'never installed'           => array( null, false, false, false, false, false, false ),
			'v1: events only'           => array( 1, true, false, false, false, false, false ),
			'v1 stored as a string'     => array( '1', true, false, false, false, false, false ),
			'v2: both tables'           => array( 2, true, true, false, false, false, false ),
			'v3: site events too'       => array( 3, true, true, true, false, false, false ),
			'v4: platforms on counters' => array( 4, true, true, true, true, false, false ),
			'v5: signatures in buffer'  => array( 5, true, true, true, true, true, true ),
			'corrupt value'             => array( 'nonsense', false, false, false, false, false, false ),
		);
	}
}
