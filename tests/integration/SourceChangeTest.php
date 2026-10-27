<?php
/**
 * Integration: disconnecting or re-pointing the install stops the old presence.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Settings\Options;
use WP_UnitTestCase;

/**
 * Drives the settings option the way both the settings screen and ``wp option
 * update`` do, through core's ``update_option()``, against the hooks the booted
 * plugin registered. Without them, clearing the website URL left the last
 * bundle, its homepage JSON-LD and its IndexNow key serving indefinitely — and
 * with no destination, nothing was scheduled to ever replace them.
 */
final class SourceChangeTest extends WP_UnitTestCase {

	private const BASE = 'https://acme.example';
	private const KEY  = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';

	public function set_up(): void {
		parent::set_up();
		update_option( Options::SETTINGS, array( 'website_url' => self::BASE ) );
		update_option(
			ArtefactCache::OPTION,
			array(
				'files'   => array( 'llms.txt' => '# llms' ),
				'version' => 'v1',
			),
			false
		);
		( new Options() )->update_state(
			array(
				'installed_version' => 'v1',
				'indexnow_key'      => self::KEY,
				'presence_origin'   => 'https://acme.globetrotters.ai',
			)
		);
	}

	public function test_disconnecting_stops_publishing(): void {
		update_option( Options::SETTINGS, array( 'website_url' => '' ) );

		$this->assertFalse( get_option( ArtefactCache::OPTION ) );
		$options = new Options();
		$this->assertSame( '', $options->indexnow_key() );
		$this->assertSame( '', $options->presence_origin() );
		$this->assertSame( '', (string) $options->state()['installed_version'] );
	}

	public function test_pointing_at_another_destination_drops_the_previous_ones_content(): void {
		update_option( Options::SETTINGS, array( 'website_url' => 'https://other.example' ) );

		$this->assertFalse( get_option( ArtefactCache::OPTION ) );
		$this->assertSame( '', ( new Options() )->indexnow_key() );
	}

	public function test_saving_other_settings_keeps_the_bundle(): void {
		update_option(
			Options::SETTINGS,
			array(
				'website_url'      => self::BASE . '/',
				'refresh_interval' => 'weekly',
			)
		);

		$this->assertIsArray( get_option( ArtefactCache::OPTION ) );
		$this->assertSame( self::KEY, ( new Options() )->indexnow_key() );
	}
}
