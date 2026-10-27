<?php
/**
 * Admin notices for drift and refresh failures.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Admin;

use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Settings\SettingsPage;

/**
 * Surfaces two conditions to admins: a newer version is available (drift), or
 * the last refresh failed and the plugin is stale-serving.
 */
final class Notices {

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Register the notice hook.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Print whichever notice applies (drift takes precedence over error).
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->options->is_connected() ) {
			return;
		}

		$state     = $this->options->state();
		$installed = (string) $state['installed_version'];
		$latest    = (string) $state['latest_version'];
		$error     = (string) $state['last_error'];

		if ( '' !== $latest && '' !== $installed && $latest !== $installed ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: 1: latest version, 2: installed version. */
					__( 'Globetrotters AI Presence: a newer version (%1$s) is available — you are serving %2$s.', 'globetrotters-ai-presence' ),
					$latest,
					$installed
				)
			);
			return;
		}

		if ( '' !== $error ) {
			$this->notice(
				'error',
				sprintf(
					/* translators: %s: error detail. */
					__( 'Globetrotters AI Presence could not refresh and is serving the last known good version. %s', 'globetrotters-ai-presence' ),
					$error
				)
			);
		}
	}

	/**
	 * Render one dismissible notice with a "Refresh now" action.
	 *
	 * @param string $level   Notice level (warning|error|info|success).
	 * @param string $message Already-translated message.
	 */
	private function notice( string $level, string $message ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			esc_attr( $level ),
			esc_html( $message ),
			esc_url( SettingsPage::refresh_url() ),
			esc_html__( 'Refresh now', 'globetrotters-ai-presence' )
		);
	}
}
