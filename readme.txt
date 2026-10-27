=== Globetrotters AI Presence ===
Contributors: globetrotters
Tags: ai, llms, schema, json-ld, discovery
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.12.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve your Official AI Presence at your site's apex — AI discovery files and server-rendered JSON-LD, pulled automatically from Globetrotters.

== Description ==

The Globetrotters AI Presence plugin installs your Official AI Presence on a
WordPress site **without filesystem access**. It hooks WordPress's own router to
serve the AI discovery file set at your apex, injects server-rendered JSON-LD into
`wp_head`, and pulls everything from Globetrotters so nothing has to be uploaded by
hand.

**What it serves at your apex:**

* `/llms.txt` and `/ai.json` — the AI Readiness discovery pair.
* `/.well-known/mcp.json` and `/.well-known/agent-card.json` — agent discovery
  manifests (pointing at the Globetrotters-hosted MCP endpoint).
* `/.well-known/ai-catalog.json` and `/.well-known/agent-skills/index.json` —
  the AI catalog and the Agent Skills index, so agents find everything your
  presence offers from one address. The index also answers at
  `/.well-known/agent-skills`.
* `/agent.json` and `/AGENTS.md` — the root agent descriptions, in JSON and in
  Markdown. `/AGENTS.md` also answers at `/agents.md`.
* `/.well-known/globetrotters-apex-version.json` — the version/drift marker.
* `/schema.json` — standalone JSON-LD, also injected inline into your pages'
  `<head>`.
* Your IndexNow verification key, when Globetrotters has issued one — served at
  the address search engines look for it, so new and updated presence content can
  be announced from your own domain.
* `/.well-known/api-catalog` — a standard API catalog (RFC 9727) pointing agents
  at `/llms.txt`, the MCP manifest, the agent card, the AI catalog and your MCP
  server, generated from what this site is serving right now.
* `/ai-sitemap.xml` — a sitemap of the files above, generated from what this site
  is serving right now and advertised in your robots.txt. Deliberately not
  `/sitemap.xml`: that address belongs to your site's own sitemap.

Heavy files (`llms-full.txt`, `content.md`) are linked back to Globetrotters rather
than served locally, keeping the footprint minimal.

**Install profile.** Globetrotters also publishes your full presence on its own
subdomain, but nothing on the public web points at it, so AI assistants reach it
only if they already know the address. The recommended **Apex and subdomain**
profile fixes that automatically: this site keeps serving the discovery files
exactly as before, and additionally points at that subdomain from the head of
your pages. Nothing visible is added and your design is untouched. The address
is read from your own presence content and re-checked on every refresh, so it
keeps working when your managed hostname is switched on or off. Choose **Apex
only** to add nothing at all.

Your homepage also answers with an HTTP `Link` header pointing agents at the
API catalog and `/llms.txt`, then the MCP manifest, the agent card and the AI
catalog — the same pointers as the head tags, for agents that read headers
rather than HTML. It is added next to any
`Link` header your site already sends, never in place of it, and only on the
homepage.

The plugin also lists the AI crawlers Globetrotters tracks, an `Agentmap:` line
for the AI catalog, and a `Sitemap:` directive, in WordPress's virtual
`robots.txt` — without changing what any crawler may access on your site — and
keeps everything fresh with a scheduled
refresh (with graceful stale-serve if Globetrotters is briefly unreachable, or
answers with something that is not your content).

**Agent traffic reporting (optional).** Because the discovery files are served
from your own site, requests for them never reach Globetrotters and would
otherwise be invisible. Paste the ingest token and endpoint URL from Studio and
the plugin records each request locally and sends batches every 15 minutes. No
cookies, no visitor identifiers — just which AI agent fetched which file, when.

