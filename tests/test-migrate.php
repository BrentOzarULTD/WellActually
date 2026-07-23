<?php
/**
 * The pre-1.8.0 prefix migration.
 *
 * Issue #33 renamed every option, table, meta key and hook from `wa_` to
 * `wellactually_`. The risk that matters is an upgrade that looks like a
 * fresh install: settings gone, swipe statements gone, scores gone. These
 * tests seed a 1.7.0-shaped site and assert the data comes out the far side.
 *
 * @package WellActually
 */

/**
 * Covers WellActually_Migrate::maybe_migrate().
 */
class Test_WellActually_Migrate extends WP_UnitTestCase {

	/**
	 * Start every test from an unmigrated site, whatever ran before.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_migration_state();
	}

	/**
	 * Leave the suite the way it was found: migrated, current names only.
	 */
	public function tear_down() {
		$this->reset_migration_state();
		update_option( WellActually_Migrate::DONE_OPTION, WELLACTUALLY_VERSION );
		parent::tear_down();
	}

	/**
	 * Clear both sides of every name this migration touches.
	 */
	private function reset_migration_state() {
		delete_option( WellActually_Migrate::DONE_OPTION );
		foreach ( WellActually_Migrate::OPTIONS as $old => $new ) {
			delete_option( $old );
			delete_option( $new );
		}
	}

