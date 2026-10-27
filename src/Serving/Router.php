<?php
/**
 * Serves the apex artefact set through WordPress's own router.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

use Globetrotters\AiPresence\Analytics\EventRecorder;
use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Analytics\SiteCapture;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Settings\Options;

/**
 * Intercepts requests for the apex file set and serves the cached body.
 *
 * Hooks ``parse_request`` early and matches the request path directly against
 * the served set — no rewrite rules to flush and no reliance on the web server
 * serving static files first. This is what makes the plugin work on managed
 * hosts that route everything through ``index.php`` and on setups where a
 * security plugin would otherwise intercept ``/.well-known/*``.
 *
 * This is also the only place agent traffic to an apex install is observable at
 * all — the request terminates here and never touches a Globetrotters edge — so
 * it is where server-log capture happens.
 *
 * Alongside the artefact set it answers three dynamic paths, none of which can
 * be a {@see ContentTypes} entry: the IndexNow key file at ``/<key>.txt``, whose
 * name is only known at runtime (see {@see IndexNowKey}), and the generated API
 * catalog at ``/.well-known/api-catalog`` and sitemap at ``/ai-sitemap.xml``,
 * both rendered from the cache rather than synced (see {@see ApiCatalog} and
 * {@see Sitemap}).
 *
 * And one write path, in a branch of its own ahead of the read gate: the
 * page-view beacon, ``POST /.well-known/globetrotters/pv``, always answered
 * with a bare ``204`` and counted only while page-view counting is on (see
 * {@see PageViewCounter}). The artefact surface itself stays
 * ``GET``/``HEAD``-only.
 */
class Router {

	private ArtefactCache $cache;
	private Options $options;
	private ?EventRecorder $recorder;
	private ?PageViewCounter $page_views;

	public function __construct(
		ArtefactCache $cache,
		Options $options,
		?EventRecorder $recorder = null,
		?PageViewCounter $page_views = null
	) {
		$this->cache      = $cache;
		$this->options    = $options;
		$this->recorder   = $recorder;
		$this->page_views = $page_views;
	}

	/**
	 * Register the interceptor.
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Serve the artefact when the current request targets one; otherwise return
	 * and let WordPress handle the request normally.
	 */
	public function maybe_serve(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = self::request_path( (string) $uri );
		if ( null === $path ) {
			return;
		}

		// The one write this router answers, matched on method *and* exact path
		// before the read gate, so no artefact path ever answers a POST.
		if ( self::is_post_request() && ltrim( PageViewCounter::PATH, '/' ) === $path ) {
			$this->maybe_count_page_view();
			return;
		}

		if ( ! self::is_read_request() ) {
			return;
		}

		// The artefact set is matched first. The Globetrotters edge proxy
		// declares its key route *before* the artefact catch-all and relies on
		// the key grammar (8-128 chars) to keep ``/llms.txt`` reaching the
		// artefact handler; the order here reaches the same outcome
		// structurally, so a served file can never be shadowed by whatever a
		// marker happens to carry.
		$served = ContentTypes::resolve( $path );
		if ( null !== $served ) {
			$this->maybe_serve_artefact( $served, $path );
			return;
		}

		if ( ApiCatalog::PATH === $path ) {
			$this->maybe_serve_api_catalog();
			return;
		}

		// The generated sitemap is matched before the key for the same
		// structural reason: ``ai-sitemap.xml`` is itself a well-formed key
		// filename (a ten-character ``[A-Za-z0-9-]`` stem), so an install whose
		// key happened to be ``ai-sitemap`` would otherwise shadow it.
		if ( Sitemap::PATH === $path ) {
			$this->maybe_serve_sitemap();
			return;
		}

		$this->maybe_serve_indexnow_key( $path );
	}