**Page-view counts (optional, off by default).** With reporting set up, you can
also switch on a count of human page views per page, so Studio can show AI
traffic next to the visits your site gets. A tiny invisible script posts the
page's path to your own site — never to Globetrotters — which keeps one count
per page per day and sends only those counts. When a visitor arrives by
clicking a link in an AI answer (ChatGPT, Claude, Perplexity…), the count also
says which assistant sent them, so Studio can show the visits AI answers bring
to your own site. No IP address or cookie is read or stored, and nothing
identifies a visitor.

Updates are published by Globetrotters rather than through the WordPress.org
directory, and arrive in your normal Plugins screen; each package is
checksum-verified before it installs.

== Installation ==

1. Upload and activate the plugin.
2. Go to **Settings → AI Presence**.
3. Enter the URL of your Globetrotters website.
4. Save. The plugin fetches your artefacts and starts serving them at your apex.

== Frequently Asked Questions ==

= Does this need FTP or filesystem access? =

No. The plugin serves the files through WordPress itself, so it works on managed
hosts that don't give you docroot access.

= I run WP-Cron from a system cron and use an object cache. Does that work? =

Yes. Since 0.9.4 a refresh run through WP-CLI reaches your web server even
when its object cache is private to it, as an APCu object cache is. **Settings
→ AI Presence** shows who ran the last refresh and which version visitors are
being served.

= Where does the content come from? =

From your already-public Globetrotters presence. The plugin pulls it and re-serves
it at your apex, so it stays in sync automatically.

= Which install profile should I choose? =

**Apex and subdomain**, unless you have a reason not to. Both halves of your AI
presence then exist and are connected: this site serves the discovery files at
your apex, and your pages point at the Globetrotters-hosted subdomain carrying
the full content. The only thing it adds is a few `<link>` tags in the page
head — invisible to visitors, and no change to your theme or layout. Choose
**Apex only** if you would rather your pages carry no outbound pointers.

= Will this add anything visible to my site? =

No. The plugin only adds `<link>` tags to the page head, which browsers never
render. If you switch on page-view counting (off by default), it also adds one
small inline script at the end of each page, which renders nothing and only
counts the view. It deliberately does not insert a visible link into your footer: a
plugin has no reliable place to put one, so it would land outside your theme's
layout. If you want a visible link — which is the kind an AI crawler follows,
unlike a head tag — Studio's **Apex install** panel gives you ready-made HTML
with your real address filled in, to paste where it belongs in your theme.

= Does the plugin change what crawlers can access on my site? =

Not unless you ask it to. The plugin lists the AI crawlers Globetrotters tracks
in your robots.txt, but a crawler your robots.txt already names keeps the rules
you gave it, and every other listed crawler follows exactly the rules your site
sets for all crawlers — WordPress's block on `/wp-admin/` included. Under
**Settings → AI Presence** you can choose to give the listed AI crawlers access
to everything, and to state that your content may be used to train AI models;
both are off by default. Giving them access to everything does not replace a
`Content-Signal` line your robots.txt already has. If your site is set to
discourage search engines, the plugin leaves robots.txt alone entirely.

= Where does the subdomain address come from? =

It is read from the presence content the plugin already downloads, not typed in.
That matters because the address changes: it is `yourslug.globetrotters.ai` until
your own `ai.yourdomain.com` hostname is switched on, and reverts if it is ever
detached. Because it is re-derived on every refresh, the link follows those
changes on its own. Until it has been determined, no link is added at all — the
plugin never guesses a hostname.

= Where do I get the ingest token? =

In Studio, open **AI Presence → Content**, then the **Apex install** panel, and
use **Issue ingest token** on the "Report agent traffic from your server" step.
The endpoint URL is shown next to the token, and the token itself is shown
exactly once — copy both before closing the dialog. The **Open Studio** button on
the settings screen links straight to that step. Your domain has to be verified
first; if it isn't, the step offers a verification link instead.

= Why don't I see any AI-agent traffic in Studio? =

