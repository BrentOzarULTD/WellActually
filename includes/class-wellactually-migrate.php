<?php
/**
 * One-time migration from the plugin's original two-character `wa_` prefix
 * to the unique `wellactually_` one.
 *
 * WordPress.org review guidance treats two- and three-letter prefixes as not
 * unique enough, so every option, table, meta key and hook moved in 1.8.0.
 * The code changed in one commit; the data on existing sites has to be moved
 * here, or an upgrade would silently look like a fresh install — settings
 * gone, swipe statements gone, scores gone.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves pre-1.8.0 data onto the current names, once, per site.
 */
class WellActually_Migrate {

	/**
	 * Marker option. Holds the version that did the migration, so this is a
	 * single option read on a migrated site and never a repeat scan.
	 */
	const DONE_OPTION = 'wellactually_prefix_migrated';

	/**
	 * Options, old name => new name.
	 */
	const OPTIONS = array(
		'wa_settings'            => 'wellactually_settings',
		'wa_db_version'          => 'wellactually_db_version',
		'wa_ai_queue_db_version' => 'wellactually_ai_queue_db_version',
		'wa_status_backfilled'   => 'wellactually_status_backfilled',
		'wa_status_repaired'     => 'wellactually_status_repaired',
	);

	/**
	 * Tables, old unprefixed name => new unprefixed name.
	 */
	const TABLES = array(
		'wa_stats'    => 'wellactually_stats',
		'wa_ai_queue' => 'wellactually_ai_queue',
	);

	/**
	 * Post meta, old key => new key.
	 */
	const POST_META = array(
		'_wa_statement'     => '_wellactually_statement',
		'_wa_verdict'       => '_wellactually_verdict',
		'_wa_status'        => '_wellactually_status',
		'_wa_skip'          => '_wellactually_skip',
		'_wa_ai_statement'  => '_wellactually_ai_statement',
		'_wa_ai_verdict'    => '_wellactually_ai_verdict',
		'_wa_ai_status'     => '_wellactually_ai_status',
		'_wa_ai_error'      => '_wellactually_ai_error',
		'_wa_ai_error_time' => '_wellactually_ai_error_time',
		'_wa_ai_claimed'    => '_wellactually_ai_claimed',
	);

	/**
	 * User meta, old key => new key.
	 */
	const USER_META = array(
		'_wa_progress' => '_wellactually_progress',
	);

	/**
	 * Run the migration if this site still has pre-1.8.0 data.
	 *
	 * Called on every request (from wellactually_init()), so the common path
	 * has to be cheap: on a migrated site, and on a fresh install, this is one
	 * autoloaded option read and a return.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		// A fresh install has no legacy settings option, so there is nothing
		// to move — mark it done and never look again. Checked against the
		// oldest, most fundamental name rather than any of the newer markers,
		// which some old installs legitimately never wrote.
		if ( false === get_option( 'wa_settings', false ) ) {
			self::mark_done();
			return;
		}

		self::migrate_options();
		self::migrate_tables();
		self::migrate_meta();

		// The stored rewrite rules still map the swipe slug to the old query
		// var, so the swipe page would 404 (the rule matches; nothing reads
		// wa_swipe any more). Drop the cached rules and let WordPress
		// regenerate them on demand — this runs on plugins_loaded, before the
		// plugin has registered its rule on init, so flushing here would
		// rebuild them without it.
		delete_option( 'rewrite_rules' );

		self::mark_done();
	}

	/**
	 * Record that this site is migrated.
	 */
	private static function mark_done() {
		update_option( self::DONE_OPTION, WELLACTUALLY_VERSION, true );
	}

