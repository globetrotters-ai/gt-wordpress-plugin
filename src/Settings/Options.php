<?php
/**
 * Plugin options + derived source URL.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Settings;

use Globetrotters\AiPresence\Analytics\TrustedProxies;
use Globetrotters\AiPresence\Serving\IndexNowKey;

/**
 * Read/write wrapper over the plugin's two options.
 *
 * ``gtaip_settings`` holds user configuration (Globetrotters website URL,
 * install profile, refresh cadence). ``gtaip_state`` holds runtime bookkeeping
 * (installed version, last refresh, last error, the derived Globetrotters
 * origin) written by the sync layer.
 */
final class Options {

	public const SETTINGS = 'gtaip_settings';
	public const STATE    = 'gtaip_state';

	/**
	 * Install profiles, mirroring the backend's apex bundle profiles.
	 *
	 * ``full_apex`` is everything at the customer apex and nothing else — what
	 * this plugin has always done. ``subdomain_breadcrumb`` is the both-lanes
	 * deployment: the same set served here *plus* the Globetrotters-hosted
	 * subdomain, which the apex links to so it is reachable by something that
	 * does not already know its hostname.
	 *
	 * The enum's third value, ``cdn_only``, is deliberately not offered: it
	 * describes the customer's own CDN proxying our origin, which happens above
	 * WordPress and needs no plugin at all.
	 */
	public const PROFILE_FULL_APEX            = 'full_apex';
	public const PROFILE_SUBDOMAIN_BREADCRUMB = 'subdomain_breadcrumb';

	private const SETTINGS_DEFAULTS = array(
		'website_url'                => '',
		'refresh_interval'           => 'daily',
		// Existing installs upgrade into ``full_apex``: switching a live site to
		// emitting new markup in its <head> and footer is a decision for the
		// customer, not a side effect of a plugin update.
		'profile'                    => self::PROFILE_FULL_APEX,
		// Agent-traffic reporting. Studio issues the endpoint and the token
		// together on the apex install screen, so both are paste targets rather
		// than a hardcoded host.
		'ingest_endpoint'            => '',
		'ingest_token'               => '',
		// Proxies whose forwarding headers may be believed, one IP or CIDR range
		// per line. The Cloudflare preset (``trusted_proxy_cloudflare``) is
		// deliberately not defaulted here: its absence from the stored option is
		// what marks an install still on 0.4.0's single checkbox. See
		// {@see trusted_proxies()}.
		'trusted_proxies'            => '',
		// The robots.txt opt-ins. Both defaults change nothing any crawler may
		// fetch; see {@see RobotsOptions}.
		'robots_ai_agents'           => RobotsOptions::INHERIT,
		'robots_ai_train'            => false,
		// First-party human page-view counting. Off by default and on upgrade:
		// it adds a script to every front-end page, which is the customer's call.
		// See {@see counts_page_views()}.
		'count_page_views'           => false,
		// Site-wide bot capture (Phase 2). On by default once reporting is
		// configured: a bot event carries a user agent, a path, a status and a
		// verified flag, never an ip and nothing about human visitors. See
		// {@see captures_site_bots()}.
		'capture_site_bots'          => true,
		// Keep AI agents out of the page cache so site capture sees them. Off by
		// default and on upgrade: it changes another plugin's configuration and
		// adds server load. See {@see excludes_ai_from_page_cache()}.
		'exclude_ai_from_page_cache' => false,
	);

	private const STATE_DEFAULTS = array(
		'installed_version'  => '',
		'latest_version'     => '',
		// Where the Globetrotters-hosted copy of this presence is published,
		// re-derived from the pulled ``ai.json`` on every refresh. See
		// {@see \Globetrotters\AiPresence\Sync\PresenceOrigin} for why it cannot
		// be a settings field.
		'presence_origin'    => '',
		'content_hash'       => '',
		// This environment's IndexNow key, learned from the synced version
		// marker so the apex can serve it at ``/<key>.txt``. State rather than a
		// setting: it is issued per environment by the presence stack, rotates
		// without the customer's involvement, and a pasted copy would rot.
		'indexnow_key'       => '',
		'last_refresh'       => 0,
		// When the bundle's *content* last changed, not when it was last
		// fetched. Stamps the generated sitemap's <lastmod>, which is why the
		// distinction matters: a daily refresh that finds identical bytes is
		// not a modification, and claiming it as one asserts a freshness the
		// content does not have.
		'content_changed_at' => 0,
		'last_error'         => '',
	);

