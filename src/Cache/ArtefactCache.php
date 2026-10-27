<?php
/**
 * Artefact cache with stale-serve fallback.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Cache;

/**
 * Stores the pulled apex artefacts and their version.
 *
 * The last-good bundle lives in a *non-autoloaded* option: it can be up to a
 * few MB of file bodies, and only apex-file requests and the front-page
 * ``wp_head`` ever read it, so keeping it out of ``alloptions`` protects the
 * per-request options cache (and memcached's 1 MB ``alloptions`` value limit).
 * Because the option is only ever overwritten by a *successful* pull, an
 * unreachable Globetrotters simply leaves the previous bundle in place —
 * stale-serve for free.
 *
 * The table is shared by every process; a persistent object cache in front of
 * it may not be. An APCu ``object-cache.php`` drop-in is per PHP process pool,
 * so a refresh run from system cron through WP-CLI writes the table while
 * PHP-FPM keeps answering from the bundle it cached before — the pull succeeds
 * and the site serves the old version indefinitely. So each pull also writes a
 * small stamp row naming the bundle's generation, read past the object cache;
 * a cached bundle of another generation is evicted and read again. The web
 * lane, in turn, leaves a heartbeat of the version it actually served, which
 * the flush reports as ``servedVersion`` and the settings screen sets against
 * the stamp's ``pulled_by``. Mirrors the Symfony bundle 0.9.4.
 */
final class ArtefactCache {

	public const OPTION = 'gtaip_artefacts';

	/**
	 * The last pull's stamp: generation, version, time and lane. Small and
	 * non-autoloaded, so it can be read past the object cache on every request
	 * that reads the bundle.
	 */
	public const STAMP_OPTION = 'gtaip_artefacts_stamp';

	/**
	 * The heartbeat a web request leaves: the version it served, and when.
	 * Autoloaded, so checking it on the serve path costs nothing.
	 */
	public const SERVED_OPTION = 'gtaip_served';

	/**
	 * How often an unchanged served version is re-stamped.
	 */
	private const HEARTBEAT_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Per-request memo of the stored bundle option.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $bundle = null;

	/**
	 * Body for a stored apex-relative path, or null when absent.
	 *
	 * @param string $path Apex-relative path (no leading slash).
	 */
	public function get( string $path ): ?string {
		$files = $this->files();
		return array_key_exists( $path, $files ) ? (string) $files[ $path ] : null;
	}

	/**
	 * All stored artefact bodies keyed by apex-relative path.
	 *
	 * @return array<string, string>
	 */
	public function files(): array {
		$bundle = $this->bundle();
		return isset( $bundle['files'] ) && is_array( $bundle['files'] ) ? $bundle['files'] : array();
	}

	/**
	 * The version string of the stored bundle (empty when nothing stored).
	 */
	public function version(): string {
		$bundle = $this->bundle();
		return isset( $bundle['version'] ) ? (string) $bundle['version'] : '';
	}

	/**
	 * The stored bundle option, read once per request.
	 *
	 * Router (``parse_request``) and HeadInjector (``wp_head``) both hit this on
	 * every front-end load, so the read + validation is memoised.
	 *
	 * @return array<string, mixed>
	 */
	private function bundle(): array {
		if ( null === $this->bundle ) {
			$this->bundle = self::read_bundle();
			$this->heartbeat( $this->bundle );
		}
		return $this->bundle;
	}

	/**
	 * The stored bundle, never a copy another process has replaced.
	 *
	 * Only behind a persistent object cache: without one, ``get_option()`` has
	 * just read the table, and the stamp would be a second query for nothing.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_bundle(): array {
		$stored = get_option( self::OPTION, array() );
		$bundle = is_array( $stored ) ? $stored : array();
		if ( ! wp_using_ext_object_cache() ) {
			return $bundle;
		}

		if ( self::is_current( $bundle, self::pull_stamp() ) ) {
			return $bundle;
		}

		// Another process pulled (or cleared) since this cache was filled — or
		// the copy predates generations and cannot be vouched for.
		self::evict( self::OPTION );
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether a cached bundle is the one the stamp describes.
	 *
	 * Nothing cached and no stamp agree. Otherwise both need a generation, and
	 * the same one: a bundle stored before 0.9.4 carries none, and with no stamp
	 * either a ``null === null`` match would vouch for a copy another process
	 * may since have deleted (a disconnect), until the next pull. Such a copy is
	 * re-read from the table instead, at most until that pull.
	 *
	 * @param array<string, mixed>      $bundle Cached bundle.
	 * @param array<string, mixed>|null $stamp  Stamp read from the table.
	 */
	private static function is_current( array $bundle, ?array $stamp ): bool {
		if ( array() === $bundle || ! isset( $bundle['generation'] ) ) {
			return array() === $bundle && null === $stamp;
		}
		return null !== $stamp && $bundle['generation'] === $stamp['generation'];
	}

	/**
	 * Record the version a web request is serving.
	 *
	 * Written when it changes, and otherwise at most hourly, so the flush (which
	 * may run in another process) can tell how recently the web lane read it.
	 * Never from cron or WP-CLI: a pull reading its own bundle is not a serve.
	 *
	 * @param array<string, mixed> $bundle The bundle this request read.
	 */
	private function heartbeat( array $bundle ): void {
		$version = isset( $bundle['version'] ) ? (string) $bundle['version'] : '';
		if ( '' === $version || self::lane() !== 'web' ) {
			return;
		}
		$served = get_option( self::SERVED_OPTION, array() );
		if ( is_array( $served ) && ( $served['version'] ?? null ) === $version
			&& time() - (int) ( $served['at'] ?? 0 ) < self::HEARTBEAT_SECONDS ) {
			return;
		}
		update_option(
			self::SERVED_OPTION,
			array(
				'version' => $version,
				'at'      => time(),
			),
			true
		);
	}

