<?php
/**
 * Site-wide bot capture on shutdown (design B).
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Serving\LinkHeader;
use Globetrotters\AiPresence\Serving\Router;
use Globetrotters\AiPresence\Settings\Options;
use Throwable;

/**
 * Records one site event per bot fetch of a site page, so the backend can tell
 * which of the customer's own pages AI agents read.
 *
 * Runs on ``shutdown``, last, once the response status and headers are final.
 * A coarse gate (contract section 3) decides **before any storage write**;
 * fine classification stays on the backend, so a new crawler needs no plugin
 * release. When the gate passes and PHP-FPM is in use, the response is handed
 * back to the client first (``fastcgi_finish_request()``), so the bot never
 * waits on the buffer write.
 *
 * A site event never holds an ip. The client ip is resolved with the trusted
 * proxy rules, matched in memory against the synced vendor ranges, and only
 * the verdict (``verified`` + ``vendor``) is kept.
 *
 * Like {@see EventRecorder}, nothing here may affect a response: every
 * Throwable is swallowed.
 */
final class SiteCapture {

	/**
	 * The coarse UA gate, verbatim from the contract.
	 */
	public const BOT_MARKER = '/bot|crawl|spider|slurp|fetch|compatible;|gpt|claude|perplexity|python|curl|wget|go-http|headlesschrome|phantomjs/i';

	/**
	 * WordPress's own directories and root scripts. Not a bare ``/wp-`` prefix:
	 * a permalink like ``/wp-performance-tips`` is a site page.
	 */
	private const WP_INTERNAL = '#^/wp-(?:admin|content|includes|json)(?:/|$)|^/wp-[a-z-]+\.php(?:/|$)#';

	/**
	 * Static asset extensions: never a page, whatever served them.
	 */
	private const ASSET_EXTENSION = '/\.(?:css|js|mjs|map|json|xml|txt|png|jpe?g|gif|webp|avif|svg|ico|bmp|tiff?|woff2?|ttf|otf|eot|mp4|webm|mov|mp3|wav|ogg|m4a|pdf|zip|gz|tgz|rar|7z|csv|rss|atom)$/i';

	/**
	 * Set when this request was already answered (and captured, where it is
	 * captured) by the plugin itself: an artefact, the sitemap, the IndexNow
	 * key, the page-view beacon. Those exit, which still runs ``shutdown``.
	 *
	 * @var bool
	 */
	private static bool $handled = false;

	private EventBuffer $buffer;
	private Options $options;
	private FlushRequest $flushes;
	private VendorRanges $ranges;
	private CaptureContext $context;

	public function __construct( EventBuffer $buffer, Options $options, FlushRequest $flushes, VendorRanges $ranges, CaptureContext $context ) {
		$this->buffer  = $buffer;
		$this->options = $options;
		$this->flushes = $flushes;
		$this->ranges  = $ranges;
		$this->context = $context;
	}

