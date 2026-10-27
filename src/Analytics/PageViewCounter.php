<?php
/**
 * First-party, cookieless human page-view counter.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * Turns one same-origin page-view beacon into one counter increment.
 *
 * {@see \Globetrotters\AiPresence\Serving\PageViewScript} prints a few bytes of
 * JavaScript that post the page's path to {@see PATH} on this same site; the
 * browser never talks to Globetrotters. {@see \Globetrotters\AiPresence\Serving\Router}
 * answers that POST and hands the few request values it needs to
 * {@see handle()}, which decides whether the beacon counts and, if so, adds one
 * to the ``(UTC day, path, bucket, platform)`` counter. Only those daily
 * counters ever leave the site, in the flush envelope's ``pageViews``.
 *
 * What this class is given, and what it keeps, is the whole privacy story:
 *
 * - The ``Origin`` host, ``Sec-Fetch-Site`` value or ``Referer`` host, compared
 *   with this site's and dropped.
 * - The User-Agent, matched against two coarse patterns and dropped. It is
 *   never stored: a counter row has no column for it.
 * - The beacon's referral hints, ``r`` (the host of the page's referrer, when
 *   it is another site) and ``u`` (the page's ``utm_source``), matched by
 *   {@see AiReferrers} against the synced AI referrer list and dropped. Only
 *   the platform they resolve to — ``chatgpt``, ``claude``…, or ``none`` — is
 *   kept, as part of the counter's key.
 * - The path, validated exactly as the backend validates it. That is the only
 *   request value that is persisted.
 *
 * It is never handed an IP address, a forwarding header or a cookie, and it does
 * not read the request itself — the router passes it strings — so there is
 * nothing here that could start to. Nothing is set on the response either.
 *
 * The numbers are indicative, not audited: a same-origin endpoint can be
 * spammed, and visitors without JavaScript are missed. The backend states that
 * wherever it shows them, and never bills on them.
 */
final class PageViewCounter {

	/**
	 * The same-origin path the beacon posts to.
	 *
	 * Under ``/.well-known/globetrotters/`` rather than at the root: it collides
	 * with nothing a site publishes, and the router already answers early on
	 * ``parse_request`` for well-known paths a security plugin might otherwise
	 * intercept.
	 */
	public const PATH = '/.well-known/globetrotters/pv';

	/**
	 * Largest request body read, in bytes. A real beacon is ~20 bytes plus the
	 * path, and a path past the backend's cap is discarded anyway.
	 */
	public const MAX_BODY_BYTES = 1024;

	/**
	 * The backend's path cap. Measured in bytes, which is never looser than the
	 * backend's character count.
	 */
	public const MAX_PATH_BYTES = 512;

	/**
	 * Per-path increments a minute, enforced only where a persistent object
	 * cache makes it free. Well above any real page on a destination site; its
	 * job is to stop one scripted loop inflating one page, not to meter people.
	 */
	public const MAX_PER_PATH_PER_MINUTE = 120;

	public const BUCKET_BROWSER    = 'browser';
	public const BUCKET_AI_BROWSER = 'ai_browser';

	/**
	 * Obvious automation. A headless runtime can execute the page's script, and
	 * a crawler that does is still not a person reading the page, so a UA
	 * carrying any of these markers is not counted at all.
	 *
	 * ``bot`` not preceded by ``cu``: CUBOT is an Android phone brand whose
	 * model names land in the UA (``Android 10; CUBOT X30``), and its owners
	 * are people.
	 */
	private const BOT_MARKER = '/(?<!cu)bot|crawl|spider|slurp|fetch|python|curl|wget|go-http|headlesschrome|phantomjs|lighthouse|preview/i';

	/**
	 * A browser driven by an AI assistant (ChatGPT agent and Atlas, Comet,
	 * Perplexity, Claude). It ran the page's script, so it is counted — in a
	 * bucket of its own, because it is a visit the customer reads differently.
	 */
	private const AI_BROWSER_MARKER = '/chatgpt|oai-|comet|perplexity|claude/i';

	private const CACHE_GROUP = 'gtaip_pv';

	/**
	 * Separates a bucket from its platform in the stored bucket,
	 * ``browser@chatgpt``. Neither a bucket nor a platform can contain it.
	 */
	private const PLATFORM_SEPARATOR = '@';

	/**
	 * One JSON escape at a time, left to right: a surrogate pair (kept), a lone
	 * surrogate (captured), or any other escape (kept, so an escaped backslash
	 * is never read as the start of a ``\u`` escape).
	 */
	private const SURROGATE_ESCAPES = '/\\\\ud[89ab][0-9a-f]{2}\\\\ud[c-f][0-9a-f]{2}|(\\\\ud[89a-f][0-9a-f]{2})|\\\\./i';

