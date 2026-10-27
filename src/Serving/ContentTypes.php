<?php
/**
 * Apex artefact Content-Type map.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

/**
 * The canonical apex-path → Content-Type map.
 *
 * Mirrors the backend source of truth (the backend's content types and the
 * Globetrotters edge proxy's served-file lists), restricted to the backend's
 * apex discovery set plus
 * the version marker. Every path but the marker is a required artefact of a
 * refresh (see ``ArtefactSync::required_paths()``); the marker is fetched on
 * its own and synthesized when missing (``resolve_version_marker()``). Heavy
 * files (``llms-full.txt``, ``content.md``) are linked back to Globetrotters
 * and are intentionally absent.
 *
 * Paths are apex-relative with no leading slash.
 */
final class ContentTypes {

	private const MAP = array(
		'llms.txt'                                    => 'text/plain; charset=utf-8',
		'ai.json'                                     => 'application/json; charset=utf-8',
		'schema.json'                                 => 'application/ld+json; charset=utf-8',
		'.well-known/mcp.json'                        => 'application/json; charset=utf-8',
		'.well-known/agent-card.json'                 => 'application/json; charset=utf-8',
		// Since 0.7.0: the ARD catalog, the Agent Skills index and the root agent
		// specs. readiness scores ``agent_spec_coverage`` on any two of
		// ``agent.json`` / ``AGENTS.md`` / …, and ``ai_catalog`` /
		// ``agent_skills_index`` on their well-known paths, so serving them here
		// takes those checks off the customer's list. Mirrors the backend's apex
		// discovery set.
		self::AI_CATALOG                              => 'application/json; charset=utf-8',
		'.well-known/agent-skills/index.json'         => 'application/json; charset=utf-8',
		'agent.json'                                  => 'application/json; charset=utf-8',
		'AGENTS.md'                                   => 'text/markdown; charset=utf-8',
		'.well-known/globetrotters-apex-version.json' => 'application/json; charset=utf-8',
	);

	/**
	 * Alias path → the served path whose cached copy it answers with.
	 *
	 * Readiness scanners probe ``/agents.md``, ``/.well-known/agent-skills`` and
	 * its trailing-slash form for the discovery files, so those spellings get
	 * the same bytes and headers as the canonical file rather than a redirect
	 * a scanner may not follow. Kept out of {@see MAP} on purpose: an alias is
	 * never fetched by a sync, and the sitemap, the API catalog and the
	 * homepage ``Link`` header name canonical paths only. The backend's server-log
	 * ingest maps each alias to its canonical file's asset format. Mirrors the
	 * Globetrotters Symfony bundle.
	 */
	private const ALIASES = array(
		'agents.md'                 => 'AGENTS.md',
		'.well-known/agent-skills'  => '.well-known/agent-skills/index.json',
		'.well-known/agent-skills/' => '.well-known/agent-skills/index.json',
	);

	/**
	 * The apex-relative path of the ARD catalog, which ``Agentmap:`` and the
	 * homepage ``Link`` header name while it is cached.
	 */
	public const AI_CATALOG = '.well-known/ai-catalog.json';

	/**
	 * The apex-relative path of the drift/version marker.
	 *
	 * Handled specially during sync: fetched from Globetrotters when present,
	 * otherwise synthesized locally.
	 */
	public const VERSION_MARKER = '.well-known/globetrotters-apex-version.json';

	/**
	 * The list of apex-relative paths the plugin serves.
	 *
	 * @return string[]
	 */
	public static function paths(): array {
		return array_keys( self::MAP );
	}

	/**
	 * Content-Type for a served path, or null when the path is not served.
	 *
	 * @param string $path Apex-relative path (no leading slash).
	 */
	public static function for_path( string $path ): ?string {
		return self::MAP[ $path ] ?? null;
	}

	/**
	 * The served path a request path answers with: the path itself when it is
	 * served, its canonical file when it is an alias, otherwise null.
	 *
	 * @param string $path Apex-relative path (no leading slash).
	 */
	public static function resolve( string $path ): ?string {
		if ( self::has( $path ) ) {
			return $path;
		}
		return self::ALIASES[ $path ] ?? null;
	}

	/**
	 * Whether the plugin serves the given apex-relative path.
	 *
	 * @param string $path Apex-relative path (no leading slash).
	 */
	public static function has( string $path ): bool {
		return isset( self::MAP[ $path ] );
	}
}