	/**
	 * Serve a cached artefact, or return and let WordPress handle the request.
	 *
	 * An alias ({@see ContentTypes::resolve()}) is answered in place with its
	 * canonical file's bytes and headers, and recorded under the path actually
	 * requested, which the backend maps to the same asset format. Answering here,
	 * at ``parse_request``, also keeps WordPress's canonical redirect out of it.
	 *
	 * @param string $path      Served apex path.
	 * @param string $requested Apex path as requested: ``$path`` or one of its aliases.
	 */
	private function maybe_serve_artefact( string $path, string $requested ): void {
		$body = $this->cache->get( $path );
		if ( null === $body ) {
			return;
		}

		// Capture before emitting: emit() ends the request with exit(). The
		// recorder swallows everything, so this cannot affect what is served.
		if ( null !== $this->recorder ) {
			$this->recorder->record( '/' . $requested, 200, strlen( $body ) );
		}

		$this->emit( $requested, $body, self::response_headers( $path ) );
	}

	/**
	 * Serve the generated API catalog, or fall through to WordPress's 404.
	 *
	 * Rendered from the cache on every request, so it lists only what this
	 * router answers, and answered only while it has something to list.
	 *
	 * Captured as agent traffic, unlike the sitemap and the key: an agent
	 * reading the catalog is asking for the presence by name, which is the
	 * demand Presence Analytics counts (the backend maps the path to
	 * ``well_known_api_catalog``). It gets the artefacts' headers for the same
	 * reasons, the CORS grant included — it is a discovery document a
	 * browser-context agent reads, listing documents it may already read.
	 */
	private function maybe_serve_api_catalog(): void {
		$origin = Sitemap::site_origin();
		if ( '' === $origin ) {
			return;
		}

		$body = ApiCatalog::render( $origin, $this->cache );
		if ( '' === $body ) {
			return;
		}

		if ( null !== $this->recorder ) {
			$this->recorder->record( '/' . ApiCatalog::PATH, 200, strlen( $body ) );
		}

		$this->emit( ApiCatalog::PATH, $body, self::artefact_headers( ApiCatalog::CONTENT_TYPE ) );
	}

	/**
	 * Serve the generated apex sitemap, or fall through to WordPress's 404.
	 *
	 * The listing is built from the artefact cache rather than fetched, so it
	 * can only ever name URLs this router answers — see {@see Sitemap} for why
	 * it is generated and why it is not served at ``/sitemap.xml``. An empty
	 * cache means there is nothing to list, and a urlset naming only the
	 * homepage would say less than the site's own sitemap already does, so the
	 * path is simply not answered until a bundle is installed. That matches
	 * {@see RobotsFilter}, which only advertises the line once one is.
	 *
	 * Deliberately not captured as agent traffic, on the IndexNow key's
	 * reasoning: Presence Analytics counts agent fetches of the discovery set,
	 * and a search engine reading a sitemap to schedule a crawl is not that.
	 */
	private function maybe_serve_sitemap(): void {
		// Gated on what the file would actually *list*, not merely on the cache
		// being non-empty: a bundle holding nothing but the version marker
		// (which the listing excludes) would otherwise answer 200 with a urlset
		// naming only the homepage — exactly the document this refuses to serve.
		$listable = Sitemap::listable( array_keys( $this->cache->files() ) );
		if ( array() === $listable ) {
			return;
		}

		$origin = Sitemap::site_origin();
		if ( '' === $origin ) {
			return;
		}

		$this->emit(
			Sitemap::PATH,
			Sitemap::render( $origin, $listable, $this->options->content_changed_at() ),
			self::sitemap_response_headers()
		);
	}

	/**
	 * Serve the IndexNow key when this request asks for exactly this site's key
	 * file; otherwise return and let WordPress answer, which is its normal 404.
	 *
	 * IndexNow verifies control of a host by reading ``https://{host}/{key}.txt``
	 * and comparing the body to the key. The apex is served by the customer's
	 * own stack, so nothing Globetrotters hosts can supply it here — without
	 * this route a file-drop apex simply cannot be announced.
	 *
	 * Never a 200 with an empty body when no key is stored: that answers the
	 * verification fetch with a file that fails it, which is worse than the
	 * absence the 404 truthfully reports.
	 *
	 * Deliberately not captured as agent traffic. Presence Analytics counts
	 * agent fetches of the artefact set; a search engine reading the key to
	 * verify host control is neither, and folding it in would inflate the
	 * numbers a customer reads as demand for their presence.
	 *
	 * @param string $path Apex-relative request path.
	 */
	private function maybe_serve_indexnow_key( string $path ): void {
		// Structural test first, so a normal page load never pays an option read.
		$candidate = IndexNowKey::candidate_from_path( $path );
		if ( '' === $candidate ) {
			return;
		}

		$key = $this->options->indexnow_key();
		if ( '' === $key || $candidate !== $key ) {
			return;
		}

		// The body is the key and nothing else — no trailing newline, matching
		// the Globetrotters edge proxy's key route, which returns the key verbatim.
		$this->emit( $path, $key, self::key_response_headers() );
	}

