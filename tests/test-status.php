<?php
/**
 * The denormalized status field every admin filter selects on.
 *
 * Regression cover for the bug where posts turned up under the wrong filter
 * — most visibly "Never"-excluded posts listed as needing setup, and ready AI
 * suggestions that were unreachable because their stored status disagreed
 * with the meta it's derived from.
 *
 * @package WellActually
 */

/**
 * Covers WellActually_Meta's status derivation, storage, and repair.
 */
class Test_WellActually_Status extends WP_UnitTestCase {

	/**
	 * The priority rules, stated as a table so a reordering can't pass
	 * unnoticed: skipped beats everything, then a resolved verdict, then a
	 * ready AI suggestion, else needs setup.
	 *
	 * @return array
	 */
	public function status_provider() {
		return array(
			// Label => skipped, verdict, ai_status, expected.
			'nothing set'        => array( false, '', '', WellActually_Meta::STATUS_NEEDS_SETUP ),
			'ready suggestion'   => array( false, '', 'ready', WellActually_Meta::STATUS_HAS_AI ),
			'queued not ready'   => array( false, '', 'queued', WellActually_Meta::STATUS_NEEDS_SETUP ),
			'errored'            => array( false, '', 'error', WellActually_Meta::STATUS_NEEDS_SETUP ),
			'true verdict'       => array( false, 'true', '', WellActually_Meta::STATUS_CONFIGURED ),
			'debatable'          => array( false, 'debatable', '', WellActually_Meta::STATUS_CONFIGURED ),
			'excluded'           => array( false, 'excluded', '', WellActually_Meta::STATUS_EXCLUDED ),
			'verdict beats ai'   => array( false, 'true', 'ready', WellActually_Meta::STATUS_CONFIGURED ),
			'skip beats verdict' => array( true, 'true', '', WellActually_Meta::STATUS_SKIPPED ),
			'skip beats ai'      => array( true, '', 'ready', WellActually_Meta::STATUS_SKIPPED ),
			'skip beats exclude' => array( true, 'excluded', '', WellActually_Meta::STATUS_SKIPPED ),
		);
	}

