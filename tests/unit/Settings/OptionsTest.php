<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Settings\Options
 */
final class OptionsTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'sanitize_title' )->alias( fn( $title ) => strtolower( (string) $title ) );
	}

	public function test_normalize_url_trims_whitespace_and_trailing_slash(): void {
		$this->assertSame( 'https://example.com', Options::normalize_url( '  https://example.com/  ' ) );
		$this->assertSame( 'https://acme.globetrotters.ai', Options::normalize_url( 'https://acme.globetrotters.ai' ) );
	}

	public function test_normalize_url_is_empty_when_unset(): void {
		$this->assertSame( '', Options::normalize_url( '' ) );
	}

	/**
	 * The flusher and the sync are separate writers on separate schedules. Each
	 * rewriting one shared option from its own copy puts back what the other
	 * wrote, so the flush fields live in an option of their own.
	 */
	public function test_flush_state_lives_in_its_own_option(): void {
		$options = new Options();
		$options->update_state( array( 'installed_version' => 'v1' ) );

		$options->update_flush_state(
			array(
				'last_flush_ok' => 123,
				'flush_count'   => 2,
			)
		);

		$this->assertArrayNotHasKey( 'last_flush_ok', $this->store[ Options::STATE ] );
		$this->assertSame( 'v1', $this->store[ Options::STATE ]['installed_version'] );
		$this->assertSame( 123, $this->store[ Options::FLUSH_STATE ]['last_flush_ok'] );
		$this->assertSame( 2, $options->flush_state()['flush_count'] );
	}

	public function test_state_no_longer_defaults_the_flush_or_drop_fields(): void {
		$state = ( new Options() )->state();

		$this->assertArrayNotHasKey( 'last_flush_ok', $state );
		$this->assertArrayNotHasKey( 'dropped_pending', $state );
		$this->assertArrayNotHasKey( 'dropped_total', $state );
	}

	public function test_flush_state_carries_over_what_0_4_0_kept_in_plugin_state(): void {
		// 0.4.0 wrote the flush fields into gtaip_state. An upgraded install keeps
		// showing its last flush until the flusher writes its own option.
		$this->store[ Options::STATE ] = array(
			'last_flush_ok'    => 99,
			'flush_count'      => 5,
			'last_flush_error' => 'HTTP 500',
		);

		$flush = ( new Options() )->flush_state();
		$this->assertSame( 99, (int) $flush['last_flush_ok'] );
		$this->assertSame( 5, (int) $flush['flush_count'] );
		$this->assertSame( 'HTTP 500', $flush['last_flush_error'] );

		( new Options() )->update_flush_state( array( 'flush_count' => 6 ) );

		$flush = ( new Options() )->flush_state();
		$this->assertSame( 6, (int) $flush['flush_count'] );
		$this->assertSame( 99, (int) $flush['last_flush_ok'] );
	}

	public function test_an_https_ingest_endpoint_is_read_back(): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log/',
			'ingest_token'    => 'tok',
		);

		$this->assertSame( 'https://api.globetrotters.ai/presence/analytics/server-log', ( new Options() )->ingest_endpoint() );
		$this->assertTrue( ( new Options() )->is_reporting_configured() );
	}

	/**
	 * The endpoint receives the bearer token and every captured client IP, so a
	 * cleartext one reads as unconfigured — including one written by ``wp option
	 * update``, which never reaches the settings sanitizer.
	 *
	 * @dataProvider non_https_endpoint_provider
	 * @param mixed $endpoint Stored endpoint value.
	 */
	public function test_a_non_https_ingest_endpoint_reads_as_unconfigured( $endpoint ): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => $endpoint,
			'ingest_token'    => 'tok',
		);

		$this->assertSame( '', ( new Options() )->ingest_endpoint() );
		$this->assertFalse( ( new Options() )->is_reporting_configured() );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function non_https_endpoint_provider(): array {
		return array(
			'http'            => array( 'http://api.globetrotters.ai/presence/analytics/server-log' ),
			'upper-case HTTP' => array( 'HTTP://api.globetrotters.ai/x' ),
			'scheme-relative' => array( '//api.globetrotters.ai/x' ),
			'no host'         => array( 'https:///x' ),
			'not a url'       => array( 'api.globetrotters.ai/x' ),
			'array'           => array( array( 'https://api.globetrotters.ai/x' ) ),
		);
	}

	public function test_is_https_url_accepts_any_case_scheme_with_a_host(): void {
		$this->assertTrue( Options::is_https_url( 'https://api.globetrotters.ai/x' ) );
		$this->assertTrue( Options::is_https_url( 'HTTPS://api.globetrotters.ai/x' ) );
		$this->assertFalse( Options::is_https_url( 'http://api.globetrotters.ai/x' ) );
		$this->assertFalse( Options::is_https_url( '' ) );
	}

	public function test_profile_defaults_to_full_apex(): void {
		// An upgrading install must not start emitting new markup on its pages.
		$this->store[ Options::SETTINGS ] = array( 'website_url' => 'https://acme.globetrotters.ai' );

		$this->assertSame( Options::PROFILE_FULL_APEX, ( new Options() )->profile() );
		$this->assertFalse( ( new Options() )->emits_breadcrumbs() );
	}

	public function test_profile_reads_back_the_breadcrumb_choice(): void {
		$this->store[ Options::SETTINGS ] = array( 'profile' => Options::PROFILE_SUBDOMAIN_BREADCRUMB );

		$this->assertSame( Options::PROFILE_SUBDOMAIN_BREADCRUMB, ( new Options() )->profile() );
		$this->assertTrue( ( new Options() )->emits_breadcrumbs() );
	}

	public function test_an_unrecognised_profile_reads_as_full_apex(): void {
		$this->store[ Options::SETTINGS ] = array( 'profile' => 'cdn_only' );

		$this->assertSame( Options::PROFILE_FULL_APEX, ( new Options() )->profile() );
	}

	public function test_a_non_string_profile_does_not_warn_on_the_request_path(): void {
		// Reachable by `wp option update gtaip_settings --format=json`, which
		// bypasses the settings sanitizer. profile() runs on every front-end
		// request via Breadcrumbs, so a bare cast would warn once per page load.
		$this->store[ Options::SETTINGS ] = array( 'profile' => array( 'full_apex' ) );

		$this->assertSame( Options::PROFILE_FULL_APEX, ( new Options() )->profile() );
	}

	public function test_robots_defaults_change_nothing_a_crawler_may_fetch(): void {
		$robots = ( new Options() )->robots();

		$this->assertFalse( $robots->allows_all() );
		$this->assertFalse( $robots->ai_train() );
	}

	public function test_robots_reads_back_both_opt_ins(): void {
		$this->store[ Options::SETTINGS ] = array(
			'robots_ai_agents' => 'allow_all',
			'robots_ai_train'  => true,
		);
		$robots                           = ( new Options() )->robots();

		$this->assertTrue( $robots->allows_all() );
		$this->assertTrue( $robots->ai_train() );
	}

	/**
	 * Hand-edited values (``wp option update``) bypass the sanitizer. Neither
	 * site-wide grant may be read out of a value that did not unambiguously
	 * make it.
	 */
	public function test_robots_reads_anything_ambiguous_as_the_default(): void {
		$this->store[ Options::SETTINGS ] = array(
			'robots_ai_agents' => array( 'allow_all' ),
			'robots_ai_train'  => 'false',
		);
		$robots                           = ( new Options() )->robots();

		$this->assertFalse( $robots->allows_all() );
		$this->assertFalse( $robots->ai_train() );
	}

	public function test_no_proxy_is_trusted_by_default(): void {
		$trusted = ( new Options() )->trusted_proxies();

		$this->assertTrue( $trusted->is_empty() );
		$this->assertFalse( $trusted->trusts_cloudflare() );
	}

	public function test_the_saved_proxy_list_and_cloudflare_preset_are_read_back(): void {
		$this->store[ Options::SETTINGS ] = array(
			'trusted_proxies'          => "10.0.0.0/8\n192.0.2.7",
			'trusted_proxy_cloudflare' => true,
		);

		$trusted = ( new Options() )->trusted_proxies();

		$this->assertSame( array( '10.0.0.0/8', '192.0.2.7' ), $trusted->entries() );
		$this->assertTrue( $trusted->trusts_cloudflare() );
	}

	/**
	 * 0.4.0 had one checkbox, and it trusted both headers from any peer. Its
	 * reason was overwhelmingly Cloudflare, so it upgrades into the Cloudflare
	 * preset. A site behind some other proxy loses verification until it lists
	 * that proxy — its hits read as unverified — rather than staying forgeable.
	 */
	public function test_a_0_4_0_proxy_tick_upgrades_into_the_cloudflare_preset(): void {
		$this->store[ Options::SETTINGS ] = array( 'trusted_proxy' => true );

		$trusted = ( new Options() )->trusted_proxies();

		$this->assertTrue( $trusted->trusts_cloudflare() );
		$this->assertSame( array(), $trusted->entries() );
	}

	public function test_the_preset_once_saved_wins_over_a_0_4_0_tick(): void {
		$this->store[ Options::SETTINGS ] = array(
			'trusted_proxy'            => true,
			'trusted_proxy_cloudflare' => false,
		);

		$this->assertFalse( ( new Options() )->trusted_proxies()->trusts_cloudflare() );
	}

	/**
	 * ``wp option update`` bypasses the sanitizer. A hand-edited ``"false"`` must
	 * not switch on header trust, and a hand-edited list is held to the same
	 * grammar as a submitted one.
	 */
	public function test_hand_edited_proxy_settings_are_read_strictly(): void {
		foreach ( array( 'false', 'no', '', array( '1' ) ) as $value ) {
			$this->store[ Options::SETTINGS ] = array( 'trusted_proxy' => $value );
			$this->assertFalse( ( new Options() )->trusted_proxies()->trusts_cloudflare(), 'legacy ' . var_export( $value, true ) );

			$this->store[ Options::SETTINGS ] = array( 'trusted_proxy_cloudflare' => $value );
			$this->assertFalse( ( new Options() )->trusted_proxies()->trusts_cloudflare(), 'preset ' . var_export( $value, true ) );
		}

		$this->store[ Options::SETTINGS ] = array( 'trusted_proxies' => "10.0.0.1\n*\nproxy.example.com" );
		$this->assertSame( array( '10.0.0.1' ), ( new Options() )->trusted_proxies()->entries() );

		$this->store[ Options::SETTINGS ] = array( 'trusted_proxies' => array( '10.0.0.1' ) );
		$this->assertTrue( ( new Options() )->trusted_proxies()->is_empty() );
	}

	public function test_non_string_presence_state_does_not_warn(): void {
		$this->store[ Options::STATE ] = array(
			'presence_origin' => array( 'https://ai.visit-acme.example' ),
		);

		$this->assertSame( '', ( new Options() )->presence_origin() );
	}

	public function test_presence_origin_is_normalised_and_empty_by_default(): void {
		$this->assertSame( '', ( new Options() )->presence_origin() );

		$this->store[ Options::STATE ] = array( 'presence_origin' => 'https://ai.visit-acme.example/' );

		$this->assertSame( 'https://ai.visit-acme.example', ( new Options() )->presence_origin() );
	}

	public function test_settings_merge_over_defaults(): void {
		$this->store[ Options::SETTINGS ] = array( 'website_url' => 'https://acme.globetrotters.ai/' );
		$options                          = new Options();

		$this->assertSame( 'https://acme.globetrotters.ai', $options->base_url() );
		$this->assertSame( 'acme', $options->slug() );
		$this->assertSame( 'daily', $options->get( 'refresh_interval' ) );
		$this->assertTrue( $options->is_connected() );
	}

	public function test_page_view_counting_is_off_by_default(): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok_live',
		);

		$this->assertFalse( ( new Options() )->counts_page_views() );
	}

	public function test_page_view_counting_needs_the_setting_and_reporting_credentials(): void {
		$this->store[ Options::SETTINGS ] = array( 'count_page_views' => true );
		$this->assertFalse( ( new Options() )->counts_page_views(), 'Counts nobody can send are not collected.' );

		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint'  => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'     => 'tok_live',
			'count_page_views' => true,
		);
		$this->assertTrue( ( new Options() )->counts_page_views() );
	}

	public function test_a_hand_edited_counting_flag_is_read_strictly(): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint'  => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'     => 'tok_live',
			'count_page_views' => 'false',
		);

		$this->assertFalse( ( new Options() )->counts_page_views() );
	}

	public function test_not_connected_without_url(): void {
		$options = new Options();

		$this->assertFalse( $options->is_connected() );
		$this->assertSame( '', $options->slug() );
	}

	public function test_update_state_merges_and_persists(): void {
		$options = new Options();
		$options->update_state( array( 'installed_version' => '2026-07-01-000000' ) );
		$options->update_state( array( 'last_error' => 'boom' ) );

		$state = $options->state();
		$this->assertSame( '2026-07-01-000000', $state['installed_version'] );
		$this->assertSame( 'boom', $state['last_error'] );
	}

	public function test_site_bot_capture_defaults_on_but_needs_reporting(): void {
		$this->assertFalse( ( new Options() )->captures_site_bots(), 'Not without credentials.' );

		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok',
		);
		$this->assertTrue( ( new Options() )->captures_site_bots() );

		$this->store[ Options::SETTINGS ]['capture_site_bots'] = 'false';
		$this->assertFalse( ( new Options() )->captures_site_bots() );
	}
}