Agent traffic reporting is off until you paste an ingest token and endpoint URL
under **Settings → AI Presence**. After that, expect a delay of roughly 30 to 45
minutes before requests appear: the plugin batches them every 15 minutes, and
Globetrotters waits for each time bucket to settle before writing it. Note that
the status block on the settings screen can only tell you that batches are being
accepted — the endpoint answers the same way to a token that is no longer valid,
so if the numbers never appear in Studio, re-issue the token.

= Why does Studio say the bot counts are "at least"? =

A page cache or a CDN in front of your site answers some requests without
WordPress ever running, and those visits cannot be seen by the plugin. The
plugin tells Globetrotters which page cache it detected and whether requests
arrive through a CDN, so Studio can show the counts as a floor when some visits
may be missing.

= My site is behind Cloudflare or a CDN. Do I need to change anything? =

Turn on **Trusted proxy** under **Settings → AI Presence**. Reporting sends the
IP the request arrived from, and that is what lets Globetrotters confirm a hit
really came from a known AI crawler. Behind a proxy with the setting off, every
hit reports the proxy's own address instead and none of them can be confirmed,
so your traffic shows up unverified. Only turn it on when it is actually true:
on a site reachable directly, the headers it starts trusting can be set by
anyone.

= Does reporting track my visitors? =

No. Agent traffic reporting records requests for the AI discovery files, and
only what the request itself carried: the file, the time, the User-Agent, and
the IP. There are no cookies and no visitor identifiers, and Globetrotters
discards the IP once it has resolved a country.

AI agent and bot visits to your own pages are reported too, on by default once
reporting is set up, with their own checkbox to turn them off. Only requests
whose user agent looks like a bot are kept, and for each one the plugin sends
its user agent, the page path (without query string), the response status and
whether the visit came from a verified AI vendor address. It never sends an IP
address for these visits, and nothing about human visitors. The check against
the AI vendor address list happens on your site, using a list the plugin
downloads from Globetrotters once a day.

Page-view counting is separate and off by default. When you switch it on, a
tiny invisible script on your pages posts the page's path to your own site,
which adds one to a count kept per page per day. Only those daily counts are
sent to Globetrotters. No IP address or cookie is read or stored, and the
browser's user-agent is checked only to leave out bots, never kept. The script
also sends the name of the website that referred the visit and the page's
`utm_source` value, when there are any; your site matches them against a short
list of AI assistants it downloads from Globetrotters once a day, keeps only the
assistant's name (or nothing), and discards them. The plugin
suggests a paragraph for your privacy policy under **Settings → Privacy →
Policy Guide** while counting is on.

= How does the plugin get its updates? =

Not from the WordPress.org directory. Updates are published by Globetrotters
and appear in your normal **Plugins** screen and update notifications like any
other plugin — WordPress checks `globetrotters.ai` for this plugin because the
plugin declares it. Each downloaded package is checked against the checksum in
that update listing before it is installed, and a package that does not match is
refused. Auto-updates can be turned on for it in the Plugins screen, same as
usual.

== Changelog ==

= 0.12.0 =
* New: the AI crawlers named in your robots.txt now come with the daily list
  the plugin already downloads, so a newly launched AI crawler is named
  without a plugin update. Until that list has downloaded, or if it is more
  than 14 days old, robots.txt names the same crawlers as before.

= 0.11.0 =
* Fixed: a Copilot answer's link (`utm_source=copilot.com?form=…`) now counts
  as a Copilot visit: the `utm_source` value is read up to its first `?`.
* Fixed: a link tagged with an AI assistant's `utm_source` that is clicked in
  Google, Bing or DuckDuckGo results counts as a search visit, not an AI one.
  The search engines come with the daily AI referrer list; until a list that
  names them has downloaded, such visits count as before.

