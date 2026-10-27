# Serving detail (`src/Serving/`)

The robots.txt block, install profiles and breadcrumb, the served set, the IndexNow key and the sitemap. Read `CLAUDE.md` first; its invariants apply here too.

## The robots.txt block

`RobotsFilter::build_block()` names the AI crawlers **without changing what any of them may fetch**. Naming an agent is not neutral: per RFC 9309 §2.2.1 a crawler obeys only the group(s) naming it and falls back to `User-agent: *` only when none does. So an appended `User-agent: X` / `Allow: /` group releases X from the site's wildcard rules (WordPress's `Disallow: /wp-admin/`, a staging `Disallow: /`), and one for an agent the site already names merges with the site's group and outranks it (`Allow: /` wins an equal-length `Disallow: /`). Reading the site's file through `Serving\RobotsPolicy`:

- agents the site names are never added — an agent counts as named when its fallback group is (`Applebot` reads `Googlebot`'s group, Apple's documented fallback);
- every other agent inherits the wildcard group's member lines, carried **once** in a single multi-`User-agent` group. A copy per agent would push a long file towards the size crawlers stop reading at, and a truncated copy is more permissive than its source;
- only with no wildcard rules does each agent get its own `Allow: /` group — which is what it already had;
- `Content-Signal: search=yes, ai-input=yes` goes in every emitted group (a copy in one group never reaches another), unless the site's wildcard group carries its own `Content-Signal`, which is inherited instead — under `allow_all` too, which opts into crawl permission, not into overwriting the site's statement of how its content may be used;
- a user-agent section the site's file leaves open (`RobotsPolicy::ends_inside_a_group()`) is closed with a pathless `Disallow:` right after the marker, so the appended groups cannot join it. Google's parser gives a pathless rule priority 0, which never decides a match, so the closer changes nothing.

Groups are read the way Google reads them: **only an `Allow` or `Disallow` line ends a user-agent section** (Google's spec, "Grouping of lines and rules", and google/robotstxt, where only `HandleAllow`/`HandleDisallow` set the separator; RFC 9309's grammar leaves other records to the crawler). A `Content-Signal`, `Crawl-delay`, `Sitemap` or unknown line between two `User-agent` lines leaves them one group sharing the rules that follow. Non-rule lines stay members of their section and are assigned once it is complete, so a line written before `*` joins still counts for the wildcard. `User-agent` values match on their product token, `[A-Za-z_-]+`: `GPTBot/1.1` and `GPTBot2` both name GPTBot, `*` alone or before whitespace is the wildcard, and `*bot` names nothing.

`Allow: /` over wildcard rules and `ai-train=yes` are explicit opt-ins, `gtaip_settings['robots_ai_agents'] = 'allow_all'` and `['robots_ai_train'] = true` (read through `Settings\RobotsOptions`), matching the Symfony bundle's `robots.ai_agents` / `robots.ai_train`. `tests/unit/Serving/RobotsFilterTest` carries a port of google/robotstxt's `RobotsMatcher` (`allows()` + `match_priority()`), self-checked against cases from Google's spec (`test_the_evaluator_groups_and_matches_the_way_google_does`), and asserts that decorating changes no registry agent's allow/deny on any probed path, across fixtures. Extend its fixtures rather than weakening it, and never rewrite that evaluator in terms of `RobotsPolicy`: an evaluator sharing `RobotsPolicy`'s model would share its mistakes, so the test could not see them.

**Which agents are named.** The registry is synced from the backend (`aiUserAgents` in the `/ranges` document, stored by `Analytics\AiUserAgents`; see "The AI crawler registry" in `CLAUDE.md`). `RobotsFilter::AI_BOTS` + `FALLBACK_GROUPS` are the offline floor, frozen at 26 names and used only when no fresh, valid list is stored. `tests/fixtures/robots-ai-groups.txt` pins the floor byte for byte: `RobotsFilterTest` asserts `RobotsFilter::ai_user_agent_groups( true )` equals it, and that with both opt-ins and nothing to inherit the whole block is marker + fixture + `Sitemap:` — exact equalities, so an extra group or a reordering fails as loudly as a missing name. Never hand-edit the fixture.

One thing the backend emits and this block deliberately does not, and one it emits only conditionally:

