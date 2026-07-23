<?php
/**
 * Uninstall routine: remove all traces of the plugin.
 *
 * @package WellActually
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the stats table.
$table_name = $wpdb->prefix . 'wa_stats';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name isn't user input.
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Remove options.
delete_option( 'wa_settings' );
delete_option( 'wa_db_version' );
delete_option( 'wa_status_backfilled' );

// Remove all post meta added by the plugin.
delete_post_meta_by_key( '_wa_statement' );
delete_post_meta_by_key( '_wa_verdict' );
delete_post_meta_by_key( '_wa_ai_statement' );
delete_post_meta_by_key( '_wa_ai_verdict' );
delete_post_meta_by_key( '_wa_ai_status' );
delete_post_meta_by_key( '_wa_ai_error' );
delete_post_meta_by_key( '_wa_ai_error_time' );
delete_post_meta_by_key( '_wa_skip' );
delete_post_meta_by_key( '_wa_status' );

// Remove user meta added by the plugin.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_wa_progress'" );