= 0.10.0 =
* New: **Never serve cached pages to AI agents**, an opt-in under "AI agents on
  your pages". A page cache answers cached pages before the plugin runs, so
  the AI agents it serves were never counted, and Studio showed your numbers
  as "at least". With it on, the AI assistants' live-answer and AI-search
  agents (never training crawlers or search engines) are added to your
  page-cache plugin's "never cache" user agents, and Studio reports those
  agents' counts as complete when no CDN sits in front (the other bot counts
  stay "at least": the cache still serves them). Works with WP Rocket, W3 Total Cache
  and LiteSpeed Cache. WP Super Cache cannot stop a cached page being served
  to chosen user agents, so it is left alone. The list comes from
  Globetrotters with the AI vendor address list, never typed in by hand.
  Switching it off, deactivating or deleting the plugin removes exactly what it
  added; entries you listed yourself stay.

= 0.9.4 =
* Fix: a refresh run by a system cron through WP-CLI now reaches your visitors
  on sites with an APCu object cache. That cache is private to the web server,
  so the web server kept serving the version it had cached before, however
  many refreshes succeeded. Each refresh now leaves a small marker the web
  server checks past its cache.
* Fix: two refreshes starting together (WP-Cron and a system cron, or a cron
  run and **Refresh now**) no longer both download every file. The second one
  stands down at once.
* New: **Settings → AI Presence** shows who ran the last refresh (WP-CLI,
  WP-Cron or **Refresh now**, and the operating-system user) and the version
  your web server is actually serving, and warns when the two disagree. Agent
  traffic reports carry the served version too.

= 0.9.3 =
* New: the robots.txt block now also welcomes Exa's crawler (`ExaSearchBot`),
  which indexes for Exa, the search API AI agents query. As for every other
  agent, a site that already names it keeps its own rules.

= 0.9.2 =
* New: the robots.txt block now also welcomes Meta's on-demand fetcher and
  AI search indexer (`meta-externalfetcher`, `meta-webindexer`) and xAI's Grok
  agents (`GrokBot`, `xAI-Grok`, `Grok-DeepSearch`), each by name. As for
  every other agent, a site that already names one keeps its own rules.

= 0.9.1 =
* New: `/agents.md`, `/.well-known/agent-skills` and
  `/.well-known/agent-skills/` answer with the same files as `/AGENTS.md` and
  `/.well-known/agent-skills/index.json`, without a redirect. These are the
  addresses AI Readiness scanners check. Agent visits to them are reported under
  the address requested. The sitemap, the API catalog and the homepage `Link`
  header still list the main addresses only.
* New: the API catalog at `/.well-known/api-catalog` now lists your MCP server
  as an `item`, the standard entry for an API a host exposes. The address comes
  from your MCP manifest. The entry is left out when the manifest names no
  server.

= 0.9.0 =
* New: with page-view counting on, a visit that comes from a link in an AI
  answer is counted under the assistant it came from (ChatGPT, Claude,
  Perplexity, Gemini, Copilot), so Studio can show the visits AI answers bring
  to your own site, page by page. The beacon also sends the referring site's
  name and the page's `utm_source`; your site matches them against the AI
  referrer list and keeps only the assistant's name. Neither value is stored or
  sent anywhere.
* New: the AI referrer list comes with the daily AI vendor address download,
  in the same request. The settings screen shows how old it is while page views
  are counted.
* Changed: the page-view table's `bucket` column is widened on upgrade (schema
  version 4). Counts already taken today carry on unchanged.

= 0.8.2 =
* New: your site serves `/.well-known/api-catalog`, a standard API catalog
  (RFC 9727) that points agents at `/llms.txt`, the MCP manifest, the agent card
  and the AI catalog. It is generated from the files this site is serving, on
  your own address, and is answered only once one of them has been downloaded.
  `/ai-sitemap.xml` lists it, and agent visits to it are reported with the rest.
* Changed: the homepage `Link` header now starts with the API catalog and
  `/llms.txt`, under the standard `api-catalog` and `service-doc` relations that
  AI Readiness checks look for, followed by the MCP manifest, the agent card and
  the AI catalog as before.

