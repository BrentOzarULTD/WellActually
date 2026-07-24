<?php
/**
 * Uninstall routine: remove all traces of the plugin.
 *
 * Everything here is enumerated explicitly — exact option names, exact meta
 * keys, and transient prefixes owned by this plugin — rather than a broad
 * prefix sweep that could catch another plugin's data.
 *
 * Both the current `wellactually_`/`_wellactually_` names and the legacy
 * `wa_`/`_wa_` ones are removed. A site that never loaded the plugin after
 * the rename (installed, deactivated, then deleted) still has its data under
 * the old names, and the migration in WellActually_Migrate only runs while
 * the plugin is active — so uninstall has to clean up both sets itself.
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
function wellactually_uninstall_site() {
	global $wpdb;

	// Drop the plugin's two tables. Direct queries and a schema change are
	// the entire point of an uninstall routine; %i binds each table name as
	// an identifier.
	$tables = array(
		'wellactually_stats',
		'wellactually_ai_queue',
		// Legacy, pre-1.8.0.
		'wa_stats',
		'wa_ai_queue',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
	}

	// Remove options — every option the plugin writes, by exact name:
	// wellactually_settings (WellActually_Settings::OPTION_NAME),
	// wellactually_db_version (WellActually_Stats::DB_VERSION_OPTION),
	// wellactually_ai_queue_db_version (WellActually_AI_Queue::DB_VERSION_OPTION),
	// wellactually_status_backfilled (WellActually_Meta's one-time backfill
	// marker), wellactually_status_repaired (the
	// WellActually_Meta::maybe_repair_statuses() marker), and
	// wellactually_prefix_migrated (WellActually_Migrate's marker).
	$options = array(
		'wellactually_settings',
		'wellactually_db_version',
		'wellactually_ai_queue_db_version',
		'wellactually_status_backfilled',
		'wellactually_status_repaired',
		'wellactually_prefix_migrated',
		'wellactually_prefix_migrating',
		// Legacy, pre-1.8.0.
		'wa_settings',
		'wa_db_version',
		'wa_ai_queue_db_version',
		'wa_status_backfilled',
		'wa_status_repaired',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove transients. The fixed-name ones first.
	delete_transient( 'wellactually_eligible_total' );
	delete_transient( 'wa_eligible_total' );

	// Two transient families have dynamic suffixes (a provider id; a rate-limit
	// bucket + hashed IP), so they can't be deleted by exact name. Delete them
	// from the options table by their full plugin-owned prefixes — narrow on
	// purpose: only keys this plugin wrote can match. Copies in an external
	// object cache (if the host uses one) can't be enumerated from here; they
	// expire on their own short TTLs (minutes).
	$prefixes = array(
		'_transient_wellactually_rl_',
		'_transient_timeout_wellactually_rl_',
		'_transient_wellactually_ai_provider_configured_',
		'_transient_timeout_wellactually_ai_provider_configured_',
		// Legacy, pre-1.8.0.
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
	$meta_keys = array(
		'statement',
		'verdict',
		'ai_statement',
		'ai_verdict',
		'ai_status',
		'ai_error',
		'ai_error_time',
		'ai_claimed',
		'skip',
		'status',
	);
	foreach ( $meta_keys as $meta_key ) {
		delete_post_meta_by_key( '_wellactually_' . $meta_key );
		// Legacy, pre-1.8.0.
		delete_post_meta_by_key( '_wa_' . $meta_key );
	}
}

if ( is_multisite() ) {
	// 'number' => 0 disables the default 100-site limit, so every site is
	// cleaned, not just the first hundred.
	$wellactually_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wellactually_site_ids as $wellactually_site_id ) {
		switch_to_blog( $wellactually_site_id );
		wellactually_uninstall_site();
		restore_current_blog();
	}
} else {
	wellactually_uninstall_site();
}

// Remove user meta added by the plugin. The usermeta table is shared across
// a network, so this runs once, not per site. delete_metadata() with
// $delete_all clears the key for every user and invalidates caches, which a
// raw DELETE would leave stale.
delete_metadata( 'user', 0, '_wellactually_progress', '', true );
// Legacy, pre-1.8.0.
delete_metadata( 'user', 0, '_wa_progress', '', true );
