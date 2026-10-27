<?php
/**
 * Integration: the self-hosted update channel through core's own update check.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Client\GtClient;
use Globetrotters\AiPresence\Update\UpdateChecker;
use WP_UnitTestCase;

/**
 * Runs ``wp_update_plugins()`` — the function WP-Cron and the Updates screen
 * call — against a stubbed ``update.json``, and reads the result where core
 * leaves it: the ``update_plugins`` site transient.
 *
 * A unit test calling {@see \Globetrotters\AiPresence\Update\UpdateChecker::check()}
 * directly cannot catch a response core discards. That is how an update object
 * without ``version`` shipped: every field the unit test asserted was present,
 * and core skipped it anyway.
 */
final class UpdateCheckTest extends WP_UnitTestCase {

	private const BASENAME = 'globetrotters-ai-presence/globetrotters-ai-presence.php';

	/**
	 * The active pre_http_request stub, tracked so it can be removed per-test.
	 *
	 * @var callable|null
	 */
	private $http_stub = null;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		delete_site_transient( 'update_plugins' );
		delete_transient( 'gtaip_update_meta' );
		// The booted plugin's checker memoises its metadata for the life of the
		// request, which in PHPUnit is the whole suite. A fresh one per test; the
		// base class restores the original hooks in tear_down().
		remove_all_filters( 'update_plugins_globetrotters.ai' );
		remove_all_filters( 'upgrader_pre_download' );
		( new UpdateChecker( new GtClient() ) )->register();
	}

	public function tear_down(): void {
		if ( null !== $this->http_stub ) {
			remove_filter( 'pre_http_request', $this->http_stub, 10 );
			$this->http_stub = null;
		}
		delete_site_transient( 'update_plugins' );
		delete_transient( 'gtaip_update_meta' );
		parent::tear_down();
	}

	public function test_core_offers_an_advertised_newer_version(): void {
		$this->stub_update_json( '99.0.0' );

		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		$this->assertIsObject( $updates );
		$this->assertArrayHasKey( self::BASENAME, $updates->response, 'core discarded the update object' );

		$offer = $updates->response[ self::BASENAME ];
		$this->assertSame( '99.0.0', $offer->new_version );
		$this->assertSame( 'https://globetrotters.ai/wp-plugin/globetrotters-ai-presence-99.0.0.zip', $offer->package );
	}

	public function test_core_offers_nothing_when_the_advertised_version_is_current(): void {
		$this->stub_update_json( \Globetrotters\AiPresence\VERSION );

		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		$this->assertIsObject( $updates );
		$this->assertArrayNotHasKey( self::BASENAME, $updates->response );
	}

	/**
	 * Answer ``update.json`` with metadata advertising ``$version``, and every
	 * other request (api.wordpress.org's own plugin check) with an empty 200 so
	 * the test never touches the network.
	 *
	 * @param string $version Advertised plugin version.
	 */
	private function stub_update_json( string $version ): void {
		$meta = array(
			'version'      => $version,
			'package'      => 'https://globetrotters.ai/wp-plugin/globetrotters-ai-presence-' . $version . '.zip',
			'sha256'       => str_repeat( 'a', 64 ),
			'url'          => 'https://globetrotters.ai/wp-plugin',
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'tested'       => '7.0',
		);

		$this->http_stub = static function ( $preempt, $args, $url ) use ( $meta ) {
			$body = '/wp-plugin/update.json' === (string) wp_parse_url( (string) $url, PHP_URL_PATH )
				? (string) wp_json_encode( $meta )
				: '';
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );
	}
}
