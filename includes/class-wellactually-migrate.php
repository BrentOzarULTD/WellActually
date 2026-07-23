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
	 * Lock option, so two simultaneous requests can't both migrate. Holds the
	 * unix time the lock was taken.
	 */
	const LOCK_OPTION = 'wellactually_prefix_migrating';

	/**
	 * How long a lock is honoured before it's treated as abandoned — a
	 * request that died mid-migration must not block the site forever.
	 */
	const LOCK_TIMEOUT = 300;

	/**
	 * Run the migration unless this site is already migrated.
	 *
	 * Called on every request (from wellactually_init()), so the common path
	 * has to be cheap: on a migrated site this is one autoloaded option read
	 * and a return.
	 *
	 * There is deliberately no "does this site look legacy?" shortcut. The
	 * obvious one — skip when `wa_settings` is missing — is wrong:
	 * register_setting() doesn't create that row, so it only exists once an
	 * administrator has saved the settings form. A site set up entirely
	 * through Posts → "Well, Actually..." can have swipe meta, user progress
	 * and populated tables while running on default settings, and would have
	 * been marked migrated with all of it stranded. The work below is bounded
	 * to a fixed list of names, so running it once against a fresh install is
	 * a handful of no-op queries — much cheaper than getting this wrong.
	 *
	 * @return bool Whether the site is migrated (including "already was").
	 */
	public static function maybe_migrate() {
		if ( get_option( self::DONE_OPTION ) ) {
			return true;
		}

		if ( ! self::acquire_lock() ) {
			// Another request is doing it. Nothing to wait for: this request
			// reads slightly stale data, the next one sees it finished.
			return false;
		}

		try {
			$ok = self::migrate_options();
			$ok = self::migrate_tables() && $ok;
			$ok = self::migrate_meta() && $ok;

			// The stored rewrite rules still map the swipe slug to the old
			// query var, so the swipe page would 404 (the rule matches;
			// nothing reads wa_swipe any more). Drop the cached rules and let
			// WordPress regenerate them on demand — this runs on
			// plugins_loaded, before the plugin has registered its rule on
			// init, so flushing here would rebuild them without it.
			delete_option( 'rewrite_rules' );

			// Only on a clean run. Leaving the marker unset costs one more
			// attempt on the next request; setting it after a failed write
			// would strand whatever didn't make it, permanently.
			if ( $ok ) {
				self::mark_done();
			}
		} finally {
			self::release_lock();
		}

		return $ok;
	}

	/**
	 * Take the migration lock, or report that someone else holds it.
	 *
	 * The test-and-set is add_option(): option_name is uniquely indexed, so
	 * exactly one concurrent caller can insert the row.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		// Someone holds it. Honour it unless it's old enough to be a request
		// that died part-way, in which case take it over.
		$held_since = (int) get_option( self::LOCK_OPTION );
		if ( $held_since && ( time() - $held_since ) < self::LOCK_TIMEOUT ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );

		return true;
	}

	/**
	 * Release the migration lock.
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
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
	 *
	 * The old copy is only dropped once the new one is confirmed readable.
	 * add_option() can fail — a full disk, a lost connection — and deleting
	 * on the strength of an unchecked return would leave neither copy.
	 *
	 * @return bool Whether every option was moved.
	 */
	private static function migrate_options() {
		$ok = true;

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

			// Read it back rather than trusting the write's return value:
			// this is the postcondition that actually matters.
			if ( null === get_option( $new, null ) ) {
				$ok = false;
				continue;
			}

			delete_option( $old );
		}

		return $ok;
	}

	/**
	 * Rename the two custom tables.
	 *
	 * RENAME TABLE keeps the rows, indexes and auto-increment position in
	 * place — far safer than copying rows out and back, and atomic per table.
	 *
	 * @return bool Whether every legacy table was dealt with.
	 */
	private static function migrate_tables() {
		global $wpdb;

		$ok = true;

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
					// administrator to look at. Uninstall drops both. This is
					// a resolved state, not a failure — retrying wouldn't
					// change it, so it must not hold the marker back.
					continue;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- as above; %i binds each name as an identifier.
			$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old_table, $new_table ) );

			// Confirm it actually landed. A failed RENAME leaves the rows
			// under the old name, which is recoverable — but only if the
			// migration doesn't mark itself finished and stop trying.
			if ( ! self::table_exists( $new_table ) ) {
				$ok = false;
			}
		}

		return $ok;
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
	 *
	 * @return bool Whether every statement succeeded.
	 */
	private static function migrate_meta() {
		global $wpdb;

		$ok = true;

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
				// $wpdb->query() returns false on error and a row count (0
				// included) otherwise, so compare identically.
				$deleted = $wpdb->query(
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

				$renamed = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET meta_key = %s WHERE meta_key = %s',
						$table,
						$new,
						$old
					)
				);

				if ( false === $deleted || false === $renamed ) {
					$ok = false;
				}
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

		return $ok;
	}
}
