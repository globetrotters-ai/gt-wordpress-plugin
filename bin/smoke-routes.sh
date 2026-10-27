#!/usr/bin/env bash
#
# Route + antagonist HTTP smoke against the running wp-env tests site (port 8889),
# where the plugin is active alongside the field antagonists (Wordfence + a page
# cache). This is the end-to-end check the in-process PHPUnit suite can't make:
# Router::emit() ends with exit(), so a real HTTP request is the only way to
# assert the full 200 + Content-Type response — and that Wordfence doesn't block
# /.well-known/* and a page cache doesn't pin a stale copy past the refresh window.
#
# Usage: bash bin/smoke-routes.sh
set -euo pipefail

SITE="${SMOKE_SITE_URL:-http://localhost:8889}"

# Run WP-CLI inside the tests container, from the plugin mount.
wp_tests() {
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/globetrotters-ai-presence "$@"
}

# Purge whichever page cache the antagonist enabled. The apex routes are immune
# (the plugin sets DONOTCACHEPAGE in Router::emit), but the front page is a normal
# cacheable page — and the antagonists can cache it *before* the bundle is seeded
# (during the PHPUnit run or provisioning), pinning a copy with no JSON-LD. Purge
# so the next front-page request renders fresh against the seeded bundle.
purge_page_cache() {
	wp_tests wp w3-total-cache flush all >/dev/null 2>&1 || true
	wp_tests wp super-cache flush >/dev/null 2>&1 || true
	# Both WP Super Cache and W3 Total Cache store page cache under wp-content/cache/.
	npx wp-env run tests-cli sh -c 'rm -rf /var/www/html/wp-content/cache/* 2>/dev/null; true' >/dev/null 2>&1 || true
}

# True while WordPress is serving its own maintenance page. Core, plugin and
# theme updates all drop a `.maintenance` flag in the docroot and every request
# — artefact routes included — answers 503 until the updater removes it. It is
# the one failure here that has nothing to do with the plugin, so name it.
in_maintenance() {
	npx wp-env run tests-cli sh -c 'test -f /var/www/html/.maintenance' >/dev/null 2>&1
}

# path -> expected Content-Type substring
PATHS=(
	"llms.txt|text/plain"
	"ai.json|application/json"
	"schema.json|application/ld+json"
	".well-known/mcp.json|application/json"
	".well-known/agent-card.json|application/json"
	".well-known/ai-catalog.json|application/json"
	".well-known/agent-skills/index.json|application/json"
	"agent.json|application/json"
	"AGENTS.md|text/markdown"
	".well-known/globetrotters-apex-version.json|application/json"
	# Aliases readiness scanners probe, answered with the canonical file's
	# bytes and headers. curl does not follow redirects here, so a 301 from
	# WordPress's canonical redirect fails the check.
	"agents.md|text/markdown"
	".well-known/agent-skills|application/json"
	".well-known/agent-skills/|application/json"
	# Generated from the cache (RFC 9727), not synced, but served with the
	# artefacts' headers, CORS grant included — so it rides the same loops.
	".well-known/api-catalog|application/linkset+json"
)

fail=0

# The WP PHPUnit harness reinstalls the shared tests database, which deactivates
# every plugin on this site — so (re)activate our plugin alongside whichever
# antagonists the environment installed before exercising real HTTP requests.
echo "== Provisioning the tests site (plugin + antagonists active) =="
# The matrix pins a WordPress version through WP_ENV_CORE, but a fresh install
# has major-version auto-updates on and WP-Cron will happily rewrite the whole
# docroot mid-run — which both invalidates the pin and makes every request 503
# while the updater holds `.maintenance`. `.wp-env.json` disables the updater
# (AUTOMATIC_UPDATER_DISABLED); this asserts it actually stuck.
core_version="$(npx wp-env run tests-cli wp core version 2>/dev/null | tr -d '\r' | tail -1)"
echo "WordPress ${core_version}"
case "${WP_ENV_CORE:-}" in
	WordPress/WordPress#*)
		pinned="${WP_ENV_CORE##*#}"
		if [ "$core_version" != "$pinned" ]; then
			echo "  ✗ core is ${core_version}, but WP_ENV_CORE pins ${pinned} — the install updated itself"
			fail=1
		fi
		;;
