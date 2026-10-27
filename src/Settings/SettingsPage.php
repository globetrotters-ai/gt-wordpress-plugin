<?php
/**
 * Admin settings + status page.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Settings;

use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Analytics\PageCacheExclusion;
use Globetrotters\AiPresence\Analytics\TrustedProxies;
use Globetrotters\AiPresence\Analytics\AiReferrers;
use Globetrotters\AiPresence\Analytics\CaptureContext;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Cache\RefreshScheduler;
use Globetrotters\AiPresence\Sync\ArtefactSync;
use Globetrotters\AiPresence\Update\UpdateChecker;

/**
 * Renders "Settings → AI Presence": connect the destination via its website
 * URL, pick the refresh cadence, see installed-vs-latest, and refresh on
 * demand.
 */
final class SettingsPage {

	public const MENU_SLUG    = 'gtaip-settings';
	public const OPTION_GROUP = 'gtaip';

	private const LATEST_TRANSIENT = 'gtaip_latest_check';

	/**
	 * Submitted in place of the stored ingest token when the admin has not
	 * retyped it. The token is shown exactly once by Studio and never echoed back
	 * here, so the form has to be able to round-trip without it.
	 */
	private const TOKEN_MASK = '••••••••';

	private Options $options;
	private ArtefactSync $sync;
	private RefreshScheduler $scheduler;
	private ArtefactCache $cache;
	private UpdateChecker $updates;
	private EventBuffer $buffer;
	private FlushScheduler $flushes;
	private ?VendorRanges $ranges;
	private ?AiReferrers $referrers;
	private ?PageCacheExclusion $exclusion;
	private ?CaptureContext $context;

	/**
	 * What {@see sanitize()} last returned in this request. See there.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $sanitized = null;

	public function __construct(
		Options $options,
		ArtefactSync $sync,
		RefreshScheduler $scheduler,
		ArtefactCache $cache,
		UpdateChecker $updates,
		EventBuffer $buffer,
		FlushScheduler $flushes,
		?VendorRanges $ranges = null,
		?AiReferrers $referrers = null,
		?PageCacheExclusion $exclusion = null,
		?CaptureContext $context = null
	) {
		$this->options   = $options;
		$this->sync      = $sync;
		$this->scheduler = $scheduler;
		$this->cache     = $cache;
		$this->updates   = $updates;
		$this->buffer    = $buffer;
		$this->flushes   = $flushes;
		$this->ranges    = $ranges;
		$this->referrers = $referrers;
		$this->exclusion = $exclusion;
		$this->context   = $context;
	}

	/**
	 * Register the menu, settings, and action handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_refresh' ) );
		add_action( 'admin_init', array( $this, 'handle_check_update' ) );
		add_action( 'update_option_' . Options::SETTINGS, array( $this, 'on_settings_saved' ) );
	}

	/**
	 * URL of the settings page.
	 */
	public static function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Nonced URL that triggers an on-demand refresh.
	 */
	public static function refresh_url(): string {
		return wp_nonce_url(
			add_query_arg( 'gtaip_action', 'refresh', self::page_url() ),
			'gtaip_refresh',
			'gtaip_nonce'
		);
	}

	/**
	 * Nonced URL that busts the update cache and re-checks for a plugin update.
	 */
	public static function check_update_url(): string {
		return wp_nonce_url(
			add_query_arg( 'gtaip_action', 'check_update', self::page_url() ),
			'gtaip_check_update',
			'gtaip_nonce'
		);
	}

