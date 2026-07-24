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
		delete_option( WellActually_Migrate::LOCK_OPTION );
		foreach ( WellActually_Migrate::OPTIONS as $old => $new ) {
			delete_option( $old );
			delete_option( $new );
		}
	}

	/**
	 * A legacy site that never saved the settings form still has to migrate.
	 *
	 * Calling register_setting() doesn't create `wa_settings` — it appears
	 * only once an administrator saves Settings → "Well, Actually...". A site
	 * set up entirely through Posts → "Well, Actually..." has swipe meta
	 * and user progress with no settings row at all, and an earlier version
	 * of this migration mistook exactly that for a fresh install and skipped
	 * it, stranding the data permanently.
	 */
	public function test_legacy_site_without_settings_option_still_migrates() {
		$this->assertFalse( get_option( 'wa_settings' ), 'This test is only meaningful with no legacy settings row.' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_wa_statement', 'Set up without ever saving settings' );
		update_post_meta( $post_id, '_wa_verdict', 'true' );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_wa_progress', array( 'seen' => array( $post_id ) ) );

		WellActually_Migrate::maybe_migrate();

		$this->assertSame(
			'Set up without ever saving settings',
			get_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, true ),
			'Swipe content must migrate even with no settings row.'
		);
		$this->assertSame( 'true', get_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, true ) );
		$this->assertSame(
			array( 'seen' => array( $post_id ) ),
			get_user_meta( $user_id, WellActually_User_Progress::META_KEY, true )
		);
	}

	/**
	 * A failed write must not be papered over: the legacy copy stays, and the
	 * done-marker stays unset so a later request tries again.
	 */
	public function test_failed_write_keeps_legacy_data_and_does_not_mark_done() {
		update_option( 'wa_settings', array( 'slug' => 'quiz' ) );
		update_option( 'wa_db_version', '1.0' );

		// Make the new option unwritable: every attempt to add or read
		// `wellactually_settings` comes back empty, standing in for a write
		// that silently didn't land.
		$block = static function () {
			return null;
		};
		add_filter( 'pre_option_wellactually_settings', $block );
		add_filter( 'pre_add_option_wellactually_settings', '__return_null' );

		$result = WellActually_Migrate::maybe_migrate();

		remove_filter( 'pre_option_wellactually_settings', $block );
		remove_filter( 'pre_add_option_wellactually_settings', '__return_null' );

		$this->assertFalse( $result, 'A failed migration must report failure.' );
		$this->assertFalse(
			get_option( WellActually_Migrate::DONE_OPTION ),
			'A failed migration must stay unmarked so it retries.'
		);
		$this->assertSame(
			array( 'slug' => 'quiz' ),
			get_option( 'wa_settings' ),
			'The legacy copy must survive a failed write — losing both is the worst outcome.'
		);

		// And the retry succeeds once the write works again.
		$this->assertTrue( WellActually_Migrate::maybe_migrate() );
		$this->assertSame( array( 'slug' => 'quiz' ), get_option( 'wellactually_settings' ) );
		$this->assertFalse( get_option( 'wa_settings' ) );
	}

	/**
	 * Two simultaneous requests must not both migrate. The second sees the
	 * lock and backs off rather than racing the first.
	 */
	public function test_concurrent_run_backs_off_while_locked() {
		update_option( 'wa_settings', array( 'slug' => 'quiz' ) );

		// Stand in for a request that is part-way through right now.
		add_option( WellActually_Migrate::LOCK_OPTION, time(), '', false );

		$this->assertFalse( WellActually_Migrate::maybe_migrate(), 'A locked site must back off.' );
		$this->assertFalse( get_option( WellActually_Migrate::DONE_OPTION ) );
		$this->assertSame( array( 'slug' => 'quiz' ), get_option( 'wa_settings' ), 'The blocked request must not touch anything.' );

		// An abandoned lock is taken over rather than blocking forever.
		update_option( WellActually_Migrate::LOCK_OPTION, time() - ( WellActually_Migrate::LOCK_TIMEOUT + 1 ), false );

		$this->assertTrue( WellActually_Migrate::maybe_migrate(), 'A stale lock must be taken over.' );
		$this->assertSame( array( 'slug' => 'quiz' ), get_option( 'wellactually_settings' ) );
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
			$this->make_stats_table( $legacy );
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

			$this->restore_stats_table( $current, $legacy );
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * If a table already exists under the new name but is empty — something
	 * created it ahead of the migration — the legacy table's rows still have
	 * to win. Dropping the wrong one here loses every swipe score on the site.
	 */
	public function test_empty_new_table_does_not_beat_legacy_rows() {
		global $wpdb;

		$legacy  = $wpdb->prefix . 'wa_stats';
		$current = $wpdb->prefix . 'wellactually_stats';

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$this->make_stats_table( $legacy );
			$wpdb->insert( $legacy, array( 'post_id' => 4242 ) );

			// An empty table sitting under the new name.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $current ) ); // phpcs:ignore WordPress.DB
			$this->make_stats_table( $current );

			update_option( 'wa_settings', array( 'slug' => 'swipe' ) );

			WellActually_Migrate::maybe_migrate();

			$this->assertSame(
				'4242',
				(string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i', $current ) ), // phpcs:ignore WordPress.DB
				'The legacy rows must survive an empty table under the new name.'
			);

			$this->restore_stats_table( $current, $legacy );
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * If both tables hold rows, neither is guessed at: the current one is
	 * left alone and the legacy one is left on disk rather than dropped.
	 */
	public function test_two_populated_tables_are_both_kept() {
		global $wpdb;

		$legacy  = $wpdb->prefix . 'wa_stats';
		$current = $wpdb->prefix . 'wellactually_stats';

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$this->make_stats_table( $legacy );
			$wpdb->insert( $legacy, array( 'post_id' => 1111 ) );

			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $current ) ); // phpcs:ignore WordPress.DB
			$this->make_stats_table( $current );
			$wpdb->insert( $current, array( 'post_id' => 2222 ) );

			update_option( 'wa_settings', array( 'slug' => 'swipe' ) );

			WellActually_Migrate::maybe_migrate();

			$this->assertSame(
				'2222',
				(string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i', $current ) ), // phpcs:ignore WordPress.DB
				'The table the running code reads must be untouched.'
			);
			$this->assertNotEmpty(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ), // phpcs:ignore WordPress.DB
				'A populated legacy table must be left for an administrator, not dropped.'
			);

			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $legacy ) ); // phpcs:ignore WordPress.DB
			$this->restore_stats_table( $current, $legacy );
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * A minimal stand-in for the stats table, as a real (not TEMPORARY)
	 * table so RENAME TABLE can see it.
	 *
	 * @param string $table Full table name.
	 */
	private function make_stats_table( $table ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id) )', $table ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Put the suite back the way the bootstrap left it: the real stats table,
	 * under its current name, with no legacy copy beside it.
	 *
	 * @param string $current Current table name.
	 * @param string $legacy  Legacy table name.
	 */
	private function restore_stats_table( $current, $legacy ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $legacy ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $current ) ); // phpcs:ignore WordPress.DB
		WellActually_Stats::instance()->create_table();
	}

	/**
	 * Two requests that both judge the lock abandoned must not both take it.
	 *
	 * This is the interleaving, not an approximation of it: both callers pass
	 * the same observed value, which is what two requests that read the stale
	 * lock before either wrote would do. A plain update_option() takeover
	 * lets both through — the exact race the lock exists to prevent.
	 */
	public function test_only_one_request_takes_over_a_stale_lock() {
		$abandoned = time() - ( WellActually_Migrate::LOCK_TIMEOUT + 60 );
		add_option( WellActually_Migrate::LOCK_OPTION, $abandoned, '', false );

		$observed = (string) $abandoned;

		$first  = WellActually_Migrate::take_over_stale_lock( $observed );
		$second = WellActually_Migrate::take_over_stale_lock( $observed );

		$this->assertTrue( $first, 'The first request to swap must win the lock.' );
		$this->assertFalse( $second, 'A second request observing the same stale lock must lose the swap.' );
	}

	/**
	 * A request that couldn't migrate must not boot the plugin.
	 *
	 * Components run against half-migrated data don't merely read stale
	 * values — they create the new names beside the old ones.
	 * WellActually_Stats::maybe_upgrade_table() on init finds no
	 * wellactually_db_version, builds an empty wellactually_stats, and one
	 * swipe recorded into it leaves two populated tables the migration then
	 * refuses to merge. So booting is gated on the migration completing.
	 */
	public function test_blocked_request_does_not_boot_components() {
		update_option( 'wa_settings', array( 'slug' => 'quiz' ) );

		// Stand in for another request part-way through right now.
		add_option( WellActually_Migrate::LOCK_OPTION, time(), '', false );

		$this->assertFalse( wellactually_init(), 'A blocked request must decline to boot.' );

		// The tell-tale of a component having run: the db-version option that
		// maybe_upgrade_table() writes when it builds a table.
		$this->assertFalse(
			get_option( WellActually_Stats::DB_VERSION_OPTION ),
			'No component may write new-name storage while the migration is outstanding.'
		);
		$this->assertSame(
			array( 'slug' => 'quiz' ),
			get_option( 'wa_settings' ),
			'The legacy data must be exactly as the blocked request found it.'
		);

		// Once the lock clears, the next request boots normally.
		delete_option( WellActually_Migrate::LOCK_OPTION );
		$this->assertTrue( wellactually_init(), 'The next request must boot once the migration can run.' );
		$this->assertSame( array( 'slug' => 'quiz' ), get_option( 'wellactually_settings' ) );
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
