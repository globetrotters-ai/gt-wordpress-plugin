<?php
/**
 * The opt-in page-cache exclusion for AI user agents.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Settings\Options;

/**
 * Keeps AI agents out of the page cache, so their requests reach PHP and site
 * capture counts them (contract "Page-cache exclusion (v5)").
 *
 * A full-page cache plugin answers cached pages before PHP runs, which is why
 * Studio shows bot counts as a floor whenever one is installed. With the
 * setting on, this registers the AI user agents the backend lists as "never
 * cache" in the page-cache plugin {@see CaptureContext::page_cache()} detects.
 * Only plugins that honour the list when *serving* a cached page are
 * supported: WP Rocket, W3 Total Cache and LiteSpeed Cache. WP Super Cache's
 * "rejected user agents" only stops a page being *stored* for them (it is read
 * in ``wp_cache_ob_callback``, never in ``wp_cache_serve_cache_file``), so a
 * page cached by a human visit is still served to an AI agent: registering the
 * list there would change nothing the counts can see, and is not done.
 *
 * The list is data, never typed here: the backend derives it from its AI user
 * agent registry (live retrieval and AI search indexing only) and serves it in
 * the ranges document, which {@see VendorRanges} pulls and hands to
 * {@see replace()}. Two non-autoloaded options, one writer each: the list in
 * ``gtaip_cache_exclusion`` (written by the ranges sync), and what was applied
 * in ``gtaip_cache_exclusion_applied`` (written by {@see sync()}): the cache it
 * went into, the entries this plugin added there, and whether the cache will
 * honour them when serving. Removal takes out exactly the added entries, so an
 * entry the site owner had already listed survives, and a WP Rocket filter
 * simply stops adding anything.
 *
 * Reversible: switching the setting (or site capture) off, deactivating and
 * uninstalling all remove what was applied. A CDN or reverse-proxy cache is out
 * of reach; Studio keeps such a site's counts a floor whatever this does.
 */
final class PageCacheExclusion {

	public const OPTION = 'gtaip_cache_exclusion';

	public const APPLIED_OPTION = 'gtaip_cache_exclusion_applied';

	/**
	 * Page caches whose user-agent exclusion is honoured when serving, and the
	 * names the settings screen gives them.
	 */
	public const SUPPORTED = array( 'wp-rocket', 'w3-total-cache', 'litespeed-cache' );

	public const LABELS = array(
		'wp-rocket'        => 'WP Rocket',
		'w3-total-cache'   => 'W3 Total Cache',
		'litespeed-cache'  => 'LiteSpeed Cache',
		'wp-super-cache'   => 'WP Super Cache',
		'wp-fastest-cache' => 'WP Fastest Cache',
	);

	/**
	 * A token the cache plugins can all take verbatim: LiteSpeed refuses quotes
	 * and backslashes, WP Rocket escapes spaces into a regex, W3TC compares
	 * substrings. Real AI user-agent tokens fit with room to spare.
	 */
	private const TOKEN_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

	private const MAX_ENTRIES = 100;

	private const W3TC_KEY = 'pgcache.reject.ua';

	/**
	 * W3TC's "Disk: Enhanced" engine: the web server serves cached files from
	 * W3TC's rewrite rules, before PHP, so the list only counts once those
	 * rules carry it.
	 */
	private const W3TC_DISK_ENHANCED = 'file_generic';

	private const LITESPEED_KEY = 'cache-exc_useragents';

	private Options $options;