	/**
	 * What the last flush did. An option of its own because the flusher and the
	 * sync are separate writers on separate schedules, and each rewriting one
	 * shared option from its own copy puts back what the other wrote. The
	 * flusher is the only writer, under its lock. The drop counters are not
	 * here at all: they are incremented concurrently on the serve path, so the
	 * event store keeps them, in place — see
	 * {@see \Globetrotters\AiPresence\Analytics\MysqlEventStore}.
	 */
	public const FLUSH_STATE = 'gtaip_flush_state';

	private const FLUSH_STATE_DEFAULTS = array(
		'last_flush_ok'      => 0,
		'last_flush_attempt' => 0,
		'last_flush_error'   => '',
		'flush_count'        => 0,
	);

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$stored = get_option( self::SETTINGS, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::SETTINGS_DEFAULTS );
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$settings = $this->settings();
		return $settings[ $key ] ?? null;
	}

	/**
	 * Persist a subset of settings (merged over the current values).
	 *
	 * @param array<string, mixed> $values Settings to write.
	 */
	public function update_settings( array $values ): void {
		update_option( self::SETTINGS, array_merge( $this->settings(), $values ) );
	}

	/**
	 * All runtime state merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$stored = get_option( self::STATE, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::STATE_DEFAULTS );
	}

	/**
	 * Persist a subset of runtime state, merged over the *stored* values.
	 *
	 * Not over this request's copy: get_option() answers from a cache filled when
	 * the request began, and a request that blocks before it writes — the
	 * settings page fetches the version marker first — would otherwise put back
	 * whatever a sync in another request wrote meanwhile: the installed version,
	 * the content hash, the IndexNow key. Writes are a handful per sync, so the
	 * re-read costs nothing.
	 *
	 * @param array<string, mixed> $values State to write.
	 */
	public function update_state( array $values ): void {
		self::evict( self::STATE );
		update_option( self::STATE, array_merge( $this->state(), $values ) );
	}

	/**
	 * Reset everything learned from the website URL's source to its defaults.
	 *
	 * Every key the state option defines is one — the flush fields and the drop
	 * counters live elsewhere. Written over the stored row rather than deleting
	 * it, so anything else left there survives: the drop counts 0.4.0 kept,
	 * which the counter rows still seed from.
	 */
	public function reset_state(): void {
		$this->update_state( self::STATE_DEFAULTS );
	}

	/**
	 * Whether the stored website URL is still ``$base_url``, read from the
	 * database rather than this request's cached copy.
	 *
	 * Lets a long-running request — a pull is several blocking fetches — notice
	 * a save another request made while it ran.
	 *
	 * @param string $base_url Base URL the caller started from.
	 */
	public function still_connected_to( string $base_url ): bool {
		self::evict( self::SETTINGS );
		return '' !== $base_url && $this->base_url() === $base_url;
	}

	/**
	 * Drop this request's cached copies of the state and flush-state rows.
	 *
	 * Both are written by cron, which may be WP-CLI behind a different object
	 * cache (an APCu drop-in is per process pool): the settings screen reads
	 * them from the table, or it reports a sync it never saw as not having run.
	 */
	public function reload_runtime_state(): void {
		self::evict( self::STATE );
		self::evict( self::FLUSH_STATE );
	}

	/**
	 * Drop this request's cached copy of an option, so the next read is the
	 * stored row.
	 *
	 * Both plugin options are autoloaded, so the copy lives in ``alloptions``
	 * (filled when the request began) as well as under its own key.
	 *
	 * @param string $option Option name.
	 */
	private static function evict( string $option ): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $option, 'options' );
	}

	/**
	 * What the last flush did, merged over defaults.
	 *
	 * Falls back to the fields 0.4.0 kept in the state option, so an upgraded
	 * install keeps showing its last flush until the flusher writes its own.
	 *
	 * @return array<string, mixed>
	 */
	public function flush_state(): array {
		$legacy = get_option( self::STATE, array() );
		$stored = get_option( self::FLUSH_STATE, array() );
		return array_merge(
			self::FLUSH_STATE_DEFAULTS,
			array_intersect_key( is_array( $legacy ) ? $legacy : array(), self::FLUSH_STATE_DEFAULTS ),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Persist a subset of the flush state (merged over the current values).
	 *
	 * A plain merge is enough here: the flusher is the only writer, and it
	 * writes under its lock.
	 *
	 * @param array<string, mixed> $values Flush state to write.
	 */
	public function update_flush_state( array $values ): void {
		// Not autoloaded: only the flusher and the settings screen read it.
		update_option( self::FLUSH_STATE, array_merge( $this->flush_state(), $values ), false );
	}

	/**
	 * Destination slug derived from the website URL's host (first label).
	 *
	 * Used to stamp the synthesized version marker. Empty when not connected.
	 */
	public function slug(): string {
		$host = (string) wp_parse_url( $this->base_url(), PHP_URL_HOST );
		if ( '' === $host ) {
			return '';
		}
		return sanitize_title( strtok( $host, '.' ) );
	}

	/**
	 * The configured install profile.
	 *
	 * Anything unrecognised (a hand-edited option, a value from a future
	 * version) reads as ``full_apex`` — the profile that adds nothing to the
	 * customer's pages.
	 */
	public function profile(): string {
		// `is_string` rather than a bare cast: this runs on every front-end request
		// (Breadcrumbs, on wp_head), so an array value written straight into the
		// option by `wp option update` would warn once per page load, not once.
		$profile = $this->get( 'profile' );
		return is_string( $profile ) && in_array( $profile, self::profiles(), true )
			? $profile
			: self::PROFILE_FULL_APEX;
	}

	/**
	 * The profiles this plugin can install.
	 *
	 * @return string[]
	 */
	public static function profiles(): array {
		return array( self::PROFILE_FULL_APEX, self::PROFILE_SUBDOMAIN_BREADCRUMB );
	}

	/**
	 * Whether this install should point at the Globetrotters-hosted copy.
	 */
	public function emits_breadcrumbs(): bool {
		return self::PROFILE_SUBDOMAIN_BREADCRUMB === $this->profile();
	}

	/**
	 * The origin the Globetrotters-hosted copy is published on, as last derived
	 * by a refresh (no trailing slash). Empty when it could not be determined.
	 */
	public function presence_origin(): string {
		$origin = $this->state()['presence_origin'];
		return is_string( $origin ) ? self::normalize_url( $origin ) : '';
	}

	/**
	 * This environment's IndexNow key, or '' when there is none to serve.
	 *
	 * Sanitized on read as well as on write: the option is writable by hand
	 * (``wp option update``), and the value it holds decides both which path the
	 * router answers and what that response's body is.
	 */
	public function indexnow_key(): string {
		return IndexNowKey::sanitize( $this->state()['indexnow_key'] );
	}

	/**
	 * Unix time the cached bundle's content last changed, or 0.
	 *
	 * Zero on an install that has not seen a content change since the plugin
	 * started recording one — the sitemap omits ``<lastmod>`` rather than
	 * inventing a date. It resolves itself on the next real change.
	 */
	public function content_changed_at(): int {
		return (int) $this->state()['content_changed_at'];
	}

	/**
	 * Whether the plugin has been connected to a destination.
	 */
	public function is_connected(): bool {
		return '' !== $this->base_url();
	}

	/**
	 * The resolved base URL to pull artefacts from (no trailing slash).
	 */
	public function base_url(): string {
		return self::normalize_url( (string) $this->get( 'website_url' ) );
	}

	/**
	 * Normalize a configured website URL: trim surrounding whitespace and any
	 * trailing slash. Returns an empty string when nothing is set.
	 *
	 * @param string $url Raw configured URL.
	 */
	public static function normalize_url( string $url ): string {
		return rtrim( trim( $url ), '/' );
	}

	/**
	 * The configured server-log ingest endpoint (no trailing slash), or '' when
	 * it is not an https URL.
	 *
	 * The endpoint receives the bearer token and every captured client IP, so a
	 * cleartext one reads as unconfigured and reporting stays off. Checked on
	 * read as well as in the settings sanitizer: ``wp option update`` writes the
	 * option without ever reaching the sanitizer.
	 */
	public function ingest_endpoint(): string {
		$endpoint = $this->get( 'ingest_endpoint' );
		if ( ! is_string( $endpoint ) ) {
			return '';
		}
		$endpoint = self::normalize_url( $endpoint );
		return self::is_https_url( $endpoint ) ? $endpoint : '';
	}

	/**
	 * Whether a URL is an absolute https URL with a host.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function is_https_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( (string) $parts['scheme'] )
			&& '' !== $parts['host'];
	}

	/**
	 * The configured ingest token.
	 *
	 * Treated as a credential everywhere it is used: never logged, never echoed
	 * back in full once saved.
	 */
	public function ingest_token(): string {
		return trim( (string) $this->get( 'ingest_token' ) );
	}

	/**
	 * Whether agent-traffic reporting has both credentials it needs.
	 *
	 * Capture and flushing are both gated on this: an install that will never
	 * report should not pay a buffer row per served artefact request.
	 */
	public function is_reporting_configured(): bool {
		return '' !== $this->ingest_endpoint() && '' !== $this->ingest_token();
	}

	/**
	 * Whether first-party page-view counting is active: switched on by the site
	 * owner, *and* reporting is configured to send the counts somewhere.
	 *
	 * Both the beacon script and the counter endpoint are gated on this, so an
	 * install that could never send a count neither prints the script nor
	 * answers the beacon. Read strictly, like {@see robots()}: a hand-edited
	 * ``"false"`` does not turn a script on across the whole site.
	 */
	public function counts_page_views(): bool {
		return true === filter_var( $this->get( 'count_page_views' ), FILTER_VALIDATE_BOOLEAN )
			&& $this->is_reporting_configured();
	}

	/**
	 * Whether AI agent and bot visits to the site's own pages are captured:
	 * the setting (default on) *and* reporting configured. Read strictly, like
	 * {@see counts_page_views()}: a hand-edited ``"false"`` reads as off.
	 */
	public function captures_site_bots(): bool {
		return true === filter_var( $this->get( 'capture_site_bots' ), FILTER_VALIDATE_BOOLEAN )
			&& $this->is_reporting_configured();
	}

	/**
	 * Whether AI agents are to be kept out of the page cache: the setting (off
	 * by default) *and* site capture active, since uncached AI requests serve
	 * nothing but the counts. Read strictly, like {@see captures_site_bots()}.
	 * See {@see \Globetrotters\AiPresence\Analytics\PageCacheExclusion}.
	 */
	public function excludes_ai_from_page_cache(): bool {
		return true === filter_var( $this->get( 'exclude_ai_from_page_cache' ), FILTER_VALIDATE_BOOLEAN )
			&& $this->captures_site_bots();
	}

	/**
	 * The proxies whose forwarding headers may be believed when resolving the
	 * client IP. None by default: see
	 * {@see \Globetrotters\AiPresence\Analytics\ClientIp} for why a spoofable
	 * default would be worse than an under-attributed one.
	 *
	 * Read as strictly as {@see robots()}, because ``wp option update`` bypasses
	 * the settings sanitizer: a hand-edited ``"false"`` does not turn the
	 * Cloudflare preset on, and a hand-edited list is held to the grammar a
	 * submitted one is.
	 *
	 * 0.4.0 had a single checkbox that trusted both headers from any peer. Until
	 * the reporting section is next saved, a tick there reads as the Cloudflare
	 * preset — by far its most common reason. A site behind some other proxy
	 * then loses verification until it lists that proxy, rather than staying
	 * forgeable. The raw option is read here, not {@see settings()}: the
	 * preset key's absence is the signal, and a merged default would hide it.
	 */
	public function trusted_proxies(): TrustedProxies {
		$stored = get_option( self::SETTINGS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$list   = $stored['trusted_proxies'] ?? '';
		$preset = array_key_exists( 'trusted_proxy_cloudflare', $stored )
			? $stored['trusted_proxy_cloudflare']
			: ( $stored['trusted_proxy'] ?? false );

		return TrustedProxies::from(
			is_string( $list ) ? $list : '',
			true === filter_var( $preset, FILTER_VALIDATE_BOOLEAN )
		);
	}

	/**
	 * How the robots.txt block treats the AI agents it names.
	 *
	 * Validated on read, like {@see profile()}: anything but the literal
	 * ``allow_all`` reads as ``inherit``, and the training signal needs a real
	 * boolean true (or its unambiguous string forms) — a hand-edited ``"false"``
	 * must not become a site-wide grant. Runs on every robots.txt request.
	 */
	public function robots(): RobotsOptions {
		return new RobotsOptions(
			RobotsOptions::ALLOW_ALL === $this->get( 'robots_ai_agents' ) ? RobotsOptions::ALLOW_ALL : RobotsOptions::INHERIT,
			true === filter_var( $this->get( 'robots_ai_train' ), FILTER_VALIDATE_BOOLEAN )
		);
	}

	/**
	 * Link to the Studio apex install drawer, focused on the capture step that
	 * issues the ingest token.
	 *
	 * The drawer lives on the "Content for AI" page and is opened by the
	 * `apex` query param; a path without the `/app` prefix falls through
	 * Studio's wildcard route to the home page.
	 */
	public function studio_url(): string {
		return 'https://studio.globetrotters.ai/app/presence/website?apex=capture';
	}
}
