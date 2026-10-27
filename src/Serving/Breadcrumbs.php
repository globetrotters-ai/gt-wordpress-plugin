<?php
/**
 * Links the apex back to the Globetrotters-hosted copy of the presence.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Settings\Options;

/**
 * The apex half of the ``subdomain_breadcrumb`` install profile.
 *
 * In that profile the presence is deployed on both lanes at once: this plugin
 * serves the small must-be-local artefact set at the apex, and Globetrotters
 * publishes the full set on a subdomain (``ai.<their-domain>``, or
 * ``<slug>.globetrotters.ai`` before a managed hostname activates). Nothing on
 * the public web points at that subdomain, so an agent reaches it only if it
 * already knows the hostname.
 *
 * This emits the head half of the block a customer otherwise pastes into a
 * theme template by hand (the Studio's inbound-link snippet), against
 * a host derived from the artefacts rather than typed in: ``rel="alternate"``
 * for the JSON-LD plus the non-standard ``rel="ai-catalog"`` / ``rel="mcp"`` /
 * ``rel="agent-card"`` relations, mirroring what the published pages emit
 * server-side.
 *
 * **Each relation points at the canonical copy of its own file, which is not the
 * same host for all four.** The apex is canonical: where
 * this install serves a file itself — ``schema.json``, ``.well-known/mcp.json``,
 * ``.well-known/agent-card.json``, everything in {@see ContentTypes} — the link
 * is root-relative and resolves to the customer's own domain, which is the copy
 * carrying the index membership and authority. Sending an agent to the
 * subdomain's copy of a file this very host answers at 200 would promote the
 * mirror over the original.
 *
 * Since 0.7.0 that is all four: ``.well-known/ai-catalog.json`` joined the
 * served set, and was until then the one relation naming the subdomain. A
 * relation still names the Globetrotters host for a file outside
 * ``ContentTypes::MAP``, or one in it that is not cached yet (a bundle cached
 * before the upgrade, until its next refresh) — the Globetrotters copy is then
 * the only one there is. The test is therefore ``ContentTypes::has()`` plus the
 * cache, not a hardcoded split — a file added to the served set starts
 * resolving locally on its own, once it has been pulled.
 *
 * **``rel="related"`` names the presence host itself**, root URL, on every page
 * and whatever the cache holds. Since 0.7.0 the discovery relations all resolve
 * to the apex once cached, so without it the profile would no longer point at
 * the Globetrotters host from ``<head>`` at all — and this plugin emits no
 * footer anchor to do it instead. Matches ``Breadcrumb::headBlock()`` in
 * the Globetrotters Symfony bundle (0.8.1).
 *
 * The component's *visible* footer anchor is deliberately not emitted. A plugin
 * injecting a link at ``wp_footer`` has no container to put it in — the hook
 * fires after all theme markup, just before ``</body>`` — so it lands outside
 * the layout on block and classic themes alike, and no styling makes that
 * belong on a design we do not own. The cost is real and worth stating: a
 * ``<link>`` is a discovery pointer, not a followed link, so the subdomain still
 * inherits none of the apex's crawl authority. That anchor stays a copy-paste
 * the customer places themselves.
 *
 * Nothing is emitted under ``full_apex``, and nothing is emitted when the host
 * is unknown — a pointer at a placeholder, or at a host that has stopped
 * serving, is worse than none.
 */
final class Breadcrumbs {

	private Options $options;
	private ArtefactCache $cache;

	/**
	 * Per-request memo of the resolved origin ('' = nothing to emit).
	 *
	 * @var string|null
	 */
	private ?string $origin = null;

	public function __construct( Options $options, ArtefactCache $cache ) {
		$this->options = $options;
		$this->cache   = $cache;
	}

	/**
	 * Register the hook.
	 *
	 * Sits alongside {@see HeadInjector} at ``wp_head`` 20.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'inject_head' ), 20 );
	}

	/**
	 * Print the discovery ``<link>`` block.
	 */
	public function inject_head(): void {
		$origin = $this->safe_origin();
		if ( '' === $origin ) {
			return;
		}

		echo "<!-- Globetrotters — AI presence -->\n";
		// Only on the front page, matching HeadInjector's own gate: the linked
		// JSON-LD describes the destination, so advertising it as an interior
		// page's alternate representation would be a claim about that page which
		// is not true.
		if ( is_front_page() ) {
			printf(
				'<link rel="alternate" type="application/ld+json" href="%s">' . "\n",
				esc_url( $this->href( 'schema.json', $origin ) )
			);
		}
		printf(
			'<link rel="ai-catalog" href="%s">' . "\n",
			esc_url( $this->href( '.well-known/ai-catalog.json', $origin ) )
		);
		printf(
			'<link rel="mcp" href="%s">' . "\n",
			esc_url( $this->href( '.well-known/mcp.json', $origin ) )
		);
		printf(
			'<link rel="agent-card" href="%s">' . "\n",
			esc_url( $this->href( '.well-known/agent-card.json', $origin ) )
		);
		// The profile's pointer to the presence host itself. Once every file
		// above is cached here, none of them names that host any more, so it
		// gets a relation of its own, on every page (0.8.1).
		printf(
			'<link rel="related" href="%s">' . "\n",
			esc_url( $origin . '/' )
		);
	}

	/**
	 * Where one relation should point: this apex when we serve the file, the
	 * Globetrotters host when only it has a copy.
	 *
	 * "We serve it" means in the served set *and* cached right now: {@see Router}
	 * answers only what the cache holds. A bundle cached before 0.7.0 has no
	 * ``ai-catalog.json`` until its next successful refresh, so until then the
	 * relation keeps naming the Globetrotters copy rather than a local 404.
	 *
	 * A locally-served file gets a root-relative URL rather than an absolute one
	 * built from ``home_url()``. {@see Router} matches the raw request path
	 * against the served set, so the artefacts answer at the domain root whatever
	 * ``home_url()`` says — and a root-relative href resolves to exactly that,
	 * while staying correct across http/https, www/non-www and staging clones.
	 *
	 * @param string $path   Apex-relative path (no leading slash).
	 * @param string $origin The derived Globetrotters origin.
	 */
	private function href( string $path, string $origin ): string {
		return ContentTypes::has( $path ) && null !== $this->cache->get( $path )
			? '/' . $path
			: $origin . '/' . $path;
	}

	/**
	 * The origin to link to, having survived ``esc_url()``, or '' to emit nothing.
	 *
	 * A rejected scheme makes ``esc_url()`` return an empty string. Checking that
	 * here, rather than only checking the raw value, is what keeps the "emit
	 * nothing when the host is unknown" contract honest: without it a stored
	 * ``javascript:`` origin would still print five empty ``<link href="">`` tags.
	 */
	private function safe_origin(): string {
		if ( null !== $this->origin ) {
			return $this->origin;
		}

		$this->origin = '';
		if ( ! $this->options->emits_breadcrumbs() ) {
			return $this->origin;
		}

		$origin = $this->options->presence_origin();
		if ( '' === $origin || '' === esc_url( $origin ) ) {
			return $this->origin;
		}

		// A pointer at ourselves is not a breadcrumb. If the presence is published
		// on this very host there is no separate site to name.
		if ( self::host_of( $origin ) === self::host_of( home_url( '/' ) ) ) {
			return $this->origin;
		}

		$this->origin = $origin;
		return $this->origin;
	}

	/**
	 * Lowercased host of a URL, or '' when it has none.
	 *
	 * @param string $url Absolute URL.
	 */
	private static function host_of( string $url ): string {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}
}
