<?php
/**
 * Uninstall cleanup for Globetrotters AI Presence.
 *
 * Removes the plugin's options and cached artefacts. Runs only when the user
 * deletes the plugin from the WordPress admin.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Keep these in sync with Settings\Options, Cache\ArtefactCache,
// Sync\MysqlRefreshLock and Analytics\Schema / Analytics\Flusher / Analytics\MysqlEventStore /
// Analytics\VendorRanges / Analytics\AiReferrers / Analytics\AiUserAgents /
// Analytics\PageCacheExclusion.
delete_option( 'gtaip_settings' );
delete_option( 'gtaip_state' );
delete_option( 'gtaip_flush_state' );
delete_option( 'gtaip_artefacts' );
delete_option( 'gtaip_artefacts_stamp' );
delete_option( 'gtaip_served' );
delete_option( 'gtaip_refresh_lock' );
delete_option( 'gtaip_db_version' );
delete_option( 'gtaip_flush_lock' );
delete_option( 'gtaip_dropped_pending' );
delete_option( 'gtaip_dropped_total' );
// What the page-cache exclusion added to another plugin's settings comes out
// before its record does. Loaded by hand: there is no autoloader here.
require_once __DIR__ . '/src/Analytics/PageCacheExclusion.php';
\Globetrotters\AiPresence\Analytics\PageCacheExclusion::remove_recorded_on_uninstall();
delete_option( 'gtaip_cache_exclusion' );
delete_option( 'gtaip_cache_exclusion_applied' );
delete_option( 'gtaip_vendor_ranges' );
delete_option( 'gtaip_ai_referrers' );
delete_option( 'gtaip_ai_user_agents' );
delete_option( 'gtaip_cdn_markers' );

// Clear every scheduled event (recurring + any queued single events).
wp_clear_scheduled_hook( 'gtaip_refresh' );
wp_clear_scheduled_hook( 'gtaip_flush' );
wp_clear_scheduled_hook( 'gtaip_flush_now' );

// Drop the server-log buffer table and the page-view counter table. Any events
// or counts still in them were never flushed and have nowhere to go once the
// plugin is gone.
// The names mirror Analytics\Schema::table() and ::page_views_table(), which
// this file cannot call — an
// uninstall runs standalone, with no plugin bootstrap and no autoloader. A table
// identifier cannot go through $wpdb->prepare() (%s quotes it as a string
// literal, and %i needs WP 6.2 while this plugin supports 6.0), so the name is
// constrained the same way: a hard-coded suffix on a prefix reduced to MySQL's
// unquoted-identifier charset. Nothing external feeds it; esc_sql() is a
// no-op on that charset, kept so static analysis can see it.
global $wpdb;
$gtaip_table_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix );
foreach ( array( 'gtaip_events', 'gtaip_page_views' ) as $gtaip_table_suffix ) {
	$gtaip_table = esc_sql( $gtaip_table_prefix . $gtaip_table_suffix );
	$wpdb->query( "DROP TABLE IF EXISTS `{$gtaip_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Remove any lingering plugin transients (best-effort; they also expire on
// their own). Covers the ``gtaip_`` prefix used by the settings layer
// (``gtaip_latest_check``) and any transients left behind by older versions.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_gtaip\_%'
	    OR option_name LIKE '\_transient\_timeout\_gtaip\_%'"
);
