<?php
/**
 * The uninstall routine.
 *
 * Regression cover for issue #35: uninstall.php claimed to remove all traces
 * of the plugin but left wa_status_repaired behind, and never cleaned up
 * transients at all.
 *
 * @package WellActually
 */

/**
 * Seeds one of every kind of data the plugin persists, runs the real
 * uninstall.php, and asserts nothing survives.
 *
 * Multisite note: uninstall.php loops sites via switch_to_blog(); this test
 * runs single-site (the suite isn't multisite), so the loop itself is
 * exercised only in its single-site branch.
 */
class Test_WA_Uninstall extends WP_UnitTestCase {

	/**
	 * Every option the plugin writes.
	 *
	 * @var string[]
	 */
	private $options = array(
		'wa_settings',
		'wa_db_version',
		'wa_ai_queue_db_version',
		'wa_status_backfilled',
		'wa_status_repaired',
	);

	/**
	 * Every post meta key the plugin writes.
	 *
	 * @var string[]
	 */
	private $meta_keys = array(
		'_wa_statement',
		'_wa_verdict',
		'_wa_ai_statement',
		'_wa_ai_verdict',
		'_wa_ai_status',
		'_wa_ai_error',
		'_wa_ai_error_time',
		'_wa_ai_claimed',
		'_wa_skip',
		'_wa_status',
	);

	/**
	 * Uninstall must remove every option, transient, table, post meta key,
	 * and user meta key the plugin has ever written — an administrator who
	 * deletes the plugin is owed a clean database.
	 */
	public function test_uninstall_removes_everything() {
		global $wpdb;

		// --- Seed one of everything the plugin persists. ---

		foreach ( $this->options as $option ) {
			update_option( $option, 'seeded' );
		}

		set_transient( 'wa_eligible_total', 42, 300 );

		// The dynamic-suffix transient families, written straight to the
		// options table the way they land on a site with no persistent
		// object cache (the tests run without one, so set_transient()
		// would do the same — this just makes the layout explicit).
		$dynamic = array(
			'_transient_wa_rl_swipe_' . md5( '203.0.113.9' ),
			'_transient_timeout_wa_rl_swipe_' . md5( '203.0.113.9' ),
			'_transient_wa_ai_provider_configured_nano-gpt',
			'_transient_timeout_wa_ai_provider_configured_nano-gpt',
		);
		foreach ( $dynamic as $name ) {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => 'seeded',
					'autoload'     => 'no',
				)
			);
		}

		$post_id = self::factory()->post->create();
		foreach ( $this->meta_keys as $key ) {
			update_post_meta( $post_id, $key, 'seeded' );
		}

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_wa_progress', array( 'seen' => array( 1 ) ) );

		// Sanity: both tables exist before (bootstrap created them).
		$this->assertNotEmpty( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wa_stats'" ) ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wa_ai_queue'" ) ); // phpcs:ignore WordPress.DB

		// --- Run the real uninstall file. ---

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		// The test suite rewrites DROP TABLE into DROP TEMPORARY TABLE, which
		// silently no-ops against the real tables the bootstrap created — and
		// would make this test pass while asserting nothing. Let the real
		// statements through for the uninstall run. The filter is registered
		// as an instance method in WP_UnitTestCase_Base::set_up(), so it must
		// be removed and restored in the same form.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			require dirname( __DIR__ ) . '/uninstall.php';
		} finally {
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}

		// --- Nothing may survive. ---

		foreach ( $this->options as $option ) {
			$this->assertFalse( get_option( $option ), "Option {$option} must be removed." );
		}

		$this->assertFalse( get_transient( 'wa_eligible_total' ) );

		$leftover_transients = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_%wa\_rl\_%'
			    OR option_name LIKE '\_transient\_%wa\_ai\_provider\_configured\_%'"
		);
		$this->assertSame( 0, $leftover_transients, 'No plugin transients may remain in the options table.' );

		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wa_stats'" ), 'The stats table must be dropped.' ); // phpcs:ignore WordPress.DB
		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wa_ai_queue'" ), 'The queue table must be dropped.' ); // phpcs:ignore WordPress.DB

		foreach ( $this->meta_keys as $key ) {
			$this->assertSame( '', get_post_meta( $post_id, $key, true ), "Post meta {$key} must be removed." );
		}

		$this->assertSame( '', get_user_meta( $user_id, '_wa_progress', true ), 'User progress meta must be removed.' );
	}

	/**
	 * Uninstall drops the plugin's tables; put them back — as real tables,
	 * not the TEMPORARY ones the suite's query filter would create — so any
	 * test running after this one finds the same state the bootstrap made.
	 */
	public function tear_down() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			WA_Stats::instance()->create_table();
			WA_AI_Queue::create_table();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}
		parent::tear_down();
	}
}