	/**
	 * Answer a page-view beacon, counting it only while counting is on.
	 *
	 * Answered whenever the plugin is active, counting on or off. Pages a cache
	 * stored while counting was on keep beaconing after it is switched off, for
	 * as long as the cache keeps them; letting those POSTs fall through would
	 * cost a full WordPress 404 render each, and feed security plugins' 404
	 * lockouts with a site's own visitors. The cheap answer is the same bare
	 * ``204`` either way, so the endpoint also reveals nothing about what it
	 * accepts. With counting off the body is not even read.
	 *
	 * The only request values read are the method and path (above), the
	 * declared length, the body, and the ``Origin``, ``Sec-Fetch-Site``,
	 * ``Referer`` and ``User-Agent`` headers — never ``REMOTE_ADDR``, a
	 * forwarding header or a cookie. None of them is kept beyond the path; see
	 * {@see PageViewCounter}.
	 *
	 * Not captured as agent traffic: a beacon is a person's browser, not an
	 * agent fetching the presence.
	 */
	private function maybe_count_page_view(): void {
		if ( null === $this->page_views ) {
			return;
		}
		if ( ! $this->options->counts_page_views() ) {
			$this->emit_status( 204, self::page_view_response_headers() );
			return;
		}

		$declared = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		// Read one byte past the cap, so an oversized body is recognised as one
		// rather than silently truncated into something that parses.
		$body = $declared > PageViewCounter::MAX_BODY_BYTES
			? ''
			: $this->read_body( PageViewCounter::MAX_BODY_BYTES + 1 );

		$this->page_views->handle(
			self::header( 'HTTP_ORIGIN' ),
			self::header( 'HTTP_REFERER' ),
			self::header( 'HTTP_USER_AGENT' ),
			$body,
			self::header( 'HTTP_SEC_FETCH_SITE' )
		);

		$this->emit_status( 204, self::page_view_response_headers() );
	}

	/**
	 * The response headers for the page-view beacon's ``204``.
	 *
	 * ``no-store`` on both headers so no cache ever answers a beacon in PHP's
	 * place — a cached ``204`` is a view that is never counted. No CORS grant:
	 * the beacon is same-origin by construction, and a cross-origin page has no
	 * business reading the answer. No cookie, ever.
	 *
	 * @return array<string, string>
	 */
	public static function page_view_response_headers(): array {
		return array(
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'no-store',
			'Surrogate-Control'      => 'no-store',
		);
	}

	/**
	 * One request header, unslashed, or ''.
	 *
	 * Takes the ``$_SERVER`` key rather than a header name so every read is a
	 * literal a reviewer can grep for.
	 *
	 * @param string $key ``$_SERVER`` key.
	 */
	private static function header( string $key ): string {
		// Compared and matched, never stored or echoed; see maybe_count_page_view().
		return isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Whether the current request is a ``POST``.
	 */
	private static function is_post_request(): bool {
		return 'POST' === self::request_method();
	}

	/**
	 * The upper-cased request method, or '' under CLI/cron.
	 */
	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
	}

	/**
	 * Whether the current request is one the artefact surface answers.
	 *
	 * The artefacts are read-only public documents, so only ``GET`` and ``HEAD``
	 * are served; anything else falls through to WordPress. This is not only
	 * correctness: serving is the sole place agent traffic to an apex install is
	 * counted, and answering a ``POST`` would fold write-method traffic — the
	 * probing kind, not the fetching kind — into the fetch counts Presence
	 * Analytics reports.
	 */
	public static function is_read_request(): bool {
		// Absent only under CLI/cron, where nothing is being served anyway.
		$method = self::request_method();
		return 'GET' === $method || 'HEAD' === $method;
	}