	/**
	 * The headline case: a 1.7.0 site upgrading keeps its settings, its
	 * per-post swipe content, and its saved progress.
	 */
	public function test_upgrade_preserves_existing_data() {
		$settings = array(
			'slug'        => 'quiz',
			'ai_provider' => 'nano-gpt',
		);
		update_option( 'wa_settings', $settings );
		update_option( 'wa_db_version', '1.0' );
		update_option( 'wa_status_backfilled', 1 );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_wa_statement', 'Temp tables are faster than CTEs' );
		update_post_meta( $post_id, '_wa_verdict', 'debatable' );
		update_post_meta( $post_id, '_wa_status', 'configured' );

		$user_id  = self::factory()->user->create();
		$progress = array(
			'seen'          => array( $post_id ),
			'correct_count' => 1,
		);
		update_user_meta( $user_id, '_wa_progress', $progress );

		WellActually_Migrate::maybe_migrate();

		// Settings and markers moved across intact.
		$this->assertSame( $settings, get_option( 'wellactually_settings' ) );
		$this->assertSame( '1.0', get_option( 'wellactually_db_version' ) );
		$this->assertSame( '1', (string) get_option( 'wellactually_status_backfilled' ) );

		// And the plugin's own accessor now finds them.
		$this->assertSame( 'quiz', wellactually_get_setting( 'slug' ) );

		// Post meta reads through the constants the plugin uses everywhere.
		$this->assertSame(
			'Temp tables are faster than CTEs',
			get_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, true )
		);
		$this->assertSame( 'debatable', get_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, true ) );
		$this->assertSame( 'configured', get_post_meta( $post_id, WellActually_Meta::STATUS_KEY, true ) );

		// User progress survived, values and all.
		$this->assertSame( $progress, get_user_meta( $user_id, WellActually_User_Progress::META_KEY, true ) );

		// Nothing is left under the old names.
		$this->assertFalse( get_option( 'wa_settings' ) );
		$this->assertSame( '', get_post_meta( $post_id, '_wa_statement', true ) );
		$this->assertSame( '', get_user_meta( $user_id, '_wa_progress', true ) );
	}

	/**
	 * Running twice must not undo the first run or touch anything again —
	 * this fires on every request, so it has to be safely repeatable.
	 */
	public function test_migration_is_idempotent() {
		update_option( 'wa_settings', array( 'slug' => 'quiz' ) );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_wa_statement', 'Kept' );

		WellActually_Migrate::maybe_migrate();

		// A later write under the current name must survive a second run.
		update_option( 'wellactually_settings', array( 'slug' => 'changed-since' ) );
		update_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, 'Edited since' );

		// Force a real second pass rather than resting on the done-marker
		// guard: this is the path a half-finished first run would take.
		delete_option( WellActually_Migrate::DONE_OPTION );
		update_option( 'wa_settings', array( 'slug' => 'from-legacy-again' ) );

		WellActually_Migrate::maybe_migrate();

		$this->assertSame( array( 'slug' => 'changed-since' ), get_option( 'wellactually_settings' ) );
		$this->assertSame( 'Edited since', get_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, true ) );
	}

	/**
	 * A fresh install has nothing to move. It must not invent options under
	 * the old names, and must mark itself done so the check stays cheap.
	 */
	public function test_fresh_install_creates_only_current_names() {
		WellActually_Migrate::maybe_migrate();

		$this->assertNotEmpty( get_option( WellActually_Migrate::DONE_OPTION ) );

		foreach ( array_keys( WellActually_Migrate::OPTIONS ) as $legacy ) {
			$this->assertFalse( get_option( $legacy ), "Fresh installs must not create {$legacy}." );
		}
	}

	/**
	 * When both names hold a value, the current one wins: it's what the
	 * running code has been reading and writing.
	 */
	public function test_current_name_wins_over_legacy() {
		update_option( 'wa_settings', array( 'slug' => 'from-legacy' ) );
		update_option( 'wellactually_settings', array( 'slug' => 'from-current' ) );

		WellActually_Migrate::maybe_migrate();

		$this->assertSame( array( 'slug' => 'from-current' ), get_option( 'wellactually_settings' ) );
		$this->assertFalse( get_option( 'wa_settings' ), 'The stale legacy option must still be cleaned up.' );
	}

	/**
	 * A post that somehow has both keys keeps the current one, and loses the
	 * stale duplicate — the UPDATE must not leave two rows for one post.
	 */
	public function test_duplicate_meta_keeps_current_value() {
		update_option( 'wa_settings', array( 'slug' => 'quiz' ) );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_wa_statement', 'Stale' );
		update_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, 'Current' );

		WellActually_Migrate::maybe_migrate();

		$values = get_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY );
		$this->assertSame( array( 'Current' ), $values );
		$this->assertSame( '', get_post_meta( $post_id, '_wa_statement', true ) );
	}

	/**
	 * The stats and queue tables are renamed in place, rows and all. This is
	 * the one part of the migration that can't be undone by re-running it, so
	 * it gets its own cover.
	 */
	public function test_tables_are_renamed_with_their_rows() {
		global $wpdb;

		$legacy  = $wpdb->prefix . 'wa_stats';
		$current = $wpdb->prefix . 'wellactually_stats';

		// Stand in for a 1.7.0 site: the real table under its old name, and
		// nothing under the new one. The suite rewrites CREATE/DROP into
		// TEMPORARY forms, which RENAME TABLE can't see, so bypass it here
		// the same way the uninstall test does.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $current ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $legacy ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id) )', $legacy ) ); // phpcs:ignore WordPress.DB
			$wpdb->insert( $legacy, array( 'post_id' => 4242 ) );

			update_option( 'wa_settings', array( 'slug' => 'swipe' ) );

			WellActually_Migrate::maybe_migrate();

			$this->assertNotEmpty(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $current ) ), // phpcs:ignore WordPress.DB
				'The stats table must exist under its new name.'
			);
			$this->assertEmpty(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ), // phpcs:ignore WordPress.DB
				'The legacy table must be gone, not left as a duplicate.'
			);
			$this->assertSame(
				'4242',
				(string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i', $current ) ), // phpcs:ignore WordPress.DB
				'The rows must come with it.'
			);

			// Put the suite back the way the bootstrap left it.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $current ) ); // phpcs:ignore WordPress.DB
			WellActually_Stats::instance()->create_table();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * The stored rewrite rules map the swipe slug to the old query var, so
	 * they have to be dropped or the swipe page 404s after the upgrade.
	 */
	public function test_stale_rewrite_rules_are_dropped() {
		update_option( 'wa_settings', array( 'slug' => 'swipe' ) );
		update_option( 'rewrite_rules', array( '^swipe/?$' => 'index.php?wa_swipe=1' ) );

		WellActually_Migrate::maybe_migrate();

		$this->assertFalse( get_option( 'rewrite_rules' ) );
	}
}