	/**
	 * Wire the settings.
	 *
	 * @param Options $options Plugin settings.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Hook WP Rocket's never-cache list wherever WP Rocket is loaded.
	 *
	 * WP Rocket rebuilds its config file and .htaccess rules whenever its own
	 * settings are saved, reading ``rocket_cache_reject_ua`` each time, so the
	 * filter has to be in place on every request, not only when this applies
	 * it. Nothing is read here: the callback reads the record only when WP
	 * Rocket calls it, and adds nothing unless the list is applied there.
	 */
	public function register(): void {
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			add_filter( 'rocket_cache_reject_ua', array( $this, 'rocket_reject_ua' ) );
		}
	}

	/**
	 * The ``rocket_cache_reject_ua`` callback: WP Rocket's list plus ours.
	 *
	 * @param mixed $user_agents WP Rocket's list.
	 * @return array<int, mixed>
	 */
	public function rocket_reject_ua( $user_agents ): array {
		$applied = $this->applied();
		$list    = is_array( $user_agents ) ? $user_agents : array();
		return 'wp-rocket' === $applied['cache'] ? array_merge( $list, $applied['entries'] ) : $list;
	}

	/**
	 * The synced list, empty when there is none.
	 *
	 * @return string[]
	 */
	public function user_agents(): array {
		return $this->stored()['user_agents'];
	}

	/**
	 * The ranges document version the list was stored at, or null.
	 */
	public function version(): ?string {
		$version = $this->stored()['version'];
		return '' === $version ? null : $version;
	}

	/**
	 * Store the list from a ``200`` on the ranges document.
	 *
	 * @param string   $version     The document's version.
	 * @param string[] $user_agents Parsed list ({@see parse()}).
	 * @param int      $now         Unix time.
	 */
	public function replace( string $version, array $user_agents, int $now ): void {
		update_option(
			self::OPTION,
			array(
				'version'     => $version,
				'fetched_at'  => $now,
				'user_agents' => $user_agents,
			),
			false
		);
	}

	/**
	 * Mark the stored list current again, after a ``304``.
	 *
	 * @param int $now Unix time.
	 */
	public function restamp( int $now ): void {
		$stored = $this->stored();
		if ( '' === $stored['version'] ) {
			return;
		}
		$stored['fetched_at'] = $now;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Validate the ``cacheExclusionUserAgents`` member of a ranges document.
	 * Absent or malformed reads as an empty list; an entry outside
	 * {@see TOKEN_PATTERN} is skipped; duplicates fold case-insensitively.
	 *
	 * @param mixed $value Decoded member.
	 * @return string[]
	 */
	public static function parse( $value ): array {
		$out  = array();
		$seen = array();
		foreach ( is_array( $value ) ? $value : array() as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$entry = trim( $entry );
			$key   = strtolower( $entry );
			if ( 1 !== preg_match( self::TOKEN_PATTERN, $entry ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $entry;
			if ( count( $out ) >= self::MAX_ENTRIES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether the exclusion is in force for this page cache: wanted, applied to
	 * exactly it, and honoured when it serves. What
	 * ``captureContext.pageCacheExclusion`` reports. Keyed on the list asked
	 * for, not on the entries added: an owner who already listed every agent
	 * has them excluded all the same.
	 *
	 * @param string|null $page_cache The detected page cache.
	 */
	public function is_active( ?string $page_cache ): bool {
		$applied = $this->applied();
		return null !== $page_cache
			&& $this->options->excludes_ai_from_page_cache()
			&& $page_cache === $applied['cache']
			&& array() !== $applied['wanted']
			&& $applied['served'];
	}

	/**
	 * Bring the page cache in line with the setting, the list and the cache
	 * detected now: apply, re-apply after a list change or a cache swap, or
	 * remove. A no-op when nothing changed, except a W3TC rule fix that failed
	 * last time, which is retried. Never throws.
	 *
	 * @param string|null $page_cache The detected page cache.
	 */
	public function sync( ?string $page_cache ): void {
		try {
			$want    = $this->options->excludes_ai_from_page_cache()
				&& null !== $page_cache
				&& in_array( $page_cache, self::SUPPORTED, true )
				? $this->user_agents()
				: array();
			$applied = $this->applied();
			if ( array() !== $want && $page_cache === $applied['cache'] && $this->same_list( $want, $applied['wanted'] ) ) {
				$this->repair( $applied );
				return;
			}
			if ( '' !== $applied['cache'] ) {
				$this->remove( $applied );
			}
			if ( array() !== $want && null !== $page_cache ) {
				$this->apply( $page_cache, $want );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Remove whatever is applied, whatever the setting says: the deactivation
	 * path. Never throws.
	 */
	public function remove_all(): void {
		try {
			$applied = $this->applied();
			if ( '' !== $applied['cache'] ) {
				$this->remove( $applied );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * What is applied: the cache, the entries this plugin added to it, the full
	 * list it was asked to apply (to tell a list change apart), and whether the
	 * cache honours them when serving.
	 *
	 * @return array{cache:string, entries:string[], wanted:string[], served:bool}
	 */
	public function applied(): array {
		$applied = get_option( self::APPLIED_OPTION, array() );
		$applied = is_array( $applied ) ? $applied : array();
		return array(
			'cache'   => is_string( $applied['cache'] ?? null ) ? $applied['cache'] : '',
			'entries' => self::strings( $applied['entries'] ?? null ),
			'wanted'  => self::strings( $applied['wanted'] ?? null ),
			'served'  => false !== ( $applied['served'] ?? true ),
		);
	}

	/**
	 * Register ``$user_agents`` in ``$cache`` and record what was added.
	 *
	 * @param string   $cache       A {@see SUPPORTED} cache.
	 * @param string[] $user_agents The list.
	 */
	private function apply( string $cache, array $user_agents ): void {
		switch ( $cache ) {
			case 'wp-rocket':
				if ( ! function_exists( 'rocket_generate_config_file' ) ) {
					return;
				}
				// Recorded first: the filter reads it while WP Rocket rebuilds.
				$this->record( $cache, $user_agents, $user_agents, true );
				add_filter( 'rocket_cache_reject_ua', array( $this, 'rocket_reject_ua' ) );
				self::rebuild_rocket();
				return;
			case 'w3-total-cache':
				$result = self::merge_into_w3tc( $user_agents );
				break;
			case 'litespeed-cache':
				$added  = self::merge_into_litespeed( $user_agents );
				$result = null === $added ? null : array( $added, true );
				break;
			default:
				return;
		}
		if ( null !== $result ) {
			$this->record( $cache, $result[0], $user_agents, $result[1] );
		}
	}

	/**
	 * Nothing changed on our side: check the cache still holds the list. An
	 * admin (or the cache plugin itself) may have taken an agent out of W3TC's
	 * or LiteSpeed's list since, and the record would otherwise go on claiming
	 * it is excluded. A missing agent is added back and recorded as ours; a
	 * W3TC rule fix that failed last time is retried. WP Rocket cannot drift:
	 * its list is the filter, hooked on every request.
	 *
	 * @param array{cache:string, entries:string[], wanted:string[], served:bool} $applied What is applied.
	 */
	private function repair( array $applied ): void {
		switch ( $applied['cache'] ) {
			case 'w3-total-cache':
				if ( ! class_exists( '\\W3TC\\Dispatcher' ) ) {
					return;
				}
				$config  = \W3TC\Dispatcher::config();
				$current = self::strings( $config->get_array( self::W3TC_KEY ) );
				$missing = self::missing_from( $current, $applied['wanted'] );
				if ( array() !== $missing ) {
					$config->set( self::W3TC_KEY, array_merge( $current, $missing ) );
					$config->save();
				} elseif ( $applied['served'] ) {
					return;
				}
				$this->record( $applied['cache'], array_merge( $applied['entries'], $missing ), $applied['wanted'], self::w3tc_rules_current( $config ) );
				return;
			case 'litespeed-cache':
				$conf = self::litespeed_conf();
				if ( null === $conf ) {
					return;
				}
				$current = self::strings( $conf->conf( self::LITESPEED_KEY ) );
				$missing = self::missing_from( $current, $applied['wanted'] );
				if ( array() !== $missing ) {
					$conf->update_confs( array( self::LITESPEED_KEY => array_merge( $current, $missing ) ) );
					$this->record( $applied['cache'], array_merge( $applied['entries'], $missing ), $applied['wanted'], true );
				}
				return;
		}
	}

	/**
	 * Take out what {@see apply()} recorded, then forget it.
	 *
	 * @param array{cache:string, entries:string[], wanted:string[], served:bool} $applied What is applied.
	 */
	private function remove( array $applied ): void {
		switch ( $applied['cache'] ) {
			case 'wp-rocket':
				// Forgotten first, so the filter adds nothing to the rebuild.
				$this->forget();
				self::rebuild_rocket();
				return;
			case 'w3-total-cache':
				self::remove_from_w3tc( $applied['entries'] );
				break;
			case 'litespeed-cache':
				self::remove_from_litespeed( $applied['entries'] );
				break;
		}
		$this->forget();
	}

	/**
	 * Remove what was applied, from ``uninstall.php``: no bootstrap, so no
	 * {@see Options} and no object of this class, only the stored record and
	 * the cache plugins' own code. A plugin is deactivated before it is
	 * deleted, which already removed everything; this covers a record left
	 * behind anyway (a deactivation that failed half-way). For WP Rocket, the
	 * list lives on in the config file and .htaccess rules it generated while
	 * the filter was hooked: rebuilt now, without this plugin loaded, they
	 * lose it.
	 */
	public static function remove_recorded_on_uninstall(): void {
		try {
			$applied = get_option( self::APPLIED_OPTION, array() );
			$applied = is_array( $applied ) ? $applied : array();
			$entries = self::strings( $applied['entries'] ?? null );
			if ( 'w3-total-cache' === ( $applied['cache'] ?? '' ) ) {
				self::remove_from_w3tc( $entries );
			} elseif ( 'litespeed-cache' === ( $applied['cache'] ?? '' ) ) {
				self::remove_from_litespeed( $entries );
			} elseif ( 'wp-rocket' === ( $applied['cache'] ?? '' ) ) {
				// The record goes first: uninstall.php deletes it anyway, and no
				// callback of ours can be hooked here to read it.
				delete_option( self::APPLIED_OPTION );
				self::rebuild_rocket();
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Rebuild WP Rocket's config file and rewrite rules, both of which carry
	 * its never-cache user agents.
	 */
	private static function rebuild_rocket(): void {
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
		if ( function_exists( 'flush_rocket_htaccess' ) ) {
			flush_rocket_htaccess();
		}
	}

	/**
	 * Add to W3TC's "rejected user agents", then let W3TC rewrite its rules.
	 * Returns the entries added and whether W3TC serves with them (only in
	 * doubt in Disk: Enhanced mode, when the rules could not be rewritten),
	 * or null when W3TC's config cannot be reached.
	 *
	 * @param string[] $user_agents The list.
	 * @return array{0:string[], 1:bool}|null
	 */
	private static function merge_into_w3tc( array $user_agents ): ?array {
		if ( ! class_exists( '\\W3TC\\Dispatcher' ) ) {
			return null;
		}
		$config  = \W3TC\Dispatcher::config();
		$current = self::strings( $config->get_array( self::W3TC_KEY ) );
		$added   = self::missing_from( $current, $user_agents );
		if ( array() === $added ) {
			return array( $added, true );
		}
		$config->set( self::W3TC_KEY, array_merge( $current, $added ) );
		$config->save();
		return array( $added, self::w3tc_rules_current( $config ) );
	}

	/**
	 * Take ``$entries`` back out of W3TC's list, leaving any other entry.
	 *
	 * @param string[] $entries What this plugin added.
	 */
	private static function remove_from_w3tc( array $entries ): void {
		if ( array() === $entries || ! class_exists( '\\W3TC\\Dispatcher' ) ) {
			return;
		}
		$config  = \W3TC\Dispatcher::config();
		$current = self::strings( $config->get_array( self::W3TC_KEY ) );
		$kept    = self::without( $current, $entries );
		if ( count( $kept ) !== count( $current ) ) {
			$config->set( self::W3TC_KEY, $kept );
			$config->save();
			self::w3tc_rules_current( $config );
		}
	}

	/**
	 * Run W3TC's own "fix environment", which rewrites the rules Disk: Enhanced
	 * mode serves from. Whether W3TC now serves with the saved list: always in
	 * the engines that read the cache from PHP (W3TC checks the list there), and
	 * in Disk: Enhanced only when the rewrite went through. A failure (an
	 * unwritable .htaccess on a cron run) is retried on the next sync.
	 *
	 * @param object $config W3TC config.
	 */
	private static function w3tc_rules_current( $config ): bool {
		try {
			\W3TC\Dispatcher::component( 'Root_Environment' )->fix_in_wpadmin( $config, true );
			return true;
		} catch ( \Throwable $error ) {
			unset( $error );
		}
		return ! method_exists( $config, 'get_string' ) || self::W3TC_DISK_ENHANCED !== $config->get_string( 'pgcache.engine' );
	}

	/**
	 * Add to LiteSpeed Cache's "Do Not Cache User Agents". ``update_confs()``
	 * saves the option and rewrites the .htaccess rule the LiteSpeed server
	 * reads before PHP runs. Returns the entries added, or null when LiteSpeed's
	 * config cannot be reached.
	 *
	 * @param string[] $user_agents The list.
	 * @return string[]|null
	 */
	private static function merge_into_litespeed( array $user_agents ): ?array {
		$conf = self::litespeed_conf();
		if ( null === $conf ) {
			return null;
		}
		$current = self::strings( $conf->conf( self::LITESPEED_KEY ) );
		$added   = self::missing_from( $current, $user_agents );
		if ( array() !== $added ) {
			$conf->update_confs( array( self::LITESPEED_KEY => array_merge( $current, $added ) ) );
		}
		return $added;
	}

	/**
	 * Take ``$entries`` back out of LiteSpeed's list, leaving any other entry.
	 *
	 * @param string[] $entries What this plugin added.
	 */
	private static function remove_from_litespeed( array $entries ): void {
		$conf = array() === $entries ? null : self::litespeed_conf();
		if ( null === $conf ) {
			return;
		}
		$current = self::strings( $conf->conf( self::LITESPEED_KEY ) );
		$kept    = self::without( $current, $entries );
		if ( count( $kept ) !== count( $current ) ) {
			$conf->update_confs( array( self::LITESPEED_KEY => $kept ) );
		}
	}

	/**
	 * LiteSpeed Cache's config object, or null when it is not loaded.
	 *
	 * @return object|null
	 */
	private static function litespeed_conf() {
		if ( ! class_exists( '\\LiteSpeed\\Conf' ) || ! method_exists( '\\LiteSpeed\\Conf', 'cls' ) ) {
			return null;
		}
		$conf = \LiteSpeed\Conf::cls();
		return is_object( $conf ) && method_exists( $conf, 'conf' ) && method_exists( $conf, 'update_confs' ) ? $conf : null;
	}

	/**
	 * The entries of ``$wanted`` that ``$current`` does not hold yet, compared
	 * case-insensitively the way every supported cache matches them.
	 *
	 * @param string[] $current Entries already listed.
	 * @param string[] $wanted  Entries to list.
	 * @return string[]
	 */
	private static function missing_from( array $current, array $wanted ): array {
		$have = array_map( 'strtolower', $current );
		return array_values(
			array_filter(
				$wanted,
				static function ( string $entry ) use ( $have ): bool {
					return ! in_array( strtolower( $entry ), $have, true );
				}
			)
		);
	}

	/**
	 * ``$current`` without ``$entries`` (case-insensitive), order kept.
	 *
	 * @param string[] $current Entries listed.
	 * @param string[] $entries Entries to take out.
	 * @return string[]
	 */
	private static function without( array $current, array $entries ): array {
		$drop = array_map( 'strtolower', $entries );
		return array_values(
			array_filter(
				$current,
				static function ( string $entry ) use ( $drop ): bool {
					return ! in_array( strtolower( $entry ), $drop, true );
				}
			)
		);
	}

	/**
	 * Whether two lists hold the same entries, case and order aside.
	 *
	 * @param string[] $a A list.
	 * @param string[] $b A list.
	 */
	private function same_list( array $a, array $b ): bool {
		$a = array_map( 'strtolower', $a );
		$b = array_map( 'strtolower', $b );
		sort( $a );
		sort( $b );
		return $a === $b;
	}

	/**
	 * The string members of a value, trimmed, empties dropped.
	 *
	 * @param mixed $value Any value.
	 * @return string[]
	 */
	private static function strings( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $entry ) {
			if ( is_string( $entry ) && '' !== trim( $entry ) ) {
				$out[] = trim( $entry );
			}
		}
		return $out;
	}

	/**
	 * Record what is applied: the one writer of {@see APPLIED_OPTION}. Not
	 * autoloaded: only the sync, the flush, the settings screen and WP Rocket's
	 * rebuild read it.
	 *
	 * @param string   $cache   The cache.
	 * @param string[] $entries What this plugin added there.
	 * @param string[] $wanted  The list it was asked to apply.
	 * @param bool     $served  Whether the cache honours it when serving.
	 */
	private function record( string $cache, array $entries, array $wanted, bool $served ): void {
		update_option(
			self::APPLIED_OPTION,
			array(
				'cache'   => $cache,
				'entries' => array_values( $entries ),
				'wanted'  => array_values( $wanted ),
				'served'  => $served,
			),
			false
		);
	}

	/**
	 * Forget what was applied.
	 */
	private function forget(): void {
		delete_option( self::APPLIED_OPTION );
	}

	/**
	 * The stored list, normalised. Written only by {@see replace()} and
	 * {@see restamp()}, from the ranges sync.
	 *
	 * @return array{version:string, fetched_at:int, user_agents:string[]}
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'version'     => is_string( $stored['version'] ?? null ) ? $stored['version'] : '',
			'fetched_at'  => (int) ( $stored['fetched_at'] ?? 0 ),
			'user_agents' => self::parse( $stored['user_agents'] ?? null ),
		);
	}
}