esac
wp_tests wp plugin activate --all
# The PHPUnit harness reinstalls this shared DB, which can leave the site without
# an active theme; activate one so the front page renders wp_head (where the
# JSON-LD is injected).
# `awk 'NR==1'` (not `head -1`) reads the whole stream, so the WP-CLI producer
# never gets SIGPIPE — which under `set -euo pipefail` would abort the script.
theme="$(npx wp-env run tests-cli wp theme list --field=name 2>/dev/null | awk 'NR==1' | tr -d '\r\n ')"
if [ -n "$theme" ]; then
	wp_tests wp theme activate "$theme"
fi
# Pretty permalinks — the near-universal real-world config, and what WordPress
# needs to route its virtual robots.txt and render the front page normally.
# (Apex file serving itself works regardless; it intercepts at parse_request.)
wp_tests wp rewrite structure '/%postname%/'
wp_tests wp rewrite flush

echo "== Seeding v1 bundle on the tests site =="
wp_tests wp eval-file bin/seed-artefacts.php v1

echo "== Route serving + Content-Type (antagonists active) =="
for entry in "${PATHS[@]}"; do
	path="${entry%%|*}"
	want_ct="${entry##*|}"
	resp="$(curl -sS -o /dev/null -w '%{http_code} %{content_type}' "$SITE/$path" || echo '000 -')"
	read -r code ctype <<< "$resp"
	printf 'GET /%s -> %s %s\n' "$path" "$code" "$ctype"
	if [ "$code" != "200" ]; then
		echo "  ✗ expected 200 (Wordfence blocking, or route not served?)"
		fail=1
	fi
	case "$ctype" in
		*"$want_ct"*) ;;
		*) echo "  ✗ expected Content-Type containing '$want_ct'"; fail=1 ;;
	esac
done
# A 503 here is almost never Wordfence: a background WordPress update holds
# `.maintenance` and every request answers 503 until it clears, so say so rather
# than leaving the next reader to blame the security plugin.
if [ "$fail" -ne 0 ] && in_maintenance; then
	echo "  ⚠ WordPress is in maintenance mode — a background update is rewriting the install"
fi

echo "== Uncacheable serve headers (precondition for server-log capture) =="
# A framework no-cache flag (DONOTCACHEPAGE) stops WP Super Cache and W3TC but
# means nothing to Cloudflare, Varnish, Fastly or a managed-host edge. Behind a
# shared TTL, PHP never executes and the reported hit count is silently low by an
# amount that varies per customer and per POP — so both real headers are a
# precondition for measurement, not an optimisation. Assert them on every path.
for entry in "${PATHS[@]}"; do
	path="${entry%%|*}"
	headers="$(curl -sSI "$SITE/$path" || true)"
	for want in "cache-control: *no-store" "surrogate-control: *no-store" "x-content-type-options: *nosniff" "access-control-allow-origin: \*"; do
		if printf '%s' "$headers" | tr 'A-Z' 'a-z' | grep -Eq "$want"; then
			printf '  ✓ /%s %s\n' "$path" "${want%%:*}"
		else
			printf '  ✗ /%s missing %s\n' "$path" "${want%%:*}"
			fail=1
		fi
	done
done

echo "== IndexNow key file (dynamic route, /<key>.txt) =="
# The one served path whose name is not known at build time: it is learned from
# the synced version marker, so it cannot be a ContentTypes entry and is matched
# dynamically. IndexNow verifies control of a host by reading this file and
# comparing its body to the key, so the byte count matters as much as the status.
INDEXNOW_KEY='smoke0000indexnow0000key0000abcd'
key_body="$(mktemp)"
# Content-Type last: it contains a space ("text/plain; charset=utf-8"), and
# `read` gives every field after the first two to the final variable — so a
# size read *after* it would swallow the charset instead of the byte count.
key_meta="$(curl -sS -o "$key_body" -w '%{http_code} %{size_download} %{content_type}' "$SITE/${INDEXNOW_KEY}.txt" || echo '000 0 -')"
read -r key_code key_size key_ctype <<< "$key_meta"
printf 'GET /%s.txt -> %s %s %s bytes\n' "$INDEXNOW_KEY" "$key_code" "$key_ctype" "$key_size"
if [ "$key_code" != "200" ]; then
	echo "  ✗ expected 200"
	fail=1
fi
case "$key_ctype" in
	*text/plain*) ;;
	*) echo "  ✗ expected Content-Type containing 'text/plain'"; fail=1 ;;