- **No `User-agent: *` group.** The backend renders the whole file for the hosts it serves; this plugin *appends* to a robots.txt WordPress already generated, which carries its own wildcard group. A second one would be combined with it and change the site's rules. The `$is_public` guard is the other half: a site asking search engines away is not overridden.
- **`Agentmap:` only while the catalog is cached.** `Agentmap: /.well-known/ai-catalog.json` (just before `Sitemap:`) is root-relative. Since 0.7.0 `ai-catalog.json` is in the served set, but a bundle cached before the upgrade has none until its next refresh, and the line would advertise a 404 meanwhile — so `RobotsFilter` emits it only while `ArtefactCache` holds the catalog.

## Install profiles

`gtaip_settings['profile']` mirrors the backend's bundle profiles. Two of its three values are offered:

| Profile | What the plugin does |
|---|---|
| `full_apex` (default) | Serve the artefact set at the apex, and nothing else. |
| `subdomain_breadcrumb` | The same, plus `Serving\Breadcrumbs`: a `<link>` discovery block on `wp_head` pointing at the Globetrotters-hosted subdomain. |

`cdn_only` is deliberately absent — it describes the customer's CDN proxying the Globetrotters origin, which happens above WordPress.

The breadcrumb exists because the Globetrotters-hosted host has no inbound link: to a crawler it is a separate site inheriting none of the apex's index membership or authority. The head block mirrors the inbound-link snippet Studio offers to paste by hand.

**Canonical is per-resource, not per-host.** The apex is canonical for every file it serves, so those relations are root-relative and resolve to the customer's own domain — sending an agent to the subdomain's copy of a file this host answers at 200 would promote the mirror over the original. Since 0.7.0 that is all four relations, `.well-known/ai-catalog.json` included. The test is `ContentTypes::has()` **and** the file being cached right now (`Router` answers only what the cache holds), never a hardcoded split. The `rel="alternate"` JSON-LD link is additionally gated to `is_front_page()`, matching `HeadInjector`: it describes the destination, not the interior page it would otherwise appear on.

