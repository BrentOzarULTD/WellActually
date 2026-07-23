<?php
/**
 * Uninstall routine: remove all traces of the plugin.
 *
 * Everything here is enumerated explicitly — exact option names, exact meta
 * keys, and transient prefixes owned by this plugin — rather than a broad
 * "delete anything starting with wa_" sweep that could catch another
 * plugin's data.
 *
 * On multisite, tables/options/post meta/transients are per-site, so the
 * cleanup runs once per site. User meta is stored network-wide in the shared
 * usermeta table, so it's cleaned once at the end.
 *
 * @package WellActually
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this plugin's data from the current site.
 */
function wa_uninstall_site() {
	global $wpdb;

	// Drop the stats table.
	$table_name = $wpdb->prefix . 'wa_stats';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name isn't user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

	// Drop the AI drafting queue table.
	$queue_table = $wpdb->prefix . 'wa_ai_queue';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name isn't user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$queue_table}" );

	// Remove options — every option the plugin writes, by exact name:
	// wa_settings (WA_Settings::OPTION_NAME), wa_db_version
	// (WA_Stats::DB_VERSION_OPTION), wa_ai_queue_db_version
	// (WA_AI_Queue::DB_VERSION_OPTION), wa_status_backfilled (WA_Meta's
	// one-time backfill marker), and wa_status_repaired (the
	// WA_Meta::maybe_repair_statuses() marker).
	delete_option( 'wa_settings' );
	delete_option( 'wa_db_version' );
	delete_option( 'wa_ai_queue_db_version' );
	delete_option( 'wa_status_backfilled' );
	delete_option( 'wa_status_repaired' );

	// Remove transients. The fixed-name one first.
	delete_transient( 'wa_eligible_total' );

	// Two transient families have dynamic suffixes (a provider id; a rate-limit
	// bucket + hashed IP), so they can't be deleted by exact name. Delete them
	// from the options table by their full plugin-owned prefixes — narrow on
	// purpose: '_transient_wa_rl_' and '_transient_wa_ai_provider_configured_'
	// can only match keys this plugin wrote. Copies in an external object
	// cache (if the host uses one) can't be enumerated from here; they expire
	// on their own short TTLs (minutes).
	$prefixes = array(
		'_transient_wa_rl_',
		'_transient_timeout_wa_rl_',
		'_transient_wa_ai_provider_configured_',
		'_transient_timeout_wa_ai_provider_configured_',
	);

	foreach ( $prefixes as $prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}

	// Remove all post meta added by the plugin, by exact key.
	delete_post_meta_by_key( '_wa_statement' );
	delete_post_meta_by_key( '_wa_verdict' );
	delete_post_meta_by_key( '_wa_ai_statement' );
	delete_post_meta_by_key( '_wa_ai_verdict' );
	delete_post_meta_by_key( '_wa_ai_status' );
	delete_post_meta_by_key( '_wa_ai_error' );
	delete_post_meta_by_key( '_wa_ai_error_time' );
	delete_post_meta_by_key( '_wa_ai_claimed' );
	delete_post_meta_by_key( '_wa_skip' );
	delete_post_meta_by_key( '_wa_status' );
}

if ( is_multisite() ) {
	// 'number' => 0 disables the default 100-site limit, so every site is
	// cleaned, not just the first hundred.
	$wa_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wa_site_ids as $wa_site_id ) {
		switch_to_blog( $wa_site_id );
		wa_uninstall_site();
		restore_current_blog();
	}
} else {
	wa_uninstall_site();
}

// Remove user meta added by the plugin. The usermeta table is shared across
// a network, so this runs once, not per site. delete_metadata() with
// $delete_all clears the key for every user and invalidates caches, which a
// raw DELETE would leave stale.
delete_metadata( 'user', 0, '_wa_progress', '', true );