esac
# Byte count as well as content: a trailing newline would make the file fail
# verification, and $(...) strips one, so the size is the assertion that catches it.
if [ "$key_size" = "${#INDEXNOW_KEY}" ] && [ "$(cat "$key_body")" = "$INDEXNOW_KEY" ]; then
	echo "  ✓ body is the key verbatim, no trailing newline"
else
	echo "  ✗ body is not the key verbatim (${key_size} bytes): $(head -c 80 "$key_body")"
	fail=1
fi
key_headers="$(curl -sSI "$SITE/${INDEXNOW_KEY}.txt" || true)"
for want in "cache-control: *no-store" "surrogate-control: *no-store" "x-content-type-options: *nosniff"; do
	if printf '%s' "$key_headers" | tr 'A-Z' 'a-z' | grep -Eq "$want"; then
		printf '  ✓ %s\n' "${want%%:*}"
	else
		printf '  ✗ missing %s\n' "${want%%:*}"
		fail=1
	fi
done
# CORS is granted to the artefacts so a browser-context agent client can read
# them; the key is fetched server-side by a search engine and this is the apex of
# a site we do not own, so the grant stays scoped to the paths that need it.
if printf '%s' "$key_headers" | tr 'A-Z' 'a-z' | grep -Eq 'access-control-allow-origin'; then
	echo "  ✗ key file must not carry Access-Control-Allow-Origin"
	fail=1
else
	echo "  ✓ no Access-Control-Allow-Origin"
fi
# Some other host's key must not be answered: a 200 here would hand anyone a
# file that verifies nothing, and the site's own 404 is the truthful answer.
other_code="$(curl -sS -o /dev/null -w '%{http_code}' "$SITE/4498b441c07d4e2fa9b31c8e6d02f5a7.txt" || echo '000')"
printf 'GET /4498b441c07d4e2fa9b31c8e6d02f5a7.txt -> %s\n' "$other_code"
if [ "$other_code" = "200" ]; then
	echo "  ✗ a key file this site does not serve must 404"
	fail=1
fi
rm -f "$key_body"

echo "== Generated sitemap (dynamic route, /ai-sitemap.xml) =="
# Generated from the artefact cache, not synced: the published GT-host sitemap
# lists URLs in the GT URL space and this plugin serves artefacts verbatim, and a
# sitemap may only list same-host URLs. Deliberately not at /sitemap.xml, which
# on a customer apex belongs to WordPress core, Yoast or Rank Math.
sitemap_body="$(mktemp)"
sitemap_meta="$(curl -sS -o "$sitemap_body" -w '%{http_code} %{content_type}' "$SITE/ai-sitemap.xml" || echo '000 -')"
read -r sm_code sm_ctype <<< "$sitemap_meta"
printf 'GET /ai-sitemap.xml -> %s %s\n' "$sm_code" "$sm_ctype"
if [ "$sm_code" != "200" ]; then
	echo "  ✗ expected 200"
	fail=1
fi
case "$sm_ctype" in
	*xml*) ;;
	*) echo "  ✗ expected an XML Content-Type"; fail=1 ;;
esac
# Every <loc> must name this site. A cross-host entry is invalid in a sitemap and
# would be the exact defect generating-instead-of-fetching exists to prevent.
foreign="$(grep -oE '<loc>[^<]+</loc>' "$sitemap_body" | sed -E 's#</?loc>##g' | grep -v "^$SITE/" || true)"
if [ -n "$foreign" ]; then
	echo "  ✗ sitemap lists URLs on another host:"
	printf '%s\n' "$foreign" | sed 's/^/      /'
	fail=1
else
	echo "  ✓ every <loc> is on $SITE"
fi
# And every listed artefact must actually be served here. The whole point of
# generating the listing from the cache is that this can never drift.
locs_ok=1
while read -r loc; do
	[ -n "$loc" ] || continue
	loc_code="$(curl -sS -o /dev/null -w '%{http_code}' "$loc" || echo '000')"
	if [ "$loc_code" != "200" ]; then
		echo "  ✗ listed but not served: $loc -> $loc_code"
		locs_ok=0
		fail=1
	fi
done <<< "$(grep -oE '<loc>[^<]+</loc>' "$sitemap_body" | sed -E 's#</?loc>##g')"
if [ "$locs_ok" -eq 1 ]; then
	echo "  ✓ every listed URL answers 200"
