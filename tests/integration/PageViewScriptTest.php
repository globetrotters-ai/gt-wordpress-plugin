<?php
/**
 * Integration: the page-view beacon script against a live WordPress.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Integration;

use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Serving\PageViewScript;
use Globetrotters\AiPresence\Settings\Options;
use WP_UnitTestCase;

/**
 * The script is the one thing page-view counting adds to a customer's pages,
 * so this pins it against real core: hooked only when counting is on, printed
 * through core's inline-script helper, same-origin only, and skipped for the
 * site's own editors and for 404s.
 */
final class PageViewScriptTest extends WP_UnitTestCase {

	private ?PageViewScript $script = null;

	public function tear_down(): void {
		if ( null !== $this->script ) {
			remove_action( 'wp_footer', array( $this->script, 'print_script' ), 99 );
		}
		delete_option( Options::SETTINGS );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_counting_off_hooks_nothing(): void {
		$this->configure( false );
		$this->script = new PageViewScript( new Options() );

		$this->script->register();

		$this->assertFalse( has_action( 'wp_footer', array( $this->script, 'print_script' ) ) );
	}

	public function test_counting_on_hooks_an_invisible_same_origin_script(): void {
		$this->configure( true );
		$this->script = new PageViewScript( new Options() );
		$this->script->register();
		$this->go_to( home_url( '/' ) );

		$this->assertSame( 99, has_action( 'wp_footer', array( $this->script, 'print_script' ) ) );
		$html = $this->footer();

		$this->assertStringContainsString( '<script', $html );
		$this->assertStringContainsString( "'" . PageViewCounter::PATH . "'", $html );
		// Nothing visible, and nothing that leaves the site.
		$this->assertDoesNotMatchRegularExpression( '#https?:#i', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertLessThan( 1024, strlen( $html ) );
	}

	public function test_an_editor_browsing_the_site_is_not_counted(): void {
		$this->configure( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->go_to( home_url( '/' ) );

		$this->assertSame( '', $this->footer() );
	}

	public function test_a_404_is_not_counted(): void {
		$this->configure( true );
		$this->go_to( home_url( '/?p=987654321' ) );

		$this->assertTrue( is_404() );
		$this->assertSame( '', $this->footer() );
	}

	/**
	 * Output of this plugin's footer callback.
	 */
	private function footer(): string {
		ob_start();
		( new PageViewScript( new Options() ) )->print_script();
		return (string) ob_get_clean();
	}

	/**
	 * Configure reporting, with counting on or off.
	 *
	 * @param bool $on Whether page-view counting is switched on.
	 */
	private function configure( bool $on ): void {
		update_option(
			Options::SETTINGS,
			array(
				'ingest_endpoint'  => 'https://api.globetrotters.ai/presence/analytics/server-log',
				'ingest_token'     => 'tok_integration',
				'count_page_views' => $on,
			)
		);
	}
}