= 0.8.1 =
* New: with the **Apex and subdomain** profile, every page's head now names your
  Globetrotters-hosted subdomain with a `<link rel="related">` tag. Since 0.7.0
  the other discovery tags point at your own site's copies once downloaded, so
  nothing in the head pointed at the subdomain any more.

= 0.8.0 =
* New: AI agent and bot visits to your own pages are reported, so Studio can
  show which pages AI agents read. On by default once reporting is set up, with
  its own checkbox under **Settings → AI Presence**. Sends the user agent, the
  page path, the status and whether the visit came from a verified AI vendor
  address; never an IP address, never anything about human visitors.
* New: the plugin downloads the AI vendor address list once a day and checks
  visits against it on your site. The settings screen shows how old the list is.
* New: each report says which page cache and CDN the site uses, so Studio can
  tell complete counts from minimums.

= 0.7.0 =
* New: your site now also serves the four agent discovery files Globetrotters
  publishes — `/agent.json`, `/AGENTS.md`, `/.well-known/ai-catalog.json` and
  `/.well-known/agent-skills/index.json` — exactly as published, refreshed with
  the rest. The AI Readiness report counts them as handled for you.
* New: your homepage answers with a `Link` header naming the MCP manifest, the
  agent card and the AI catalog, added next to any `Link` header your site
  already sends.
* New: robots.txt names the AI catalog with an `Agentmap:` line, once the
  catalog has been downloaded.
* Changed: the `ai-catalog` head tag points at your own site's copy once it has
  been downloaded, instead of the Globetrotters address.
* Changed: `/ai-sitemap.xml` lists the four new files.

= 0.6.1 =
* Fixed: a failed refresh is now retried within the hour instead of waiting a
  whole refresh interval (a day, or a week). The refresh now checks hourly and
  pulls only when your chosen interval has passed since the last successful
  refresh — or straight away if the cached files have gone missing.
* Fixed: the Globetrotters website URL must be an https:// address. A
  cleartext or scheme-less one is refused when you save, and a refresh refuses
  one set any other way before fetching anything, keeping the last good files
  in place.
* Fixed: fetches from Globetrotters follow a redirect only to another https://
  address. A redirect to a cleartext one fails the refresh before anything is
  fetched from it, and the last good files stay in place.

= 0.6.0 =
* New, optional and off by default: count human page views per page. Switch it
  on under **Settings → AI Presence** (it needs reporting set up first). A tiny
  invisible script posts the page's path to your own site — never to
  Globetrotters — which keeps one count per page per day, and only those daily
  counts are sent, once each day is over. No IP address or cookie is read or
  stored. Bots are left out, visits from AI-driven browsers are counted
  separately, and your own editors browsing the site are not counted.
* While counting is on, a suggested paragraph for your privacy policy appears
  under **Settings → Privacy → Policy Guide**.
* Up to 2,000 distinct pages are counted per day; views of any further page are
  counted together as "other".
= 0.5.0 =
* The AI crawler list your robots.txt names now matches the one Globetrotters
  itself publishes and grades sites against: 20 named crawlers instead of 11,
  covering OpenAI, Anthropic, Google, Microsoft, Apple, Meta, Amazon,
  Perplexity, DuckDuckGo, Common Crawl, ByteDance and Cohere.
* Listing those crawlers no longer changes what they may access on your site.
  Earlier versions gave each listed crawler its own "allow everything" entry,
  and a crawler with an entry of its own ignores your site's general rules —
  so each was released from WordPress's standard block on `/wp-admin/`, and
  from a site-wide block on a staging site. Now a crawler your robots.txt
  already names keeps exactly the rules you gave it, and every other one
  follows the same rules as all other crawlers on your site. Your robots.txt
  is read the way Google reads it, so a line such as `Content-Signal` or
  `Crawl-delay` placed between two `User-agent` lines does not change which
  rules those crawlers follow.