fi
# /sitemap.xml stays the site's own: on a customer apex that path belongs to
# WordPress core, Yoast or Rank Math, and shadowing it would replace a real
# sitemap with an artefact listing.
own_sitemap="$(mktemp)"
own_code="$(curl -sS -o "$own_sitemap" -w '%{http_code}' "$SITE/sitemap.xml" || echo '000')"
printf 'GET /sitemap.xml -> %s (must not be ours)\n' "$own_code"
if grep -q 'llms.txt' "$own_sitemap"; then
	echo "  ✗ the plugin answered /sitemap.xml — it must leave that path to the site"
	fail=1
else
	echo "  ✓ /sitemap.xml is not the plugin's"
fi
rm -f "$sitemap_body" "$own_sitemap"

echo "== robots.txt AI block (coexistence) =="
robots="$(curl -sS "$SITE/robots.txt" || true)"
if printf '%s' "$robots" | grep -q 'GPTBot'; then
	echo "  ✓ AI-bot block present"
else
	echo "  ✗ robots.txt missing the AI-bot block"
	fail=1
fi
# Search and inference only: ai-train is the site owner's opt-in, never a default.
if printf '%s' "$robots" | grep -q '^Content-Signal: search=yes, ai-input=yes$'; then
	echo "  ✓ Content-Signal present"
else
	echo "  ✗ robots.txt missing the Content-Signal directive (or signalling training by default)"
	fail=1
fi
# WordPress's own wildcard group closes /wp-admin/. The named crawlers inherit
# that; an "Allow: /" would release them from it (RFC 9309: a crawler obeys only
# the group naming it).
if printf '%s' "$robots" | grep -q '^Allow: /$'; then
	echo "  ✗ robots.txt hands a named crawler Allow: /, overriding the site's own rules"
	fail=1
else
	echo "  ✓ named crawlers inherit the site's wildcard rules"
fi
# One wildcard group, WordPress's own: a duplicate can override the site's rules.
wildcards="$(printf '%s' "$robots" | grep -c '^User-agent: \*' || true)"
if [ "$wildcards" = "1" ]; then
	echo "  ✓ exactly one User-agent: * group"
else
	echo "  ✗ expected exactly one User-agent: * group, found $wildcards"
	fail=1
fi
# The Sitemap line names this host, and the URL it names answers.
if printf '%s' "$robots" | grep -q "^Sitemap: $SITE/ai-sitemap.xml$"; then
	echo "  ✓ Sitemap: names this host's own sitemap"
else
	echo "  ✗ robots.txt Sitemap: does not name $SITE/ai-sitemap.xml"
	printf '%s\n' "$robots" | grep -i '^sitemap:' | sed 's/^/      /' || true
	fail=1
fi

# The ARD catalog is in the seeded bundle, so its root-relative pointer is
# advertised — and answers, which the route loop above already proved.
if printf '%s' "$robots" | grep -q '^Agentmap: /.well-known/ai-catalog.json$'; then
	echo "  ✓ Agentmap: names the catalog this host serves"
else
	echo "  ✗ robots.txt missing Agentmap: /.well-known/ai-catalog.json"
	fail=1
fi

echo "== Front-page JSON-LD in raw HTML =="
# This asserts server-side *rendering* — that the plugin injects JSON-LD into the
# raw <head> — which is orthogonal to antagonist coexistence (route serving proves
# Wordfence doesn't block us; freshness proves the page cache doesn't pin apex
# routes). The antagonists fight us *here* for reasons that aren't plugin defects:
# the page cache can replay a copy of `/` captured before the bundle was seeded,
# and a persistent object cache can hand the front-end a stale show_on_front.
# Purging is unreliable across containers (the cache dir is owned by the web user,
# not the CLI user). So take the antagonists out of the loop for just this render:
# flush the object cache and deactivate them, then reactivate for the freshness
# check below.
wp_tests wp cache flush >/dev/null 2>&1 || true
antagonists="$(wp_tests wp plugin list --field=name --status=active 2>/dev/null | tr -d '\r' | grep -Ex 'wordfence|wp-super-cache|w3-total-cache' || true)"
if [ -n "$antagonists" ]; then
	# shellcheck disable=SC2086
	wp_tests wp plugin deactivate $antagonists >/dev/null 2>&1 || true
fi
purge_page_cache

front_body="$(mktemp)"
front_headers="$(mktemp)"
front_status='000'
front_bytes=0
trap 'rm -f "$front_body" "$front_headers"' EXIT