	/**
	 * Hook the capture. Last on shutdown: after everything else has had its say
	 * on the response, and after anything that still prints (a debug bar).
	 */
	public function register(): void {
		add_action( 'shutdown', array( $this, 'on_shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Mark the current request as answered by the plugin itself.
	 */
	public static function exclude_current_request(): void {
		self::$handled = true;
	}

	/**
	 * Reset the per-request flag (tests).
	 */
	public static function reset(): void {
		self::$handled = false;
	}

	/**
	 * Shutdown callback.
	 */
	public function on_shutdown(): void {
		try {
			if ( self::$handled || ! $this->options->captures_site_bots() ) {
				return;
			}
			// The UA gate first: a human page load must cost a string match, not
			// the request copy, headers_list() and the main-query conditionals.
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_scalar( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! self::is_bot_ua( $ua ) && ! self::is_signed_request() ) {
				return;
			}
			$code = http_response_code();
			$this->capture(
				self::request_values(),
				headers_list(),
				is_int( $code ) ? $code : 200,
				self::is_excluded_context(),
				true
			);
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Gate, then build and buffer the event. Returns whether one was written.
	 *
	 * @param array<string, string> $server   Request values (``$_SERVER`` keys).
	 * @param string[]              $headers  Response header lines.
	 * @param int                   $status   Final response status.
	 * @param bool                  $excluded Admin, REST, ajax, cron, feed, preview...
	 * @param bool                  $finish   Whether to hand the response back first.
	 */
	public function capture( array $server, array $headers, int $status, bool $excluded, bool $finish = false ): bool {
		try {
			if ( self::$handled || $excluded || ! $this->options->captures_site_bots() || ! Schema::site_events_ready() ) {
				return false;
			}
			$path = self::gate( $server, $headers );
			if ( null === $path ) {
				return false;
			}

			if ( $finish ) {
				self::finish_response();
			}

			$this->context->record_markers( $server );
			$verdict = $this->ranges->verify( ClientIp::resolve( $server, $this->options->trusted_proxies() ) );
			$event   = ( Event::site(
				wp_generate_uuid4(),
				gmdate( 'Y-m-d\TH:i:s\Z' ),
				$path,
				$server['HTTP_USER_AGENT'] ?? '',
				$status,
				self::content_length( $headers ),
				$verdict['verified'],
				$verdict['vendor']
			) )->with_signature( RequestSignature::from_server( $server ) );
			if ( $this->buffer->append( $event ) ) {
				$this->flushes->request_flush();
			}
			return true;
		} catch ( Throwable $error ) {
			unset( $error );
			return false;
		}
	}

	/**
	 * The request-level half of the gate. Returns the normalised path when the
	 * request qualifies, null otherwise. A bot UA or, since contract v7, a
	 * non-empty ``Signature-Agent`` (a signed agent may send a browser UA).
	 *
	 * @param array<string, string> $server  Request values.
	 * @param string[]              $headers Response header lines.
	 */
	public static function gate( array $server, array $headers ): ?string {
		$method = strtoupper( $server['REQUEST_METHOD'] ?? '' );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}
		if ( ! self::is_bot_ua( $server['HTTP_USER_AGENT'] ?? '' ) && ! RequestSignature::is_signed( $server[ RequestSignature::AGENT_KEY ] ?? '' ) ) {
			return null;
		}
		$path = self::normalise_path( $server['REQUEST_URI'] ?? '' );
		if ( null === $path || self::is_excluded_path( $path ) ) {
			return null;
		}
		return LinkHeader::is_html( $headers ) ? $path : null;
	}

	/**
	 * The UA gate: a broad bot marker, or no ``Mozilla/`` browser token at all
	 * (an empty UA included).
	 *
	 * @param string $ua User-Agent.
	 */
	public static function is_bot_ua( string $ua ): bool {
		return 1 === preg_match( self::BOT_MARKER, $ua ) || false === strpos( $ua, 'Mozilla/' );
	}

	/**
	 * Whether the current request carries a ``Signature-Agent`` (contract v7):
	 * a signed agent may send a stock browser User-Agent. Read straight from
	 * ``$_SERVER``, before any copy, like the UA.
	 */
	private static function is_signed_request(): bool {
		return isset( $_SERVER[ RequestSignature::AGENT_KEY ] ) && is_scalar( $_SERVER[ RequestSignature::AGENT_KEY ] ) && RequestSignature::is_signed( (string) $_SERVER[ RequestSignature::AGENT_KEY ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	}

	/**
	 * The request path as a site event carries it: no query or fragment,
	 * leading slash, at most 512 bytes, trailing slash folded (``/`` stays).
	 * Null for a URI that is not a plain rooted path.
	 *
	 * @param string $uri Raw request URI.
	 */
	public static function normalise_path( string $uri ): ?string {
		$relative = Router::request_path( $uri );
		if ( null === $relative ) {
			return null;
		}
		// Clipped rather than refused (a crawler URL is not ours to reject),
		// then the page-view counter's rules: the contract's one normalisation.
		return PageViewCounter::normalise_path( substr( '/' . $relative, 0, PageViewCounter::MAX_PATH_BYTES ) );
	}

	/**
	 * Paths that are never a site page: WordPress internals, the counter
	 * endpoint, the artefact set, the profiler, static assets.
	 *
	 * @param string $path Normalised path.
	 */
	public static function is_excluded_path( string $path ): bool {
		if ( 1 === preg_match( self::WP_INTERNAL, $path ) || 0 === strpos( $path, '/xmlrpc.php' ) || 0 === strpos( $path, '/.well-known/' ) ) {
			return true;
		}
		if ( 0 === strpos( $path, '/_profiler' ) || 0 === strpos( $path, '/_wdt' ) ) {
			return true;
		}
		if ( PageViewCounter::PATH === $path || null !== \Globetrotters\AiPresence\Serving\ContentTypes::resolve( ltrim( $path, '/' ) ) ) {
			return true;
		}
		return 1 === preg_match( self::ASSET_EXTENSION, $path );
	}

	/**
	 * The ``Content-Length`` already queued, or null when it is not known
	 * without buffering the body.
	 *
	 * @param string[] $headers Response header lines.
	 */
	public static function content_length( array $headers ): ?int {
		foreach ( $headers as $header ) {
			$parts = explode( ':', $header, 2 );
			if ( 2 === count( $parts ) && 'content-length' === strtolower( trim( $parts[0] ) ) ) {
				$value = trim( $parts[1] );
				return ctype_digit( $value ) ? (int) $value : null;
			}
		}
		return null;
	}

	/**
	 * Whether WordPress is answering something other than a front-end page.
	 *
	 * @codeCoverageIgnore Reads runtime constants and the main query.
	 */
	private static function is_excluded_context(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		// The conditionals need the main query; without one (an early exit)
		// there is no template response to capture.
		if ( ! isset( $GLOBALS['wp_query'] ) || ! did_action( 'wp' ) ) {
			return true;
		}
		return is_feed() || is_preview() || is_customize_preview() || is_trackback() || is_robots();
	}

	/**
	 * Hand the response back to the client before writing.
	 *
	 * @codeCoverageIgnore SAPI-specific.
	 */
	private static function finish_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * The request values the gate and the event read.
	 *
	 * @return array<string, string>
	 */
	private static function request_values(): array {
		$keys   = array_merge( array( 'REQUEST_METHOD', 'REQUEST_URI', 'HTTP_USER_AGENT', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ), CaptureContext::server_keys(), RequestSignature::server_keys() );
		$values = array();
		foreach ( $keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_scalar( $_SERVER[ $key ] ) ) {
				// Verbatim, as EventRecorder records it: evidence of what the agent
				// sent. Clipped by Event, parameter-bound on write.
				$values[ $key ] = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		return $values;
	}
}