**`rel="related"` closes the block and names the presence host itself** — `esc_url( $origin . '/' )`, on every page, whatever the cache holds (0.8.1, mirroring the Symfony bundle's `Breadcrumb::headBlock()`). Once every discovery file is cached here, no other relation names the Globetrotters host. Do not gate it on the cache or the front page.

**A visible footer anchor is deliberately not emitted.** `wp_footer` fires after all theme markup, just before `</body>`, so an injected anchor lands outside the layout on block and classic themes alike (verified against Twenty Twenty-Five and Twenty Twenty-One), and no styling makes that belong on a design we do not own. The cost is real: a `<link>` is a discovery pointer, not a followed link, so the subdomain still inherits no crawl authority from the apex. That anchor stays a copy-paste the customer places themselves. Do not re-add it without a placement mechanism the theme controls.

`Sync\PresenceOrigin` derives the host to point at from `ai.json`'s `metadata.relatedFiles`, which the backend renders against the bundle's canonical origin. It cannot be a settings field: the canonical host flips between `<slug>.globetrotters.ai` and `ai.<their-domain>` on managed-hostname activation and detach, and a pasted value would rot silently. `ArtefactSync::run()` re-derives it on every successful pull. The class docblock records why the alternatives were rejected.

## The served set

| Path | Content-Type |
|---|---|
| `llms.txt` | `text/plain` |
| `ai.json` | `application/json` |
| `schema.json` | `application/ld+json` |
| `.well-known/mcp.json` | `application/json` |
| `.well-known/agent-card.json` | `application/json` |
| `.well-known/ai-catalog.json` | `application/json` (since 0.7.0) |
| `.well-known/agent-skills/index.json` | `application/json` (since 0.7.0) |
| `agent.json` | `application/json` (since 0.7.0) |
| `AGENTS.md` | `text/markdown` (since 0.7.0) |
| `.well-known/globetrotters-apex-version.json` | `application/json` |

`ContentTypes` mirrors the backend's apex discovery set plus the version marker, with the backend's content types. Every entry except the version marker is a required artefact of a refresh (the marker is fetched on its own and synthesized when missing, so a keyless or marker-less environment never fails a sync), and the server-log ingest contract must map every served path to an asset format or its captured hits are dropped. Changing the set here alone produces a silent apex-vs-subdomain divergence; it changes with the backend and the Symfony bundle.

Three aliases answer with a canonical file in place (since 0.9.1): `agents.md` → `AGENTS.md`, `.well-known/agent-skills` and `.well-known/agent-skills/` → the skills index. They live in `ContentTypes::ALIASES`, outside `MAP`, so no sync fetches them and the sitemap, the API catalog and the `Link` header keep listing canonical paths only. `ContentTypes::resolve()` maps a request path to the file it answers with; `Router` records the hit under the path requested, which the ingest contract maps to the canonical file's asset format.

Three further paths are served, and all are deliberately **not** in that map. The IndexNow key file at `/<key>.txt` (`text/plain`): its name is only known at runtime, so `Router` matches it dynamically. The generated sitemap at `/ai-sitemap.xml` (`application/xml`), which is rendered rather than synced — putting it in `MAP` would make `ArtefactSync::required_paths()` try to fetch it. And the generated RFC 9727 API catalog at `/.well-known/api-catalog` (`application/linkset+json`, no charset), for the same reason.

## The IndexNow key

IndexNow verifies control of a host by reading `https://{host}/{key}.txt` and comparing the body to the key. The apex is served by the customer's own stack, so nothing Globetrotters hosts can supply it there — without this route an apex served by this plugin cannot be announced at all.

The key rides on the version marker (`Sync\ArtefactSync::resolve_version_marker` → `gtaip_state['indexnow_key']`). It is not a required fetched path: that would 404 in every keyless environment and abort every sync there. It is not a bundle file either: the marker is injected after hashing, so the key cannot perturb `content_hash`. `IndexNowKey` applies the backend's key grammar (`[A-Za-z0-9-]{8,128}`) on the way in and on the way out — the marker arrives from `website_url`, which is untrusted input, and the value decides both which path is answered and what its body is.

- **Served only when one is stored, and only at its own URL.** No stored key means the path falls through to WordPress's normal 404 — never a 200 with an empty body. `ArtefactSync` writes `indexnow_key` on every *successful* pull including a keyless one, so a key rotated away upstream stops being served; a failed pull writes nothing, so the last known key keeps serving alongside the last known good bundle.
- **Matched after the artefact set**, so no key can ever shadow a served file. `tests/unit/Serving/RouterTest` pins it.
- **Not CORS-readable.** The key file is fetched server-side by a search engine. It carries `no-store` on both cache headers: a cached copy of a rotated-away key fails verification for as long as it lives.
- **Not agent traffic.** A search engine reading the key to verify host control is not demand for the presence, so it is not recorded.

Rotation carries a lag: a rotated key reaches an install only on its next sync (`refresh_interval`, daily by default), and submissions for that host fail during the window.

## The sitemap

`Serving\Sitemap` renders a sitemaps.org 0.9 `<urlset>` over the site root plus every artefact currently in the cache, and `Router` answers it at `/ai-sitemap.xml`. `RobotsFilter`'s `Sitemap:` line names it on this site's own origin — never `Options::base_url()`, the Globetrotters origin: a cross-host `Sitemap:` directive is ignored by Google and Bing without cross-domain verification.

**Generated, not fetched.** The backend's per-host sitemap lists URLs on the Globetrotters host, and this plugin serves synced artefacts verbatim, with no origin rewriting; a sitemap may only list same-host URLs. Generating locally needs no fetch and no rewrite, and it is self-consistent by construction: the paths come from `ArtefactCache::files()`, so the file can never advertise a URL this install answers with a 404.

**`/ai-sitemap.xml`, deliberately not `/sitemap.xml`.** A customer's apex is not ours: core publishes `/wp-sitemap.xml` (and already advertises it in robots.txt), Yoast and Rank Math claim `/sitemap_index.xml` and redirect `/sitemap.xml` onto it, and plenty of sites have a physical one. Answering that path from `parse_request` at priority 0 would shadow the site's real sitemap. A distinct name collides with nothing: robots.txt takes a *list* of `Sitemap:` directives, so ours is additive to core's.

`<lastmod>` comes from `gtaip_state['content_changed_at']`, which `ArtefactSync` stamps only when `content_hash` actually moves. A daily refresh finding identical bytes is not a modification. `0` (an install that has not seen a change since the field existed) omits the element, which is valid. The stamp is on the artefacts only: `/` is the customer's homepage, moving independently of the bundle, so stamping it would claim a freshness this plugin cannot vouch for.