# Deliberately *not* `curl … | grep -q`: piping throws away the bytes, so a
# failure can only ever be reported as "missing" with nothing to show for it —
# which is exactly how a 45-second run of 503 maintenance pages once read as a
# plugin defect. Fetch to a file, then assert on the file we can also print.
fetch_front() {
	front_status='000'
	front_bytes=0
	meta="$(curl -sS --max-time 20 -D "$front_headers" -o "$front_body" \
		-w '%{http_code} %{size_download}' "$SITE/" 2>/dev/null || true)"
	if [ -n "$meta" ]; then
		read -r front_status front_bytes <<< "$meta"
	fi
}

# A short poll still absorbs a genuinely cold first render.
ld_found=0
warmup_budget="${SMOKE_WARMUP_BUDGET:-45}"
attempt=0
deadline=$(( SECONDS + warmup_budget ))
while [ "$SECONDS" -lt "$deadline" ]; do
	attempt=$(( attempt + 1 ))
	fetch_front
	if grep -q 'application/ld+json' "$front_body"; then
		ld_found=1
		break
	fi
	sleep 4
done
if [ "$ld_found" -eq 1 ]; then
	# Print the shape of the *passing* render too: without a baseline in the log,
	# a failing render's byte count says nothing.
	printf '  ✓ JSON-LD injected (HTTP %s, %s bytes, attempt %s)\n' "$front_status" "$front_bytes" "$attempt"
else
	echo "  ✗ front page missing server-rendered JSON-LD"
	echo "    -- front-page diagnostics --"
	echo "    last response: HTTP ${front_status}, ${front_bytes} bytes, after ${attempt} request(s) over ${warmup_budget}s"
	echo "    deactivated antagonists: $(printf '%s' "${antagonists:-none}" | tr '\n' ' ')"
	# The one cause that is not a plugin defect: core/plugin/theme updates drop a
	# `.maintenance` flag and every request answers 503 until it clears, then the
	# site heals itself — so an after-the-fact probe shows nothing wrong.
	if [ "$front_status" = '503' ] || grep -q 'Briefly unavailable for scheduled maintenance' "$front_body"; then
		echo "    ⚠ WordPress served its maintenance page: a background update was rewriting the install"
	fi
	if in_maintenance; then
		echo "    ⚠ /var/www/html/.maintenance is still present"
	fi
	echo "    response headers:"
	sed 's/^/      /' "$front_headers" | head -20 || true
	# The raw <head> is the ground truth for what the render actually contained:
	# whether wp_head ran at all, and what sits either side of where the JSON-LD
	# should be. Truncated so a full page can't drown the log.
	echo "    <head> of the last render:"
	sed -n '1,/<\/head>/p' "$front_body" | head -60 | cut -c1-200 | sed 's/^/      /' || true
	echo "    script tags seen: $(grep -oE '<script[^>]*type="[^"]*"' "$front_body" | sort -u | tr '\n' ' ' || true)"
	# The <body> class on the *live* request is the ground truth for is_front_page:
	# a "home" class means the front-page guard passed and the miss is elsewhere.
	echo "    body class: $(grep -oE '<body class="[^"]*"' "$front_body" | awk 'NR==1' || true)"
	# What the plugin had to work with: an isset() check can't tell an intact
	# bundle from one a failed refresh replaced with empty or non-JSON bodies.
	wp_tests wp eval 'echo "show_on_front=".get_option("show_on_front").", page_on_front=".get_option("page_on_front").", blog_public=".get_option("blog_public")."\n"; $c=get_option("gtaip_artefacts"); $s=$c["files"]["schema.json"] ?? null; echo "schema.json=".(null===$s?"absent":strlen($s)." bytes: ".substr($s,0,80))."\n"; $st=get_option("gtaip_state"); echo "last_refresh=".($st["last_refresh"]??"-").", last_error=".substr((string)($st["last_error"]??""),0,160)."\n";' 2>&1 | grep -vE '^(ℹ Starting|✔ Ran)' | sed 's/^/    /' | tail -3 || true
	echo "    wp core: ${core_version}, active theme: $(wp_tests wp theme list --status=active --field=name 2>/dev/null | tr -d '\r' | tail -1 || true)"
	fail=1
fi
echo "== Homepage Link header =="
# The HTTP twin of the head <link> tags, on the homepage only, added beside the
# Link header core already sends (rest_output_link_header) rather than replacing
# it. Read off the same render as the JSON-LD check above.
WANT_LINK='Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"'
if tr -d '\r' < "$front_headers" | grep -qiF "$WANT_LINK"; then
	echo "  ✓ homepage carries the agent discovery Link header"
else
	echo "  ✗ homepage missing: $WANT_LINK"
	tr -d '\r' < "$front_headers" | grep -i '^link:' | sed 's/^/      /' || true
	fail=1
fi
if tr -d '\r' < "$front_headers" | grep -qi '^link:.*rel="https://api.w.org/"'; then
	echo "  ✓ core's own Link header survives beside it"
else
	echo "  ✗ core's api.w.org Link header was replaced"
	fail=1
fi
inner_headers="$(curl -sS -o /dev/null -D - "$SITE/gtaip-smoke-inner-page/" || true)"
if printf '%s' "$inner_headers" | tr -d '\r' | grep -qi '^link:.*rel="ai-catalog"'; then
	echo "  ✗ a non-homepage response carries the agent discovery Link header"
	fail=1
else
	echo "  ✓ no agent discovery Link header off the homepage"
fi

# Restore the antagonists for the freshness check below (which needs the page
# cache live to prove apex routes aren't pinned past the refresh window).
if [ -n "$antagonists" ]; then
	# shellcheck disable=SC2086
	wp_tests wp plugin activate $antagonists >/dev/null 2>&1 || true
fi

echo "== Freshness under page cache (publish v2, must not serve stale v1) =="
wp_tests wp eval-file bin/seed-artefacts.php v2
served="$(curl -sS "$SITE/llms.txt")"
# Truncated: when this fails the body is usually a whole HTML page, and printing
# it in full buries the rest of the run. Sliced in the shell rather than piped
# through `head -c`, which would SIGPIPE its producer under `set -o pipefail`.
printf '/llms.txt after update -> %s\n' "${served:0:200}"
case "$served" in
	*v2*) echo "  ✓ fresh content served" ;;
	*) echo "  ✗ served stale content — page cache pinned a route past refresh"; fail=1 ;;
