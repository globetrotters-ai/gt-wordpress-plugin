<?php
/**
 * The IndexNow verification key and the path it is served at.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

/**
 * Shape rules for the IndexNow key this apex serves at ``/<key>.txt``.
 *
 * IndexNow verifies control of a host by reading that file, and per
 * `indexnow.org/documentation <https://www.indexnow.org/documentation>`_ a key
 * file scopes submissions to **its own directory** — a key under
 * ``/.well-known/`` could cover ``mcp.json`` and ``agent-card.json`` but not
 * ``llms.txt`` / ``ai.json`` / ``schema.json``. So the key sits at the root, and
 * the filename is ``<key>.txt`` regardless of location (per the IndexNow FAQ).
 *
 * The key reaches this plugin over the network, on the version marker pulled
 * from the configured ``website_url`` — untrusted input like every other byte
 * from that origin. A value outside IndexNow's own grammar cannot verify a host
 * anyway, so it reads as *unconfigured* rather than as something to serve. That
 * is the same posture as the backend's IndexNow key grammar, which is what decides
 * whether the field is put on the marker at all; matching it here means the two
 * ends agree on what counts as a key.
 */
final class IndexNowKey {

	/**
	 * IndexNow's own key grammar: 8-128 characters of ``[A-Za-z0-9-]``.
	 *
	 * ``\z`` rather than ``$``, deliberately and for the same reason the backend
	 * uses ``fullmatch`` on an unanchored pattern: ``$`` also matches *before* a
	 * trailing newline, so ``$`` here would accept ``"<key>\n"`` — a stray
	 * newline in a tfvars value is exactly the misconfiguration worth catching,
	 * and it would land inside a served response body.
	 */
	private const PATTERN = '/\A[A-Za-z0-9-]{8,128}\z/';

	/**
	 * The suffix a key file's path carries.
	 */
	private const SUFFIX = '.txt';

	/**
	 * The key as it may be used, or an empty string when it may not be.
	 *
	 * @param mixed $raw Value read from the marker or from stored state.
	 */
	public static function sanitize( $raw ): string {
		return is_string( $raw ) && 1 === preg_match( self::PATTERN, $raw ) ? $raw : '';
	}

	/**
	 * The key a requested path would be the file for, or '' when it cannot be
	 * one — a cheap structural test that runs before any stored value is read.
	 *
	 * No path {@see ContentTypes} serves today parses as a candidate — none is a
	 * root-level ``<key>.txt`` whose stem is eight or more ``[A-Za-z0-9-]``
	 * characters. Convenience, not the guarantee: ``llms-full.txt`` has a stem
	 * that *is* well-formed, and it is one decision away from being served
	 * locally. What actually stops a key shadowing an artefact is that
	 * {@see Router::maybe_serve} matches the map **first**, pinned by RouterTest
	 * over every path in it rather than over a hand-listed sample.
	 *
	 * @param string $path Apex-relative request path (no leading slash).
	 */
	public static function candidate_from_path( string $path ): string {
		$stem_length = strlen( $path ) - strlen( self::SUFFIX );
		if ( $stem_length < 1 || substr( $path, $stem_length ) !== self::SUFFIX ) {
			return '';
		}
		return self::sanitize( substr( $path, 0, $stem_length ) );
	}
}
