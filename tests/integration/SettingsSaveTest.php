<?php
/**
 * Integration: saving the settings screen through core's own option API.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Analytics\Flusher;
use Globetrotters\AiPresence\Analytics\IngestClient;
use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Cache\RefreshScheduler;
use Globetrotters\AiPresence\Client\GtClient;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Settings\SettingsPage;
use Globetrotters\AiPresence\Sync\ArtefactSync;
use Globetrotters\AiPresence\Update\UpdateChecker;
use WP_UnitTestCase;

/**
 * ``options.php`` saves the form with ``update_option()``. On an install that
 * has never saved it, core sanitizes twice — once in ``update_option()``, and
 * again in the ``add_option()`` it falls through to — and the unit suite, which
 * calls the sanitizer directly, cannot see the second pass.
 */
final class SettingsSaveTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Options::SETTINGS );
		$this->page()->register_settings();
	}

	public function tear_down(): void {
		unregister_setting( SettingsPage::OPTION_GROUP, Options::SETTINGS );
		parent::tear_down();
	}

	public function test_the_first_save_keeps_every_checkbox_and_the_proxy_list(): void {
		// The form as submitted: section markers, ticked boxes, a pasted list.
		update_option(
			Options::SETTINGS,
			array(
				'website_url'              => '',
				'robots_section'           => '1',
				'robots_ai_train'          => '1',
				'reporting_section'        => '1',
				'trusted_proxy_cloudflare' => '1',
				'trusted_proxies'          => '10.0.0.0/8',
			)
		);

		$stored = get_option( Options::SETTINGS );
		$this->assertTrue( $stored['robots_ai_train'] );
		$this->assertTrue( $stored['trusted_proxy_cloudflare'] );
		$this->assertSame( '10.0.0.0/8', $stored['trusted_proxies'] );
		$this->assertTrue( ( new Options() )->trusted_proxies()->trusts_cloudflare() );
	}

	/**
	 * Core's esc_url_raw() turns a scheme-less entry into ``http://``; the
	 * sanitizer must refuse that as surely as a typed ``http://``, and keep the
	 * stored source.
	 */
	public function test_a_scheme_less_or_http_website_url_is_refused_on_save(): void {
		update_option( Options::SETTINGS, array( 'website_url' => 'https://acme.example' ) );

		update_option( Options::SETTINGS, array( 'website_url' => 'acme.example' ) );
		$this->assertSame( 'https://acme.example', get_option( Options::SETTINGS )['website_url'] );

		update_option( Options::SETTINGS, array( 'website_url' => 'http://acme.example' ) );
		$this->assertSame( 'https://acme.example', get_option( Options::SETTINGS )['website_url'] );

		$codes = wp_list_pluck( get_settings_errors( Options::SETTINGS ), 'code' );
		$this->assertContains( 'gtaip_website_url_https', $codes );
	}

	/**
	 * A fully wired settings page, as Plugin::boot() builds it.
	 */
	private function page(): SettingsPage {
		$options = new Options();
		$cache   = new ArtefactCache();
		$sync    = new ArtefactSync( new GtClient(), $cache, $options );
		$buffer  = new EventBuffer( new MysqlEventStore() );

		return new SettingsPage(
			$options,
			$sync,
			new RefreshScheduler( $sync, $cache, $options ),
			$cache,
			new UpdateChecker( new GtClient() ),
			$buffer,
			new FlushScheduler( new Flusher( $buffer, new IngestClient(), $options ), $options )
		);
	}
}
