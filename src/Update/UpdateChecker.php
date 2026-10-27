<?php
/**
 * Self-hosted plugin update checker + integrity guard.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Update;

use Globetrotters\AiPresence\Client\Fetcher;

/**
 * Routes this plugin's WordPress update checks to a GT-hosted metadata endpoint
 * and verifies the downloaded package's ``sha256`` before install.
 *
 * The plugin header carries ``Update URI: https://globetrotters.ai/wp-plugin``,
 * so WordPress 5.8+ hands its update lookups to the
 * ``update_plugins_globetrotters.ai`` filter (and refuses WP.org packages for
 * this slug). WordPress does not verify third-party package signatures, so —
 * given the metadata is fetched over HTTPS from a host we control — the checked
 * ``sha256`` guarantees the downloaded package matches what that trusted
 * metadata advertised, and the package must share the metadata's origin.
 */
final class UpdateChecker {

	/**
	 * Default metadata endpoint. Overridable via the
	 * ``GTAIP_UPDATE_METADATA_URL`` constant or the
	 * ``gtaip_update_metadata_url`` filter (used to point at a fixture in E2E).
	 */
	private const METADATA_URL = 'https://globetrotters.ai/wp-plugin/update.json';

	/**
	 * Transient holding the decoded metadata.
	 */
	private const TRANSIENT = 'gtaip_update_meta';

	/**
	 * How long the metadata fetch is cached. WordPress runs update checks often;
	 * this keeps the plugin from hammering the endpoint.
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * ``plugin_basename()`` of the main plugin file — the key WordPress uses in
	 * the ``update_plugins`` transient.
	 */
	private const PLUGIN_BASENAME = 'globetrotters-ai-presence/globetrotters-ai-presence.php';

	private Fetcher $client;

	/**
	 * Per-request memoised metadata (``$fetched`` distinguishes "not looked up
	 * yet" from "looked up, result was null"), so a single request never issues
	 * more than one blocking fetch — even when the endpoint is unhealthy.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $memo  = null;
	private bool $fetched = false;

	public function __construct( Fetcher $client ) {
		$this->client = $client;
	}

	/**
	 * Register the update-check and download-integrity filters.
	 *
	 * Registered unconditionally (not gated on ``is_admin()``): the
	 * ``update_plugins_*`` filter also fires from WP-Cron's background update
	 * check, where ``is_admin()`` is false.
	 */
	public function register(): void {
		add_filter( 'update_plugins_globetrotters.ai', array( $this, 'check' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'guard_download' ), 10, 3 );
	}

	/**
	 * Answer WordPress's ``update_plugins_{hostname}`` lookup.
	 *
	 * Returns the update object when the endpoint advertises a newer version,
	 * otherwise returns the incoming value untouched. A missing/unreachable/
	 * malformed endpoint yields ``$update`` unchanged — the update check degrades
	 * silently with no wp-admin error, mirroring the artefact-sync philosophy.
	 *
	 * @param mixed                $update      Incoming update value (false by default).
	 * @param array<string, mixed> $plugin_data Parsed plugin header. Unused.
	 * @param string               $plugin_file Plugin basename being queried.
	 * @param array<string, mixed> $locales     Requested locales. Unused.
	 * @return mixed The update object, or ``$update`` unchanged.
	 */
	public function check( $update, $plugin_data = array(), $plugin_file = self::PLUGIN_BASENAME, $locales = array() ) {
		// The ``update_plugins_{hostname}`` filter is shared by every plugin whose
		// Update URI resolves to this host. Only answer for our own plugin so we
		// never hand our package to a lookalike sharing the host.
		if ( self::PLUGIN_BASENAME !== $plugin_file ) {
			return $update;
		}

		$meta = $this->metadata();
		if ( null === $meta ) {
			return $update;
		}

		if ( ! version_compare( \Globetrotters\AiPresence\VERSION, (string) $meta['version'], '<' ) ) {
			return $update;
		}

		// ``version`` is the field core requires: wp_update_plugins() silently
		// skips an ``update_plugins_{hostname}`` response without it, before it
		// ever reads ``new_version``. ``new_version`` is what it then displays.
		// tests/integration/UpdateCheckTest runs the real pipeline to pin this.
		return (object) array(
			'id'           => 'globetrotters.ai/wp-plugin',
			'slug'         => 'globetrotters-ai-presence',
			'plugin'       => self::PLUGIN_BASENAME,
			'version'      => (string) $meta['version'],
			'new_version'  => (string) $meta['version'],
			'url'          => (string) ( $meta['url'] ?? '' ),
			'package'      => (string) $meta['package'],
			'requires'     => (string) ( $meta['requires'] ?? '' ),
			'requires_php' => (string) ( $meta['requires_php'] ?? '' ),
			'tested'       => (string) ( $meta['tested'] ?? '' ),
		);
	}

