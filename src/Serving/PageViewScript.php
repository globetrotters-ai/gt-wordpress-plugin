<?php
/**
 * The page-view beacon printed on front-end pages.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Serving;

use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Settings\Options;

/**
 * Prints the few bytes of JavaScript that count a human page view.
 *
 * Off by default, and hooked at all only when the site owner has switched
 * page-view counting on *and* reporting is configured
 * ({@see Options::counts_page_views()}): with it off the plugin adds nothing to
 * a customer's pages, which is the behaviour every earlier version had.
 *
 * With it on, it adds exactly one thing: an inline ``<script>`` in
 * ``wp_footer``, invisible, dependency-free, wrapped in ``try``/``catch`` so it
 * can never break a page. It posts ``location.pathname`` — never the query
 * string, the fragment or the full URL — with ``navigator.sendBeacon`` to a
 * root-relative path on this same site, answered by
 * {@see \Globetrotters\AiPresence\Serving\Router}. The browser never contacts
 * Globetrotters. A browser without ``sendBeacon`` simply does not count.
 *
 * Two referral hints ride along, so a click on an AI answer's citation can be
 * told apart: ``r``, the host of ``document.referrer`` and only when it is
 * another site's, and ``u``, the page's ``utm_source`` value, left out when
 * longer than 100 characters (cutting it could split a surrogate pair, and no
 * AI source is anywhere near that long). Never the referrer's path or query,
 * and no other query parameter. The server matches them against the synced AI referrer list and
 * keeps only the platform ({@see \Globetrotters\AiPresence\Analytics\AiReferrers}).
 *
 * A script rather than a server-side hook on page render because the page is
 * usually not rendered by PHP at all: a page cache (plugin, host or CDN) serves
 * it, and PHP never sees the view. The script is part of the cached HTML, and
 * the POST it makes is never cached, so every view reaches the counter.
 *
 * Skipped where a request is not a visitor reading a page: admin screens, feeds,
 * post previews, the Customizer, 404s, and anyone logged in who can edit posts
 * — the site's own staff browsing it.
 */
final class PageViewScript {

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Hook the script, only when counting is on.
	 *
	 * Decided once per request, at boot: an install with counting off has no
	 * ``wp_footer`` callback of ours at all, not one that returns early.
	 */
	public function register(): void {
		if ( ! $this->options->counts_page_views() ) {
			return;
		}
		add_action( 'wp_footer', array( $this, 'print_script' ), 99 );
	}

	/**
	 * Print the inline script, unless this request should not be counted.
	 */
	public function print_script(): void {
		if ( ! $this->options->counts_page_views() || ! self::is_countable_view() ) {
			return;
		}
		// Core's helper rather than a hand-written tag: it adds the attributes a
		// Content-Security-Policy plugin hooks into (``wp_inline_script_attributes``).
		wp_print_inline_script_tag( self::javascript() );
	}

	/**
	 * The beacon itself. Under 500 bytes, and its only target is a
	 * root-relative path on this site.
	 *
	 * Sent only once the page is actually shown. A Speculation Rules prerender
	 * (core's speculative loading since 6.8, Performance Lab before it) runs a
	 * page's scripts with nobody looking at it, and most prerenders are never
	 * activated; counting them would inflate every page a visitor merely
	 * hovered. So a prerendering page waits for ``prerenderingchange`` — fired
	 * once, on activation — and any other page sends at once. The deferred send
	 * carries its own ``try``, since it runs outside the outer one, and each
	 * hint its own, since ``new URL('')`` throws on a page with no referrer: a
	 * hint that cannot be read is left out, never a reason to lose the view.
	 *
	 * Byte-identical to the Symfony bundle's beacon: the two producers must
	 * count the same visits the same way. Change both together.
	 */
	public static function javascript(): string {
		return '(function(){var n=navigator,d=document,l=location;function s(){try{'
			. 'var b={p:l.pathname},r,u;'
			. 'try{r=new URL(d.referrer).host}catch(e){}if(r&&r!==l.host)b.r=r;'
			. "try{u=new URLSearchParams(l.search).get('utm_source')}catch(e){}if(u&&u.length<=100)b.u=u;"
			. "n.sendBeacon('" . PageViewCounter::PATH . "',new Blob([JSON.stringify(b)],{type:'text/plain'}))"
			. '}catch(e){}}try{if(!n.sendBeacon)return;'
			. "if(d.prerendering){d.addEventListener('prerenderingchange',s,{once:true})}else{s()}"
			. '}catch(e){}})();';
	}

	/**
	 * Offer a paragraph for the site's privacy policy, when counting is on.
	 *
	 * Hooked on ``admin_init``, which is where core expects it; it appears under
	 * Settings → Privacy → Policy Guide for the site owner to copy.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! $this->options->counts_page_views() || ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Globetrotters AI Presence', 'globetrotters-ai-presence' ),
			wp_kses_post( wpautop( self::privacy_policy_text(), false ) )
		);
	}

	/**
	 * The suggested privacy-policy paragraph. Also published in README.md.
	 */
	public static function privacy_policy_text(): string {
		return __( 'This site counts page views with a first-party, cookieless counter. When a page loads, a small script sends the address of that page (without any query string) to this site, which adds one to a count kept per page per day. With it, the script sends the name of the website that referred the visit, if any, and the page\'s utm_source value, if any; this site uses them only to tell whether the visit came from an AI assistant such as ChatGPT, keeps only the assistant\'s name, and discards them. No IP address, cookie or other identifier is read or stored, and the browser\'s user-agent is checked only to leave out automated traffic, never kept. Only those aggregate daily counts per page are sent to Globetrotters, the service that reports this site\'s visibility to AI assistants.', 'globetrotters-ai-presence' );
	}

	/**
	 * Whether the current request is a visitor reading a front-end page.
	 */
	private static function is_countable_view(): bool {
		if ( is_admin() || is_feed() || is_preview() || is_customize_preview() || is_404() ) {
			return false;
		}
		return ! ( is_user_logged_in() && current_user_can( 'edit_posts' ) );
	}
}
