# Releasing and local testing

## Releasing

**Release checklist:** run `make check`. `tests/fixtures/robots-ai-groups.txt` is the backend registry's own rendering of the frozen `RobotsFilter::AI_BOTS` floor (the live registry syncs from the ranges document), so it is not regenerated per release and must never be hand-edited; the fixture test enforces that the plugin matches it.

The version lives in three places that must move together: the `Version:` plugin header, `const VERSION` (both in `globetrotters-ai-presence.php`), and `Stable tag:` in `readme.txt`. The release workflow refuses a tag whose three values disagree, so this is enforced, not just convention. The number is kept in step with the Globetrotters Symfony bundle so that the same behaviour ships under the same version on both; when the two would otherwise diverge, bump this one to match rather than letting the numbers drift.

Distribution is **self-hosted, not WP.org**. The plugin header carries `Update URI: https://globetrotters.ai/wp-plugin`, and `Update\UpdateChecker` answers the `update_plugins_globetrotters.ai` filter against `update.json` (12h transient). Because that filter is shared by every plugin whose Update URI resolves to the host, the checker answers only for its own basename. This is also why CI's Plugin Check excludes `plugin_updater`: the self-hosted channel is the intended design, not a defect. Whether to drop it for WP.org-served updates is an open decision.

Push a `v*` tag: the release workflow validates versions, runs `make check`, builds the versioned zip + `update.json`, and cuts a GitHub Release. Publishing is a second step: `make release RELEASE_DIR=/path/to/website/public/wp-plugin` stages both artefacts into the website's `wp-plugin/` directory (set `RELEASE_DIR` on the command line or in an untracked `Makefile.local`; it has no default), then deploy the website to put them live at https://globetrotters.ai/wp-plugin.

## Local testing against a real WordPress

`.wp-env.json` mounts and activates the plugin on both instances. Dev site is `localhost:8888` (admin/password, ships Plugin Check); the isolated tests instance is `localhost:8889` and preloads the field antagonists. Configure without the UI:

```bash
npx wp-env run cli wp option update gtaip_settings --format=json \
  '{"website_url":"https://your-site.example","refresh_interval":"daily"}'
```

Integration tests stub Globetrotters HTTP via `pre_http_request`, so they never touch the network. `bin/smoke-routes.sh` curls each served path on the tests instance with Wordfence and a page cache active, which is the check that catches an antagonist swallowing a route.

`.wp-env.json` sets `AUTOMATIC_UPDATER_DISABLED` — **the matrix is meaningless without it.** A fresh install has major-version core auto-updates on, and the smoke's own HTTP requests spawn the WP-Cron run that fires them: the tests site pinned to `WordPress/WordPress#7.0` would quietly rewrite itself to the current release mid-run. While the updater holds `.maintenance`, *every* request — apex routes included — answers 503 with WordPress's maintenance page, and the site heals itself seconds later, so an after-the-fact probe shows nothing wrong. That is what made the front-page JSON-LD check fail intermittently on CI while the plugin was working the whole time. The smoke asserts `wp core version` still matches the `WP_ENV_CORE` pin, and its front-page failure path prints the response it actually got.