	/**
	 * Integrity guard: intercept the download of *our* package, verify its
	 * ``sha256`` against the metadata, and hard-fail on mismatch.
	 *
	 * WordPress calls ``upgrader_pre_download`` for every package; we only act on
	 * downloads sharing the metadata's origin and pass everything else through
	 * untouched. Returning a file path short-circuits WordPress's own download;
	 * returning a ``WP_Error`` aborts the install before anything is written to
	 * ``wp-content/plugins``.
	 *
	 * @param mixed  $reply    Incoming short-circuit value (false by default).
	 * @param string $package  Package URL WordPress is about to download.
	 * @param mixed  $upgrader The WP_Upgrader instance. Unused.
	 * @return mixed File path on success, WP_Error on mismatch, or ``$reply``.
	 */
	public function guard_download( $reply, $package = '', $upgrader = null ) {
		$package = (string) $package;
		if ( ! $this->same_origin( $package ) ) {
			return $reply;
		}

		$meta = $this->metadata();
		if ( null === $meta ) {
			return new \WP_Error(
				'gtaip_no_metadata',
				__( 'Could not verify the update: the update metadata is unavailable.', 'globetrotters-ai-presence' )
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$error = $this->verify_sha256( (string) $file, (string) $meta['sha256'] );
		if ( null !== $error ) {
			wp_delete_file( (string) $file );
			return new \WP_Error( 'gtaip_hash_mismatch', $error );
		}

		return $file;
	}

	/**
	 * Fetch (and cache) the update metadata.
	 *
	 * Returns null on any transport, size, JSON, or validation failure — callers
	 * treat null as "no update information", never an error. Invalid payloads are
	 * not cached, so a transient upstream glitch self-heals on the next check.
	 *
	 * @return array<string, mixed>|null
	 */
	public function metadata(): ?array {
		if ( $this->fetched ) {
			return $this->memo;
		}
		$this->fetched = true;

		// A cached payload is re-validated: never trust a partial/poisoned or
		// stale-format transient without the same checks a fresh fetch gets.
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && $this->is_valid( $cached ) ) {
			$this->memo = $cached;
			return $this->memo;
		}

		$result = $this->client->fetch( $this->endpoint() );
		// An oversize body is truncated by the transport (see GtClient); treat it
		// as unusable rather than parsing a corrupt fragment.
		if ( ! $result->is_ok() || strlen( $result->body() ) > Fetcher::MAX_BODY_BYTES ) {
			return null;
		}

		$meta = json_decode( $result->body(), true );
		if ( ! $this->is_valid( $meta ) ) {
			return null;
		}

		set_transient( self::TRANSIENT, $meta, self::TTL );
		$this->memo = $meta;
		return $this->memo;
	}

	/**
	 * The latest advertised plugin version, or empty string when unknown.
	 *
	 * May trigger a metadata fetch — call from explicit user actions or cron, not
	 * from a passive page render (use {@see cached_latest_version()} there).
	 */
	public function latest_version(): string {
		$meta = $this->metadata();
		return null !== $meta ? (string) $meta['version'] : '';
	}

	/**
	 * The latest advertised version from the cache only — never fetches.
	 *
	 * For passive rendering (the settings page): a cold cache reads as "unknown"
	 * rather than blocking the request on HTTP, matching the artefact-sync rule of
	 * keeping blocking pulls off admin page loads. WordPress's own periodic update
	 * check (which runs {@see check()}) warms the cache in the background.
	 */
	public function cached_latest_version(): string {
		$cached = get_transient( self::TRANSIENT );
		return is_array( $cached ) && $this->is_valid( $cached ) ? (string) $cached['version'] : '';
	}

	/**
	 * Bust the metadata cache and force WordPress to re-run its plugin update
	 * check on the next request. Backs the "Check for updates now" action.
	 */
	public function flush(): void {
		delete_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		$this->fetched = false;
		$this->memo    = null;
	}

	/**
	 * Compare a file's ``sha256`` against the expected digest.
	 *
	 * Pure and side-effect-free so the integrity check can be unit-tested with a
	 * real temp file and no WordPress. Returns null on match, or a human-readable
	 * error message on mismatch / missing digest.
	 *
	 * @param string $file     Absolute path to the downloaded file.
	 * @param string $expected Expected hex sha256 digest from the metadata.
	 */
	public function verify_sha256( string $file, string $expected ): ?string {
		$expected = strtolower( trim( $expected ) );
		if ( '' === $expected ) {
			return (string) __( 'The update metadata is missing a checksum — refusing to install.', 'globetrotters-ai-presence' );
		}

		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return sprintf(
				/* translators: 1: expected sha256 digest, 2: actual sha256 digest. */
				(string) __( 'Checksum mismatch — refusing to install. Expected %1$s, got %2$s.', 'globetrotters-ai-presence' ),
				$expected,
				is_string( $actual ) ? $actual : 'none'
			);
		}

		return null;
	}

