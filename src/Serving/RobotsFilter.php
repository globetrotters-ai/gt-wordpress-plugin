<?php
/**
 * Appends the AI-crawler registry and a Sitemap directive to WordPress's robots.txt.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

use Globetrotters\AiPresence\Analytics\AiUserAgents;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Settings\RobotsOptions;

/**
 * Extends WordPress's virtual ``robots.txt`` via the ``robots_txt`` filter.
 *
 * WordPress generates ``robots.txt`` dynamically, so a physical file would both
 * conflict and often be ignored. Filtering is the correct seam: we name the AI
 * crawlers in the Globetrotters registry (synced daily with the vendor ranges,
 * see {@see AiUserAgents}; the compiled {@see self::AI_BOTS} otherwise), point
 * ``Agentmap:`` at the ARD
 * catalog (while it is cached) and ``Sitemap:`` at the sitemap this site
 * serves.
 *
 * **Decorating never changes what a crawler may fetch, by default.** Naming an
 * agent is not neutral: under RFC 9309 a crawler obeys only the group(s) naming
 * it and falls back to ``User-agent: *`` only when none does. So a named group
 * carrying ``Allow: /`` would release that agent from every wildcard restriction
 * the site has (WordPress's own ``Disallow: /wp-admin/``, a staging site's
 * ``Disallow: /``), and one appended for an agent the site already names would
 * merge into — and outrank — the site's own rules for it. Hence, with
 * {@see RobotsOptions} at its defaults:
 *
 * - agents the site's file already names are **never** added — its own group
 *   stays the only one that applies to them;
 * - every other registry agent inherits the site's ``*`` member lines verbatim,
 *   so the rules it obeys are the rules it was obeying;
 * - ``Content-Signal`` says ``search=yes, ai-input=yes`` and nothing about
 *   training, unless the site's own wildcard group already carries a signal,
 *   which is inherited instead;
 * - a user-agent section the site's file leaves open is closed with a pathless
 *   ``Disallow:`` first, so the appended groups cannot join it.
 *
 * Groups are read the way Google reads them — only Allow and Disallow end a
 * user-agent section — see {@see RobotsPolicy}.
 *
 * ``allow_all`` and ``ai-train`` are the explicit opt-ins for a broader grant
 * (Settings → AI Presence); with both, and a site file with no wildcard rules,
 * the output is byte-for-byte the backend registry block. Behaviour mirrors
 * the Globetrotters Symfony bundle's ``Serving\RobotsFilter`` — keep the two
 * in step.
 */
final class RobotsFilter {

	/**
	 * The comment that opens the appended block.
	 */
	public const MARKER = '# Globetrotters AI Presence';

	/**
	 * ``robots_txt`` priority: last, so the block is built from the file every
	 * other filter produced. Which agents the site names and what its wildcard
	 * group says are read from ``$output``; a filter running after this one
	 * could name an agent this block has already given a group, and the two
	 * would merge.
	 */
	public const PRIORITY = PHP_INT_MAX;

	/**
	 * AI crawler user-agents, in emission order: the offline floor.
	 *
	 * The registry itself lives on the Globetrotters backend and reaches a
	 * reporting install through the ranges document's ``aiUserAgents``
	 * ({@see AiUserAgents}), so a new crawler needs no plugin release. This
	 * copy is used only when that list is missing, older than 14 days or
	 * unusable — an install without reporting configured, or before its first
	 * sync. It is **frozen** at the 26 names the registry held when the sync
	 * shipped and is no longer updated per crawler.
	 *
	 * ``tests/fixtures/robots-ai-groups.txt`` pins it: the registry's own
	 * rendering of these groups at that point, which
	 * ``tests/unit/Serving/RobotsFilterTest`` compares byte for byte. Do not
	 * edit either by hand.
	 */
	private const AI_BOTS = array(
		'GPTBot',
		'OAI-SearchBot',
		'ChatGPT-User',
		'ClaudeBot',
		'Claude-SearchBot',
		'Claude-User',
		'anthropic-ai',
		'PerplexityBot',
		'Perplexity-User',
		'Google-Extended',
		'Googlebot',
		'Bingbot',
		'Applebot',
		'Applebot-Extended',
		'CCBot',
		'meta-externalagent',
		'meta-externalfetcher',
		'meta-webindexer',
		'GrokBot',
		'xAI-Grok',
		'Grok-DeepSearch',
		'Amazonbot',
		'DuckAssistBot',
		'Bytespider',
		'cohere-ai',
		'ExaSearchBot',
	);

