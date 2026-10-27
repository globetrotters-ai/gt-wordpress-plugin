# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Email **contact@globetrotters.ai** with "Security" in the subject, and include:

- the plugin version (Settings → AI Presence, or the `Version:` header of `globetrotters-ai-presence.php`) and your WordPress and PHP versions;
- what an attacker can do, and the steps or a proof of concept to reproduce it;
- any configuration it depends on (page cache, proxy or CDN, reporting enabled, page-view counting on).

We will acknowledge your report and keep you informed while we work on a fix. Please give us a reasonable time to ship it before disclosing the issue.

## Supported versions

Security fixes ship in the latest release only, through the plugin's update channel (`Update URI: https://globetrotters.ai/wp-plugin`). Keep automatic updates on, or update from the Plugins screen.

## Scope

This repository covers the WordPress plugin. The Globetrotters service it talks to (the published artefacts, the ingest endpoint, Studio) is in scope too: report issues with it the same way.
