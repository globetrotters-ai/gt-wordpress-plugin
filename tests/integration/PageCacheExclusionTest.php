<?php
/**
 * The page-cache exclusion against a real W3 Total Cache.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\PageCacheExclusion;
use Globetrotters\AiPresence\Plugin;
use Globetrotters\AiPresence\Settings\Options;
use WP_UnitTestCase;

/**
 * Runs in CI's ``w3tc`` cell, where wp-env installs W3 Total Cache; skipped
 * where it is not installed. The suite does not activate the antagonists (only
 * the HTTP smoke meets them), so W3TC is loaded here from its plugin file: its
 * config API and its page-cache read gate are what the exclusion relies on.
 *
 * WP Rocket is commercial and LiteSpeed Cache's page cache needs a LiteSpeed
 * server, so neither is in the matrix; both are covered by the unit suite
 * against their APIs.
 */
final class PageCacheExclusionTest extends WP_UnitTestCase {

	private const W3TC_FILE = 'w3-total-cache/w3-total-cache.php';

	private const LIST = array( 'OAI-SearchBot', 'ChatGPT-User', 'Claude-User' );

	/**
	 * @var array<int, string>
	 */
	private array $owner_entries = array( 'AhrefsBot' );

	public function set_up(): void {
		parent::set_up();
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . self::W3TC_FILE ) ) {
			$this->markTestSkipped( 'W3 Total Cache is not installed (CI runs this in the w3tc cell).' );
		}
		// Loading W3TC starts its page-cache output buffer; close what it opened,
		// or PHPUnit flags the first test of the run for an unclosed buffer.
		$level = ob_get_level();
		require_once WP_PLUGIN_DIR . '/' . self::W3TC_FILE;
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$config = \W3TC\Dispatcher::config();
		$config->set( 'pgcache.reject.ua', $this->owner_entries );
		$config->save();

		update_option(
			Options::SETTINGS,
			array(
				'ingest_endpoint'            => 'https://api.globetrotters.ai/presence/analytics/server-log',
				'ingest_token'               => 'tok',
				'exclude_ai_from_page_cache' => true,
			)
		);
		( new PageCacheExclusion( new Options() ) )->replace( 'v1', self::LIST, time() );
	}

	public function tear_down(): void {
		if ( class_exists( '\\W3TC\\Dispatcher' ) ) {
			$config = \W3TC\Dispatcher::config();
			$config->set( 'pgcache.reject.ua', array() );
			$config->save();
		}
		delete_option( PageCacheExclusion::OPTION );
		delete_option( PageCacheExclusion::APPLIED_OPTION );
		unset( $_SERVER['HTTP_USER_AGENT'] );
		parent::tear_down();
	}

	public function test_w3tc_is_detected_and_given_the_list(): void {
		Plugin::instance()->sync_page_cache_exclusion();

		$this->assertSame(
			array( 'AhrefsBot', 'OAI-SearchBot', 'ChatGPT-User', 'Claude-User' ),
			$this->reject_ua()
		);
		$this->assertTrue( ( new PageCacheExclusion( new Options() ) )->is_active( 'w3-total-cache' ) );
	}

	public function test_w3tc_no_longer_reads_its_cache_for_an_ai_agent(): void {
		Plugin::instance()->sync_page_cache_exclusion();

		$grabber                    = new \W3TC\PgCache_ContentGrabber();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';
		$this->assertFalse( $grabber->_check_ua(), 'A cached page is not served to ChatGPT-User.' );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15';
		$this->assertTrue( $grabber->_check_ua(), 'A browser still is.' );
	}

	public function test_switching_it_off_on_save_restores_the_owners_list(): void {
		Plugin::instance()->sync_page_cache_exclusion();

		$settings                               = get_option( Options::SETTINGS );
		$settings['exclude_ai_from_page_cache'] = false;
		update_option( Options::SETTINGS, $settings );

		$this->assertSame( array( 'AhrefsBot' ), $this->reject_ua() );
	}

	public function test_deactivation_restores_the_owners_list(): void {
		Plugin::instance()->sync_page_cache_exclusion();

		Plugin::deactivate();

		$this->assertSame( array( 'AhrefsBot' ), $this->reject_ua() );
		$this->assertFalse( ( new PageCacheExclusion( new Options() ) )->is_active( 'w3-total-cache' ) );
	}

	public function test_uninstall_restores_the_owners_list(): void {
		Plugin::instance()->sync_page_cache_exclusion();

		PageCacheExclusion::remove_recorded_on_uninstall();

		$this->assertSame( array( 'AhrefsBot' ), $this->reject_ua() );
	}

	/**
	 * W3TC's stored list, read back through a fresh config.
	 *
	 * @return array<int, string>
	 */
	private function reject_ua(): array {
		return array_values( ( new \W3TC\Config() )->get_array( 'pgcache.reject.ua' ) );
	}
}