	/**
	 * Add the options page under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Globetrotters AI Presence', 'globetrotters-ai-presence' ),
			__( 'AI Presence', 'globetrotters-ai-presence' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the setting + sanitizer.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Options::SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		// On the first save core sanitizes twice: update_option() runs this, finds
		// no stored option and hands the result to add_option(), which runs it
		// again. The section markers are form fields, not settings, so that second
		// pass would read every section-gated checkbox as unticked and the proxy
		// list as empty. A value this method has just produced is already clean.
		if ( null !== $this->sanitized && $input === $this->sanitized ) {
			return $this->sanitized;
		}

		$out = $this->options->settings();

		if ( is_array( $input ) ) {
			if ( isset( $input['website_url'] ) ) {
				$website_url = esc_url_raw( trim( (string) wp_unslash( $input['website_url'] ) ) );
				// What is pulled from here is published at this site's apex, so a
				// cleartext source is refused, and the stored value — a working
				// source, or none — stays rather than being replaced. A scheme-less
				// entry is refused too: esc_url_raw() reads it as http://.
				if ( '' === $website_url || Options::is_https_url( $website_url ) ) {
					$out['website_url'] = $website_url;
				} else {
					add_settings_error(
						Options::SETTINGS,
						'gtaip_website_url_https',
						'' === (string) $out['website_url']
							? __( 'The Globetrotters website URL must be an https:// URL. It was not saved.', 'globetrotters-ai-presence' )
							: __( 'The Globetrotters website URL must be an https:// URL. The previous URL was kept.', 'globetrotters-ai-presence' )
					);
				}
			}
			if ( isset( $input['profile'] ) ) {
				// Guarded against a non-scalar submission (``profile[]=``), which
				// would reach sanitize_key() as an array and fatal on strtolower().
				$profile        = is_scalar( $input['profile'] )
					? sanitize_key( (string) wp_unslash( $input['profile'] ) )
					: '';
				$out['profile'] = in_array( $profile, Options::profiles(), true )
					? $profile
					: Options::PROFILE_FULL_APEX;
			}
			if ( isset( $input['robots_ai_agents'] ) ) {
				// Same non-scalar guard as ``profile``. Anything unrecognised
				// falls back to the mode that changes nothing a crawler may fetch.
				$agents                  = is_scalar( $input['robots_ai_agents'] )
					? sanitize_key( (string) wp_unslash( $input['robots_ai_agents'] ) )
					: '';
				$out['robots_ai_agents'] = in_array( $agents, RobotsOptions::agent_modes(), true )
					? $agents
					: RobotsOptions::INHERIT;
			}
			// A checkbox, so absence means "off" — but only when the robots
			// section was on screen, which its own hidden marker records.
			if ( isset( $input['robots_section'] ) ) {
				$out['robots_ai_train'] = self::is_ticked( $input, 'robots_ai_train' );
			}
			if ( isset( $input['refresh_interval'] ) ) {
				$interval                = sanitize_key( wp_unslash( $input['refresh_interval'] ) );
				$allowed                 = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
				$out['refresh_interval'] = in_array( $interval, $allowed, true ) ? $interval : 'daily';
			}
			if ( isset( $input['ingest_endpoint'] ) ) {
				$endpoint = esc_url_raw( trim( (string) wp_unslash( $input['ingest_endpoint'] ) ) );
				// The endpoint carries the bearer token and every captured client
				// IP. A cleartext one is refused, and the stored value — a working
				// endpoint, or none — stays rather than being replaced by a typo.
				if ( '' === $endpoint || Options::is_https_url( $endpoint ) ) {
					$out['ingest_endpoint'] = $endpoint;
				} else {
					add_settings_error(
						Options::SETTINGS,
						'gtaip_ingest_endpoint_https',
						__( 'The ingest endpoint must be an https:// URL. The previous endpoint was kept.', 'globetrotters-ai-presence' )
					);
				}
			}
			if ( isset( $input['ingest_token'] ) ) {
				$out['ingest_token'] = $this->sanitize_token( (string) wp_unslash( $input['ingest_token'] ) );
			}
			// An unchecked checkbox submits nothing, so absence means "off" — but
			// only when the reporting section was actually on screen, which the
			// hidden companion field records.
			if ( isset( $input['reporting_section'] ) ) {
				$out['trusted_proxy_cloudflare'] = self::is_ticked( $input, 'trusted_proxy_cloudflare' );
				$out['trusted_proxies']          = self::sanitize_proxies( $input['trusted_proxies'] ?? '' );
				// Adds a script to every front-end page, so only the explicit tick.
				$out['count_page_views'] = self::is_ticked( $input, 'count_page_views' );
				// On by default, so only an explicit untick on a submitted
				// reporting section turns it off.
				$out['capture_site_bots'] = self::is_ticked( $input, 'capture_site_bots' );
				// Changes another plugin's configuration, so only the explicit tick.
				$out['exclude_ai_from_page_cache'] = self::is_ticked( $input, 'exclude_ai_from_page_cache' );
				// 0.4.0's single checkbox, superseded by the two fields above. Its
				// removal is what tells Options the preset is now explicit.
				unset( $out['trusted_proxy'] );
			}
		}

		$this->sanitized = $out;
		return $out;
	}

	/**
	 * Normalize a submitted proxy list to one valid entry per line.
	 *
	 * Entries that are not an IP address or a CIDR range are dropped with a
	 * notice rather than stored: a hostname or a typo would read as configured
	 * while trusting nobody.
	 *
	 * @param mixed $submitted Raw submitted field.
	 */
	private static function sanitize_proxies( $submitted ): string {
		if ( ! is_scalar( $submitted ) ) {
			return '';
		}

		$parsed = TrustedProxies::parse( (string) wp_unslash( $submitted ) );
		if ( $parsed['invalid'] > 0 ) {
			add_settings_error(
				Options::SETTINGS,
				'gtaip_trusted_proxies',
				sprintf(
					/* translators: %d: number of ignored entries. */
					__( 'Ignored %d trusted proxy entries that are not an IP address or a CIDR range.', 'globetrotters-ai-presence' ),
					$parsed['invalid']
				)
			);
		}

		return implode( "\n", $parsed['valid'] );
	}

	/**
	 * Resolve a submitted ingest token against the stored one.
	 *
	 * An empty field or the mask means "leave it alone": the form never renders
	 * the real value, so a plain re-save must not wipe the credential. Anything
	 * else replaces it.
	 *
	 * @param string $submitted Raw submitted token field.
	 */
	private function sanitize_token( string $submitted ): string {
		$submitted = trim( $submitted );
		if ( '' === $submitted || self::TOKEN_MASK === $submitted ) {
			return $this->options->ingest_token();
		}
		return sanitize_text_field( $submitted );
	}

	/**
	 * Whether a submitted checkbox is ticked.
	 *
	 * Only ``"1"``, the value this form's checkboxes submit, counts. Both are
	 * site-wide grants — trusting proxy headers for the client IP, and signalling
	 * that content may train AI models — so a crafted ``"false"`` or ``"no"``
	 * must not read as one. {@see Options} is looser on read: it parses the
	 * stored value with ``FILTER_VALIDATE_BOOLEAN``, so a ``"yes"`` or ``"on"``
	 * written by ``wp option update`` still counts. It holds the guarantee that
	 * matters, though — ``"false"`` or ``"no"`` never enables either grant.
	 *
	 * @param array<string, mixed> $input Raw submitted values.
	 * @param string               $key   Checkbox field name.
	 */
	private static function is_ticked( array $input, string $key ): bool {
		return isset( $input[ $key ] )
			&& is_scalar( $input[ $key ] )
			&& '1' === (string) wp_unslash( $input[ $key ] );
	}

