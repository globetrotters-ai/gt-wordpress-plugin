<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Settings;

use Globetrotters\AiPresence\Settings\SettingsPage;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * The status screen's "served an older pull" warning.
 *
 * @covers \Globetrotters\AiPresence\Settings\SettingsPage::serves_an_older_pull
 */
final class SettingsPageStatusTest extends TestCase {

	private const STAMP = array(
		'generation' => 'g2',
		'version'    => 'v2',
		'stored_at'  => 1000,
		'pulled_by'  => array(
			'lane' => 'wp-cli',
			'user' => 'deploy',
		),
	);

	public function test_a_version_served_after_the_pull_that_is_not_the_pull_warns(): void {
		$this->assertTrue(
			SettingsPage::serves_an_older_pull(
				self::STAMP,
				array(
					'version' => 'v1',
					'at'      => 1500,
				)
			)
		);
	}

	public function test_the_pulled_version_served_does_not_warn(): void {
		$this->assertFalse(
			SettingsPage::serves_an_older_pull(
				self::STAMP,
				array(
					'version' => 'v2',
					'at'      => 1500,
				)
			)
		);
	}

	public function test_an_older_version_served_before_the_pull_does_not_warn(): void {
		// No web request has read the bundle since the pull landed.
		$this->assertFalse(
			SettingsPage::serves_an_older_pull(
				self::STAMP,
				array(
					'version' => 'v1',
					'at'      => 900,
				)
			)
		);
	}

	public function test_nothing_to_compare_does_not_warn(): void {
		$this->assertFalse( SettingsPage::serves_an_older_pull( null, null ) );
		$this->assertFalse( SettingsPage::serves_an_older_pull( self::STAMP, null ) );
	}
}
