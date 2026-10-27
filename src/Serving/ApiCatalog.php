<?php
/**
 * The locally generated RFC 9727 API catalog.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

use Globetrotters\AiPresence\Cache\ArtefactCache;

/**
 * Renders this site's RFC 9727 API catalog, answered by {@see Router} at
 * ``/.well-known/api-catalog``: an RFC 9264 linkset whose single entry is
 * anchored on the homepage and points at the agent surface this install serves.
 *
 * **Generated locally, never fetched**, for the {@see Sitemap}'s reason: the
 * Globetrotters edge proxy renders the same document per host, so a
 * fetched copy would name the Globetrotters host. Built from this site's own
 * origin and from the paths the cache holds, it cannot list a URL this install
 * does not answer.
 *
 * Same shape as the edge proxy's, minus ``/okf``, which no companion serves
 * locally. readiness' ``check_api_catalog`` scores a linkset entry carrying both
 * ``service-desc`` and ``service-doc`` at 100. Mirrors the Symfony bundle's
 * ``Serving\ApiCatalog``.
 *
 * Since 0.9.1 the entry also carries RFC 9727's ``item`` relation, one link per
 * API the host exposes. Today that is the MCP server, read from the cached
 * ``mcp.json``; it lives on the Globetrotters host, so it is the one link here
 * that does not resolve on this site's origin.
 */
final class ApiCatalog {

	/**
	 * The apex-relative path this document is served at.
	 *
	 * Not a {@see ContentTypes} entry: that map drives the sync's required
	 * paths, and this file is generated rather than pulled.
	 */
	public const PATH = '.well-known/api-catalog';

	/**
	 * RFC 9727 §5: no charset parameter is defined for the linkset media type.
	 */
	public const CONTENT_TYPE = 'application/linkset+json';

	/**
	 * Apex path → [relation, target media type], in served order. Mirrors the
	 * Globetrotters edge proxy's catalog and the Symfony bundle.
	 *
	 * The catalog is answered exactly when one of these is cached.
	 * {@see LinkHeader} and {@see Sitemap} apply that rule to the cache reads
	 * they already make ({@see is_served_with()}), so neither advertises a
	 * catalog that would 404.
	 *
	 * @var array<string, array{0:string, 1:string}>
	 */
	public const RELS = array(
		'llms.txt'                    => array( 'service-doc', 'text/plain' ),
		'.well-known/mcp.json'        => array( 'service-desc', 'application/json' ),
		'.well-known/agent-card.json' => array( 'service-desc', 'application/json' ),
		ContentTypes::AI_CATALOG      => array( 'service-desc', 'application/json' ),
	);

	/**
	 * The document, or '' when none of its targets is cached.
	 *
	 * @param string        $origin Site origin, as {@see Sitemap::site_origin()} returns it.
	 * @param ArtefactCache $cache  The artefact cache.
	 */
	public static function render( string $origin, ArtefactCache $cache ): string {
		$origin = rtrim( $origin, '/' );
		$entry  = array();
		foreach ( self::RELS as $path => list( $rel, $type ) ) {
			if ( null !== $cache->get( $path ) ) {
				$entry[ $rel ][] = array(
					'href' => $origin . '/' . $path,
					'type' => $type,
				);
			}
		}
		if ( array() === $entry ) {
			return '';
		}

		$mcp_server = self::mcp_server_url( $cache );
		if ( '' !== $mcp_server ) {
			$entry['item'] = array(
				array(
					'href' => $mcp_server,
					'type' => 'application/json',
				),
			);
		}

		$linkset = array( 'linkset' => array( array( 'anchor' => $origin . '/' ) + $entry ) );
		$json    = wp_json_encode( $linkset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json . "\n" : '';
	}

	/**
	 * The MCP server's URL, ``remotes[0].url`` in the cached ``mcp.json``, or ''
	 * when the file is not cached or names no remote. Read as published, never
	 * rewritten onto this site's origin: the server is reached where the
	 * manifest says it is.
	 *
	 * @param ArtefactCache $cache The artefact cache.
	 */
	private static function mcp_server_url( ArtefactCache $cache ): string {
		$manifest = json_decode( (string) $cache->get( '.well-known/mcp.json' ), true );
		$url      = is_array( $manifest ) ? $manifest['remotes'][0]['url'] ?? null : null;

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Whether the catalog is served alongside a set of cached paths: true
	 * exactly when one of its targets is among them.
	 *
	 * @param string[] $paths Apex-relative paths currently in the cache.
	 */
	public static function is_served_with( array $paths ): bool {
		return array() !== array_intersect( array_keys( self::RELS ), $paths );
	}
}