	private PageViewStore $store;

	private ?AiReferrers $referrers;

	/**
	 * Wire the counter store and, to attribute referred views, the AI list.
	 *
	 * @param PageViewStore    $store     Where the counters live.
	 * @param AiReferrers|null $referrers The AI referrer list; null attributes nothing.
	 */
	public function __construct( PageViewStore $store, ?AiReferrers $referrers = null ) {
		$this->store     = $store;
		$this->referrers = $referrers;
	}

	/**
	 * Count one beacon if it qualifies. Returns whether it was counted.
	 *
	 * Every rejection is silent: the caller answers ``204`` either way, so the
	 * endpoint reveals nothing about what it accepts.
	 *
	 * @param string $origin         ``Origin`` request header, or ''.
	 * @param string $referer        ``Referer`` request header, or ''.
	 * @param string $ua             ``User-Agent`` request header, or ''.
	 * @param string $body           Request body, already capped by the caller.
	 * @param string $sec_fetch_site ``Sec-Fetch-Site`` request header, or ''.
	 */
	public function handle( string $origin, string $referer, string $ua, string $body, string $sec_fetch_site = '' ): bool {
		// Nothing to count into until the v2 counter table is there; agent
		// traffic reporting is unaffected either way.
		if ( ! Schema::page_views_ready() ) {
			return false;
		}

		if ( ! self::is_same_origin( $origin, $referer, (string) home_url( '/' ), $sec_fetch_site ) ) {
			return false;
		}

		$bucket = self::bucket( $ua );
		if ( null === $bucket ) {
			return false;
		}

		$beacon = self::beacon_from_body( $body );
		if ( null === $beacon ) {
			return false;
		}

		return $this->count( $beacon['path'], $bucket, $this->platform( $bucket, $beacon['r'], $beacon['u'] ) );
	}

	/**
	 * Add one view of ``$path`` to today's counter, under the daily path cap.
	 *
	 * The cap is enforced after the fact, which keeps the common case a single
	 * statement: only an increment that *created* a counter pays for the
	 * distinct-path count, and when that goes past {@see PageViewStore::MAX_PATHS_PER_DAY}
	 * the new counter is removed and the view goes to ``(other)`` instead. The
	 * removal only happens while the counter still holds this one view, so a
	 * concurrent beacon for the same path is never deleted with it; racing
	 * requests can each leave one extra path — a bound that holds to within the
	 * concurrency, which is all it needs.
	 *
	 * The cap counts paths, not rows: a path already counted today is not new
	 * when its first referred view adds a platform row beside it, and a path
	 * past the cap is never counted under its own name, so its views fold into
	 * ``(other)`` together, each under its own bucket and platform.
	 *
	 * @param string $path     Normalised page path.
	 * @param string $bucket   UA bucket.
	 * @param string $platform AI platform the visit came from, or ``none``.
	 */
	public function count( string $path, string $bucket, string $platform = AiReferrers::NONE ): bool {
		if ( $this->is_rate_limited( $path ) ) {
			return false;
		}

		// Only a person's browser takes a platform, and only once the schema
		// can hold it: before version 4 ``bucket`` is 16 characters, and
		// ``browser@perplexity`` would be refused or truncated there.
		if ( self::BUCKET_BROWSER !== $bucket || ! Schema::page_view_platforms_ready() ) {
			$platform = AiReferrers::NONE;
		}
		$day     = gmdate( 'Y-m-d' );
		$stored  = self::stored_bucket( $bucket, $platform );
		$written = $this->store->increment( $day, $path, $stored );
		if ( 1 !== $written ) {
			return 2 === $written;
		}

		if ( $this->store->distinct_paths( $day ) <= PageViewStore::MAX_PATHS_PER_DAY ) {
			return true;
		}

		if ( ! $this->store->remove( $day, $path, $stored ) ) {
			// A concurrent beacon already added to the new counter: keep it,
			// one path over the cap, rather than delete that view with it.
			return true;
		}
		return 0 !== $this->store->increment( $day, PageViewStore::OTHER_PATH, $stored );
	}