esac

echo "== Page-view beacon (POST /.well-known/globetrotters/pv, antagonists active) =="
# The one write the plugin answers, and the one route a security plugin is most
# likely to swallow: a POST to /.well-known/* from a browser. Counting is off by
# default, so first prove the endpoint still answers the cheap 204 but counts
# nothing — cached pages keep beaconing after counting is switched off, and a
# fall-through would cost a 404 render each — then switch it on (with reporting
# credentials, which it needs) and prove a same-origin beacon from a browser UA
# is counted while a cross-origin one and a bot are not.
PV_PATH='/.well-known/globetrotters/pv'
BROWSER_UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'
pv_views() { # [stored bucket] -> views of /smoke-page (every bucket when omitted)
	wp_tests wp eval 'global $wpdb; $t=\Globetrotters\AiPresence\Analytics\Schema::page_views_table(); $b="'"${1:-}"'"; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(views),0) FROM `$t` WHERE path=%s AND (%s = \"\" OR bucket = %s)", "/smoke-page", $b, $b));' 2>/dev/null | tr -d '\r' | tail -1
}
pv_post() { # origin ua [body] -> "code headers-file"
	local hdrs; hdrs="$(mktemp)"
	local body='{"p":"/smoke-page/"}'
	[ -n "${3:-}" ] && body="$3"
	local code
	code="$(curl -sS -o /dev/null -D "$hdrs" -w '%{http_code}' -X POST \
		-H "Origin: $1" -H "User-Agent: $2" -H 'Content-Type: text/plain' \
		--data "$body" "$SITE$PV_PATH" || echo '000')"
	echo "$code $hdrs"
}
set_counting() { # 0|1
	wp_tests wp eval "\$s=get_option('gtaip_settings',array()); \$s=is_array(\$s)?\$s:array(); \$s['ingest_endpoint']='https://ingest.invalid/presence/analytics/server-log'; \$s['ingest_token']='tok_smoke'; \$s['count_page_views']=(bool) $1; update_option('gtaip_settings',\$s);" >/dev/null 2>&1
}

set_counting 0
off_before="$(pv_views)"
read -r off_code off_hdrs <<< "$(pv_post "$SITE" "$BROWSER_UA")"
off_after="$(pv_views)"
printf 'POST %s (counting off) -> %s, views %s -> %s\n' "$PV_PATH" "$off_code" "${off_before:-?}" "${off_after:-?}"
if [ "$off_code" = "204" ]; then
	echo "  ✓ answered with the cheap 204, not a WordPress 404"
