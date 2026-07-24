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
	 * Views sorting is only valid while a stats provider is available.
	 */
	public function test_views_sort_requires_a_stats_provider() {
		if ( class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) || function_exists( 'stats_get_csv' ) ) {
			$this->markTestSkipped( 'This regression test needs an environment without Jetpack Stats.' );
		}

		$method = new ReflectionMethod( 'WellActually_Bulk_Setup', 'current_args' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$_REQUEST['wellactually_orderby'] = 'views_30';

		try {
			$args = $method->invoke( WellActually_Bulk_Setup::instance() );
			$this->assertSame( 'date', $args['orderby'] );

			$query              = new WP_Query();
			$query->found_posts = 0;
			$render             = new ReflectionMethod( 'WellActually_Bulk_Setup', 'render_filters' );
			if ( PHP_VERSION_ID < 80100 ) {
				$render->setAccessible( true );
			}

			ob_start();
			$render->invoke( WellActually_Bulk_Setup::instance(), $args, $query );
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'value="views_30"', $html );

			$filter = static function () {
				return array();
			};
			add_filter( 'wellactually_jetpack_views_30_days', $filter );

			$args = $method->invoke( WellActually_Bulk_Setup::instance() );
			$this->assertSame( 'views_30', $args['orderby'] );

			ob_start();
			$render->invoke( WellActually_Bulk_Setup::instance(), $args, $query );
			$html = ob_get_clean();
			$this->assertStringContainsString( 'value="views_30"', $html );
		} finally {
			unset( $_REQUEST['wellactually_orderby'] );
			if ( isset( $filter ) ) {
				remove_filter( 'wellactually_jetpack_views_30_days', $filter );
			}
		}
	}

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

		$duplicate_verdicts = self::factory()->post->create();
		add_post_meta( $duplicate_verdicts, WellActually_Meta::VERDICT_KEY, 'true' );
		add_post_meta( $duplicate_verdicts, WellActually_Meta::VERDICT_KEY, WellActually_Meta::VERDICT_EXCLUDED );
		update_post_meta( $duplicate_verdicts, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$ids = array( $configured, $untouched, $excluded, $explicit_needs_setup, $skipped, $ready, $duplicate_verdicts );

		$this->assertSame(
			array( $untouched, $explicit_needs_setup ),
			WellActually_Meta::filter_post_ids_by_live_status( $ids, WellActually_Meta::STATUS_NEEDS_SETUP )
		);
		$this->assertSame(
			array( $excluded, $duplicate_verdicts ),
			WellActually_Meta::filter_post_ids_by_live_status( $ids, WellActually_Meta::STATUS_EXCLUDED )
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
			'author'  => 0,
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
	 * The direct Needs Setup query must preserve WP_Query's category semantics:
	 * selecting a parent includes its descendants, while any skipped category
	 * excludes a post even if it also belongs to the selected branch.
	 */
	public function test_needs_setup_screen_preserves_category_scope() {
		$parent   = self::factory()->category->create();
		$child    = self::factory()->category->create( array( 'parent' => $parent ) );
		$other    = self::factory()->category->create();
		$excluded = self::factory()->category->create();

		$included_post = self::factory()->post->create( array( 'post_category' => array( $child ) ) );
		self::factory()->post->create( array( 'post_category' => array( $other ) ) );
		self::factory()->post->create( array( 'post_category' => array( $child, $excluded ) ) );

		update_option(
			'wellactually_settings',
			array_merge( WellActually_Settings::get_settings(), array( 'excluded_categories' => array( $excluded ) ) )
		);

		$method = new ReflectionMethod( 'WellActually_Bulk_Setup', 'build_query' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$query = $method->invoke(
			WellActually_Bulk_Setup::instance(),
			array(
				'status'  => 'needs_setup',
				'done'    => array(),
				'cat'     => $parent,
				'author'  => 0,
				'order'   => 'DESC',
				'orderby' => 'date',
				'paged'   => 1,
			)
		);

		$this->assertSame( 1, $query->found_posts );
		$this->assertSame( array( $included_post ), wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * An invalid category must produce an empty screen instead of passing a
	 * WP_Error from get_term_children() into array_merge().
	 */
	public function test_needs_setup_screen_handles_invalid_category() {
		self::factory()->post->create();

		$method = new ReflectionMethod( 'WellActually_Bulk_Setup', 'build_query' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$query = $method->invoke(
			WellActually_Bulk_Setup::instance(),
			array(
				'status'  => 'needs_setup',
				'done'    => array(),
				'cat'     => 999999,
				'author'  => 0,
				'order'   => 'DESC',
				'orderby' => 'date',
				'paged'   => 1,
			)
		);

		$this->assertSame( 0, $query->found_posts );
		$this->assertSame( 0, $query->post_count );
		$this->assertSame( array(), $query->posts );
	}

	/**
	 * The author filter must apply to both the authoritative Needs Setup SQL
	 * path and the normal status-indexed views.
	 */
	public function test_setup_screen_filters_every_status_by_author() {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );

		$needs_a = self::factory()->post->create( array( 'post_author' => $author_a ) );
		self::factory()->post->create( array( 'post_author' => $author_b ) );

		$configured_a = self::factory()->post->create( array( 'post_author' => $author_a ) );
		$configured_b = self::factory()->post->create( array( 'post_author' => $author_b ) );
		WellActually_Meta::apply_meta( $configured_a, 'Configured by A', 'true' );
		WellActually_Meta::apply_meta( $configured_b, 'Configured by B', 'false' );

		$method = new ReflectionMethod( 'WellActually_Bulk_Setup', 'build_query' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$args = array(
			'status'  => 'needs_setup',
			'done'    => array(),
			'cat'     => 0,
			'author'  => $author_a,
			'order'   => 'DESC',
			'orderby' => 'date',
			'paged'   => 1,
		);

		$needs_query = $method->invoke( WellActually_Bulk_Setup::instance(), $args );
		$this->assertSame( array( $needs_a ), wp_list_pluck( $needs_query->posts, 'ID' ) );
		$this->assertSame( 1, $needs_query->found_posts );

		$args['status'] = 'in_deck';
		$args['author'] = $author_b;
		$deck_query     = $method->invoke( WellActually_Bulk_Setup::instance(), $args );

		$this->assertSame( array( $configured_b ), wp_list_pluck( $deck_query->posts, 'ID' ) );
		$this->assertSame( 1, $deck_query->found_posts );
	}

	/**
	 * Popularity sorting must order the entire matching set before pagination
	 * in both the authoritative Needs Setup path and normal status views.
	 */
	public function test_setup_screen_sorts_all_matching_posts_by_jetpack_views() {
		$needs_low  = self::factory()->post->create();
		$needs_high = self::factory()->post->create();
		$needs_mid  = self::factory()->post->create();

		$deck_low  = self::factory()->post->create();
		$deck_high = self::factory()->post->create();
		$deck_mid  = self::factory()->post->create();
		WellActually_Meta::apply_meta( $deck_low, 'Low-view configured post', 'true' );
		WellActually_Meta::apply_meta( $deck_high, 'High-view configured post', 'false' );
		WellActually_Meta::apply_meta( $deck_mid, 'Mid-view configured post', 'debatable' );

		$views  = array(
			$needs_low  => 10,
			$needs_high => 3000,
			$needs_mid  => 200,
			$deck_low   => 1,
			$deck_high  => 9000,
			$deck_mid   => 500,
		);
		$filter = static function () use ( $views ) {
			return $views;
		};
		add_filter( 'wellactually_jetpack_views_30_days', $filter );

		$method = new ReflectionMethod( 'WellActually_Bulk_Setup', 'build_query' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$args = array(
			'status'  => 'needs_setup',
			'done'    => array(),
			'cat'     => 0,
			'author'  => 0,
			'order'   => 'DESC',
			'orderby' => 'views_30',
			'paged'   => 1,
		);

		try {
			$needs_query = $method->invoke( WellActually_Bulk_Setup::instance(), $args );
			$this->assertSame( array( $needs_high, $needs_mid, $needs_low ), wp_list_pluck( $needs_query->posts, 'ID' ) );

			$args['status'] = 'in_deck';
			$deck_query     = $method->invoke( WellActually_Bulk_Setup::instance(), $args );
			$this->assertSame( array( $deck_high, $deck_mid, $deck_low ), wp_list_pluck( $deck_query->posts, 'ID' ) );

			$args['order'] = 'ASC';
			$deck_query    = $method->invoke( WellActually_Bulk_Setup::instance(), $args );
			$this->assertSame( array( $deck_low, $deck_mid, $deck_high ), wp_list_pluck( $deck_query->posts, 'ID' ) );
		} finally {
			remove_filter( 'wellactually_jetpack_views_30_days', $filter );
		}
	}

	/**
	 * The grid exposes both page-wide controls and the requested post details.
	 */
	public function test_setup_grid_renders_bulk_controls_author_and_views() {
		$author  = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Brent Ozar',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_date'   => '2016-12-14 12:00:00',
				'post_title'  => 'A post that needs setup',
			)
		);
		$zero_id = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_title'  => 'A post with zero views',
			)
		);

		$filter = static function () use ( $post_id ) {
			return array( $post_id => 2500 );
		};
		add_filter( 'wellactually_jetpack_views_30_days', $filter );

		$query  = new WP_Query(
			array(
				'post_type'      => 'post',
				'post__in'       => array( $post_id, $zero_id ),
				'posts_per_page' => 2,
			)
		);
		$method = new ReflectionMethod( 'WellActually_Bulk_Setup', 'render_form' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$args = array(
			'status'  => 'needs_setup',
			'done'    => array(),
			'cat'     => 0,
			'author'  => 0,
			'order'   => 'DESC',
			'orderby' => 'date',
			'paged'   => 1,
		);

		try {
			ob_start();
			$method->invoke( WellActually_Bulk_Setup::instance(), $args, $query );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'wa-check-all-skip', $html );
			$this->assertStringContainsString( 'wa-check-all-exclude', $html );
			$this->assertStringContainsString( 'aria-label="Select Skip for Now for all posts on this page"', $html );
			$this->assertStringContainsString( 'aria-label="Select Never for all posts on this page"', $html );
			$this->assertStringContainsString( 'Views: 30 days', $html );
			$this->assertStringContainsString( '2.5K', $html );
			$this->assertMatchesRegularExpression( '/A post with zero views.*?wa-col-views.*?Views in the last 30 days:.*?0/s', $html );
			$this->assertStringNotContainsString( 'No stats', $html );
			$this->assertStringContainsString( 'December 14, 2016 - Brent Ozar', $html );
		} finally {
			remove_filter( 'wellactually_jetpack_views_30_days', $filter );
			wp_reset_postdata();
		}
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
