<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Serving\PageViewScript;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Serving\PageViewScript
 */
final class PageViewScriptTest extends TestCase {

	use WpOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( $js ) {
				echo '<script>' . $js . '</script>'; // phpcs:ignore
			}
		);
		$this->configure( true );
	}

	public function test_the_script_is_hooked_on_wp_footer_when_counting_is_on(): void {
		$script = $this->script();

		$script->register();

		$this->assertNotFalse( has_action( 'wp_footer', array( $script, 'print_script' ) ) );
	}

	public function test_nothing_is_hooked_when_counting_is_off(): void {
		$this->configure( false );

		$this->script()->register();

		$this->assertFalse( has_action( 'wp_footer' ) );
	}

	public function test_nothing_is_hooked_without_reporting_credentials(): void {
		// The setting alone is not enough: counts nobody will ever send are not
		// worth a script on every page.
		$this->store[ Options::SETTINGS ] = array( 'count_page_views' => true );

		$this->script()->register();

		$this->assertFalse( has_action( 'wp_footer' ) );
	}

	public function test_the_script_posts_the_path_to_this_site_only(): void {
		$js = PageViewScript::javascript();

		$this->assertStringContainsString( "'" . PageViewCounter::PATH . "'", $js );
		$this->assertStringContainsString( 'p:l.pathname', $js );
		$this->assertStringContainsString( 'sendBeacon', $js );
		// Same-origin by construction: the only URL is a root-relative path.
		$this->assertDoesNotMatchRegularExpression( '#https?:#i', $js );
		$this->assertStringNotContainsString( '//', str_replace( "'" . PageViewCounter::PATH . "'", '', $js ) );
		$this->assertStringNotContainsStringIgnoringCase( 'globetrotters.ai', $js );
		$this->assertStringNotContainsString( 'href', $js );
		$this->assertStringNotContainsString( 'document.cookie', $js );
		$this->assertStringNotContainsString( 'localStorage', $js );
		$this->assertStringStartsWith( '(function(){', $js );
		$this->assertStringEndsWith( '})();', $js );
		$this->assertLessThan( 1024, strlen( $js ) );
		// Exactly one beacon target, and the send is always inside a try.
		$this->assertSame( 1, substr_count( $js, 'sendBeacon(' ) );
		$this->assertStringContainsString( 'function s(){try{', $js );
	}

	public function test_the_script_sends_the_referrer_host_and_utm_source_only(): void {
		// The referral hints: the referrer's host — never its path or query —
		// and only when it is another site, and the one query parameter that
		// names an AI source, dropped when over-long. The rest of the query is never read.
		$js = PageViewScript::javascript();

		$this->assertStringContainsString( 'r=new URL(d.referrer).host', $js );
		$this->assertStringContainsString( 'if(r&&r!==l.host)b.r=r', $js );
		$this->assertStringContainsString( "new URLSearchParams(l.search).get('utm_source')", $js );
		$this->assertStringContainsString( 'if(u&&u.length<=100)b.u=u', $js );
		$this->assertSame( 1, substr_count( $js, 'l.search' ) );
		$this->assertSame( 1, substr_count( $js, 'd.referrer' ) );
		$this->assertSame( 1, substr_count( $js, '.get(' ) );
		// Exactly three body keys: p, r and u.
		preg_match_all( '/\bb\.([a-z]+)=|\{p:/', $js, $keys );
		$this->assertSame( array( '{p:', 'b.r=', 'b.u=' ), $keys[0] );
	}

	public function test_a_prerendered_page_is_counted_only_once_it_is_shown(): void {
		// Speculation Rules prerender (core 6.8, Performance Lab) runs the
		// page's scripts with nobody looking at it; most prerenders are never
		// shown. The beacon waits for the page to be activated.
		$js = PageViewScript::javascript();

		$this->assertStringContainsString(
			"if(d.prerendering){d.addEventListener('prerenderingchange',s,{once:true})}else{s()}",
			$js
		);
		// The beacon lives inside the deferred function, defined before the
		// prerendering check and never called inline ahead of it.
		$this->assertLessThan( strpos( $js, 'if(d.prerendering)' ), strpos( $js, 'sendBeacon(' ) );
		$this->assertStringContainsString( 'function s(){', $js );
	}

	public function test_the_script_is_byte_identical_to_the_symfony_bundles(): void {
		// Both producers must count the same visits the same way: this literal
		// is pinned, byte for byte, in the Symfony bundle's PageViewBeaconTest
		// too. Change the two together.
		$this->assertSame(
			"(function(){var n=navigator,d=document,l=location;function s(){try{var b={p:l.pathname},r,u;try{r=new URL(d.referrer).host}catch(e){}if(r&&r!==l.host)b.r=r;try{u=new URLSearchParams(l.search).get('utm_source')}catch(e){}if(u&&u.length<=100)b.u=u;n.sendBeacon('/.well-known/globetrotters/pv',new Blob([JSON.stringify(b)],{type:'text/plain'}))}catch(e){}}try{if(!n.sendBeacon)return;if(d.prerendering){d.addEventListener('prerenderingchange',s,{once:true})}else{s()}}catch(e){}})();",
			PageViewScript::javascript()
		);
	}

	public function test_the_endpoint_path_is_root_relative(): void {
		$this->assertStringStartsWith( '/', PageViewCounter::PATH );
		$this->assertStringStartsNotWith( '//', PageViewCounter::PATH );
	}

	public function test_a_front_end_page_gets_the_script(): void {
		$out = $this->render();

		$this->assertStringContainsString( '<script>', $out );
		$this->assertStringContainsString( PageViewCounter::PATH, $out );
	}

	/**
	 * @dataProvider skipped_context_provider
	 */
	public function test_no_script_where_a_view_is_not_a_visitor_reading_a_page( string $conditional ): void {
		Functions\when( $conditional )->justReturn( true );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function skipped_context_provider(): array {
		return array(
			'admin'      => array( 'is_admin' ),
			'feed'       => array( 'is_feed' ),
			'preview'    => array( 'is_preview' ),
			'customizer' => array( 'is_customize_preview' ),
			'404'        => array( 'is_404' ),
		);
	}

	public function test_editors_are_not_counted(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_posts' === $cap );

		$this->assertSame( '', $this->render() );
	}

	public function test_a_logged_in_subscriber_is_counted(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->assertStringContainsString( '<script>', $this->render() );
	}

	public function test_turning_counting_off_mid_request_prints_nothing(): void {
		$this->configure( false );

		$this->assertSame( '', $this->render() );
	}

	public function test_the_privacy_policy_paragraph_states_the_scope(): void {
		$text = PageViewScript::privacy_policy_text();

		foreach ( array( 'cookie', 'IP address', 'per page', 'per day', 'Globetrotters', 'AI assistant', 'referr' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $text );
		}
	}

	public function test_the_privacy_policy_paragraph_is_suggested_when_counting_is_on(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		$added = $this->capture_policy_content();

		$this->script()->add_privacy_policy_content();

		$this->assertCount( 1, $added->calls );
		$this->assertSame( PageViewScript::privacy_policy_text(), $added->calls[0][1] );
	}

	public function test_no_privacy_policy_paragraph_when_counting_is_off(): void {
		$this->configure( false );
		$added = $this->capture_policy_content();

		$this->script()->add_privacy_policy_content();

		$this->assertSame( array(), $added->calls );
	}

	/**
	 * Record calls to wp_add_privacy_policy_content().
	 */
	private function capture_policy_content(): object {
		$added        = new \stdClass();
		$added->calls = array();
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			function ( $name, $content ) use ( $added ) {
				$added->calls[] = array( $name, $content );
			}
		);
		return $added;
	}

	private function script(): PageViewScript {
		return new PageViewScript( new Options() );
	}

	private function render(): string {
		ob_start();
		$this->script()->print_script();
		return (string) ob_get_clean();
	}

	private function configure( bool $on ): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint'  => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'     => 'tok_live',
			'count_page_views' => $on,
		);
	}
}
