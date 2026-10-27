<?php
/**
 * The locally generated apex sitemap.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

/**
 * Renders a sitemaps.org 0.9 ``<urlset>`` over the artefacts this site serves.
 *
 * **Generated here, never fetched.** Globetrotters does publish a per-tenant
 * ``sitemap.xml`` artefact, but its ``<loc>`` entries are in the *GT host* URL
 * space, and this plugin serves every synced artefact verbatim — there is no
 * origin-rewriting machinery in this repo, and a sitemap may only list
 * same-host URLs. Building the listing locally needs no fetch, no rewrite and
 * no backend change, and it is self-consistent by construction: the paths come
 * from the artefact cache, so the file can never advertise a URL this install
 * does not answer. That is the failure the backend renderer's
 * ``offloaded_paths`` strip exists to prevent, avoided here structurally.
 *
 * **Served at ``/ai-sitemap.xml``, deliberately not ``/sitemap.xml``.** On the
 * Globetrotters-hosted subdomain the edge proxy owns the whole host, so
 * ``/sitemap.xml`` is free. A customer's apex is not ours: WordPress core
 * publishes ``/wp-sitemap.xml`` (and already advertises it in robots.txt),
 * Yoast and Rank Math claim ``/sitemap_index.xml`` and redirect
 * ``/sitemap.xml`` onto it, and plenty of sites have a physical
 * ``/sitemap.xml``. Answering that path from ``parse_request`` at priority 0
 * would shadow the site's real sitemap with an artefact listing —
 * widening the plugin into an install we do not own, which is exactly what the
 * scoped CORS grant and the read-only method gate exist to avoid. A distinct
 * name collides with nothing, needs no yield heuristic, and costs nothing:
 * robots.txt takes a *list* of ``Sitemap:`` directives, so ours is additive to
 * whatever the site already declares.
 */
final class Sitemap {

	/**
	 * The apex-relative path this document is served at.
	 *
	 * Not a {@see ContentTypes} entry: that map drives the sync's required
	 * paths, and this file is generated rather than pulled. It is matched by
	 * {@see Router} the same way the IndexNow key is.
	 */
	public const PATH = 'ai-sitemap.xml';

	/**
	 * The Content-Type, matching both backend emitters.
	 */
	public const CONTENT_TYPE = 'application/xml; charset=utf-8';

	/**
	 * Render the urlset for a set of served artefact paths.
	 *
	 * Lists the site root plus every cached artefact {@see listable} admits, in
	 * {@see ContentTypes} order, so identical inputs produce byte-identical
	 * output.
	 *
	 * ``$changed_at`` stamps ``<lastmod>`` and is the moment the *content* last
	 * changed, not the moment it was last fetched — refreshing daily and finding
	 * the same bytes is not a modification, and stamping it as one asserts a
	 * freshness the content does not have. ``0`` (an install that has not seen a
	 * change since the plugin started recording one) omits the element
	 * entirely, which is valid: ``<lastmod>`` is optional, and an absent stamp
	 * is better than a false one.
	 *
	 * **The stamp applies to the artefacts only, never to the site root.** ``/``
	 * is the customer's own homepage: they edit it in WordPress whenever they
	 * like, and it moves independently of the presence bundle. Stamping it with
	 * the bundle's change date would tell a crawler a homepage rewritten today
	 * was last modified whenever the artefacts last moved — a freshness claim
	 * about a page this plugin knows nothing about, and the signal search
	 * engines use to decide whether to recrawl. The backend renderer restricts
	 * its own stamp for exactly this reason (``_render_sitemap_xml``'s
	 * ``lastmod_locs``). WordPress's ``/wp-sitemap.xml`` already carries the
	 * homepage with a date it can actually vouch for.
	 *
	 * @param string   $origin     Site origin, no trailing slash.
	 * @param string[] $paths      Apex-relative paths currently in the cache.
	 * @param int      $changed_at Unix time the bundle content last changed.
	 */
	public static function render( string $origin, array $paths, int $changed_at ): string {
		$stamp = $changed_at > 0
			? "\n    <lastmod>" . gmdate( 'Y-m-d', $changed_at ) . '</lastmod>'
			: '';

		// The site root is listed unstamped; the artefacts carry the bundle's date.
		$urls = self::url_xml( $origin . '/', '' );
		foreach ( self::listable( $paths ) as $path ) {
			$urls .= self::url_xml( $origin . '/' . $path, $stamp );
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
			. $urls
			. "</urlset>\n";
	}

	/**
	 * The cached paths this sitemap lists, in {@see ContentTypes} order, then
	 * the {@see ApiCatalog} whenever it has something to list.
	 *
	 * Intersecting with the served map rather than trusting the caller is what
	 * makes the listing self-consistent: a path the plugin does not serve cannot
	 * reach the file. The version marker is excluded — it is machine
	 * bookkeeping for the sync lane, not a surface anyone should be pointed at
	 * to crawl. Empty means there is nothing worth publishing, which is the
	 * condition {@see Router} refuses to answer on.
	 *
	 * @param string[] $paths Apex-relative paths currently in the cache.
	 * @return string[]
	 */
	public static function listable( array $paths ): array {
		$listable = array();
		foreach ( ContentTypes::paths() as $path ) {
			if ( ContentTypes::VERSION_MARKER !== $path && in_array( $path, $paths, true ) ) {
				$listable[] = $path;
			}
		}
		// Rendered from the same cache, so it is served exactly when one of its
		// targets is among the artefacts above, and is dated by the same change.
		if ( ApiCatalog::is_served_with( $listable ) ) {
			$listable[] = ApiCatalog::PATH;
		}
		return $listable;
	}

	/**
	 * One ``<url>`` element.
	 *
	 * @param string $loc   Absolute URL.
	 * @param string $stamp Rendered ``<lastmod>`` element, or '' for none.
	 */
	private static function url_xml( string $loc, string $stamp ): string {
		return "  <url>\n    <loc>" . esc_xml( $loc ) . '</loc>' . $stamp . "\n  </url>\n";
	}

	/**
	 * This site's origin — scheme, host and port, no path and no trailing slash.
	 *
	 * Taken from the host root rather than from ``home_url()`` verbatim because
	 * that is what {@see Router} actually answers: it matches ``REQUEST_URI``
	 * against root-relative paths, so on the subdirectory install where
	 * ``home_url()`` carries a path the artefacts are not served under it
	 * anyway. (A subdirectory install is already outside what this plugin
	 * supports — WordPress's virtual ``robots.txt`` is not served at the host
	 * root there either — so this keeps the sitemap honest rather than making
	 * that case work.)
	 */
	public static function site_origin(): string {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port;
	}
}