* Each listed crawler carries a `Content-Signal` line stating that your
  content may be used for search and for answering questions. Nothing is said
  about AI training unless you choose to — and if your robots.txt already has
  its own `Content-Signal` line, that one is used instead, including when you
  give the listed crawlers access to everything.
* Two new options under **Settings → AI Presence**, both off by default
  because each is a decision about your whole site: give the listed AI
  crawlers access to everything, including the paths closed to other
  crawlers; and tell them your content may be used to train AI models.
* If a refresh receives a web page where a discovery file should be — a
  maintenance page, a login screen or a proxy error that still answers "OK" —
  the refresh is now refused and your site keeps serving the files it already
  had. Previously the page was saved in place of the file and the refresh
  reported success.
* A value in your presence content containing HTML-like text such as
  `<!--<script>` could make browsers read the rest of your homepage as part of
  the structured data in its head, hiding the page. Those characters are now
  written in an escaped form that structured-data readers decode straight
  back, so nothing in the content can affect how your page is displayed.
* Your robots.txt now points search engines at a sitemap **on your own domain**
  instead of at Globetrotters. The old line named another host, which search
  engines ignore, so the discovery files had no sitemap entry anywhere that
  counted.
* The plugin serves that sitemap itself, at `/ai-sitemap.xml`. It lists your
  homepage and every discovery file this site actually serves, so it can never
  point at an address that isn't there. Your existing sitemap is untouched:
  nothing is served at `/sitemap.xml`, WordPress's own `/wp-sitemap.xml` is
  left alone, and an SEO plugin keeps whichever address it uses — robots.txt
  simply lists both.
* Plugin updates now reach your site. Every earlier version answered
  WordPress's update check in a form WordPress silently discards, so no update
  was ever offered in wp-admin. Install this version by hand once; updates
  after it arrive normally.
* Clearing the Globetrotters website URL now stops publishing straight away:
  the discovery files, the homepage structured data and the search-engine
  verification file are all removed until you connect again. Pointing the
  plugin at a different website URL likewise clears the previous one's files,
  and the new ones are served as soon as the first refresh completes.
  Previously the last files kept being served indefinitely.
* Agent traffic reporting only ever sends to an `https://` endpoint and never
  follows a redirect, so your ingest token and the visitor addresses it
  reports cannot travel unencrypted, or to another address.
* Trusted proxies are now a list. Tick **This site sits behind Cloudflare**,
  or enter the addresses of your own proxies or load balancers: the visitor
  address is read from forwarding headers only on requests that actually come
  from one of them. Previously a single checkbox trusted those headers from
  anyone, so on a site still reachable directly, a visitor could pass for a
  known AI crawler. A ticked checkbox now reads as the Cloudflare option — if
  your proxy is something else, add its addresses.
* Reporting bookkeeping and refreshes no longer overwrite each other: a busy
  moment can no longer lose count of dropped requests, or undo a refresh that
  finished at the same time.

= 0.4.0 =
* Your site can now prove to search engines that it is yours, so Globetrotters
  can tell them the moment your presence content changes instead of waiting to
  be crawled. The plugin serves the verification file search engines look for,
  at the one address where it counts — your apex. Nothing to install, configure
  or upload: the key arrives with your normal refresh, and if Globetrotters has
  not issued one for your site the address simply isn't served.
* If a key is later changed or withdrawn, your site stops serving the old one on
  its next refresh rather than keeping a copy that no longer verifies anything.
* Requests for the verification file are not counted in Presence Analytics —
  those numbers stay a measure of AI agents fetching your discovery files.

