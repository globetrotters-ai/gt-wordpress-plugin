# Globetrotters AI Presence — WordPress plugin

Serves your Official AI Presence at a WordPress site's apex (AI discovery files +
server-rendered JSON-LD), pulled automatically from Globetrotters. No filesystem
access required — the plugin hooks WordPress's own router. It also reports agent
traffic to those paths back to Globetrotters (see
[Agent traffic reporting](#agent-traffic-reporting)) and updates itself from a
Globetrotters-hosted channel (see [Versions and updates](#versions-and-updates)).

User-facing docs live in [`readme.txt`](./readme.txt) (WordPress.org format). This
README is for development.

- Plugin page and download: <https://globetrotters.ai/wp-plugin>
- Not on WordPress? The same behaviour for Symfony apps:
  [globetrotters-ai/gt-symfony-bundle](https://github.com/globetrotters-ai/gt-symfony-bundle)
  (`composer require globetrotters-ai/symfony-bundle`)
- An Official AI Presence to serve: [sign up for Studio](https://studio.globetrotters.ai/signup)
- Contributing: [CONTRIBUTING.md](https://github.com/globetrotters-ai/gt-wordpress-plugin/blob/main/CONTRIBUTING.md)
  (issues welcome, pull requests by invitation). Security reports:
  [SECURITY.md](https://github.com/globetrotters-ai/gt-wordpress-plugin/blob/main/SECURITY.md).

## Local development (wp-env)

Prerequisites: [Docker Desktop](https://www.docker.com/products/docker-desktop) and
[Node.js](https://developer.wordpress.org/block-editor/getting-started/devenv/).

From the plugin directory:

```sh
npx @wordpress/env start
```

`wp-env` (configured in [`.wp-env.json`](./.wp-env.json)) mounts and activates this
plugin automatically on both instances. Then:

- Dev site: `http://localhost:8888` (`wp-admin`, user `admin`, password `password`) —
  ships with the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin.
- Isolated tests instance: `http://localhost:8889` — preloads the field antagonists
  (Wordfence + WP Super Cache) the integration lane runs against.

Common commands:

```sh
npx @wordpress/env stop        # stop the containers
npx @wordpress/env clean all   # reset the database
npx @wordpress/env destroy     # remove the environment
npx wp-env run cli wp <cmd>    # run WP-CLI inside the container
```

Ref: [Get started with wp-env](https://developer.wordpress.org/block-editor/getting-started/devenv/get-started-with-wp-env/).

## Configure it (set your website URL)

In `wp-admin` → **Settings → AI Presence**: enter your **Globetrotters website URL**,
choose a **refresh cadence**, and **Save Changes** — then **Refresh now**. The plugin
pulls the apex artefacts from that URL and starts serving them.

Scriptable equivalent:

```sh
npx wp-env run cli wp option update gtaip_settings --format=json \
  '{"website_url":"https://your-site.example","refresh_interval":"daily"}'
```

Verify it's serving:

```sh
curl -I http://localhost:8888/llms.txt
curl -s http://localhost:8888/.well-known/globetrotters-apex-version.json
```

## What the apex serves

| Path | Content-Type |
|---|---|
| `/llms.txt` | `text/plain; charset=utf-8` |
| `/ai.json` | `application/json; charset=utf-8` |
| `/schema.json` | `application/ld+json; charset=utf-8` |
| `/.well-known/mcp.json` | `application/json; charset=utf-8` |
| `/.well-known/agent-card.json` | `application/json; charset=utf-8` |
| `/.well-known/ai-catalog.json` | `application/json; charset=utf-8` (since 0.7.0) |
| `/.well-known/agent-skills/index.json` | `application/json; charset=utf-8` (since 0.7.0) |
| `/agent.json` | `application/json; charset=utf-8` (since 0.7.0) |
| `/AGENTS.md` | `text/markdown; charset=utf-8` (since 0.7.0) |
| `/.well-known/globetrotters-apex-version.json` | `application/json; charset=utf-8` (version/drift marker) |
| `/.well-known/api-catalog` | `application/linkset+json` (since 0.8.2, generated locally, see below) |

Every file except the version marker is the published copy, byte for byte,
and a required artefact of every refresh: one missing upstream fails the pull
and the last good bundle keeps serving. The marker is fetched on its own and
synthesized when Globetrotters serves none, so it never fails a refresh. The set is [`ContentTypes`](src/Serving/ContentTypes.php), a mirror of
the backend's apex discovery set plus the marker.

Since 0.9.1 three aliases answer with a canonical file's bytes and headers, in
place, with no redirect: `/agents.md` (`AGENTS.md`), and
`/.well-known/agent-skills` and `/.well-known/agent-skills/` (the Agent Skills
index). They are the spellings AI Readiness scanners probe. A hit is reported
under the path actually requested. The sitemap, the API catalog and the `Link`
header list canonical paths only.

[`ApiCatalog`](src/Serving/ApiCatalog.php) answers `/.well-known/api-catalog`,
an [RFC 9727](https://www.rfc-editor.org/rfc/rfc9727) API catalog: one linkset
entry anchored on this site's homepage, with `/llms.txt` as `service-doc` and
the MCP card, the agent card and the AI catalog as `service-desc`. Since 0.9.1
it also lists the MCP server as an `item` (`remotes[0].url` from the cached
`mcp.json`, the one link on the Globetrotters host), left out when the manifest
names no remote. Like the
sitemap it is generated from the cache on every request, never fetched, on this
site's own origin, and lists only what is cached; with none of its targets
cached the path falls through to WordPress's 404. It carries the artefacts'
headers (`nosniff`, `no-store` on both cache headers,
`Access-Control-Allow-Origin: *`), is listed in `/ai-sitemap.xml`, and agent
requests for it are reported as agent traffic.

[`LinkHeader`](src/Serving/LinkHeader.php) adds the IANA-registered
`api-catalog` and `service-doc` relations validators look for, then the HTTP
twin of the head `<link>` tags, to the homepage, on a `200` HTML front-page response only, as a
header line of its own beside the `Link` header core already sends:

```
Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"
```

It names only the targets this install serves: a cached artefact, or the API
catalog once one of its targets is cached. A full-page cache serving the
homepage without running PHP (WP Super Cache's static mode, for one) replays
whatever headers it stored, which may not include it.

Under the `subdomain_breadcrumb` install profile,
[`Breadcrumbs`](src/Serving/Breadcrumbs.php) also prints a `<link>` block on
`wp_head`. Each discovery relation points at this apex once the file is cached
here (the Globetrotters copy until then), `rel="alternate"` is homepage-only, and
`rel="related"` names the Globetrotters-hosted presence host itself, on every
page and whatever the cache holds — once every file is served locally, it is the
one relation that still points there:

```html
<!-- Globetrotters — AI presence -->
<link rel="alternate" type="application/ld+json" href="/schema.json">
<link rel="ai-catalog" href="/.well-known/ai-catalog.json">
<link rel="mcp" href="/.well-known/mcp.json">
<link rel="agent-card" href="/.well-known/agent-card.json">
<link rel="related" href="https://ai.your-domain.example/">
```

## Refreshing from cron, WP-CLI and the web server alike

The pulled bundle lives in a non-autoloaded option, `gtaip_artefacts`, so every
process using the site's database reads the same copy: WP-Cron's loopback, a
system cron running `wp cron event run --due-now`, and PHP-FPM. A persistent
object cache in front of that table need not be shared, though. An APCu
`object-cache.php` drop-in (W3 Total Cache's APCu object cache, APCu Manager…)
lives inside one PHP process pool, so a pull run by WP-CLI wrote the table while
PHP-FPM kept serving the bundle it had cached, indefinitely. Since 0.9.4:

- **Each pull writes a stamp** (`gtaip_artefacts_stamp`): the bundle's
  generation, version, time, and *who pulled it* — the lane (`wp-cli`,
  `wp-cron` or `web` for **Refresh now**) and the OS user. Behind a persistent
  object cache, every request that reads the bundle reads this small row past
  the cache, and a cached bundle of another generation is evicted and read
  again (a bundle cached before 0.9.4 carries no generation and is re-read
  until the next pull). A stamp that does not land rolls the bundle back.
  Without a persistent object cache, nothing extra is read.
- **The web server leaves a heartbeat** (`gtaip_served`): the version a web
  request actually served, written when it changes and otherwise at most
  hourly, never from cron or WP-CLI. The flush sends it as
  `captureContext.servedVersion` (the apex version id, e.g.
  `"2026-10-18-050325"`, or `null`), the same key and format as
  the Globetrotters Symfony bundle 0.9.4. **Settings → AI Presence** shows the last pull's
  lane and user beside the served version, and flags a version served *after*
  the pull that is not the pull's.
- **One refresh at a time.** WP-Cron's loopback and a system cron could fire
  the same due refresh milliseconds apart, and each fetched the whole set. A
  lock row (`gtaip_refresh_lock`), taken with `INSERT IGNORE` against the
  options table — never `add_option()`, which trusts the local cache — makes a
  second refresher stand down at once without fetching or recording an error.
  A holder that died is reclaimed after ten minutes, and a holder that was
  only slow finds its lock gone before it stores, and discards its pull.

## robots.txt

[`RobotsFilter`](src/Serving/RobotsFilter.php) appends one block to WordPress's
virtual `robots.txt`: the AI user agents in the Globetrotters registry, a
`Content-Signal` for them, an `Agentmap: /.well-known/ai-catalog.json` line
while the catalog is cached (since 0.7.0), and a `Sitemap:` line for this host's
`/ai-sitemap.xml`. It hooks `robots_txt` at `PHP_INT_MAX`, so it reads the file
every other filter produced, and adds nothing when the site discourages search
engines, is not connected, or has no bundle yet.

**Naming an agent is not neutral, so by default the block changes nothing a
crawler may fetch.** Under RFC 9309 a crawler obeys only the group(s) naming it
and falls back to `User-agent: *` only when none does. A named group saying
`Allow: /` would release that agent from the wildcard rules — WordPress's own
`Disallow: /wp-admin/`, a staging site's `Disallow: /` — and one added for an
agent the file already names would merge with the site's rules for it and
outrank them. So, reading the existing file through
[`RobotsPolicy`](src/Serving/RobotsPolicy.php):

- **agents the file already names are never added.** Applebot counts as named
  when Googlebot is, since Apple documents that it follows Googlebot's group;
- **every other agent inherits the wildcard group's lines**, carried once in a
  single shared multi-`User-agent` group. Only a file with no wildcard rules
  gives each agent its own `Allow: /` group — which is what it already had;
- the signal is `Content-Signal: search=yes, ai-input=yes`, or the wildcard
  group's own `Content-Signal` when it has one;
- a user-agent section the file leaves open is closed with a pathless
  `Disallow:` — which changes nothing — before the block is appended, so the
  appended groups cannot join it.

Groups are read the way Google reads them: only an `Allow` or `Disallow` line
ends a user-agent section, so a `Content-Signal`, `Crawl-delay` or `Sitemap`
line between two `User-agent` lines leaves them sharing the rules that follow.
`User-agent` values are matched on their product token (`GPTBot/1.1` and
`GPTBot2` both name GPTBot), as Google's parser reads them.

`tests/unit/Serving/RobotsFilterTest` checks this against a port of
google/robotstxt's matcher: across every fixture, decorating must not change
any registry agent's allow/deny on any probed path.

Two opt-ins, both off, under **Settings → AI Presence → AI crawlers in
robots.txt**. `robots_ai_agents: allow_all` gives every agent the file does not
name `Allow: /`, overriding the wildcard rules for it (never a group the site
wrote); `robots_ai_train: true` adds `ai-train=yes`. With both, and no wildcard
rules, the block is byte-for-byte the backend registry's. A `Content-Signal`
your wildcard group declares is kept either way: `allow_all` grants crawl
permission, and does not overwrite what you have said about how your content
may be used. Same behaviour and option names as the Symfony bundle's
`robots.ai_agents` / `robots.ai_train`.
Scripted — the option is written whole, so include every key the install
already uses (see the note under [Agent traffic reporting](#agent-traffic-reporting)):

```sh
npx wp-env run cli wp option update gtaip_settings --format=json '{
  "website_url": "https://your-site.example",
  "robots_ai_agents": "allow_all",
  "robots_ai_train": true
}'
```

## Agent traffic reporting

Requests for the artefact paths terminate in this WordPress install — they never
reach a Globetrotters edge — so without this lane an apex install looks like it
gets no agent traffic at all. Studio issues an **endpoint** and a **token**
together on the apex install screen (`AI Presence → Content → Apex install`, the
token shown exactly once); both go into `wp-admin` → **Settings → AI Presence**.
Capture and flushing are both gated on the pair being present ([`Options::is_reporting_configured()`](src/Settings/Options.php)) —
an install that will never report pays nothing per served request.

Scriptable equivalent:

```sh
npx wp-env run cli wp option update gtaip_settings --format=json '{
  "website_url": "https://your-site.example",
  "refresh_interval": "daily",
  "ingest_endpoint": "https://api.globetrotters.ai/presence/analytics/server-log",
  "ingest_token": "…",
  "trusted_proxies": "",
  "trusted_proxy_cloudflare": false
}'
```

The endpoint must be `https://`: it receives the token and every captured client
IP, so a cleartext one is refused on save and reads as unconfigured if written by
hand.

The website URL must be `https://` too: what is pulled from it is published at
this site's apex. A cleartext or scheme-less one is refused on save, and if
written by hand every refresh refuses it before fetching, records why, and
keeps the last good bundle serving. Redirects are followed only to https, so an
https source cannot hand the pull to a cleartext one: such a redirect fails
the refresh.

Write the whole option rather than patching one key: `wp option patch update`
fails with "No data exists for key" when the key isn't already present, and the
defaults live in PHP, not in the stored option. The save itself is what creates
the buffer table — `update_option_gtaip_settings` is hooked outside `is_admin()`
precisely so a WP-CLI configuration gets the table too.

How it fits together:

- **Capture** — [`EventRecorder`](src/Analytics/EventRecorder.php) writes one row
  per served artefact request from the serve path. It swallows every `Throwable`:
  a dead buffer degrades reporting, never serving. Timestamps are always
  `gmdate()`, never `current_time()` (site-local time would land hits in the
  wrong bucket silently).
- **Storage** — a dedicated table, `{prefix}gtaip_events`
  ([`Schema`](src/Analytics/Schema.php)), not a serialised option: concurrent
  artefact requests would lose events to a read-modify-write on one blob. It is
  created **on demand** (settings save, `admin_init`, the flush cron, activation) and every read
  and write is gated on `Schema::is_ready()` (version ≥ 1), so a DB user without `CREATE`
  degrades to "no reporting" rather than a broken site.
- **Bounds** — 5000 events or 512KB, whichever comes first
  ([`EventBuffer`](src/Analytics/EventBuffer.php)). Overflow drops the oldest and
  *counts* them; the count ships in the next envelope and clears only once that
  envelope is accepted, so a gap reads as a gap and not as quiet traffic. The
  counts live beside the table in two option rows that are only ever changed by
  a single SQL statement each: prunes run concurrently on the serve path, in
  exactly the burst that produced the drops.
- **Client IP** — [`ClientIp`](src/Analytics/ClientIp.php) believes a forwarding
  header only when the connection itself came from a proxy listed under
  *Trusted proxies* ([`TrustedProxies`](src/Analytics/TrustedProxies.php)).
  `X-Forwarded-For` is walked from the right past listed hops, and
  `CF-Connecting-IP` is read only from Cloudflare's own ranges, under the
  Cloudflare preset. Nothing listed means `REMOTE_ADDR`: a forged vendor IP is
  the one thing that could manufacture a verified hit.
- **Flush** — WP-Cron, `gtaip_flush` on a plugin-registered 15-minute schedule
  ([`FlushScheduler`](src/Analytics/FlushScheduler.php)), independent of the
  artefact refresh cadence (which the customer may set to weekly). `wp_cron()`
  runs on `init`, ahead of the `parse_request` serve hook, so artefact traffic
  drives its own flushes. Past 60% of either bound, a one-off `gtaip_flush_now`
  is queued so a busy site follows traffic instead of the clock. The flush is
  always the cron loopback, never inline in a served response.
- **Wire** — `POST` to the configured endpoint, token as a `Bearer` credential,
  JSON body gzipped when zlib is available
  ([`IngestClient`](src/Analytics/IngestClient.php)). https only, and never
  following a redirect: core's HTTP client re-sends every header, the token
  included, to whatever `Location` a 3xx names. Per event: UUID, UTC
  timestamp, canonical path, User-Agent, client IP, referer, status, byte size.
  No cookies, no per-visitor identifiers.
- **Signed requests** — ingest contract § "Signed requests (v7)". Some AI
  agents (Meta's) send a stock browser User-Agent and sign the request with
  Web Bot Auth (RFC 9421) instead. A non-empty `Signature-Agent` header passes
  the site-capture gate whatever the UA, and when `Signature-Agent`,
  `Signature-Input` and `Signature` are all present the event — site or
  artefact — carries `"signature": {agent, input, value, authority}`: the
  three headers as received (trimmed, quotes kept), plus the request's
  authority (the host lower-cased, `:port` only when not the scheme's
  default, `X-Forwarded-Host`/`-Port`/`-Proto` honoured only from a trusted
  proxy, exactly as the Symfony bundle sends it), each clipped at 1024
  characters ([`RequestSignature`](src/Analytics/RequestSignature.php)).
  Nothing is verified here: the backend verifies at ingest, so a request never
  triggers a key fetch from the site. Buffered in the `signature` column
  (schema 5); before that column lands, the event is buffered without it.
  A page-view beacon carrying `Signature-Agent` counts as `ai_browser`.

Inspecting a local install:

```sh
npx wp-env run cli wp option get gtaip_state --format=json   # flush counters, drops, last error
prefix=$(npx wp-env run cli wp db prefix | tr -d '\r' | tail -1)
npx wp-env run cli wp db query "SELECT COUNT(*) FROM ${prefix}gtaip_events"
npx wp-env run cli wp cron event list | grep gtaip
```

**A `202` is not proof the token is good.** The ingest endpoint answers `202` to
a valid batch, a revoked token, an unknown install and a malformed body alike —
deliberately, so it reveals nothing about which tokens exist. The status block on
the settings screen ([`SettingsPage::render_reporting_status()`](src/Settings/SettingsPage.php))
can therefore only distinguish "configured but never accepted" from "batches are
being accepted"; confirm the actual numbers in Studio.

### Behind a proxy or CDN

The client IP is what lets the backend forward-confirm that a claimed ClaudeBot
hit really came from Anthropic. [`ClientIp`](src/Analytics/ClientIp.php) reads
`CF-Connecting-IP` → leftmost `X-Forwarded-For` → `REMOTE_ADDR`, but **only
consults the first two when the "trusted proxy" setting is on**. Off by default:
on a direct-to-origin site both headers are attacker-controlled, and honouring
them would let anyone claim an IP inside a published vendor range and manufacture
`verified=true` hits. The cost of the safe default is the opposite failure —
behind Cloudflare with the toggle off, every hit reports Cloudflare's address and
the whole install fails verification.

### Page-view counts (optional, off by default)

A second, separate lane: **human page views per page of the whole site**, the
denominator Presence Analytics otherwise cannot see. Switched on by the
*Count human page views on this site* checkbox in the reporting section
(`gtaip_settings['count_page_views']`, default `false`), and active only while
reporting is configured ([`Options::counts_page_views()`](src/Settings/Options.php)).
The wire contract is the Globetrotters server-log ingest contract,
§ "Page-view counts (v2)".

- **Script** — [`PageViewScript`](src/Serving/PageViewScript.php) prints one
  inline `<script>` on `wp_footer` (via `wp_print_inline_script_tag()`, so CSP
  plugins can nonce it), under 500 bytes, no dependency, wrapped in
  `try`/`catch`. Once the page is actually shown — a Speculation Rules
  prerender waits for `prerenderingchange` — it `sendBeacon`s
  `{"p": location.pathname, "r": <referrer host>, "u": <utm_source>}` to the
  **same-origin** path `/.well-known/globetrotters/pv`; the browser never talks
  to Globetrotters. `r` is sent only when the referrer is another host, `u`
  only when `utm_source` is set and at most 100 characters long; never the referrer's
  path or any other query parameter. Byte-identical to the Symfony bundle's
  beacon, and pinned in a test in each repo. Skipped in admin, feeds, previews, the Customizer, 404s, and
  for logged-in users who can `edit_posts`. With counting off, nothing is hooked
  on `wp_footer` at all. A script rather than a render hook because the page is
  usually served by a page cache that PHP never sees; the script is in the
  cached HTML, and its POST is never cached.
- **Endpoint** — [`Router`](src/Serving/Router.php) answers
  `POST /.well-known/globetrotters/pv` on the same `parse_request` priority 0
  hook, in a branch of its own ahead of the artefacts' `GET`/`HEAD` gate. It is
  answered whenever the plugin is active, and counts only while counting is on:
  cached pages keep beaconing after counting is switched off, and falling
  through would cost a full 404 render each and feed security plugins' 404
  lockouts. [`PageViewCounter`](src/Analytics/PageViewCounter.php) accepts
  only a same-origin beacon — a real `Origin` host decides; else
  `Sec-Fetch-Site: same-origin` (what a `Referrer-Policy: no-referrer` page
  sends, with `Origin: null` and no `Referer`); else the `Referer` host — a
  body ≤ 1 KB of `{"p": "<path>"}`, and a path the backend would accept (no
  `?` or `#`, starts with one `/`, ≤ 512 bytes, trailing slash folded). A UA
  with a bot marker, or none, is not counted; one naming ChatGPT, OAI, Comet,
  Perplexity or Claude, or a beacon carrying a `Signature-Agent` header,
  counts as `ai_browser`, anything else as `browser`. The
  answer is always a bare `204` with `no-store` on both cache headers. It
  **never reads `REMOTE_ADDR` or a forwarding header, never stores the UA, and
  never sets a cookie**. With a persistent object cache, a path is capped at 120
  increments a minute.
- **AI referrals** — for a `browser` view only, and only when the beacon
  carries `r` or `u` as strings, [`AiReferrers`](src/Analytics/AiReferrers.php)
  matches them against the synced AI referrer list: `r` (lower-cased, `:port`
  and trailing dot stripped) in `hosts` first, then — only when `r` is empty or
  matches none of the `searchHosts` patterns (a tagged link clicked in Google,
  Bing or DuckDuckGo results is a search click) — `u` (trimmed, lower-cased,
  cut at its first `?`) in `utmSources`; a host entry wins. A bare pattern
  matches exactly; `google.*` matches `google.` plus one or two TLD labels. Only the resulting platform (`chatgpt`,
  `claude`…, else `none`) is kept; `r` and `u` are never stored, logged or
  sent. The list is the `aiReferrers` member of the daily `/ranges` document,
  stored by [`VendorRanges`](src/Analytics/VendorRanges.php) in its own
  non-autoloaded option `gtaip_ai_referrers` (`{version, fetched_at, hosts,
  utmSources, searchHosts}`) from the same fetch: a `304` re-stamps both lists,
  and no `If-None-Match` is sent while that option is missing, at another
  version or stored without `searchHosts`, so an upgrade from 0.8.x or 0.10.x
  fills it on the first sync. A beacon with no hint
  never reads it, and no beacon ever loads the vendor range list. Missing,
  unreadable or older than 14 days, the list matches nothing and views count as
  `none`.
- **Storage** — `{prefix}gtaip_page_views`, one row per `(UTC day, bucket,
  path)`, upserted in one statement. `bucket` holds `browser` / `ai_browser`
  for an unattributed view — exactly what 0.8.x wrote, so an open day keeps
  counting across the upgrade — and `browser@<platform>` for a referred one
  (schema version 4 widens the column to 48; until it lands, referred views
  count as `none`). At most 2000 distinct paths a day, counted across buckets
  and platforms; views of any further path go to the literal path `(other)`,
  each keeping its bucket and platform. Same table lifecycle as
  the event buffer, but its own readiness (`Schema::page_views_ready()`, schema
  version ≥ 2): a missing counter table leaves page views inert and never stops
  agent-traffic reporting, which needs only the buffer (`Schema::is_ready()`,
  version ≥ 1).
- **Flush** — in the same envelope as events, under `pageViews`, as
  `{id, day, path, bucket, platform, count}`: the stored bucket is split back
  into `bucket` and `platform` (`none` for a row without one). A day is sent
  only once it has closed (UTC); closing it assigns each row a UUID once, which
  every retry re-sends. Rows are deleted only after a 2xx, and days older than
  the backend's 7-day window are pruned rather than sent. A page-view-only
  envelope (`"events": []`) is sent when there are no events.

Suggested privacy-policy paragraph (also offered under **Settings → Privacy →
Policy Guide** while counting is on):

> This site counts page views with a first-party, cookieless counter. When a
> page loads, a small script sends the address of that page (without any query
> string) to this site, which adds one to a count kept per page per day. With
> it, the script sends the name of the website that referred the visit, if any,
> and the page's utm_source value, if any; this site uses them only to tell
> whether the visit came from an AI assistant such as ChatGPT, keeps only the
> assistant's name, and discards them. No IP address, cookie or other identifier
> is read or stored, and the browser's user-agent is checked only to leave out
> automated traffic, never kept. Only those aggregate daily counts per page are
> sent to Globetrotters, the service that reports this site's visibility to AI
> assistants.

## Versions and updates

Two unrelated version tracks, easy to confuse:

**The artefact bundle** — what was pulled from Globetrotters.
[`ArtefactSync`](src/Sync/ArtefactSync.php) writes `installed_version`,
`latest_version` and `content_hash` into the `gtaip_state` option; the settings
screen shows installed vs latest, and [`Notices`](src/Admin/Notices.php) raises an
admin notice on drift. The same version is published at
`/.well-known/globetrotters-apex-version.json` as the marker an external checker
reads.

**The plugin itself** — updated from a Globetrotters-hosted channel, not WP.org.
The plugin header carries `Update URI: https://globetrotters.ai/wp-plugin`, so
WordPress 5.8+ routes its lookups to the `update_plugins_globetrotters.ai` filter
(and refuses a WP.org package for this slug).
[`UpdateChecker`](src/Update/UpdateChecker.php) fetches
`https://globetrotters.ai/wp-plugin/update.json` (12-hour transient) and verifies
the downloaded package's `sha256` against that metadata before install —
WordPress does not check third-party package signatures itself. `make release
RELEASE_DIR=…` stages the versioned zip and `update.json` into the website's
`wp-plugin/` directory, which is deployed to https://globetrotters.ai/wp-plugin.

Point it at a fixture instead (E2E, local):

```php
define( 'GTAIP_UPDATE_METADATA_URL', 'http://localhost:8888/fixtures/update.json' );
// or: add_filter( 'gtaip_update_metadata_url', fn() => '…' );
```

## Developer tasks (Make → Composer)

```sh
make install           # composer install
make lint              # PHPCS (WPCS + PHP 7.4 compat)
make lint-fix          # auto-fix fixable violations
make test              # PHPUnit unit suite (Brain\Monkey, no WordPress booted)
make check             # lint + unit test
make dist              # build build/globetrotters-ai-presence.zip
```

### Integration + Plugin Check (needs Docker + wp-env)

These boot a real WordPress core, so they run separately from `make check`:

```sh
make test-integration  # wp-env start → PHPUnit integration suite + route/antagonist smoke → stop
make plugin-check      # wp-env start → `wp plugin check` (the WP.org release gate) → stop
```

- **Integration suite** (`tests/integration/`, PHP config `phpunit-integration.xml.dist`)
  exercises the seams unit tests can't: route serving, the `robots_txt` filter,
  `wp_head` JSON-LD, and refresh/stale-serve. Globetrotters HTTP is stubbed via
  `pre_http_request`, so it never touches the network.
- **Route smoke** (`bin/smoke-routes.sh`) curls each served path on the tests instance
  (Wordfence + a page cache active) to confirm 200s, Content-Types, `robots.txt`,
  front-page JSON-LD, and that a fresh pull isn't served stale past the refresh window.

CI runs the unit suite across PHP 7.4–8.3, the integration suite across WP latest +
latest-1 × page-cache antagonist, and Plugin Check on every PR. To reproduce a specific
CI matrix cell locally, override the wp-env core before starting:

```sh
WP_ENV_CORE=WordPress/WordPress#6.7 npm run env:start
```
