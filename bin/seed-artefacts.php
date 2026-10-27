<?php
/**
 * CI helper: seed connection settings + a full artefact bundle on a running site
 * so the route smoke has something to serve.
 *
 * Runs inside wp-env with WP loaded:
 *   wp eval-file bin/seed-artefacts.php <version>
 *
 * The <version> tag is stamped into every body so the smoke can prove a fresh
 * pull is served (v1 → v2) rather than a page-cache-pinned stale copy.
 *
 * `wp eval-file` runs this through eval(), so it deliberately omits a
 * `declare(strict_types=1)` (which must be a file's first statement).
 *
 * @package Globetrotters\AiPresence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// `wp eval-file` exposes positional CLI arguments in $args.
$version = isset( $args[0] ) ? (string) $args[0] : 'v1';

update_option(
	'gtaip_settings',
	array(
		'website_url'      => 'https://acme.example',
		'refresh_interval' => 'daily',
	)
);

$files = array(
	'llms.txt'                                    => "# llms {$version}",
	'ai.json'                                     => '{"version":"' . $version . '"}',
	'schema.json'                                 => '{"@context":"https://schema.org","@type":"Organization","name":"Acme ' . $version . '"}',
	'.well-known/mcp.json'                        => '{"mcp":"' . $version . '"}',
	'.well-known/agent-card.json'                 => '{"agent":"' . $version . '"}',
	'.well-known/ai-catalog.json'                 => '{"specVersion":"1.0","catalog":"' . $version . '"}',
	'.well-known/agent-skills/index.json'         => '{"skills":[],"index":"' . $version . '"}',
	'agent.json'                                  => '{"name":"Acme ' . $version . '"}',
	'AGENTS.md'                                   => "# Acme {$version}\n",
	'.well-known/globetrotters-apex-version.json' => '{"version":"' . $version . '"}',
);

update_option(
	'gtaip_artefacts',
	array(
		'files'     => $files,
		'version'   => $version,
		'stored_at' => time(),
	),
	false
);

// The IndexNow key an apex install learns from the synced version marker, so
// the smoke can assert the dynamic /<key>.txt route end to end. Merged into
// whatever state is already there rather than replacing it.
$state = get_option( 'gtaip_state', array() );
update_option(
	'gtaip_state',
	array_merge(
		is_array( $state ) ? $state : array(),
		array( 'indexnow_key' => 'smoke0000indexnow0000key0000abcd' )
	)
);

echo "seeded bundle {$version}\n";
