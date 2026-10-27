<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\PageCacheExclusion;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FakeCachePlugins.php';

/**
 * @covers \Globetrotters\AiPresence\Analytics\PageCacheExclusion
 */
final class PageCacheExclusionTest extends TestCase {

	use WpOptions;

	private const LIST = array( 'OAI-SearchBot', 'ChatGPT-User', 'Claude-User' );

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint'            => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'               => 'tok',
			'exclude_ai_from_page_cache' => true,
		);
		\W3TC\Config::$values         = array( 'pgcache.reject.ua' => array( 'AhrefsBot', 'chatgpt-user' ) );
		\W3TC\Config::$saves          = 0;
		\W3TC\Root_Environment::$fixes = 0;
		\W3TC\Root_Environment::$fails = null;
		\LiteSpeed\Conf::$values       = array( 'cache-exc_useragents' => array( 'SemrushBot' ) );
		\LiteSpeed\Conf::$updates      = 0;
	}

	public function test_parse_keeps_plain_tokens_once(): void {
		$this->assertSame(
			array( 'ChatGPT-User', 'OAI-SearchBot' ),
			PageCacheExclusion::parse( array( ' ChatGPT-User ', 'chatgpt-user', 'OAI-SearchBot', 'bad"quote', 'two words', 42, '' ) )
		);
		$this->assertSame( array(), PageCacheExclusion::parse( null ) );
		$this->assertSame( array(), PageCacheExclusion::parse( 'ChatGPT-User' ) );
	}

	public function test_w3tc_gets_only_the_missing_entries_and_the_owners_stay(): void {
		$exclusion = $this->with_list();

		$exclusion->sync( 'w3-total-cache' );

		$this->assertSame(
			array( 'AhrefsBot', 'chatgpt-user', 'OAI-SearchBot', 'Claude-User' ),
			\W3TC\Config::$values['pgcache.reject.ua']
		);
		$this->assertSame( 1, \W3TC\Config::$saves );
		$this->assertSame( 1, \W3TC\Root_Environment::$fixes, 'W3TC rewrites the rules disk-enhanced mode serves from.' );
		$this->assertSame( array( 'OAI-SearchBot', 'Claude-User' ), $exclusion->applied()['entries'] );
		$this->assertTrue( $exclusion->is_active( 'w3-total-cache' ) );
	}

	public function test_an_owner_who_listed_every_agent_is_active_with_nothing_added(): void {
		\W3TC\Config::$values['pgcache.reject.ua'] = array( 'oai-searchbot', 'ChatGPT-User', 'claude-user' );
		$exclusion = $this->with_list();

		$exclusion->sync( 'w3-total-cache' );

		$this->assertSame( 0, \W3TC\Config::$saves );
		$this->assertSame( array(), $exclusion->applied()['entries'] );
		$this->assertTrue( $exclusion->is_active( 'w3-total-cache' ) );
	}

	public function test_disk_enhanced_rules_not_rewritten_is_not_active_and_is_retried(): void {
		\W3TC\Config::$values['pgcache.engine'] = 'file_generic';
		\W3TC\Root_Environment::$fails        = new \RuntimeException( '.htaccess not writable' );
		$exclusion                              = $this->with_list();

		$exclusion->sync( 'w3-total-cache' );

		$this->assertFalse( $exclusion->is_active( 'w3-total-cache' ), 'Apache still serves the cached files.' );
		$this->assertSame( array( 'OAI-SearchBot', 'Claude-User' ), $exclusion->applied()['entries'], 'Recorded, so it can be removed.' );

		\W3TC\Root_Environment::$fails = null;
		$exclusion->sync( 'w3-total-cache' );

		$this->assertTrue( $exclusion->is_active( 'w3-total-cache' ) );
		$this->assertSame( 1, \W3TC\Config::$saves, 'The retry rewrites the rules, not the list.' );
	}

	public function test_a_failed_rule_fix_does_not_matter_where_w3tc_reads_from_php(): void {
		\W3TC\Config::$values['pgcache.engine'] = 'file';
		\W3TC\Root_Environment::$fails        = new \RuntimeException( '.htaccess not writable' );
		$exclusion                              = $this->with_list();

		$exclusion->sync( 'w3-total-cache' );

		$this->assertTrue( $exclusion->is_active( 'w3-total-cache' ) );
	}

	public function test_the_list_and_the_record_are_two_options(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'w3-total-cache' );

		// The ranges sync writes the list without touching what was applied.
		$exclusion->replace( 'v2', self::LIST, time() );

		$this->assertSame( 'w3-total-cache', $exclusion->applied()['cache'] );
		$this->assertArrayNotHasKey( 'applied', $this->store[ PageCacheExclusion::OPTION ] );
	}

	public function test_switching_it_off_removes_exactly_what_was_added(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'w3-total-cache' );

		$this->store[ Options::SETTINGS ]['exclude_ai_from_page_cache'] = false;
		$exclusion->sync( 'w3-total-cache' );

		// The owner's own entry stays, ChatGPT-User included: it was theirs.
		$this->assertSame( array( 'AhrefsBot', 'chatgpt-user' ), \W3TC\Config::$values['pgcache.reject.ua'] );
		$this->assertSame( '', $exclusion->applied()['cache'] );
		$this->assertFalse( $exclusion->is_active( 'w3-total-cache' ) );
	}

	public function test_turning_site_capture_off_removes_it_too(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'litespeed-cache' );

		$this->store[ Options::SETTINGS ]['capture_site_bots'] = false;
		$exclusion->sync( 'litespeed-cache' );

		$this->assertSame( array( 'SemrushBot' ), \LiteSpeed\Conf::$values['cache-exc_useragents'] );
	}

	public function test_litespeed_saves_through_update_confs(): void {
		$exclusion = $this->with_list();

		$exclusion->sync( 'litespeed-cache' );

		$this->assertSame(
			array( 'SemrushBot', 'OAI-SearchBot', 'ChatGPT-User', 'Claude-User' ),
			\LiteSpeed\Conf::$values['cache-exc_useragents']
		);
		$this->assertTrue( $exclusion->is_active( 'litespeed-cache' ) );
	}

	public function test_deactivation_removes_whatever_the_setting_says(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'litespeed-cache' );

		$exclusion->remove_all();

		$this->assertSame( array( 'SemrushBot' ), \LiteSpeed\Conf::$values['cache-exc_useragents'] );
		$this->assertSame( '', $exclusion->applied()['cache'] );
	}

	public function test_uninstall_removes_a_record_left_behind(): void {
		$this->with_list()->sync( 'w3-total-cache' );

		PageCacheExclusion::remove_recorded_on_uninstall();

		$this->assertSame( array( 'AhrefsBot', 'chatgpt-user' ), \W3TC\Config::$values['pgcache.reject.ua'] );
	}

	public function test_nothing_changes_when_nothing_changed(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'w3-total-cache' );

		$exclusion->sync( 'w3-total-cache' );

		$this->assertSame( 1, \W3TC\Config::$saves );
	}

	public function test_an_agent_taken_out_of_w3tc_is_put_back(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'w3-total-cache' );
		// An admin edits W3TC's list by hand, dropping one of ours.
		\W3TC\Config::$values['pgcache.reject.ua'] = array( 'AhrefsBot', 'chatgpt-user', 'Claude-User' );

		$exclusion->sync( 'w3-total-cache' );

		$this->assertSame( array( 'AhrefsBot', 'chatgpt-user', 'Claude-User', 'OAI-SearchBot' ), \W3TC\Config::$values['pgcache.reject.ua'] );
		$this->assertTrue( $exclusion->is_active( 'w3-total-cache' ) );

		$exclusion->remove_all();

		$this->assertSame( array( 'AhrefsBot', 'chatgpt-user' ), \W3TC\Config::$values['pgcache.reject.ua'], 'The re-added entry is ours to remove.' );
	}

	public function test_an_agent_taken_out_of_litespeed_is_put_back(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'litespeed-cache' );
		\LiteSpeed\Conf::$values['cache-exc_useragents'] = array( 'SemrushBot', 'OAI-SearchBot' );

		$exclusion->sync( 'litespeed-cache' );

		$this->assertSame( array( 'SemrushBot', 'OAI-SearchBot', 'ChatGPT-User', 'Claude-User' ), \LiteSpeed\Conf::$values['cache-exc_useragents'] );
	}

	public function test_uninstall_rebuilds_wp_rocket_without_the_list(): void {
		$rebuilds = 0;
		Functions\when( 'rocket_generate_config_file' )->alias(
			function () use ( &$rebuilds ) {
				++$rebuilds;
			}
		);
		$this->with_list()->sync( 'wp-rocket' );

		PageCacheExclusion::remove_recorded_on_uninstall();

		$this->assertSame( 2, $rebuilds );
		$this->assertArrayNotHasKey( PageCacheExclusion::APPLIED_OPTION, $this->store );
	}

	public function test_a_new_list_is_re_applied(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'litespeed-cache' );

		$exclusion->replace( 'v2', array( 'OAI-SearchBot', 'PerplexityBot' ), time() );
		$exclusion->sync( 'litespeed-cache' );

		$this->assertSame( array( 'SemrushBot', 'OAI-SearchBot', 'PerplexityBot' ), \LiteSpeed\Conf::$values['cache-exc_useragents'] );
	}

	public function test_a_swapped_cache_moves_the_exclusion(): void {
		$exclusion = $this->with_list();
		$exclusion->sync( 'w3-total-cache' );

		$exclusion->sync( 'litespeed-cache' );

		$this->assertSame( array( 'AhrefsBot', 'chatgpt-user' ), \W3TC\Config::$values['pgcache.reject.ua'] );
		$this->assertContains( 'ChatGPT-User', \LiteSpeed\Conf::$values['cache-exc_useragents'] );
		$this->assertFalse( $exclusion->is_active( 'w3-total-cache' ) );
		$this->assertTrue( $exclusion->is_active( 'litespeed-cache' ) );
	}

	public function test_wp_super_cache_is_left_alone(): void {
		// Its rejected user agents only stop a page being stored, never a
		// cached one being served: registering them would change nothing.
		$exclusion = $this->with_list();

		$exclusion->sync( 'wp-super-cache' );

		$this->assertSame( '', $exclusion->applied()['cache'] );
		$this->assertFalse( $exclusion->is_active( 'wp-super-cache' ) );
	}

	public function test_no_list_yet_applies_nothing(): void {
		$exclusion = new PageCacheExclusion( new Options() );

		$exclusion->sync( 'w3-total-cache' );

		$this->assertSame( 0, \W3TC\Config::$saves );
		$this->assertFalse( $exclusion->is_active( 'w3-total-cache' ) );
	}

	public function test_wp_rocket_is_given_the_list_through_its_filter_and_rebuilt(): void {
		$rebuilds = 0;
		Functions\when( 'rocket_generate_config_file' )->alias(
			function () use ( &$rebuilds ) {
				++$rebuilds;
			}
		);
		$exclusion = $this->with_list();

		$exclusion->sync( 'wp-rocket' );

		$this->assertSame( 1, $rebuilds );
		$this->assertNotFalse( has_filter( 'rocket_cache_reject_ua', array( $exclusion, 'rocket_reject_ua' ) ) );
		$this->assertSame(
			array( 'facebookexternalhit', 'OAI-SearchBot', 'ChatGPT-User', 'Claude-User' ),
			$exclusion->rocket_reject_ua( array( 'facebookexternalhit' ) )
		);
		$this->assertTrue( $exclusion->is_active( 'wp-rocket' ) );

		$exclusion->remove_all();

		$this->assertSame( 2, $rebuilds, 'Rebuilt again, without the list.' );
		$this->assertSame( array( 'facebookexternalhit' ), $exclusion->rocket_reject_ua( array( 'facebookexternalhit' ) ) );
	}

	public function test_the_record_is_not_autoloaded(): void {
		$autoload = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $auto = null ) use ( &$autoload ) {
				$this->store[ $name ] = $value;
				$autoload[ $name ]    = $auto;
				return true;
			}
		);

		$this->with_list()->sync( 'w3-total-cache' );

		$this->assertFalse( $autoload[ PageCacheExclusion::OPTION ] );
		$this->assertFalse( $autoload[ PageCacheExclusion::APPLIED_OPTION ] );
	}

	private function with_list(): PageCacheExclusion {
		$exclusion = new PageCacheExclusion( new Options() );
		$exclusion->replace( 'v1', self::LIST, time() );
		return $exclusion;
	}
}
