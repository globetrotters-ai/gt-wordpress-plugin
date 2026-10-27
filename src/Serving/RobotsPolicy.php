<?php
/**
 * What a site's own robots.txt already says, read the way crawlers read it.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

/**
 * Exactly as much of a robots.txt as {@see RobotsFilter} needs to append to it
 * without changing what any crawler may fetch.
 *
 * Two facts matter. A crawler obeys only the group(s) naming it, and falls back
 * to the ``*`` group(s) only when none does; groups naming the same agent are
 * combined. So a group appended for an agent the file already names would
 * *merge into* the site's own rules for it — an ``Allow: /`` there beats the
 * site's ``Disallow: /``, because equal-length matches go to Allow — and a group
 * appended for an agent it does not name *replaces* the ``*`` rules that agent
 * was obeying. This class reports which agents are named, and the member lines
 * of the ``*`` group that every other agent is on.
 *
 * **Only an Allow or Disallow line ends a user-agent section.** Google's spec
 * ("Rules other than allow, disallow, and user-agent are ignored by the
 * robots.txt parser") and its reference parser (google/robotstxt, where only
 * HandleAllow and HandleDisallow set the group separator) both group this way.
 * RFC 9309's grammar has no place for other records inside a group and leaves
 * them to the crawler, so Google's reading is the one matched here. A
 * Content-Signal, Crawl-delay, Sitemap or unknown line between two User-agent
 * lines therefore leaves them in one group, sharing the rules that follow.
 * Those non-rule lines are still kept as the group's members — a signal is part
 * of what an agent inheriting the ``*`` group should carry — but they never
 * decide where a group ends. Blank lines do not end one either, and
 * ``Sitemap:`` is a global record that belongs to no group.
 *
 * A port of the Symfony bundle's ``Serving\RobotsPolicy``; keep the two in step.
 */
final class RobotsPolicy {

	/**
	 * Lowercased product tokens that have a group of their own.
	 *
	 * @var array<string, true>
	 */
	private array $named_agents;

	/**
	 * Member lines of every ``*`` group, in file order.
	 *
	 * @var string[]
	 */
	private array $wildcard_lines;

	/**
	 * Whether the file stops inside a section no rule has closed.
	 *
	 * @var bool
	 */
	private bool $ends_open;

	/**
	 * Use {@see parse()}.
	 *
	 * @param array<string, true> $named_agents   Lowercased product tokens with a group of their own.
	 * @param string[]            $wildcard_lines Member lines of every ``*`` group, in file order.
	 * @param bool                $ends_open      Whether the file stops inside a section no rule has closed.
	 */
	private function __construct( array $named_agents, array $wildcard_lines, bool $ends_open ) {
		$this->named_agents   = $named_agents;
		$this->wildcard_lines = $wildcard_lines;
		$this->ends_open      = $ends_open;
	}

	/**
	 * Read a robots.txt body.
	 *
	 * @param string $robots The site's robots.txt, '' when there is none.
	 */
	public static function parse( string $robots ): self {
		if ( 0 === strncmp( $robots, "\xEF\xBB\xBF", 3 ) ) {
			$robots = substr( $robots, 3 );
		}

		$named = array();
		// Completed sections, each a pair of its agents and its member lines.
		$sections = array();
		// The section being read: its agents, and its member lines so far.
		$agents    = array();
		$members   = array();
		$seen_rule = false;

		$lines = preg_split( '/\r\n|\r|\n/', $robots );
		foreach ( false === $lines ? array() : $lines as $raw ) {
			$line = trim( explode( '#', $raw, 2 )[0] );
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = explode( ':', $line, 2 );
			$field                 = strtolower( trim( $field ) );

			if ( 'user-agent' === $field ) {
				if ( $seen_rule ) {
					$sections[] = array( $agents, $members );
					$agents     = array();
					$members    = array();
					$seen_rule  = false;
				}
				$token = self::product_token( $value );
				if ( '' !== $token ) {
					$agents[] = $token;
					if ( '*' !== $token ) {
						$named[ $token ] = true;
					}
				}
				continue;
			}
			if ( 'sitemap' === $field || array() === $agents ) {
				continue;
			}

			$members[] = $line;
			if ( 'allow' === $field || 'disallow' === $field ) {
				$seen_rule = true;
			}
		}
		$sections[] = array( $agents, $members );

		// Members are assigned once a section is complete, because a line can
		// come before one of its agents: in "User-agent: GPTBot / Crawl-delay: 5
		// / User-agent: * / Disallow: /x", the delay is the wildcard's too.
		$wildcard = array();
		foreach ( $sections as list( $section_agents, $section_members ) ) {
			if ( in_array( '*', $section_agents, true ) ) {
				$wildcard = array_merge( $wildcard, $section_members );
			}
		}

		return new self( $named, $wildcard, array() !== $agents && ! $seen_rule );
	}

	/**
	 * Whether the file gives this agent a group of its own (case-insensitive,
	 * as crawlers match it).
	 *
	 * @param string $agent Product token, e.g. ``GPTBot``.
	 */
	public function names( string $agent ): bool {
		return isset( $this->named_agents[ strtolower( $agent ) ] );
	}

	/**
	 * The ``*`` group's member lines — rules, ``Crawl-delay``, any signal —
	 * verbatim, minus comments. Empty when the file has no wildcard group, in
	 * which case an agent without a group of its own is unrestricted.
	 *
	 * @return string[]
	 */
	public function wildcard_lines(): array {
		return $this->wildcard_lines;
	}

	/**
	 * Whether the file stops inside a user-agent section no Allow or Disallow
	 * line has closed. Anything appended to such a file joins that section: its
	 * User-agent lines read as more agents of the site's group, and the site's
	 * agents take on the appended rules.
	 */
	public function ends_inside_a_group(): bool {
		return $this->ends_open;
	}

	/**
	 * The matchable part of a ``User-agent:`` value. RFC 9309 product tokens
	 * are letters, "-" and "_", and Google stops matching at the first other
	 * character — a version, a digit, a stray "*" — so ``GPTBot/1.1`` and
	 * ``GPTBot2`` both name ``gptbot``. A "*" alone or followed by whitespace is
	 * the wildcard, as Google reads it; ``*bot`` names nothing.
	 *
	 * @param string $value Raw ``User-agent:`` value.
	 */
	private static function product_token( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '~^\*(\s|$)~', $value ) ) {
			return '*';
		}

		return 1 === preg_match( '~^[A-Za-z_-]+~', $value, $match ) ? strtolower( $match[0] ) : '';
	}
}