	/**
	 * The canonical apex-relative path a request URI names, or null when the URI
	 * is not in canonical form.
	 *
	 * Only the canonical form — a single leading slash, then the path exactly as
	 * it is spelled — is served. Everything else falls through to WordPress,
	 * which normalises what it can with its own canonical redirect.
	 *
	 * The leading-slash test is what stops the artefacts being published at an
	 * unbounded set of duplicate URLs. ``REQUEST_URI`` is an origin-form request
	 * target and always begins with exactly one slash; a second one turns the
	 * string into a protocol-relative URL, and ``parse_url`` then reads the
	 * segment after it as an *authority* and throws it away — so
	 * ``//anything/llms.txt`` parses to the path ``/llms.txt`` and would serve
	 * the artefact under a URL naming a host that is not this site. Duplicates
	 * split whatever crawl equity the file has, and none of them is the URL the
	 * bundle's own cross-file relations point at.
	 *
	 * Membership of the served set is *not* tested here: the artefact map and the
	 * runtime-named IndexNow key file share these rules and are matched
	 * separately by {@see maybe_serve}.
	 *
	 * @param string $uri Raw request URI (may include a query string).
	 */
	public static function request_path( string $uri ): ?string {
		if ( '' === $uri || '/' !== $uri[0] || '/' === ( $uri[1] ?? '' ) ) {
			return null;
		}
		// Safe to parse now that the string is known to be a plain rooted path:
		// this only strips the query and fragment.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '/' !== ( $path[0] ?? '' ) ) {
			return null;
		}
		// Deliberately not ltrim(): nothing here collapses or rewrites the
		// requested path, so ``/.well-known//mcp.json`` and ``/llms.txt/`` stay
		// misses rather than becoming aliases of a served file.
		return substr( $path, 1 );
	}

	/**
	 * The response headers for a served artefact.
	 *
	 * ``no-store`` on both headers is a **precondition for measurement, not an
	 * optimisation**. A framework-level "don't cache this page" flag like
	 * ``DONOTCACHEPAGE`` means nothing to Cloudflare, Varnish, Fastly or a
	 * managed-host edge: with a shared TTL in front of the origin, PHP never
	 * executes for the duration of that TTL and the reported hit count is
	 * silently low by an amount that varies per customer and per POP — a number
	 * wrong by an unknown amount is worse than no number at all.
	 * ``Surrogate-Control`` is what Cloudflare and Varnish honour;
	 * ``Cache-Control: no-store`` covers browsers, agents and everything else.
	 *
	 * ``Access-Control-Allow-Origin: *`` is granted because every artefact here
	 * is public, unauthenticated, read-only agent metadata: the same bytes are
	 * served to any anonymous GET, there is no cookie or credential in play, and
	 * a browser-context agent client cannot read a discovery document without
	 * it. Note this is the apex of a site we do not own, so the header is scoped
	 * to the artefact paths this router serves and never set on the rest of the
	 * customer's WordPress install.
	 *
	 * @param string $path Served apex path.
	 * @return array<string, string>
	 */
	public static function response_headers( string $path ): array {
		return self::artefact_headers( (string) ContentTypes::for_path( $path ) );
	}

	/**
	 * The artefact headers under a given Content-Type: what
	 * {@see response_headers()} sends, shared with the generated API catalog.
	 *
	 * @param string $content_type Content-Type value.
	 * @return array<string, string>
	 */
	private static function artefact_headers( string $content_type ): array {
		return array(
			'Content-Type'                => $content_type,
			'X-Content-Type-Options'      => 'nosniff',
			'Cache-Control'               => 'no-store',
			'Surrogate-Control'           => 'no-store',
			'Access-Control-Allow-Origin' => '*',
		);
	}

	/**
	 * The response headers for the IndexNow key file.
	 *
	 * ``no-store`` on both headers for the artefacts' measurement reason and for
	 * one of its own: the key is mutable state that reaches an install only on
	 * its next sync (``refresh_interval``, daily by default), so a cached copy of
	 * a rotated-away key fails verification for as long as it lives.
	 *
	 * No ``Access-Control-Allow-Origin``, deliberately. That header is granted to
	 * the artefacts because a browser-context agent client cannot read a
	 * discovery document without it; the key file is fetched server-side by a
	 * search engine and needs no such grant. This is the apex of a site we do not
	 * own, so the grant stays scoped to the paths that need it.
	 *
	 * A statement about what this plugin sends, not a guarantee about what
	 * reaches the client: nothing here strips a CORS header some other plugin or
	 * the server config adds to every response, and nothing should. The key is
	 * public by construction — its entire job is to be readable off the host it
	 * verifies — so cross-origin readability discloses nothing, and fighting a
	 * site owner's own header policy would cost them something for no gain.
	 * ``no-store`` is the opposite case and earns its insistence: a shared TTL
	 * breaks verification and measurement outright.
	 *
	 * @return array<string, string>
	 */
	public static function key_response_headers(): array {
		return array(
			'Content-Type'           => 'text/plain; charset=utf-8',
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'no-store',
			'Surrogate-Control'      => 'no-store',
		);
	}

	/**
	 * The response headers for the generated sitemap.
	 *
	 * ``no-store`` on both headers, but for freshness rather than measurement:
	 * the listing is derived from the artefact cache, so a shared TTL in front
	 * of the origin could keep advertising a URL a later refresh dropped.
	 * Rendering it costs one option read, so there is nothing to protect.
	 *
	 * No ``Access-Control-Allow-Origin``, matching the key file and for its
	 * reason: that grant exists so a browser-context agent client can read a
	 * discovery document, and a sitemap is fetched server-side by a crawler.
	 * The grant stays scoped to the paths that need it.
	 *
	 * @return array<string, string>
	 */
	public static function sitemap_response_headers(): array {
		return array(
			'Content-Type'           => Sitemap::CONTENT_TYPE,
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'no-store',
			'Surrogate-Control'      => 'no-store',
		);
	}

	/**
	 * Send the response and stop. Extracted so the routing decision above can
	 * be unit-tested without terminating the process.
	 *
	 * @param string                $path    Served apex path.
	 * @param string                $body    Response body.
	 * @param array<string, string> $headers Response headers.
	 * @codeCoverageIgnore
	 */
	protected function emit( string $path, string $body, array $headers ): void {
		// Answered (and, for an artefact, already captured) here: the shutdown
		// site capture must not count it a second time.
		SiteCapture::exclude_current_request();
		// Keep in-application page caches (WP Super Cache, W3 Total Cache, …) from
		// storing this dynamic apex response. Their write happens on shutdown,
		// which still runs after the exit below, so defining this here suppresses
		// it. It does nothing for a shared edge cache — that is what the headers
		// below are for. DONOTCACHEPAGE is a WordPress-defined cache-control
		// constant, so it is intentionally not plugin-prefixed.
		defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		status_header( 200 );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		// The body is a pre-serialised artefact (JSON / text) served verbatim
		// under its own Content-Type; escaping it would corrupt it.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Send a bodyless response and stop. The page-view beacon's counterpart of
	 * {@see emit()}, extracted for the same reason.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Response headers.
	 * @codeCoverageIgnore
	 */
	protected function emit_status( int $status, array $headers ): void {
		SiteCapture::exclude_current_request();
		// See emit(): keeps an in-application page cache from storing the answer.
		defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		status_header( $status );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		exit;
	}

	/**
	 * Read at most ``$max_bytes`` of the request body. A seam for the unit
	 * suite, which has no request body to read.
	 *
	 * @param int $max_bytes Most bytes to read.
	 * @codeCoverageIgnore
	 */
	protected function read_body( int $max_bytes ): string {
		$body = file_get_contents( 'php://input', false, null, 0, $max_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $body ) ? $body : '';
	}
}