	/**
	 * Resolve the metadata endpoint, honouring the override constant and filter.
	 */
	private function endpoint(): string {
		$url = defined( 'GTAIP_UPDATE_METADATA_URL' )
			? (string) constant( 'GTAIP_UPDATE_METADATA_URL' )
			: self::METADATA_URL;

		return (string) apply_filters( 'gtaip_update_metadata_url', $url );
	}

	/**
	 * Whether a URL shares the metadata endpoint's origin (scheme + host).
	 *
	 * The metadata is fetched from a host we control (over HTTPS by default), so
	 * a package on that same origin is as trustworthy as the metadata that named
	 * it. This is both the integrity-guard scope and the package allowlist:
	 * ``check()`` only offers same-origin packages and ``guard_download()`` only
	 * verifies (and can only block) same-origin packages — the two never diverge,
	 * so a package can never be *offered* without also being *hash-checked*.
	 *
	 * @param string $url URL to test.
	 */
	private function same_origin( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$a = wp_parse_url( $url );
		$b = wp_parse_url( $this->endpoint() );
		return is_array( $a ) && is_array( $b )
			&& isset( $a['scheme'], $a['host'], $b['scheme'], $b['host'] )
			&& $a['scheme'] === $b['scheme']
			&& $a['host'] === $b['host'];
	}

	/**
	 * Validate a decoded metadata payload: required fields present and the
	 * package served from the metadata's own origin (so it is covered by the
	 * integrity guard). A cross-origin ``package`` is rejected outright.
	 *
	 * @param mixed $meta Decoded JSON.
	 */
	private function is_valid( $meta ): bool {
		if ( ! is_array( $meta ) ) {
			return false;
		}
		if ( empty( $meta['version'] ) || empty( $meta['package'] ) || empty( $meta['sha256'] ) ) {
			return false;
		}
		return $this->same_origin( (string) $meta['package'] );
	}
}