	/**
	 * The value the store keys a counter on: the bucket alone for an
	 * unattributed view — exactly what 0.8.x wrote, so a day a 0.8.x install
	 * opened keeps counting into the same rows after the upgrade — and
	 * ``bucket@platform`` for a view referred by an AI answer.
	 *
	 * @param string $bucket   UA bucket.
	 * @param string $platform AI platform, or ``none``.
	 */
	public static function stored_bucket( string $bucket, string $platform ): string {
		if ( AiReferrers::NONE === $platform || 1 !== preg_match( AiReferrers::PLATFORM_PATTERN, $platform ) ) {
			return $bucket;
		}
		return $bucket . self::PLATFORM_SEPARATOR . $platform;
	}

	/**
	 * Split a stored bucket back into the bucket and platform the flush sends.
	 * A row without a platform — every row 0.8.x wrote — is ``none``.
	 *
	 * @param string $stored Stored bucket.
	 * @return array{0:string, 1:string} Bucket, platform.
	 */
	public static function split_stored_bucket( string $stored ): array {
		$parts    = explode( self::PLATFORM_SEPARATOR, $stored, 2 );
		$platform = $parts[1] ?? '';
		if ( 1 !== preg_match( AiReferrers::PLATFORM_PATTERN, $platform ) ) {
			$platform = AiReferrers::NONE;
		}
		return array( $parts[0], $platform );
	}

	/**
	 * Whether the beacon came from a page on this site.
	 *
	 * In order of precedence:
	 *
	 * 1. A real ``Origin`` decides by its host. A browser sends one on every
	 *    ``sendBeacon`` POST, and a foreign one is refused whatever else the
	 *    request carries.
	 * 2. Otherwise ``Sec-Fetch-Site``, set by the browser and not by page
	 *    script: ``same-origin`` accepts, any other value refuses. This is what
	 *    counts a page under ``Referrer-Policy: no-referrer``, whose beacon
	 *    carries ``Origin: null`` and no ``Referer`` at all.
	 * 3. Otherwise the ``Referer`` host, for browsers that send neither.
	 *
	 * ``Origin: null`` names no host, so it is treated as absent rather than as
	 * a verdict. Hosts are compared exactly, case aside: a subdomain is another
	 * site.
	 *
	 * @param string $origin         ``Origin`` header.
	 * @param string $referer        ``Referer`` header.
	 * @param string $home           This site's home URL.
	 * @param string $sec_fetch_site ``Sec-Fetch-Site`` header.
	 */
	public static function is_same_origin( string $origin, string $referer, string $home, string $sec_fetch_site = '' ): bool {
		$own = self::host( $home );
		if ( '' === $own ) {
			return false;
		}

		$origin = trim( $origin );
		if ( '' !== $origin && 'null' !== strtolower( $origin ) ) {
			return self::host( $origin ) === $own;
		}

		$fetch_site = strtolower( trim( $sec_fetch_site ) );
		if ( '' !== $fetch_site ) {
			return 'same-origin' === $fetch_site;
		}

		return self::host( $referer ) === $own;
	}

	/**
	 * The coarse bucket for a User-Agent, or null when it is not counted.
	 *
	 * An empty UA is not a browser. The bot marker is tested first, so a
	 * crawler that names an AI vendor (``ClaudeBot``, ``PerplexityBot``) is
	 * never mistaken for an AI-driven browser.
	 *
	 * @param string $ua User-Agent header.
	 */
	public static function bucket( string $ua ): ?string {
		if ( '' === trim( $ua ) || 1 === preg_match( self::BOT_MARKER, $ua ) ) {
			return null;
		}
		return 1 === preg_match( self::AI_BROWSER_MARKER, $ua )
			? self::BUCKET_AI_BROWSER
			: self::BUCKET_BROWSER;
	}

	/**
	 * The normalised path a beacon body carries, or null.
	 *
	 * @param string $body Request body.
	 */
	public static function path_from_body( string $body ): ?string {
		$beacon = self::beacon_from_body( $body );
		return null === $beacon ? null : $beacon['path'];
	}

