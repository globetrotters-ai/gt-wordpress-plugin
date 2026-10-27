<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Analytics\Flusher;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Cache\RefreshScheduler;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Settings\RobotsOptions;
use Globetrotters\AiPresence\Settings\SettingsPage;
use Globetrotters\AiPresence\Sync\ArtefactSync;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\FakeFetcher;
use Globetrotters\AiPresence\Tests\Support\FakeIngestTransport;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;
use Globetrotters\AiPresence\Update\UpdateChecker;

/**
 * The install-profile and robots.txt fields of the settings sanitizer.
 *
 * @covers \Globetrotters\AiPresence\Settings\SettingsPage
 */
final class SettingsSanitizeProfileTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
	}

	public function test_site_bot_capture_is_on_by_default_and_survives_an_unrelated_save(): void {
		$this->assertTrue( $this->sanitize( array( 'website_url' => 'https://acme.globetrotters.ai' ) )['capture_site_bots'] );
	}

	public function test_site_bot_capture_is_turned_off_only_by_an_untick_on_the_reporting_section(): void {
		$this->assertFalse( $this->sanitize( array( 'reporting_section' => '1' ) )['capture_site_bots'] );
		$this->assertTrue(
			$this->sanitize(
				array(
					'reporting_section' => '1',
					'capture_site_bots' => '1',
				)
			)['capture_site_bots']
		);
	}

	public function test_a_known_profile_is_kept(): void {
		$out = $this->sanitize( array( 'profile' => Options::PROFILE_SUBDOMAIN_BREADCRUMB ) );

		$this->assertSame( Options::PROFILE_SUBDOMAIN_BREADCRUMB, $out['profile'] );
	}

	public function test_an_unknown_profile_falls_back_to_full_apex(): void {
		// `cdn_only` is a real backend profile this plugin does not implement.
		$this->assertSame( Options::PROFILE_FULL_APEX, $this->sanitize( array( 'profile' => 'cdn_only' ) )['profile'] );
		$this->assertSame( Options::PROFILE_FULL_APEX, $this->sanitize( array( 'profile' => 'nonsense' ) )['profile'] );
	}

	public function test_a_non_scalar_submission_cannot_reach_sanitize_key(): void {
		// `profile[]=` would otherwise hand sanitize_key() an array and fatal on
		// strtolower(). It must degrade to the profile that changes nothing.
		$out = $this->sanitize( array( 'profile' => array( 'full_apex' ) ) );

		$this->assertSame( Options::PROFILE_FULL_APEX, $out['profile'] );
	}

	public function test_an_absent_profile_leaves_the_stored_value_alone(): void {
		$this->store[ Options::SETTINGS ] = array( 'profile' => Options::PROFILE_SUBDOMAIN_BREADCRUMB );

		$out = $this->sanitize( array( 'website_url' => 'https://acme.globetrotters.ai' ) );

		$this->assertSame( Options::PROFILE_SUBDOMAIN_BREADCRUMB, $out['profile'] );
	}

	public function test_the_robots_opt_ins_are_kept(): void {
		$out = $this->sanitize(
			array(
				'robots_ai_agents' => RobotsOptions::ALLOW_ALL,
				'robots_section'   => '1',
				'robots_ai_train'  => '1',
			)
		);

		$this->assertSame( RobotsOptions::ALLOW_ALL, $out['robots_ai_agents'] );
		$this->assertTrue( $out['robots_ai_train'] );
	}

	public function test_an_unknown_or_non_scalar_robots_mode_falls_back_to_inherit(): void {
		$this->assertSame( RobotsOptions::INHERIT, $this->sanitize( array( 'robots_ai_agents' => 'everything' ) )['robots_ai_agents'] );
		$this->assertSame( RobotsOptions::INHERIT, $this->sanitize( array( 'robots_ai_agents' => array( 'allow_all' ) ) )['robots_ai_agents'] );
	}

	public function test_an_unticked_training_box_turns_the_signal_off_only_when_its_section_was_submitted(): void {
		$this->store[ Options::SETTINGS ] = array( 'robots_ai_train' => true );

		// A submission without the robots section says nothing about the box.
		$this->assertTrue( $this->sanitize( array( 'website_url' => 'https://acme.globetrotters.ai' ) )['robots_ai_train'] );
		// The section was on screen and the box was left unticked.
		$this->assertFalse( $this->sanitize( array( 'robots_section' => '1' ) )['robots_ai_train'] );
	}

	/**
	 * The checkbox submits "1". Anything else — a crafted "false", "no", an
	 * array — is not the explicit opt-in a site-wide training grant needs.
	 *
	 * @dataProvider non_checkbox_value_provider
	 * @param mixed $value Submitted value.
	 */
	public function test_only_the_checkbox_value_opts_into_training( $value ): void {
		$out = $this->sanitize(
			array(
				'robots_section'  => '1',
				'robots_ai_train' => $value,
			)
		);

		$this->assertFalse( $out['robots_ai_train'] );
	}

	/**
	 * Same rule for the Cloudflare preset: it makes CF-Connecting-IP evidence
	 * for any request from Cloudflare's ranges, which only the site's owner can
	 * say is how it is deployed.
	 *
	 * @dataProvider non_checkbox_value_provider
	 * @param mixed $value Submitted value.
	 */
	public function test_only_the_checkbox_value_turns_on_the_cloudflare_preset( $value ): void {
		$this->store[ Options::SETTINGS ] = array( 'trusted_proxy_cloudflare' => true );

		$out = $this->sanitize(
			array(
				'reporting_section'        => '1',
				'trusted_proxy_cloudflare' => $value,
			)
		);

		$this->assertFalse( $out['trusted_proxy_cloudflare'] );
	}

	public function test_a_ticked_cloudflare_box_is_kept(): void {
		$out = $this->sanitize(
			array(
				'reporting_section'        => '1',
				'trusted_proxy_cloudflare' => '1',
			)
		);

		$this->assertTrue( $out['trusted_proxy_cloudflare'] );
	}

	public function test_page_view_counting_is_off_until_ticked(): void {
		$this->assertFalse( $this->sanitize( array( 'website_url' => 'https://acme.globetrotters.ai' ) )['count_page_views'] );
		$this->assertTrue(
			$this->sanitize(
				array(
					'reporting_section' => '1',
					'count_page_views'  => '1',
				)
			)['count_page_views']
		);
	}

	public function test_an_unticked_counting_box_turns_counting_off_only_when_its_section_was_submitted(): void {
		$this->store[ Options::SETTINGS ] = array( 'count_page_views' => true );

		$this->assertTrue( $this->sanitize( array( 'website_url' => 'https://acme.globetrotters.ai' ) )['count_page_views'] );
		$this->assertFalse( $this->sanitize( array( 'reporting_section' => '1' ) )['count_page_views'] );
	}

	/**
	 * @dataProvider non_checkbox_value_provider
	 * @param mixed $value Submitted value.
	 */
	public function test_only_the_checkbox_value_turns_on_page_view_counting( $value ): void {
		$out = $this->sanitize(
			array(
				'reporting_section' => '1',
				'count_page_views'  => $value,
			)
		);

		$this->assertFalse( $out['count_page_views'] );
	}

	public function test_saving_the_reporting_section_retires_the_0_4_0_proxy_checkbox(): void {
		$this->store[ Options::SETTINGS ] = array( 'trusted_proxy' => true );

		$out = $this->sanitize(
			array(
				'reporting_section'        => '1',
				'trusted_proxy_cloudflare' => '1',
			)
		);

		$this->assertArrayNotHasKey( 'trusted_proxy', $out );
		$this->assertTrue( $out['trusted_proxy_cloudflare'] );
	}

	public function test_the_proxy_list_is_stored_one_entry_per_line(): void {
		$out = $this->sanitize(
			array(
				'reporting_section' => '1',
				'trusted_proxies'   => " 10.0.0.0/8, 192.0.2.7\r\n2001:db8::/32 ",
			)
		);

		$this->assertSame( "10.0.0.0/8\n192.0.2.7\n2001:db8::/32", $out['trusted_proxies'] );
	}

	public function test_entries_that_are_not_addresses_are_dropped_with_a_notice(): void {
		Functions\expect( 'add_settings_error' )->once();

		$out = $this->sanitize(
			array(
				'reporting_section' => '1',
				'trusted_proxies'   => "10.0.0.1\nproxy.example.com\n*",
			)
		);

		$this->assertSame( '10.0.0.1', $out['trusted_proxies'] );
	}

	public function test_a_non_scalar_proxy_list_is_emptied(): void {
		$out = $this->sanitize(
			array(
				'reporting_section' => '1',
				'trusted_proxies'   => array( '10.0.0.1' ),
			)
		);

		$this->assertSame( '', $out['trusted_proxies'] );
	}

	public function test_an_https_ingest_endpoint_is_kept(): void {
		$out = $this->sanitize( array( 'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log' ) );

		$this->assertSame( 'https://api.globetrotters.ai/presence/analytics/server-log', $out['ingest_endpoint'] );
	}

	public function test_an_empty_ingest_endpoint_clears_it(): void {
		$this->store[ Options::SETTINGS ] = array( 'ingest_endpoint' => 'https://api.globetrotters.ai/x' );

		$this->assertSame( '', $this->sanitize( array( 'ingest_endpoint' => '' ) )['ingest_endpoint'] );
	}

	/**
	 * A cleartext endpoint would carry the bearer token and every captured client
	 * IP in the clear. Refused with a notice, and the stored endpoint — a working
	 * one, or none — is left as it was rather than replaced by a typo.
	 */
	public function test_an_http_ingest_endpoint_is_refused_and_the_stored_one_kept(): void {
		$this->store[ Options::SETTINGS ] = array( 'ingest_endpoint' => 'https://api.globetrotters.ai/x' );
		Functions\expect( 'add_settings_error' )->once();

		$out = $this->sanitize( array( 'ingest_endpoint' => 'http://api.globetrotters.ai/x' ) );

		$this->assertSame( 'https://api.globetrotters.ai/x', $out['ingest_endpoint'] );
	}

	public function test_an_https_website_url_is_kept(): void {
		$this->assertSame(
			'https://acme.globetrotters.ai',
			$this->sanitize( array( 'website_url' => 'https://acme.globetrotters.ai' ) )['website_url']
		);
	}

	public function test_an_empty_website_url_clears_it(): void {
		$this->store[ Options::SETTINGS ] = array( 'website_url' => 'https://acme.globetrotters.ai' );

		$this->assertSame( '', $this->sanitize( array( 'website_url' => '' ) )['website_url'] );
	}

	/**
	 * Whatever is pulled from the website URL is published at this site's apex.
	 * A cleartext source is refused with a notice and the stored one kept. A
	 * scheme-less one too: core's esc_url_raw() turns it into http://.
	 *
	 * @dataProvider insecure_website_url_provider
	 * @param string $website_url Submitted URL.
	 */
	public function test_a_website_url_that_is_not_https_is_refused_and_the_stored_one_kept( string $website_url ): void {
		$this->store[ Options::SETTINGS ] = array( 'website_url' => 'https://acme.globetrotters.ai' );
		Functions\expect( 'add_settings_error' )->once()->with( Options::SETTINGS, 'gtaip_website_url_https', \Mockery::type( 'string' ) );

		$out = $this->sanitize( array( 'website_url' => $website_url ) );

		$this->assertSame( 'https://acme.globetrotters.ai', $out['website_url'] );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function insecure_website_url_provider(): array {
		return array(
			'http'              => array( 'http://acme.globetrotters.ai' ),
			'scheme-less'       => array( 'acme.globetrotters.ai' ),
			'protocol-relative' => array( '//acme.globetrotters.ai' ),
		);
	}

	/**
	 * On the first save core runs the sanitizer twice: update_option() finds no
	 * stored option and hands the result to add_option(), which sanitizes it
	 * again. That second pass carries no section markers — they are form fields,
	 * not settings — so it must not read the checkboxes as unticked.
	 */
	public function test_a_second_pass_over_its_own_output_changes_nothing(): void {
		$page  = $this->page();
		$first = $page->sanitize(
			array(
				'robots_section'           => '1',
				'robots_ai_train'          => '1',
				'reporting_section'        => '1',
				'trusted_proxy_cloudflare' => '1',
				'trusted_proxies'          => '10.0.0.0/8',
			)
		);

		$this->assertTrue( $first['robots_ai_train'] );
		$this->assertTrue( $first['trusted_proxy_cloudflare'] );
		$this->assertSame( $first, $page->sanitize( $first ) );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function non_checkbox_value_provider(): array {
		return array(
			'false string' => array( 'false' ),
			'no'           => array( 'no' ),
			'on'           => array( 'on' ),
			'array'        => array( array( '1' ) ),
		);
	}

	/**
	 * Run the sanitizer over a submitted settings array.
	 *
	 * @param array<string, mixed> $input Raw submitted values.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input ): array {
		return $this->page()->sanitize( $input );
	}

	/**
	 * A fully-wired SettingsPage.
	 *
	 * Every collaborator is ``final``, so the graph is built for real against the
	 * suite's fakes rather than doubled. ``sanitize()`` touches none of it beyond
	 * ``Options``, but the constructor demands the full set.
	 */
	private function page(): SettingsPage {
		$options = new Options();
		$cache   = new ArtefactCache();
		$fetcher = new FakeFetcher();
		$buffer  = new EventBuffer( new FakeEventStore() );
		$sync    = new ArtefactSync( $fetcher, $cache, $options );

		return new SettingsPage(
			$options,
			$sync,
			new RefreshScheduler( $sync, $cache, $options ),
			$cache,
			new UpdateChecker( $fetcher ),
			$buffer,
			new FlushScheduler(
				new Flusher( $buffer, new FakeIngestTransport(), $options ),
				$options
			)
		);
	}
}