else
	echo "  ✗ expected 204 while counting is off (a fall-through costs a 404 render)"
	fail=1
fi
if [ -n "$off_before" ] && [ "$off_before" = "$off_after" ]; then
	echo "  ✓ nothing counted while counting is off"
else
	echo "  ✗ a beacon was counted while counting is off"
	fail=1
fi
rm -f "$off_hdrs"

set_counting 1
purge_page_cache
before="$(pv_views)"
read -r on_code on_hdrs <<< "$(pv_post "$SITE" "$BROWSER_UA")"
after="$(pv_views)"
printf 'POST %s (same-origin browser) -> %s, views %s -> %s\n' "$PV_PATH" "$on_code" "${before:-?}" "${after:-?}"
if [ "$on_code" != "204" ]; then
	echo "  ✗ expected 204 (Wordfence blocking the beacon, or route not answered?)"
	fail=1
fi
if [ -n "$before" ] && [ -n "$after" ] && [ "$after" -eq $(( before + 1 )) ]; then
	echo "  ✓ counted once, under the folded path"
else
	echo "  ✗ the counter did not move by exactly one"
	fail=1
fi
for want in "cache-control: *no-store" "surrogate-control: *no-store"; do
	if tr 'A-Z' 'a-z' < "$on_hdrs" | grep -Eq "$want"; then
		printf '  ✓ %s\n' "${want%%:*}"
	else
		printf '  ✗ missing %s\n' "${want%%:*}"
		fail=1
	fi
done
if tr 'A-Z' 'a-z' < "$on_hdrs" | grep -q '^set-cookie:'; then
	echo "  ✗ the beacon response set a cookie"
	fail=1
else
	echo "  ✓ no Set-Cookie"
fi
rm -f "$on_hdrs"

read -r x_code x_hdrs <<< "$(pv_post 'https://evil.example' "$BROWSER_UA")"
read -r b_code b_hdrs <<< "$(pv_post "$SITE" 'curl/8.7.1')"
rm -f "$x_hdrs" "$b_hdrs"
final="$(pv_views)"
printf 'POST cross-origin -> %s, bot UA -> %s, views now %s\n' "$x_code" "$b_code" "${final:-?}"
if [ "$final" = "$after" ]; then
	echo "  ✓ neither was counted"
else
	echo "  ✗ a cross-origin or bot beacon was counted"
	fail=1
fi

# A click on an AI answer: with the synced AI referrer list in place, the
# referrer host the beacon carries is matched and dropped, and the view counts
# under the platform alone.
wp_tests wp eval "update_option('gtaip_ai_referrers', array('version'=>'smoke','fetched_at'=>time(),'hosts'=>array('chatgpt.com'=>'chatgpt'),'utmSources'=>array(),'searchHosts'=>array()), false);" >/dev/null 2>&1
ref_before="$(pv_views 'browser@chatgpt')"
read -r r_code r_hdrs <<< "$(pv_post "$SITE" "$BROWSER_UA" '{"p":"/smoke-page","r":"chatgpt.com"}')"
rm -f "$r_hdrs"
ref_after="$(pv_views 'browser@chatgpt')"
printf 'POST referred by chatgpt.com -> %s, chatgpt views %s -> %s\n' "$r_code" "${ref_before:-?}" "${ref_after:-?}"
if [ "$r_code" = "204" ] && [ -n "$ref_before" ] && [ -n "$ref_after" ] && [ "$ref_after" -eq $(( ref_before + 1 )) ]; then
	echo "  ✓ counted once, under its platform"
else
	echo "  ✗ the referred view was not counted under browser@chatgpt"
	fail=1
fi
wp_tests wp eval "delete_option('gtaip_ai_referrers');" >/dev/null 2>&1

front_pv="$(curl -sS "$SITE/?smoke-pv=$RANDOM" || true)"
if printf '%s' "$front_pv" | grep -q "sendBeacon('$PV_PATH'"; then
	echo "  ✓ the beacon script is on the front page"
else
	echo "  ✗ the beacon script is missing from the front page"
	fail=1
fi
set_counting 0

if [ "$fail" -ne 0 ]; then
	echo "SMOKE FAILED"
	exit 1
fi
echo "SMOKE OK"