	/**
	 * Copy each option onto its new name and drop the old one.
	 *
	 * A new name that already holds a value wins: that means something has
	 * already written under the current names (a partial earlier run, or a
	 * site that downgraded and came back), and the newer value is the one the
	 * running code has been using.
	 */
	private static function migrate_options() {
		foreach ( self::OPTIONS as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}

			if ( null === get_option( $new, null ) ) {
				// Autoloaded to match how the plugin writes these itself:
				// settings are read on every request, the db-version and
				// marker options are not. add_option() takes a bool here and
				// normalizes it ('on'/'off' since 6.6).
				$autoload = ( 'wellactually_settings' === $new );

				add_option( $new, $value, '', $autoload );
			}

			delete_option( $old );
		}
	}

	/**
	 * Rename the two custom tables.
	 *
	 * RENAME TABLE keeps the rows, indexes and auto-increment position in
	 * place — far safer than copying rows out and back, and atomic per table.
	 */
	private static function migrate_tables() {
		global $wpdb;

		foreach ( self::TABLES as $old => $new ) {
			$old_table = $wpdb->prefix . $old;
			$new_table = $wpdb->prefix . $new;

			if ( ! self::table_exists( $old_table ) ) {
				continue;
			}

			// Both exist. This shouldn't happen — the migration runs before
			// anything can create a table under the new name — but "drop the
			// wrong one" would silently destroy every swipe score on the
			// site, so resolve it by which table actually holds data rather
			// than by which name is newer.
			if ( self::table_exists( $new_table ) ) {
				if ( 0 === self::row_count( $new_table ) ) {
					// An empty table created ahead of us. Safe to discard.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a schema change is the entire point of this method.
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $new_table ) );
				} else {
					// Both hold rows. Never guess: keep the one the running
					// code reads and leave the legacy table on disk for an
					// administrator to look at. Uninstall drops both.
					continue;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- as above; %i binds each name as an identifier.
			$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old_table, $new_table ) );
		}
	}

	/**
	 * Whether a table exists, by exact name.
	 *
	 * @param string $table Full table name, including the site prefix.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no cached way to ask this, and it runs once per site.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return ! empty( $found );
	}

	/**
	 * How many rows a table holds. Used only to decide which of two tables
	 * is the real one, so an exact count is what's wanted.
	 *
	 * @param string $table Full table name, including the site prefix.
	 * @return int
	 */
	private static function row_count( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count, once per site, on a table that may not be in any cache.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Rename post and user meta keys in place.
	 *
	 * One UPDATE per key rather than a LIKE '_wa_%' sweep: another plugin is
	 * free to own a key starting `_wa_`, and renaming someone else's data
	 * would be a genuinely destructive bug. Rows already sitting under the
	 * new key are left alone and the old row dropped, for the same
	 * "new name wins" reason as options.
	 *
	 * On multisite the usermeta table is shared network-wide, so the first
	 * site to migrate moves every user's progress. That's fine: the next
	 * site's run finds no rows left under the old key and updates nothing.
	 */
	private static function migrate_meta() {
		global $wpdb;

		$sets = array(
			array( $wpdb->postmeta, 'post_id', self::POST_META ),
			array( $wpdb->usermeta, 'user_id', self::USER_META ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- renaming meta keys in bulk is exactly what this method is for, and there is no API for it. Caches are flushed below.
		foreach ( $sets as list( $table, $id_column, $map ) ) {
			foreach ( $map as $old => $new ) {
				// Drop old rows for objects that already have the new key, so
				// the UPDATE below can't collide with them. Every table and
				// column name is bound with %i, so nothing is interpolated.
				$wpdb->query(
					$wpdb->prepare(
						'DELETE old FROM %i AS old INNER JOIN %i AS current ON current.%i = old.%i AND current.meta_key = %s WHERE old.meta_key = %s',
						$table,
						$table,
						$id_column,
						$id_column,
						$new,
						$old
					)
				);

				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET meta_key = %s WHERE meta_key = %s',
						$table,
						$new,
						$old
					)
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Every one of those rows was cached under its object id, and the
		// cache still holds the pre-rename copy. Group flushing is optional
		// for a persistent object cache to implement, so fall back to the
		// blunt instrument — this runs once, ever, per site.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
			wp_cache_flush_group( 'user_meta' );
		} else {
			wp_cache_flush();
		}
	}
}