	/**
	 * After settings save: re-arm the refresh poll and queue an immediate run of
	 * it, which pulls when a refresh is due — always, after a new website URL,
	 * whose state the save has reset. "Refresh now" pulls unconditionally.
	 *
	 * The pull is queued (not run inline) so a slow or unreachable Globetrotters
	 * host can't hang the ``options.php`` save with up to a minute of blocking
	 * HTTP. Cron picks it up on the next request.
	 */
	public function on_settings_saved(): void {
		delete_transient( self::LATEST_TRANSIENT );
		// reschedule() re-arms cron with an immediate first run, which queues the
		// pull off-request — no separate (dedup-prone) single event needed.
		$this->scheduler->reschedule();
		// Arm or tear down the flush event to match the credentials just saved.
		$this->flushes->reschedule();
	}

	/**
	 * Handle the nonced "Refresh now" action.
	 */
	public function handle_refresh(): void {
		if ( ! isset( $_GET['gtaip_action'] ) || 'refresh' !== $_GET['gtaip_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'gtaip_refresh', 'gtaip_nonce' );

		$result = $this->sync->run();
		// Prime the latest-version cache from the run we just did, so the status
		// page doesn't immediately re-fetch the same marker to render.
		if ( $result->is_success() ) {
			set_transient( self::LATEST_TRANSIENT, $result->version(), 15 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( self::LATEST_TRANSIENT );
		}

		if ( $result->is_success() ) {
			$flag = '1';
		} else {
			$flag = $result->is_busy() ? 'busy' : '0';
		}
		wp_safe_redirect( add_query_arg( 'gtaip_refreshed', $flag, self::page_url() ) );
		exit;
	}

	/**
	 * Handle the nonced "Check for updates now" action.
	 *
	 * Busts the cached update metadata and forces WordPress to re-run its plugin
	 * update check, then redirects back with a flash.
	 */
	public function handle_check_update(): void {
		if ( ! isset( $_GET['gtaip_action'] ) || 'check_update' !== $_GET['gtaip_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'gtaip_check_update', 'gtaip_nonce' );

		// Bust the cache, then re-fetch inline so the page shows the fresh result
		// immediately. Blocking HTTP is acceptable here — this is an explicit user
		// action, exactly like the "Refresh now" pull above. Because the user asked,
		// surface a reachability failure rather than degrading silently.
		$this->updates->flush();
		$ok = null !== $this->updates->metadata();

		wp_safe_redirect( add_query_arg( 'gtaip_update_checked', $ok ? '1' : '0', self::page_url() ) );
		exit;
	}

	/**
	 * The latest advertised version, cached briefly to avoid per-load fetches.
	 */
	private function latest_version(): string {
		$cached = get_transient( self::LATEST_TRANSIENT );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		$latest = $this->sync->check_latest();
		set_transient( self::LATEST_TRANSIENT, $latest, 15 * MINUTE_IN_SECONDS );
		$this->options->update_state( array( 'latest_version' => $latest ) );
		return $latest;
	}

	/**
	 * Render the settings + status page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->options->reload_runtime_state();
		$settings        = $this->options->settings();
		$state           = $this->options->state();
		$stamp           = ArtefactCache::pull_stamp();
		$served          = ArtefactCache::served();
		$connected       = $this->options->is_connected();
		$proxies         = $this->options->trusted_proxies();
		$installed       = null !== $stamp ? (string) $stamp['version'] : (string) $state['installed_version'];
		$latest          = $connected ? $this->latest_version() : '';
		$base_url        = $this->options->base_url();
		$presence_origin = $this->options->presence_origin();
		// The *validated* profile, not the raw setting: an out-of-range stored
		// value (a `wp option update` of `cdn_only`, say) matches no <option>, and
		// the browser would then preselect the first one — showing a profile the
		// site is not running, which the next save would make true.
		$profile = $this->options->profile();
		// Validated for the same reason: the select must show the mode the
		// robots.txt block is actually running.
		$robots         = $this->options->robots();
		$robots_agents  = $robots->allows_all() ? RobotsOptions::ALLOW_ALL : RobotsOptions::INHERIT;
		$plugin_version = \Globetrotters\AiPresence\VERSION;
		// Cached-only read: rendering the page must not trigger a blocking HTTP
		// fetch. WordPress's periodic update check (and the "Check for updates now"
		// action below) warm the cache off the render path.
		$plugin_latest    = $this->updates->cached_latest_version();
		$update_available = '' !== $plugin_latest && version_compare( $plugin_version, $plugin_latest, '<' );
		// The stored token is never rendered. A saved credential shows as a mask,
		// which the sanitizer reads as "keep what you have".
		$token_field = '' !== $this->options->ingest_token() ? self::TOKEN_MASK : '';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Globetrotters AI Presence', 'globetrotters-ai-presence' ); ?></h1>

			<?php $this->render_flash(); ?>

			<h2><?php esc_html_e( 'Status', 'globetrotters-ai-presence' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<?php
							echo $connected
								? esc_html( $base_url )
								: esc_html__( 'Not connected — enter your Globetrotters website URL below.', 'globetrotters-ai-presence' );
							?>
						</td>
					</tr>
					<?php if ( $this->options->emits_breadcrumbs() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'AI presence subdomain', 'globetrotters-ai-presence' ); ?></th>
							<td>
								<?php if ( '' !== $presence_origin ) : ?>
									<a href="<?php echo esc_url( $presence_origin ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $presence_origin ); ?>
									</a>
									<p class="description" style="margin:4px 0 0">
										<?php esc_html_e( 'Detected from your presence content, and re-checked on every refresh — you never have to update it here.', 'globetrotters-ai-presence' ); ?>
									</p>
								<?php else : ?>
									<?php esc_html_e( 'Not detected yet — no pointers are being added to your pages.', 'globetrotters-ai-presence' ); ?>
									<p class="description" style="margin:4px 0 0">
										<?php esc_html_e( 'It is read from your presence content, so it appears after the next successful refresh.', 'globetrotters-ai-presence' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content version', 'globetrotters-ai-presence' ); ?></th>
						<td><?php echo esc_html( '' !== $installed ? $installed : '—' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Latest content version', 'globetrotters-ai-presence' ); ?></th>
						<td><?php echo esc_html( '' !== $latest ? $latest : __( 'unknown', 'globetrotters-ai-presence' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last refresh', 'globetrotters-ai-presence' ); ?></th>
						<td><?php echo esc_html( $this->format_time( (int) $state['last_refresh'] ) ); ?></td>
					</tr>
					<?php if ( null !== $stamp ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last pulled by', 'globetrotters-ai-presence' ); ?></th>
							<td><?php echo esc_html( $this->describe_puller( $stamp ) ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( '' !== $installed ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Served to visitors', 'globetrotters-ai-presence' ); ?></th>
							<td>
								<?php
								echo esc_html(
									null !== $served
										? sprintf(
											/* translators: 1: content version, 2: human-readable time span, e.g. "5 mins". */
											__( '%1$s, seen %2$s ago', 'globetrotters-ai-presence' ),
											$served['version'],
											human_time_diff( $served['at'] )
										)
										: __( 'Not seen yet — it appears once a visitor or an AI agent loads a page that uses it.', 'globetrotters-ai-presence' )
								);
								?>
								<?php if ( self::serves_an_older_pull( $stamp, $served ) ) : ?>
									<p class="description" style="margin:4px 0 0;color:#b32d2e">
										<?php esc_html_e( 'Your web server is still serving an older version than the last pull. Cron and the web server are not reading the same database: check that your system cron runs this site\'s WordPress install, on this database.', 'globetrotters-ai-presence' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( '' !== (string) $state['last_error'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last error', 'globetrotters-ai-presence' ); ?></th>
							<td><?php echo esc_html( (string) $state['last_error'] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<?php if ( $connected ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( self::refresh_url() ); ?>">
						<?php esc_html_e( 'Refresh now', 'globetrotters-ai-presence' ); ?>
					</a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( $this->options->studio_url() ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open Studio', 'globetrotters-ai-presence' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Plugin updates', 'globetrotters-ai-presence' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The plugin itself updates automatically from Globetrotters — this is separate from the presence content above.', 'globetrotters-ai-presence' ); ?>
			</p>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugin version', 'globetrotters-ai-presence' ); ?></th>
						<td><?php echo esc_html( $plugin_version ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Latest plugin version', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<?php
							echo esc_html( '' !== $plugin_latest ? $plugin_latest : __( 'unknown', 'globetrotters-ai-presence' ) );
							if ( $update_available ) {
								echo ' <strong>' . esc_html__( '(update available)', 'globetrotters-ai-presence' ) . '</strong>';
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( self::check_update_url() ); ?>">
					<?php esc_html_e( 'Check for updates now', 'globetrotters-ai-presence' ); ?>
				</a>
				<?php if ( $update_available ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
						<?php esc_html_e( 'Go to Plugins to update', 'globetrotters-ai-presence' ); ?>
					</a>
				<?php endif; ?>
			</p>

			<?php $this->render_reporting_status(); ?>

			<h2><?php esc_html_e( 'Settings', 'globetrotters-ai-presence' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="gtaip_website_url"><?php esc_html_e( 'Globetrotters website URL', 'globetrotters-ai-presence' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[website_url]" id="gtaip_website_url" type="url"
								class="regular-text" value="<?php echo esc_attr( (string) $settings['website_url'] ); ?>"
								placeholder="https://acme.globetrotters.ai" />
							<p class="description"><?php esc_html_e( "The URL of your organisation's Globetrotters website.", 'globetrotters-ai-presence' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gtaip_profile"><?php esc_html_e( 'Install profile', 'globetrotters-ai-presence' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( Options::SETTINGS ); ?>[profile]" id="gtaip_profile">
								<?php foreach ( $this->profile_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $profile, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description" style="max-width:640px">
								<?php esc_html_e( 'Globetrotters also publishes your full presence on its own subdomain, but nothing on the web points at it, so AI assistants reach it only if they already know the address. "Apex and subdomain" is the recommended setup: this site keeps serving the discovery files exactly as it does now, and additionally points at that subdomain from your pages. Nothing visible is added — the pointers are invisible tags in the page head, and your design is untouched. Choose "Apex only" to add nothing at all.', 'globetrotters-ai-presence' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gtaip_refresh"><?php esc_html_e( 'Refresh cadence', 'globetrotters-ai-presence' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( Options::SETTINGS ); ?>[refresh_interval]" id="gtaip_refresh">
								<?php foreach ( $this->cadence_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['refresh_interval'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'AI crawlers in robots.txt', 'globetrotters-ai-presence' ); ?></h3>
				<p class="description" style="max-width:640px">
					<?php esc_html_e( 'The plugin lists the AI crawlers Globetrotters tracks in your robots.txt. By default this changes nothing any crawler may access: a crawler your robots.txt already names keeps the rules you gave it, and every other one follows the same rules as all other crawlers on your site. The two options below go further, and each is a decision about your whole site, not only your AI presence.', 'globetrotters-ai-presence' ); ?>
				</p>
				<input type="hidden" name="<?php echo esc_attr( Options::SETTINGS ); ?>[robots_section]" value="1" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="gtaip_robots_ai_agents"><?php esc_html_e( 'AI crawler access', 'globetrotters-ai-presence' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( Options::SETTINGS ); ?>[robots_ai_agents]" id="gtaip_robots_ai_agents">
								<?php foreach ( $this->robots_agent_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $robots_agents, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description" style="max-width:640px">
								<?php esc_html_e( '"Allow everything" gives each listed AI crawler access to your whole site, including the paths your robots.txt closes to other crawlers, such as /wp-admin/. A crawler your robots.txt names itself always keeps your rules.', 'globetrotters-ai-presence' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI training', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<label for="gtaip_robots_ai_train">
								<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[robots_ai_train]" id="gtaip_robots_ai_train"
									type="checkbox" value="1" <?php checked( $robots->ai_train() ); ?> />
								<?php esc_html_e( 'Tell AI crawlers my content may be used to train AI models', 'globetrotters-ai-presence' ); ?>
							</label>
							<p class="description" style="max-width:640px">
								<?php esc_html_e( 'Adds ai-train=yes to the Content-Signal line. Off by default: the plugin states that your content may be used for search and for answering questions, and says nothing about training on your behalf. If your robots.txt already carries its own Content-Signal line, that one is used instead.', 'globetrotters-ai-presence' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Reporting credentials', 'globetrotters-ai-presence' ); ?></h3>
				<p class="description" style="max-width:640px">
					<?php esc_html_e( 'Requests to the AI discovery files land on this site and never reach Globetrotters, so nothing shows up in your analytics until this plugin reports them. In Studio, open Content for AI, then the Apex install panel, and use "Issue ingest token" on the "Report agent traffic from your server" step — the token is shown exactly once. Paste it here, with the endpoint URL shown alongside it.', 'globetrotters-ai-presence' ); ?>
				</p>
				<input type="hidden" name="<?php echo esc_attr( Options::SETTINGS ); ?>[reporting_section]" value="1" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="gtaip_ingest_endpoint"><?php esc_html_e( 'Ingest endpoint URL', 'globetrotters-ai-presence' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[ingest_endpoint]" id="gtaip_ingest_endpoint" type="url"
								class="regular-text" value="<?php echo esc_attr( (string) $settings['ingest_endpoint'] ); ?>"
								placeholder="https://api.globetrotters.ai/presence/analytics/server-log" />
							<p class="description"><?php esc_html_e( 'Studio shows this next to the token when you issue it.', 'globetrotters-ai-presence' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gtaip_ingest_token"><?php esc_html_e( 'Ingest token', 'globetrotters-ai-presence' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[ingest_token]" id="gtaip_ingest_token" type="password"
								class="regular-text" autocomplete="off" spellcheck="false"
								value="<?php echo esc_attr( $token_field ); ?>" />
							<p class="description">
								<?php
								echo esc_html(
									'' !== $this->options->ingest_token()
										? __( 'A token is saved. Leave this as-is to keep it, or paste a new one to replace it.', 'globetrotters-ai-presence' )
										: __( 'Paste the token from Studio. It is stored on this site and sent only to the endpoint above.', 'globetrotters-ai-presence' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Trusted proxies', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<fieldset>
								<label for="gtaip_trusted_proxy_cloudflare">
									<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[trusted_proxy_cloudflare]" id="gtaip_trusted_proxy_cloudflare"
										type="checkbox" value="1" <?php checked( $proxies->trusts_cloudflare() ); ?> />
									<?php esc_html_e( 'This site sits behind Cloudflare', 'globetrotters-ai-presence' ); ?>
								</label>
								<p class="description" style="max-width:640px">
									<?php esc_html_e( 'Trusts requests arriving from Cloudflare\'s published addresses, and reads the visitor IP from the CF-Connecting-IP header Cloudflare sets.', 'globetrotters-ai-presence' ); ?>
								</p>
								<p style="margin:12px 0 4px">
									<label for="gtaip_trusted_proxies"><?php esc_html_e( 'Other proxies or load balancers in front of this site', 'globetrotters-ai-presence' ); ?></label>
								</p>
								<textarea name="<?php echo esc_attr( Options::SETTINGS ); ?>[trusted_proxies]" id="gtaip_trusted_proxies"
									class="large-text code" rows="3" spellcheck="false" style="max-width:640px"
									placeholder="10.0.0.0/8"><?php echo esc_textarea( implode( "\n", $proxies->entries() ) ); ?></textarea>
								<p class="description" style="max-width:640px">
									<?php esc_html_e( 'IP addresses or CIDR ranges, one per line. The visitor IP is read from X-Forwarded-For only on requests that arrive from one of these, and never from an entry a client could have written itself. Any other request is recorded with its connecting address: a request that did not come through your proxy can carry forged headers, which would let anyone pass for a known AI crawler.', 'globetrotters-ai-presence' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI agents on your pages', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<label for="gtaip_capture_site_bots">
								<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[capture_site_bots]" id="gtaip_capture_site_bots"
									type="checkbox" value="1" <?php checked( true === filter_var( $settings['capture_site_bots'], FILTER_VALIDATE_BOOLEAN ) ); ?> />
								<?php esc_html_e( 'Report AI agent and bot visits to your pages', 'globetrotters-ai-presence' ); ?>
							</label>
							<p class="description" style="max-width:640px">
								<?php esc_html_e( 'When a crawler or AI agent fetches one of your pages, the plugin sends its user agent, the page path (without query string), the response status and whether the visit came from a verified AI vendor address. It never sends an IP address, and never anything about human visitors.', 'globetrotters-ai-presence' ); ?>
							</p>
							<p style="margin:12px 0 4px">
								<label for="gtaip_exclude_ai_from_page_cache">
									<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[exclude_ai_from_page_cache]" id="gtaip_exclude_ai_from_page_cache"
										type="checkbox" value="1" <?php checked( true === filter_var( $settings['exclude_ai_from_page_cache'], FILTER_VALIDATE_BOOLEAN ) ); ?> />
									<?php esc_html_e( 'Never serve cached pages to AI agents', 'globetrotters-ai-presence' ); ?>
								</label>
							</p>
							<p class="description" style="max-width:640px">
								<?php esc_html_e( 'A page cache answers cached pages before the plugin runs, so the AI agents it serves are never counted. This adds the AI assistants\' live-answer and AI-search agents (never training crawlers or search engines) to your page-cache plugin\'s "never cache" user agents. The trade-off: those agents get uncached pages, a small extra load on your server. Switching it off, or deactivating this plugin, removes exactly what it added. A CDN in front of your site is out of its reach.', 'globetrotters-ai-presence' ); ?>
							</p>
							<?php $this->render_page_cache_exclusion_status(); ?>
							<?php if ( ! $this->options->is_reporting_configured() ) : ?>
								<p class="description" style="max-width:640px">
									<?php esc_html_e( 'Takes effect once the ingest endpoint and token above are saved.', 'globetrotters-ai-presence' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page views', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<label for="gtaip_count_page_views">
								<input name="<?php echo esc_attr( Options::SETTINGS ); ?>[count_page_views]" id="gtaip_count_page_views"
									type="checkbox" value="1" <?php checked( true === filter_var( $settings['count_page_views'], FILTER_VALIDATE_BOOLEAN ) ); ?> />
								<?php esc_html_e( 'Count human page views on this site', 'globetrotters-ai-presence' ); ?>
							</label>
							<p class="description" style="max-width:640px">
								<?php esc_html_e( 'Adds a tiny script to your pages that posts the page path to this site (never to Globetrotters). The plugin keeps a per-page daily count and sends only those counts. No IP address or cookie is read or stored, and the browser\'s user-agent is checked only to leave out bots, never stored.', 'globetrotters-ai-presence' ); ?>
							</p>
							<?php if ( ! $this->options->is_reporting_configured() ) : ?>
								<p class="description" style="max-width:640px">
									<?php esc_html_e( 'Takes effect once the ingest endpoint and token above are saved.', 'globetrotters-ai-presence' ); ?>
								</p>
							<?php elseif ( $this->options->counts_page_views() ) : ?>
								<p class="description" style="max-width:640px">
									<?php esc_html_e( 'A suggested paragraph for your privacy policy is under Settings → Privacy → Policy Guide.', 'globetrotters-ai-presence' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the agent-traffic reporting status block.
	 *
	 * This block is load-bearing: the ingest endpoint answers ``202`` for a good
	 * batch *and* for a bad token, an unknown install or a malformed body, so a
	 * customer who pasted a stale token gets no error anywhere. The most this
	 * screen can honestly say is "flushes are being accepted" — which is why
	 * "configured but never confirmed" is rendered as a visibly different state
	 * from "reporting normally", and why the copy points at Studio for the only
	 * real confirmation.
	 */
	private function render_reporting_status(): void {
		$state      = $this->options->flush_state();
		$status     = $this->reporting_status();
		$configured = $this->options->is_reporting_configured();
		$buffered   = $configured ? $this->buffer->count() : 0;
		$dropped    = $configured ? $this->buffer->dropped_total() : 0;
		?>
		<h2><?php esc_html_e( 'Agent traffic reporting', 'globetrotters-ai-presence' ); ?></h2>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'globetrotters-ai-presence' ); ?></th>
					<td>
						<strong style="color:<?php echo esc_attr( $status['colour'] ); ?>">
							<?php echo esc_html( $status['label'] ); ?>
						</strong>
						<?php if ( '' !== $status['detail'] ) : ?>
							<p class="description" style="margin:4px 0 0"><?php echo esc_html( $status['detail'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $configured ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last accepted flush', 'globetrotters-ai-presence' ); ?></th>
						<td><?php echo esc_html( $this->format_time( (int) $state['last_flush_ok'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Waiting to be sent', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: %d: number of buffered events. */
								esc_html( _n( '%d request', '%d requests', $buffered, 'globetrotters-ai-presence' ) ),
								(int) $buffered
							);
							?>
						</td>
					</tr>
					<?php if ( null !== $this->ranges ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'AI vendor address list', 'globetrotters-ai-presence' ); ?></th>
							<td>
								<?php echo esc_html( $this->ranges_age() ); ?>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( null !== $this->referrers && $this->options->counts_page_views() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'AI referrer list', 'globetrotters-ai-presence' ); ?></th>
							<td>
								<?php echo esc_html( $this->referrers_age() ); ?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dropped (buffer full)', 'globetrotters-ai-presence' ); ?></th>
						<td>
							<?php echo esc_html( (string) $dropped ); ?>
							<?php if ( $dropped > 0 ) : ?>
								<p class="description" style="margin:4px 0 0">
									<?php esc_html_e( 'Reported to Globetrotters so the gap is visible in your numbers rather than looking like quiet traffic.', 'globetrotters-ai-presence' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Under the exclusion checkbox: which page cache it applies to, whether it
	 * is in force there, or why it cannot be.
	 */
	private function render_page_cache_exclusion_status(): void {
		if ( null === $this->exclusion || null === $this->context ) {
			return;
		}
		$page_cache = $this->context->page_cache();
		$name       = null === $page_cache ? '' : ( PageCacheExclusion::LABELS[ $page_cache ] ?? $page_cache );
		$ticked     = true === filter_var( $this->options->get( 'exclude_ai_from_page_cache' ), FILTER_VALIDATE_BOOLEAN );
		if ( null === $page_cache ) {
			$message = __( 'No page-cache plugin detected: nothing to exclude.', 'globetrotters-ai-presence' );
		} elseif ( ! in_array( $page_cache, PageCacheExclusion::SUPPORTED, true ) ) {
			/* translators: %s: page-cache plugin name, e.g. WP Super Cache. */
			$message = sprintf( __( 'Detected page cache: %s. It cannot stop cached pages being served to chosen user agents, so this setting has no effect with it. Supported: WP Rocket, W3 Total Cache, LiteSpeed Cache.', 'globetrotters-ai-presence' ), $name );
		} elseif ( $ticked && ! $this->options->captures_site_bots() ) {
			/* translators: %s: page-cache plugin name. */
			$message = sprintf( __( 'Detected page cache: %s. Takes effect while AI agent and bot visits are reported (above).', 'globetrotters-ai-presence' ), $name );
		} elseif ( $this->exclusion->is_active( $page_cache ) ) {
			/* translators: 1: page-cache plugin name, 2: number of user agents. */
			$message = sprintf( __( 'Active in %1$s: %2$d AI user agents are never served a cached page.', 'globetrotters-ai-presence' ), $name, count( $this->exclusion->applied()['wanted'] ) );
		} elseif ( $ticked && $page_cache === $this->exclusion->applied()['cache'] ) {
			/* translators: %s: page-cache plugin name. */
			$message = sprintf( __( 'Added to %1$s, but its rewrite rules could not be updated yet, so cached pages may still reach AI agents. Retried every 15 minutes; saving %2$s\'s own settings also updates them.', 'globetrotters-ai-presence' ), $name, $name );
		} elseif ( $ticked && array() === $this->exclusion->user_agents() ) {
			/* translators: %s: page-cache plugin name. */
			$message = sprintf( __( 'Detected page cache: %s. Waiting for the AI user-agent list, downloaded with the AI vendor address list.', 'globetrotters-ai-presence' ), $name );
		} else {
			/* translators: %s: page-cache plugin name. */
			$message = sprintf( __( 'Detected page cache: %s.', 'globetrotters-ai-presence' ), $name );
		}
		?>
		<p class="description" style="max-width:640px"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	/**
	 * How old the cached vendor range list is, in words.
	 */
	private function ranges_age(): string {
		$fetched = null === $this->ranges ? 0 : $this->ranges->fetched_at();
		if ( 0 === $fetched ) {
			return __( 'Not downloaded yet. Visits are reported without verification until it is.', 'globetrotters-ai-presence' );
		}
		/* translators: %s: human-readable time difference, e.g. "3 hours". */
		$age = sprintf( __( 'Updated %s ago.', 'globetrotters-ai-presence' ), human_time_diff( $fetched, time() ) );
		if ( ! $this->ranges->is_fresh() ) {
			$age .= ' ' . __( 'Older than 14 days, so visits are reported without verification until it refreshes.', 'globetrotters-ai-presence' );
		}
		return $age;
	}

	/**
	 * How old the cached AI referrer list is, in words.
	 *
	 * Shown only while page views are counted: the list does nothing else.
	 */
	private function referrers_age(): string {
		$stored = null === $this->referrers ? array() : $this->referrers->stored();
		if ( array() === $stored || 0 === $stored['fetched_at'] ) {
			return __( 'Not downloaded yet. Page views are counted, but none is attributed to an AI assistant until it is.', 'globetrotters-ai-presence' );
		}
		/* translators: %s: human-readable time difference, e.g. "3 hours". */
		$age = sprintf( __( 'Updated %s ago.', 'globetrotters-ai-presence' ), human_time_diff( $stored['fetched_at'], time() ) );
		if ( time() - $stored['fetched_at'] > AiReferrers::STALE_AFTER_SECONDS ) {
			$age .= ' ' . __( 'Older than 14 days, so page views are not attributed to an AI assistant until it refreshes.', 'globetrotters-ai-presence' );
		}
		return $age;
	}

	/**
	 * The current reporting state as a label, colour and detail line.
	 *
	 * @return array{label:string, colour:string, detail:string}
	 */
	private function reporting_status(): array {
		if ( ! $this->options->is_reporting_configured() ) {
			return array(
				'label'  => __( 'Not reporting', 'globetrotters-ai-presence' ),
				'colour' => '#646970',
				'detail' => __( 'Add an ingest endpoint and token below to start reporting AI-agent requests to Globetrotters.', 'globetrotters-ai-presence' ),
			);
		}

		$state = $this->options->flush_state();
		$error = (string) $state['last_flush_error'];
		if ( '' !== $error ) {
			return array(
				'label'  => __( 'Not sending', 'globetrotters-ai-presence' ),
				'colour' => '#d63638',
				/* translators: %s: transport or HTTP error detail. */
				'detail' => sprintf( __( 'The last attempt failed (%s). Requests stay buffered and are retried — nothing is lost yet.', 'globetrotters-ai-presence' ), $error ),
			);
		}

		if ( 0 === (int) $state['last_flush_ok'] ) {
			return array(
				'label'  => __( 'Configured — nothing sent yet', 'globetrotters-ai-presence' ),
				'colour' => '#dba617',
				'detail' => __( 'Waiting for the first batch. Reporting runs every 15 minutes and only when the AI files are actually being requested.', 'globetrotters-ai-presence' ),
			);
		}

		return array(
			'label'  => __( 'Sending', 'globetrotters-ai-presence' ),
			'colour' => '#00a32a',
			'detail' => __( 'Batches are being accepted. Acceptance does not by itself prove the token is valid — check that the numbers are appearing in Studio.', 'globetrotters-ai-presence' ),
		);
	}

	/**
	 * Render the post-refresh flash message.
	 */
	private function render_flash(): void {
		if ( isset( $_GET['gtaip_update_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok = '1' === $_GET['gtaip_update_checked']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				$ok ? 'success' : 'error',
				esc_html(
					$ok
						? __( 'Checked for plugin updates.', 'globetrotters-ai-presence' )
						: __( 'Could not reach the update server — try again shortly.', 'globetrotters-ai-presence' )
				)
			);
			return;
		}

		if ( ! isset( $_GET['gtaip_refreshed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( 'busy' === $_GET['gtaip_refreshed'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html__( 'A refresh was already running, so this one was skipped. Reload this page in a minute to see its result.', 'globetrotters-ai-presence' )
			);
			return;
		}
		$ok = '1' === $_GET['gtaip_refreshed']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$ok ? 'success' : 'error',
			esc_html(
				$ok
					? __( 'Refreshed from Globetrotters.', 'globetrotters-ai-presence' )
					: __( 'Refresh failed — still serving the last known good version.', 'globetrotters-ai-presence' )
			)
		);
	}

	/**
	 * "WP-CLI, as deploy — <time>" for the last pull's stamp.
	 *
	 * @param array{stored_at:int, pulled_by:array{lane:string, user:string}} $stamp Pull stamp.
	 */
	private function describe_puller( array $stamp ): string {
		$lanes = array(
			'wp-cli'  => __( 'WP-CLI (system cron)', 'globetrotters-ai-presence' ),
			'wp-cron' => __( 'WP-Cron', 'globetrotters-ai-presence' ),
			'web'     => __( 'Refresh now, or activation', 'globetrotters-ai-presence' ),
		);
		$lane  = (string) ( $stamp['pulled_by']['lane'] ?? '' );
		$label = $lanes[ $lane ] ?? $lane;
		$user  = (string) ( $stamp['pulled_by']['user'] ?? '' );
		if ( '' !== $user ) {
			/* translators: 1: how the pull ran, e.g. "WP-Cron", 2: operating-system user name. */
			$label = sprintf( __( '%1$s, as OS user %2$s', 'globetrotters-ai-presence' ), $label, $user );
		}
		return $label . ' — ' . $this->format_time( (int) $stamp['stored_at'] );
	}

	/**
	 * Whether visitors were served another version *after* the last pull.
	 *
	 * The heartbeat follows every version change at once, so a served version
	 * recorded after the pull that is not the pull's own means the web server
	 * read some other copy than the one cron wrote.
	 *
	 * @param array<string, mixed>|null          $stamp  Pull stamp.
	 * @param array{version:string, at:int}|null $served Served-version heartbeat.
	 */
	public static function serves_an_older_pull( ?array $stamp, ?array $served ): bool {
		return null !== $stamp && null !== $served
			&& $served['at'] > (int) $stamp['stored_at']
			&& $served['version'] !== (string) $stamp['version'];
	}

	/**
	 * Install-profile select choices.
	 *
	 * Labelled by what the site owner gets, not by the internal profile name.
	 *
	 * @return array<string, string>
	 */
	private function profile_choices(): array {
		return array(
			Options::PROFILE_SUBDOMAIN_BREADCRUMB => __( 'Apex and subdomain (recommended)', 'globetrotters-ai-presence' ),
			Options::PROFILE_FULL_APEX            => __( 'Apex only', 'globetrotters-ai-presence' ),
		);
	}

	/**
	 * AI-crawler access choices for robots.txt, the change-nothing default first.
	 *
	 * @return array<string, string>
	 */
	private function robots_agent_choices(): array {
		return array(
			RobotsOptions::INHERIT   => __( 'Same rules as other crawlers (recommended)', 'globetrotters-ai-presence' ),
			RobotsOptions::ALLOW_ALL => __( 'Allow everything', 'globetrotters-ai-presence' ),
		);
	}

	/**
	 * Refresh-cadence select choices.
	 *
	 * @return array<string, string>
	 */
	private function cadence_choices(): array {
		return array(
			'hourly'     => __( 'Hourly', 'globetrotters-ai-presence' ),
			'twicedaily' => __( 'Twice daily', 'globetrotters-ai-presence' ),
			'daily'      => __( 'Daily', 'globetrotters-ai-presence' ),
			'weekly'     => __( 'Weekly', 'globetrotters-ai-presence' ),
		);
	}

	/**
	 * Format a timestamp for display in the site's timezone.
	 *
	 * @param int $timestamp Unix timestamp (0 = never).
	 */
	private function format_time( int $timestamp ): string {
		if ( 0 === $timestamp ) {
			return (string) __( 'Never', 'globetrotters-ai-presence' );
		}
		return (string) wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}
}