	/**
	 * @dataProvider status_provider
	 *
	 * @param bool   $skipped   Skip flag.
	 * @param string $verdict   Verdict meta.
	 * @param string $ai_status AI status meta.
	 * @param string $expected  Expected derived status.
	 */
	public function test_status_priority( $skipped, $verdict, $ai_status, $expected ) {
		$post_id = self::factory()->post->create();

		if ( $skipped ) {
			update_post_meta( $post_id, WellActually_Meta::SKIP_KEY, '1' );
		}
		if ( '' !== $verdict ) {
			update_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, $verdict );
		}
		if ( '' !== $ai_status ) {
			update_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, $ai_status );
		}

		$this->assertSame( $expected, WellActually_Meta::compute_status( $post_id ) );
	}

	/**
	 * Values handed in by a caller must win over what's stored.
	 *
	 * This is the fix for suggestions becoming unreachable: store_result()
	 * wrote "ready" and then recompute_status() read that key back to derive
	 * the status. Where reads can lag writes, the read returned the previous
	 * value and the wrong status was persisted permanently.
	 */
	public function test_known_values_are_used_instead_of_rereading() {
		$post_id = self::factory()->post->create();

		// What's actually stored says one thing...
		update_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, 'queued' );

		// ...but the caller just wrote 'ready' and tells us so.
		$this->assertSame(
			WellActually_Meta::STATUS_HAS_AI,
			WellActually_Meta::compute_status( $post_id, array( 'ai_status' => 'ready' ) )
		);

		// Without the override it believes the stale stored value.
		$this->assertSame( WellActually_Meta::STATUS_NEEDS_SETUP, WellActually_Meta::compute_status( $post_id ) );
	}

	/**
	 * Drift has to be repairable, because a wrong stored value hides itself:
	 * the filters select on it, so the mis-filed posts are exactly the ones
	 * those screens can never show.
	 */
	public function test_repair_fixes_drift_in_both_directions() {
		$stale_excluded = self::factory()->post->create();
		update_post_meta( $stale_excluded, WellActually_Meta::VERDICT_KEY, 'excluded' );
		update_post_meta( $stale_excluded, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$missing_status = self::factory()->post->create();
		update_post_meta( $missing_status, WellActually_Meta::AI_STATUS_KEY, 'ready' );
		delete_post_meta( $missing_status, WellActually_Meta::STATUS_KEY );

		$already_right = self::factory()->post->create();
		update_post_meta( $already_right, WellActually_Meta::VERDICT_KEY, 'true' );
		update_post_meta( $already_right, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_CONFIGURED );

		$fixed = WellActually_Meta::repair_statuses();

		$this->assertGreaterThanOrEqual( 2, $fixed );
		$this->assertSame( WellActually_Meta::STATUS_EXCLUDED, get_post_meta( $stale_excluded, WellActually_Meta::STATUS_KEY, true ) );
		$this->assertSame( WellActually_Meta::STATUS_HAS_AI, get_post_meta( $missing_status, WellActually_Meta::STATUS_KEY, true ) );
		$this->assertSame( WellActually_Meta::STATUS_CONFIGURED, get_post_meta( $already_right, WellActually_Meta::STATUS_KEY, true ) );

		// Idempotent: a second pass has nothing left to do.
		$this->assertSame( 0, WellActually_Meta::repair_statuses() );
	}

	/**
	 * The final Needs Setup check must use source meta, not the denormalized
	 * status that selected a candidate or a potentially stale meta cache.
	 */
	public function test_live_status_filter_rejects_phantom_needs_setup_posts() {
		$untouched = self::factory()->post->create();

		$explicit_needs_setup = self::factory()->post->create();
		update_post_meta( $explicit_needs_setup, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$configured = self::factory()->post->create();
		update_post_meta( $configured, WellActually_Meta::VERDICT_KEY, 'true' );
		update_post_meta( $configured, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$excluded = self::factory()->post->create();
		update_post_meta( $excluded, WellActually_Meta::VERDICT_KEY, WellActually_Meta::VERDICT_EXCLUDED );
		update_post_meta( $excluded, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$skipped = self::factory()->post->create();
		update_post_meta( $skipped, WellActually_Meta::SKIP_KEY, '1' );
		update_post_meta( $skipped, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$ready = self::factory()->post->create();
		update_post_meta( $ready, WellActually_Meta::AI_STATUS_KEY, 'ready' );
		update_post_meta( $ready, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$ids = array( $configured, $untouched, $excluded, $explicit_needs_setup, $skipped, $ready );

		$this->assertSame(
			array( $untouched, $explicit_needs_setup ),
			WellActually_Meta::filter_post_ids_by_live_status( $ids, WellActually_Meta::STATUS_NEEDS_SETUP )
		);
	}

	/**
	 * The screen must fill and count pages after the authoritative check. A
	 * block of cached phantom IDs at the top may not create short pages or
	 * leak configured/excluded posts into the table.
	 */
	public function test_needs_setup_screen_filters_before_pagination() {
		$base = strtotime( '2025-01-01 00:00:00' );
		for ( $i = 0; $i < 25; $i++ ) {
			self::factory()->post->create(
				array( 'post_date' => gmdate( 'Y-m-d H:i:s', $base + ( $i * MINUTE_IN_SECONDS ) ) )
			);
		}

		$phantoms = array();
		foreach ( array( 'true', WellActually_Meta::VERDICT_EXCLUDED ) as $offset => $verdict ) {
			$post_id = self::factory()->post->create(
				array( 'post_date' => gmdate( 'Y-m-d H:i:s', $base + ( ( 30 + $offset ) * MINUTE_IN_SECONDS ) ) )
			);
			update_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, $verdict );
			update_post_meta( $post_id, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );
			$phantoms[] = $post_id;
		}

		$method = new ReflectionMethod( 'WellActually_Bulk_Setup', 'build_query' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$args = array(
			'status'  => 'needs_setup',
			'done'    => array(),
			'cat'     => 0,
			'order'   => 'DESC',
			'orderby' => 'date',
			'paged'   => 1,
		);

		$first_page = $method->invoke( WellActually_Bulk_Setup::instance(), $args );

		$this->assertSame( 25, $first_page->found_posts );
		$this->assertSame( 20, $first_page->post_count );
		$this->assertEmpty( array_intersect( $phantoms, wp_list_pluck( $first_page->posts, 'ID' ) ) );

		$args['paged'] = 2;
		$second_page   = $method->invoke( WellActually_Bulk_Setup::instance(), $args );

		$this->assertSame( 25, $second_page->found_posts );
		$this->assertSame( 5, $second_page->post_count );
		$this->assertEmpty( array_intersect( $phantoms, wp_list_pluck( $second_page->posts, 'ID' ) ) );
	}

	/**
	 * Skipping a category has to cover everything beneath it, including
	 * children added after the parent was ticked.
	 */
	public function test_excluded_categories_expand_to_descendants() {
		$parent = self::factory()->category->create();
		$child  = self::factory()->category->create( array( 'parent' => $parent ) );
		$grand  = self::factory()->category->create( array( 'parent' => $child ) );

		update_option(
			'wellactually_settings',
			array_merge( WellActually_Settings::get_settings(), array( 'excluded_categories' => array( $parent ) ) )
		);

		$excluded = WellActually_Settings::excluded_categories();

		$this->assertContains( $parent, $excluded );
		$this->assertContains( $child, $excluded, 'A child of an excluded category must be excluded too.' );
		$this->assertContains( $grand, $excluded, 'Exclusion must reach the whole branch, not just direct children.' );
	}

	/**
	 * The "Rebuild status index" button posts to admin-post.php with an
	 * action name; the handler has to be registered under exactly that name.
	 * The two are written in different files and a rename broke the pairing
	 * once already — the button silently did nothing.
	 */
	public function test_rebuild_status_action_is_registered_under_the_posted_name() {
		WellActually_Settings::instance();

		ob_start();
		WellActually_Settings::instance()->render_rebuild_status_block();
		$form = ob_get_clean();

		preg_match( '/name="action" value="([^"]+)"/', $form, $matches );
		$posted_action = isset( $matches[1] ) ? $matches[1] : '';

		$this->assertNotSame( '', $posted_action, 'The rebuild form must post an action.' );
		$this->assertNotFalse(
			has_action( 'admin_post_' . $posted_action, array( WellActually_Settings::instance(), 'handle_rebuild_status' ) ),
			"Nothing handles admin_post_{$posted_action}, so the button would do nothing."
		);
	}
}