	/**
	 * The last pull's stamp, read past the object cache, or null.
	 *
	 * @return array{generation:string, version:string, stored_at:int, pulled_by:array{lane:string, user:string}}|null
	 */
	public static function pull_stamp(): ?array {
		self::evict( self::STAMP_OPTION );
		$stamp = get_option( self::STAMP_OPTION, null );
		return is_array( $stamp ) && isset( $stamp['generation'] ) ? $stamp : null;
	}

	/**
	 * The web lane's heartbeat, read past the object cache, or null.
	 *
	 * @return array{version:string, at:int}|null
	 */
	public static function served(): ?array {
		self::evict( self::SERVED_OPTION );
		// Autoloaded, so this process may also hold it in ``alloptions``.
		self::evict( 'alloptions' );
		$served = get_option( self::SERVED_OPTION, null );
		if ( ! is_array( $served ) || ! isset( $served['version'] ) || '' === (string) $served['version'] ) {
			return null;
		}
		return array(
			'version' => (string) $served['version'],
			'at'      => (int) ( $served['at'] ?? 0 ),
		);
	}

	/**
	 * The version a web request last served (``captureContext.servedVersion``).
	 */
	public static function served_version(): ?string {
		$served = self::served();
		return null === $served ? null : $served['version'];
	}

	/**
	 * Which lane this process is: ``wp-cli``, ``wp-cron`` or ``web``.
	 */
	public static function lane(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}
		return wp_doing_cron() ? 'wp-cron' : 'web';
	}

	/**
	 * The OS user this process runs as, or ''.
	 *
	 * A pull from another account's crontab is the other way the lanes part.
	 */
	private static function os_user(): string {
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$entry = posix_getpwuid( posix_geteuid() );
			if ( is_array( $entry ) && isset( $entry['name'] ) ) {
				return (string) $entry['name'];
			}
		}
		return '';
	}

	/**
	 * Drop an option from this process's object cache.
	 *
	 * Its ``notoptions`` entry too: once this process has seen an option
	 * missing, ``get_option()`` answers the default from that negative cache
	 * before it looks at the table, so a row another process created since
	 * would stay invisible.
	 *
	 * @param string $name Option name, or ``alloptions``.
	 */
	private static function evict( string $name ): void {
		wp_cache_delete( $name, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
			unset( $missing[ $name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}

	/**
	 * Whether any bundle has been stored.
	 */
	public function has_any(): bool {
		return array() !== $this->files();
	}

	/**
	 * Replace the stored bundle.
	 *
	 * Returns false when the bundle could not be persisted, leaving both the
	 * stored option and this request's memo on the previous bundle. The caller
	 * must treat that as a failed refresh: a write that silently did nothing
	 * while the state still advanced would leave the plugin claiming a version
	 * it cannot serve. A bundle whose stamp does not land is rolled back to the
	 * previous one: processes behind another object cache would never see it.
	 *
	 * @param array<string, string> $files   Apex-relative path → body.
	 * @param string                $version Bundle version string.
	 * @return bool True when the bundle is stored.
	 */
	public function store( array $files, string $version ): bool {
		$generation = wp_generate_uuid4();
		$stored_at  = time();
		$bundle     = array(
			'files'      => $files,
			'version'    => $version,
			'stored_at'  => $stored_at,
			'generation' => $generation,
		);

		// Autoload 'no' — see the class docblock. ``stored_at`` changes on every
		// pull, so installs upgrading from an autoloaded bundle self-correct on
		// their next refresh.
		// What the table held before, to put back should the stamp not land.
		self::evict( self::OPTION );
		$previous = get_option( self::OPTION, null );

		if ( ! self::persist( self::OPTION, $bundle ) ) {
			return false;
		}

		// Written after the bundle, so a process that sees the new stamp finds
		// the new bundle behind it. A stamp that does not land fails the pull:
		// other processes would go on serving the bundle they cached.
		$stamp = array(
			'generation' => $generation,
			'version'    => $version,
			'stored_at'  => $stored_at,
			'pulled_by'  => array(
				'lane' => self::lane(),
				'user' => self::os_user(),
			),
		);
		if ( ! self::persist( self::STAMP_OPTION, $stamp ) ) {
			// All or nothing: the new bundle must not serve from a failed pull.
			// Put the previous one back (and with it the stamp still standing).
			if ( is_array( $previous ) ) {
				self::persist( self::OPTION, $previous );
			} else {
				delete_option( self::OPTION );
			}
			return false;
		}

		$this->bundle = $bundle;
		return true;
	}

	/**
	 * Write a non-autoloaded option, reporting whether it is stored.
	 *
	 * Core's update_option() returns false for two unrelated outcomes: the write
	 * failed, *or* the stored value was already identical and it skipped the
	 * write. Reporting failure for the second would turn a republish of an
	 * unchanged bundle into a spurious refresh failure, so read back and apply
	 * the same maybe_serialize() equality test core used to decide it had
	 * nothing to do. Equal means the value is on disk — a success.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value to store.
	 */
	private static function persist( string $name, $value ): bool {
		if ( update_option( $name, $value, false ) ) {
			return true;
		}
		return maybe_serialize( get_option( $name, null ) ) === maybe_serialize( $value );
	}

	/**
	 * Drop the stored bundle.
	 */
	public function clear(): void {
		$this->bundle = array();
		delete_option( self::OPTION );
		delete_option( self::STAMP_OPTION );
		delete_option( self::SERVED_OPTION );
	}
}