	/**
	 * Agents that read another agent's group when the file names none of their
	 * own — beyond RFC 9309, but documented by the vendor, so for them "no group
	 * of its own" does not mean "on the wildcard rules". Applebot follows
	 * Googlebot's instructions when robots.txt names Googlebot but not Applebot
	 * (Apple's "About Applebot" page); adding an Applebot group there would take
	 * it off the rules the site gave Googlebot.
	 */
	private const FALLBACK_GROUPS = array( 'Applebot' => 'Googlebot' );

	/**
	 * The ``Content-Signal`` directive, emitted inside *every* named group.
	 *
	 * A `contentsignals.org <https://contentsignals.org>`_ declaration. It is
	 * placed in each group rather than once, because RFC 9309 §2.2.1 group
	 * selection means a directive placed in one group is never seen by a crawler
	 * that matched another. Search and inference input are what an AI presence
	 * exists for; training is a separate statement about the whole site, made
	 * only through the ``ai-train`` opt-in (the registry's own block carries it —
	 * see {@see self::CONTENT_SIGNAL_WITH_TRAINING}).
	 */
	private const CONTENT_SIGNAL               = 'Content-Signal: search=yes, ai-input=yes';
	private const CONTENT_SIGNAL_WITH_TRAINING = 'Content-Signal: search=yes, ai-input=yes, ai-train=yes';

	/**
	 * Ends a user-agent section the site's file leaves open (see
	 * {@see RobotsPolicy::ends_inside_a_group()}), so the groups appended below
	 * start their own instead of joining it. Only an Allow or Disallow line ends
	 * a section, and a pathless one is the only rule that changes nothing:
	 * crawlers ignore a rule without a path (Google's spec), and
	 * google/robotstxt gives it priority 0, which never decides a match.
	 */
	private const SECTION_CLOSER = 'Disallow:';

	/**
	 * The ARD catalog pointer, root-relative like the backend's: it resolves
	 * against the host that served it, which is this apex.
	 */
	private const AGENTMAP = 'Agentmap: /' . ContentTypes::AI_CATALOG;

	private Options $options;
	private ArtefactCache $cache;
	private AiUserAgents $user_agents;

	/**
	 * Wire the settings, the artefact cache and the synced crawler registry.
	 *
	 * @param Options           $options     Plugin settings.
	 * @param ArtefactCache     $cache       The cached bundle.
	 * @param AiUserAgents|null $user_agents The synced AI crawler registry.
	 */
	public function __construct( Options $options, ArtefactCache $cache, ?AiUserAgents $user_agents = null ) {
		$this->options     = $options;
		$this->cache       = $cache;
		$this->user_agents = $user_agents ?? new AiUserAgents();
	}

	/**
	 * Register the filter.
	 */
	public function register(): void {
		add_filter( 'robots_txt', array( $this, 'filter' ), self::PRIORITY, 2 );
	}

	/**
	 * Append the AI block to the generated robots.txt.
	 *
	 * Untyped on purpose. This runs last (see {@see self::PRIORITY}), so
	 * ``$output`` is whatever every other filter returned, and a callback that
	 * echoes instead of returning hands on ``null``. A typed parameter would turn
	 * that into a fatal on /robots.txt; a value this cannot read is passed
	 * through untouched instead. ``$is_public`` is core's ``blog_public``
	 * option, a ``'0'``/``'1'`` string.
	 *
	 * @param mixed $output    The robots.txt content generated so far.
	 * @param mixed $is_public Whether the site is set to be indexed.
	 * @return mixed The decorated robots.txt, or ``$output`` unchanged.
	 */
	public function filter( $output, $is_public ) {
		// If the site asks search engines to stay away, don't override that.
		// Only advertise once a bundle is actually installed, so we never point
		// crawlers at a sitemap that has nothing to list.
		if ( ! is_string( $output ) || ! $is_public || ! $this->options->is_connected() || ! $this->cache->has_any() ) {
			return $output;
		}

		return rtrim( $output, "\n" ) . "\n\n" . self::build_block( Sitemap::site_origin(), $output, $this->options->robots(), $this->serves_catalog(), $this->user_agents->agents() );
	}

	/**
	 * Whether the ARD catalog the ``Agentmap:`` line names is cached, and so
	 * served by {@see Router}.
	 */
	private function serves_catalog(): bool {
		return null !== $this->cache->get( ContentTypes::AI_CATALOG );
	}