	/**
	 * What a beacon body carries, or null when it carries no countable path.
	 *
	 * The body is ``{"p":"<path>","r":"<referrer host>","u":"<utm_source>"}``,
	 * ``r`` and ``u`` optional. ``json_decode`` also guarantees the path is
	 * valid UTF-8, which the flush's own JSON encode depends on. A hint that is
	 * not a string is ignored, never a reason to drop the view.
	 *
	 * @param string $body Request body.
	 * @return array{path:string, r:string, u:string}|null
	 */
	private static function beacon_from_body( string $body ): ?array {
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return null;
		}
		$decoded = json_decode( $body, true );
		if ( null === $decoded && JSON_ERROR_UTF16 === json_last_error() ) {
			$decoded = json_decode( self::nul_lone_surrogates( $body ), true );
		}
		if ( ! is_array( $decoded ) || ! array_key_exists( 'p', $decoded ) ) {
			return null;
		}
		$path = self::normalise_path( $decoded['p'] );
		if ( null === $path ) {
			return null;
		}
		return array(
			'path' => $path,
			'r'    => self::hint( $decoded['r'] ?? null ),
			'u'    => self::hint( $decoded['u'] ?? null ),
		);
	}

	/**
	 * A referral hint as a string, or '' when it is not usable.
	 *
	 * A NUL marks a lone surrogate {@see nul_lone_surrogates()} took out: the
	 * hint is unreadable, so it is dropped, never the view.
	 *
	 * @param mixed $value Decoded ``r`` or ``u``.
	 */
	private static function hint( $value ): string {
		return is_string( $value ) && false === strpos( $value, "\0" ) ? $value : '';
	}

	/**
	 * Replace every lone UTF-16 surrogate escape in a JSON body with ``\u0000``.
	 *
	 * ``JSON.stringify`` escapes a lone surrogate rather than failing, and
	 * PHP's decoder refuses the whole document for it. Run only after that
	 * refusal, never on the common path. A NUL is what the rules downstream
	 * already refuse: {@see normalise_path()} rejects a path carrying one, and
	 * {@see hint()} drops a hint carrying one, so the view still counts.
	 *
	 * @param string $body Request body.
	 */
	private static function nul_lone_surrogates( string $body ): string {
		return (string) preg_replace_callback(
			self::SURROGATE_ESCAPES,
			static function ( array $escape ): string {
				return isset( $escape[1] ) && '' !== $escape[1] ? '\\u0000' : $escape[0];
			},
			$body
		);
	}

	/**
	 * The AI platform a view came from, or ``none``.
	 *
	 * Only a person's browser is attributed: an AI-driven browser is already
	 * its own bucket, and its referrer says nothing about a click. The list is
	 * consulted only when the schema can store the answer — before version 4 a
	 * referred view still counts, as ``none``.
	 *
	 * @param string $bucket UA bucket.
	 * @param string $r      Referrer host hint, or ''.
	 * @param string $u      ``utm_source`` hint, or ''.
	 */
	private function platform( string $bucket, string $r, string $u ): string {
		if (
			null === $this->referrers
			|| self::BUCKET_BROWSER !== $bucket
			|| ( '' === $r && '' === $u )
			|| ! Schema::page_view_platforms_ready()
		) {
			return AiReferrers::NONE;
		}
		return $this->referrers->match( $r, $u );
	}

	/**
	 * A page path in the one form the backend accepts, or null.
	 *
	 * Refused, never repaired — the backend's own rules: an absolute path on
	 * this host only (not ``//host``, not a full URL), no query string and no
	 * fragment (where personal data in URLs usually lives), at most 512 bytes.
	 * Control characters are refused too. A trailing slash is folded, so
	 * ``/visiter/`` and ``/visiter`` are one page; ``/`` stays ``/``. Case is
	 * kept: paths are case-sensitive.
	 *
	 * @param mixed $value Submitted path.
	 */
	public static function normalise_path( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		if (
			'/' !== $value[0]
			|| 0 === strncmp( $value, '//', 2 )
			|| false !== strpbrk( $value, '?#' )
			|| strlen( $value ) > self::MAX_PATH_BYTES
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
		) {
			return null;
		}
		$folded = rtrim( $value, '/' );
		return '' === $folded ? '/' : $folded;
	}

	/**
	 * Whether ``$path`` has had its share of increments this minute.
	 *
	 * Only with a persistent object cache, where the check is an in-memory
	 * increment. Without one it would cost a database write per beacon — more
	 * than the counter itself — so it is skipped. Keyed on the path alone: there
	 * is nothing about the visitor to key on, by design.
	 *
	 * @param string $path Normalised page path.
	 */
	private function is_rate_limited( string $path ): bool {
		if ( ! wp_using_ext_object_cache() ) {
			return false;
		}
		$key = md5( $path ) . ':' . intdiv( time(), MINUTE_IN_SECONDS );
		wp_cache_add( $key, 0, self::CACHE_GROUP, 2 * MINUTE_IN_SECONDS );
		$seen = wp_cache_incr( $key, 1, self::CACHE_GROUP );
		return is_int( $seen ) && $seen > self::MAX_PER_PATH_PER_MINUTE;
	}

	/**
	 * The lower-cased host of a URL, or ''.
	 *
	 * @param string $url URL or origin.
	 */
	private static function host( string $url ): string {
		$host = wp_parse_url( trim( $url ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}
}