= 0.3.0 =
* Added the **Apex and subdomain** install profile, and made it the recommended
  choice. Your site keeps serving the discovery files at your apex exactly as
  before, and now also points at the Globetrotters-hosted subdomain carrying
  your full presence — which nothing on the public web linked to until now, so
  AI assistants could only reach it if they already knew the address. The
  pointer is added to the head of your pages only: no visible change to your
  design, nothing for your theme to accommodate. Choose it under
  **Settings → AI Presence**; **Apex only** stays the default and behaves
  exactly as it always has.
* Robustness fixes to how the discovery files are refreshed and served. Nothing
  changes in what the plugin does when everything is working; these all concern
  what happens when something upstream is not.
* If every discovery file comes back empty in one refresh, the refresh is now
  refused and your site keeps serving the files it already had. That pattern
  means a broken source or a proxy answering with no content, not a real
  publish — previously the empty files were cached and your apex went blank
  until the next good refresh.
* If the refreshed files cannot be saved, the refresh is now reported as
  failed instead of succeeding silently. Your previous files keep serving and
  the reason appears under **Settings → AI Presence**, which until now could
  show a healthy, up-to-date install that was in fact still serving the older
  files.
* The discovery files now answer `GET` and `HEAD` requests only. Other request
  methods are passed on to WordPress, so they no longer inflate the request
  counts in your Presence Analytics.
* Each discovery file is now served at its own single URL only. Requests with
  extra leading slashes — which could reach the file under any number of
  made-up addresses, including ones naming a site that is not yours — are
  passed on to WordPress instead.

= 0.2.2 =
* Fixed a WordPress notice ("translations were triggered too early") that the
  plugin could write to your debug log on WordPress 6.7 and later. It appeared
  only on the single page load where the plugin first set up its scheduled
  tasks — a fresh install, or the first load after you turn on agent traffic
  reporting. Nothing was broken by it, and scheduled refreshes and reporting
  still start on that same page load.
* Internal compatibility work for the WordPress.org plugin review: stricter
  handling of the reporting table's name in database queries, and removal of a
  translation loader the plugin never needed. Nothing changes in what the
  plugin does.

= 0.2.1 =
* The AI discovery files are now served with `Access-Control-Allow-Origin: *`.
  They are public, read-only files that anyone can already fetch, and without
  this header an AI assistant running inside a browser is blocked from reading
  them. Only the discovery files this plugin serves are affected; nothing else
  on your site changes.

= 0.2.0 =
* Added agent traffic reporting. Requests to the AI discovery files terminate on
  your own site and are invisible to Globetrotters, so the plugin now records
  each one locally and sends batches to the Globetrotters ingest endpoint every
  15 minutes. Paste the ingest token and endpoint URL (issued together in the
  Apex install panel in Studio) under **Settings → AI Presence**; until you do,
  nothing is recorded and nothing is sent.
* The AI discovery files are now served `no-store` (both `Cache-Control` and
  `Surrogate-Control`). Without this a CDN or managed-host edge cache would serve
  them without ever reaching PHP, and the reported counts would be low by an
  unknowable amount.
* Added a "trusted proxy" setting for sites behind Cloudflare, a CDN or a load
  balancer. It is off by default and should only be turned on when it is true.
* Buffered requests are capped at 5000 or 512KB; anything dropped past that is
  counted and reported, so a gap shows up as a gap rather than as quiet traffic.
* A site with enough agent traffic to fill that buffer inside one 15-minute
  window now sends early rather than waiting for the next scheduled send, so
  busy sites report in full instead of losing the overflow.

= 0.1.1 =
* Fixed the content hash to match the backend `canonical_hash` byte-for-byte
  (raw inner digest + byte-lexicographic ordering). Side effect: the first
  refresh after updating recomputes the stored hash with the new algorithm, so
  a single refresh reports the bundle as changed even when the content is
  byte-identical (same behaviour as a fresh install); it settles from the next
  refresh on.

= 0.1.0 =
* Initial release: apex file serving, `wp_head` JSON-LD injection, `robots.txt`
  filter, scheduled refresh with stale-serve fallback, and an admin status page.