	/**
	 * Build the appended robots.txt block for this site's own origin.
	 *
	 * ``$existing`` is the robots.txt the block is appended to, '' when there is
	 * none; see the class docblock for how it shapes the groups.
	 *
	 * Named groups only. The backend's own emitters also render a
	 * ``User-agent: *`` group because they own the whole file; this plugin
	 * *appends* to a robots.txt WordPress has already generated, which carries
	 * its own wildcard group, and a second one would be combined with it and
	 * change the site's rules.
	 *
	 * The ``Sitemap:`` line names **this site's own host**. It used to name the
	 * configured Globetrotters origin, which was inert: a cross-host ``Sitemap:``
	 * directive is ignored by Google and Bing without cross-domain sitemap
	 * verification. In plugin mode the content is served from the customer's
	 * apex, so that is where the sitemap belongs — see {@see Sitemap}, including
	 * why it is at ``/ai-sitemap.xml`` rather than ``/sitemap.xml``. robots.txt
	 * takes a list of ``Sitemap:`` directives, so this is additive to the
	 * ``wp-sitemap.xml`` line WordPress core emits above it.
	 *
	 * ``$agentmap`` adds ``Agentmap: /.well-known/ai-catalog.json`` just before
	 * it, as the backend does. The line is root-relative, so pass it only while
	 * this install serves the catalog: a bundle cached before 0.7.0 has none
	 * until its next refresh, and the line would advertise a 404 meanwhile.
	 *
	 * ``$registry`` is the synced crawler registry ({@see AiUserAgents::agents()});
	 * null names the compiled {@see self::AI_BOTS} with {@see self::FALLBACK_GROUPS}.
	 *
	 * @param string                                                     $origin   This site's own origin (no trailing slash).
	 * @param string                                                     $existing The site's robots.txt so far.
	 * @param RobotsOptions|null                                         $options  The opt-ins; null reads as the defaults.
	 * @param bool                                                       $agentmap Whether to emit the ``Agentmap:`` line.
	 * @param array<int, array{name:string, fallbackGroup?:string}>|null $registry The synced registry, or null.
	 */
	public static function build_block( string $origin, string $existing = '', ?RobotsOptions $options = null, bool $agentmap = false, ?array $registry = null ): string {
		$options = $options ?? new RobotsOptions();
		$site    = RobotsPolicy::parse( $existing );
		$agents  = array();
		foreach ( self::registry( $registry ) as $agent => $fallback ) {
			if ( ! self::is_governed_by_site( (string) $agent, $fallback, $site ) ) {
				$agents[] = (string) $agent;
			}
		}

		$block = self::MARKER . "\n"
			. ( $site->ends_inside_a_group() ? self::SECTION_CLOSER . "\n" : '' )
			. self::groups( $agents, $site, $options )
			. ( $agentmap ? self::AGENTMAP . "\n" : '' );
		if ( '' === $origin ) {
			return rtrim( $block, "\n" ) . "\n";
		}
		return $block . 'Sitemap: ' . $origin . '/' . Sitemap::PATH . "\n";
	}

	/**
	 * One group per agent: ``User-agent:``, ``Allow: /``, its ``Content-Signal``,
	 * blank-line separated.
	 *
	 * The block genuinely *ends* with a blank line, so a ``Sitemap:`` appended
	 * below is a sibling of the groups rather than a directive inside the final
	 * one. With ``$ai_train`` and the full registry this is byte-for-byte the
	 * backend registry's own rendering — see
	 * ``tests/fixtures/robots-ai-groups.txt``.
	 *
	 * @param bool     $ai_train Whether the signal declares ``ai-train=yes``.
	 * @param string[] $agents   Agents to name, in order.
	 */
	public static function ai_user_agent_groups( bool $ai_train, array $agents = self::AI_BOTS ): string {
		return self::per_agent_groups( $agents, array( self::content_signal( $ai_train ) ) );
	}

	/**
	 * One ``Allow: /`` group per agent, each carrying the given signal line(s).
	 *
	 * @param string[] $agents Agents to name, in order.
	 * @param string[] $signal The ``Content-Signal`` line(s) each group carries.
	 */
	private static function per_agent_groups( array $agents, array $signal ): string {
		$lines = array();
		foreach ( $agents as $agent ) {
			$lines[] = 'User-agent: ' . $agent;
			$lines[] = 'Allow: /';
			$lines   = array_merge( $lines, $signal );
			$lines[] = '';
		}

		// The final '' only becomes the string's line terminator; the extra
		// newline is what makes the last separator a real blank line.
		return array() === $lines ? '' : implode( "\n", $lines ) . "\n";
	}

