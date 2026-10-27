<?php
/**
 * Adds the agent discovery Link header to the homepage response.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

use Globetrotters\AiPresence\Cache\ArtefactCache;

/**
 * Adds the homepage ``Link`` header (RFC 8288): the IANA-registered
 * ``api-catalog`` (RFC 9727) and ``service-doc`` relations validators look for,
 * then the HTTP twin of the ``<link rel="mcp|agent-card|ai-catalog">`` tags
 * {@see Breadcrumbs} prints:
 *
 *     Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"
 *
 * readiness' ``check_link_headers`` reads it off the homepage response, so it
 * goes on a ``200`` HTML response for the front page only — the gate
 * {@see HeadInjector} uses, minus its paged, feed, embed and 404 variants —
 * and names only the targets {@see Router} would answer at these root-relative
 * paths: a cached artefact, or the {@see ApiCatalog} once it has something to
 * list.
 *
 * Sent as a header line of its own (``header( …, false )``), never by
 * replacing: core already sends a ``Link`` header on every front-end page
 * (``rest_output_link_header``, ``wp_shortlink_header``), a theme or SEO
 * plugin may add more, and HTTP reads repeated ``Link`` lines as one list.
 *
 * Hooked on ``template_redirect``, the first point at which the main query
 * (and so ``is_front_page()`` and ``is_404()``) is known while headers can
 * still be sent — the same hook and priority core sends its own ``Link``
 * headers from. Mirrors the Symfony bundle's ``Serving\LinkHeader``.
 */
class LinkHeader {

	/**
	 * Apex path → relation, in served order: registered relations first (what
	 * validators read), ours after. Mirrors the backend's apex homepage link
	 * relations and the Globetrotters Symfony bundle.
	 *
	 * @var array<string, string>
	 */
	public const RELS = array(
		ApiCatalog::PATH              => 'api-catalog',
		'llms.txt'                    => 'service-doc',
		'.well-known/mcp.json'        => 'mcp',
		'.well-known/agent-card.json' => 'agent-card',
		ContentTypes::AI_CATALOG      => 'ai-catalog',
	);

	/**
	 * ``template_redirect`` priority: core's ``rest_output_link_header`` and
	 * ``wp_shortlink_header`` run at 11, after ``redirect_canonical`` (10) has
	 * had its chance to redirect instead.
	 */
	public const PRIORITY = 11;

	private ArtefactCache $cache;

	public function __construct( ArtefactCache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register the hook.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_send' ), self::PRIORITY );
	}

	/**
	 * Add the header when this response is the homepage's HTML page.
	 */
	public function maybe_send(): void {
		if ( ! is_front_page() || is_paged() || is_404() || is_feed() || is_embed() ) {
			return;
		}
		if ( 200 !== $this->status() ) {
			return;
		}

		$sent = $this->headers_so_far();
		if ( ! self::is_html( $sent ) ) {
			return;
		}

		$value = $this->value();
		if ( '' === $value ) {
			return;
		}

		$line = 'Link: ' . $value;
		if ( in_array( $line, $sent, true ) ) {
			return;
		}

		$this->send_header( $line, false );
	}

	/**
	 * The header value for what this install serves, '' when it serves none of
	 * it.
	 */
	public function value(): string {
		// Each target is read from the cache once: the catalog is served exactly
		// when one of its targets is, and every one of them is also named here.
		$served = array();
		foreach ( array_keys( self::RELS + ApiCatalog::RELS ) as $path ) {
			if ( ApiCatalog::PATH !== $path && null !== $this->cache->get( $path ) ) {
				$served[ $path ] = true;
			}
		}
		if ( ApiCatalog::is_served_with( array_keys( $served ) ) ) {
			$served[ ApiCatalog::PATH ] = true;
		}

		$parts = array();
		foreach ( self::RELS as $path => $rel ) {
			if ( isset( $served[ $path ] ) ) {
				$parts[] = sprintf( '</%s>; rel="%s"', $path, $rel );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Whether the header lines queued so far describe an HTML response.
	 *
	 * The last ``Content-Type`` line wins, as it does on the wire. None at all
	 * reads as HTML: that is PHP's own ``default_mimetype``, and what WordPress
	 * sends for a page.
	 *
	 * @param string[] $headers Header lines, as ``headers_list()`` returns them.
	 */
	public static function is_html( array $headers ): bool {
		$type = null;
		foreach ( $headers as $header ) {
			$parts = explode( ':', $header, 2 );
			if ( 2 === count( $parts ) && 'content-type' === strtolower( trim( $parts[0] ) ) ) {
				$type = strtolower( trim( $parts[1] ) );
			}
		}

		return null === $type || 0 === strpos( $type, 'text/html' );
	}

	/**
	 * The response status so far. ``http_response_code()`` is ``false`` where
	 * there is no response (CLI), which only the test suite reaches.
	 *
	 * @codeCoverageIgnore
	 */
	protected function status(): int {
		$code = http_response_code();
		return is_int( $code ) ? $code : 200;
	}

	/**
	 * The header lines queued so far. A seam for the unit suite, where the CLI
	 * SAPI keeps none.
	 *
	 * @return string[]
	 * @codeCoverageIgnore
	 */
	protected function headers_so_far(): array {
		return headers_list();
	}

	/**
	 * Queue one header line. A seam for the unit suite, which cannot observe
	 * ``header()``.
	 *
	 * @param string $line    Full header line.
	 * @param bool   $replace Whether it replaces a same-named header.
	 * @codeCoverageIgnore
	 */
	protected function send_header( string $line, bool $replace ): void {
		if ( headers_sent() ) {
			return;
		}
		header( $line, $replace );
	}
}