	/**
	 * The groups for the registry agents the site's file leaves to its wildcard.
	 *
	 * @param string[]      $agents  Registry agents the site does not name.
	 * @param RobotsPolicy  $site    The site's robots.txt, parsed.
	 * @param RobotsOptions $options The opt-ins.
	 */
	private static function groups( array $agents, RobotsPolicy $site, RobotsOptions $options ): string {
		if ( array() === $agents ) {
			return '';
		}

		$inherited = $site->wildcard_lines();
		if ( $options->allows_all() || array() === $inherited ) {
			// Nothing to inherit: no wildcard group, or an explicit allow_all.
			// A Content-Signal the wildcard group declares still speaks for the
			// site — allow_all opts into crawl permission, not into replacing the
			// site's own statement of how its content may be used.
			$site_signal = array_values(
				array_filter(
					$inherited,
					static fn( string $line ): bool => self::carries( array( $line ), array( 'content-signal' ) )
				)
			);

			return self::per_agent_groups(
				$agents,
				array() !== $site_signal ? $site_signal : array( self::content_signal( $options->ai_train() ) )
			);
		}

		// One group naming every agent, carrying the site's wildcard lines once.
		// RFC 9309 lets a group start with several User-agent lines, and a copy
		// per agent would multiply a long file twentyfold towards the size
		// crawlers stop reading at — past which a truncated group would be *more*
		// permissive than the rules it was copied from.
		$lines = array_map( static fn( string $agent ): string => 'User-agent: ' . $agent, $agents );
		if ( ! self::carries( $inherited, array( 'allow', 'disallow' ) ) ) {
			// A group needs a rule; this one is equivalent to having none.
			$lines[] = 'Allow: /';
		}
		$lines = array_merge( $lines, $inherited );
		if ( ! self::carries( $inherited, array( 'content-signal' ) ) ) {
			$lines[] = self::content_signal( $options->ai_train() );
		}

		return implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * The agents to name, in order, each mapped to its fallback group or null.
	 *
	 * @param array<int, array{name:string, fallbackGroup?:string}>|null $registry The synced registry, or null for the compiled one.
	 * @return array<string, string|null>
	 */
	private static function registry( ?array $registry ): array {
		$agents = array();
		if ( null === $registry ) {
			foreach ( self::AI_BOTS as $agent ) {
				$agents[ $agent ] = self::FALLBACK_GROUPS[ $agent ] ?? null;
			}
			return $agents;
		}
		$seen = array();
		foreach ( $registry as $entry ) {
			// Product tokens match case-insensitively, so a name repeated in any
			// case is one agent: its first entry keeps its position and fallback.
			$key = strtolower( $entry['name'] );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ]             = true;
				$agents[ $entry['name'] ] = $entry['fallbackGroup'] ?? null;
			}
		}
		return $agents;
	}

	/**
	 * Whether the site's own file already decides what this agent obeys.
	 *
	 * @param string       $agent    Registry agent.
	 * @param string|null  $fallback The agent whose group it reads when it has none.
	 * @param RobotsPolicy $site     The site's robots.txt, parsed.
	 */
	private static function is_governed_by_site( string $agent, ?string $fallback, RobotsPolicy $site ): bool {
		if ( $site->names( $agent ) ) {
			return true;
		}

		return null !== $fallback && $site->names( $fallback );
	}

	/**
	 * Whether any of the lines is one of the given fields.
	 *
	 * @param string[] $lines  Member lines.
	 * @param string[] $fields Lowercased field names.
	 */
	private static function carries( array $lines, array $fields ): bool {
		foreach ( $lines as $line ) {
			if ( in_array( strtolower( trim( explode( ':', $line, 2 )[0] ) ), $fields, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The ``Content-Signal`` line for the training opt-in.
	 *
	 * @param bool $ai_train Whether the signal declares ``ai-train=yes``.
	 */
	private static function content_signal( bool $ai_train ): string {
		return $ai_train ? self::CONTENT_SIGNAL_WITH_TRAINING : self::CONTENT_SIGNAL;
	}
}
