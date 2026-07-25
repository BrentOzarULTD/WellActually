<?php
/**
 * AI drafting behaviour that doesn't require calling a provider.
 *
 * @package WellActually
 */

/**
 * Covers candidate selection, prompt assembly, and rate-limit handling.
 */
class Test_WellActually_Drafting extends WP_UnitTestCase {

	/**
	 * Reach a private static method for testing.
	 *
	 * SetAccessible() is required on PHP < 8.1 and deprecated from 8.5, and
	 * this plugin supports 7.4, so it's called only where it's needed.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return ReflectionMethod
	 */
	private function private_method( $class_name, $method ) {
		$reflected = new ReflectionMethod( $class_name, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflected->setAccessible( true );
		}
		return $reflected;
	}

	/**
	 * Start from an empty queue.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'TRUNCATE ' . WellActually_AI_Queue::table_name() ); // phpcs:ignore WordPress.DB
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A provider saying "slow down" must be told apart from a problem with
	 * the post, because the two are handled in opposite ways: one stops the
	 * whole run, the other is recorded against that post and moves on.
	 */
	public function test_rate_limit_detection() {
		$method = $this->private_method( 'WellActually_AI', 'is_rate_limit_error' );

		$rate_limited = array(
			'Too Many Requests (429) - Rate limit exceeded. Please try again later.',
			'429 Client Error',
			'You have exceeded your rate_limit',
			'RateLimit reached for model',
		);

		foreach ( $rate_limited as $message ) {
			$this->assertTrue( $method->invoke( null, $message ), "Should be treated as a rate limit: {$message}" );
		}

		$not_rate_limited = array(
			'Invalid API key',
			'The AI returned an unexpected response.',
			'No AI provider is selected in settings.',
		);

		foreach ( $not_rate_limited as $message ) {
			$this->assertFalse( $method->invoke( null, $message ), "Should not be treated as a rate limit: {$message}" );
		}
	}

	/**
	 * The response-format instruction is appended rather than being part of
	 * the editable prompt, so a site owner rewriting the wording for their
	 * own voice can't accidentally stop drafts being parsed.
	 */
	public function test_custom_prompt_keeps_the_response_contract() {
		$method = $this->private_method( 'WellActually_AI', 'system_instruction' );

		$settings = WellActually_Settings::get_settings();

		// Default when unset.
		update_option( 'wellactually_settings', array_merge( $settings, array( 'ai_system_prompt' => '' ) ) );
		$this->assertStringContainsString( 'swipe statements', $method->invoke( null ) );
		$this->assertStringContainsString( WellActually_AI::response_contract(), $method->invoke( null ) );

		// A complete rewrite still carries the contract.
		update_option( 'wellactually_settings', array_merge( $settings, array( 'ai_system_prompt' => 'Write like a grumpy DBA.' ) ) );
		$instruction = $method->invoke( null );

		$this->assertStringContainsString( 'grumpy DBA', $instruction );
		$this->assertStringNotContainsString( 'swipe statements', $instruction );
		$this->assertStringContainsString( WellActually_AI::response_contract(), $instruction );
	}

	/**
	 * Posts that failed to draft previously must remain eligible — a past
	 * failure says nothing about whether it can be drafted now.
	 */
	public function test_previously_errored_posts_stay_eligible() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, 'error' );
		update_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY, 'an earlier failure' );
		WellActually_Meta::recompute_status( $post_id, array( 'ai_status' => 'error' ) );

		$this->assertContains( $post_id, WellActually_AI::select_candidates( 100 ) );
	}

	/**
	 * Posts already set up, excluded, or set aside are not offered to
	 * drafting.
	 */
	public function test_candidates_exclude_resolved_posts() {
		$needs_setup = self::factory()->post->create();

		$configured = self::factory()->post->create();
		WellActually_Meta::apply_meta( $configured, 'Already written', 'true' );
		// Simulate a stale/corrupt status index (or an old cached query result)
		// still offering this ID as needs setup.
		update_post_meta( $configured, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$excluded = self::factory()->post->create();
		WellActually_Meta::apply_meta( $excluded, '', WellActually_Meta::VERDICT_EXCLUDED );
		update_post_meta( $excluded, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );

		$skipped = self::factory()->post->create();
		update_post_meta( $skipped, WellActually_Meta::SKIP_KEY, '1' );
		WellActually_Meta::recompute_status( $skipped, array( 'skipped' => true ) );

		$candidates = WellActually_AI::select_candidates( 100 );

		$this->assertContains( $needs_setup, $candidates );
		$this->assertNotContains( $configured, $candidates );
		$this->assertNotContains( $excluded, $candidates );
		$this->assertNotContains( $skipped, $candidates );
	}

	/**
	 * Candidate selection must continue into later bounded windows when the
	 * newest window contains only stale-index phantoms.
	 */
	public function test_candidates_scan_past_a_full_window_of_phantoms() {
		$base = strtotime( '2025-01-01 00:00:00' );

		$eligible = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$eligible[] = self::factory()->post->create(
				array( 'post_date' => gmdate( 'Y-m-d H:i:s', $base + ( $i * MINUTE_IN_SECONDS ) ) )
			);
		}

		// Newer than every eligible post and deeper than the 100-ID minimum
		// scan window. The status index lies; the verdict is authoritative.
		for ( $i = 0; $i < 110; $i++ ) {
			$post_id = self::factory()->post->create(
				array( 'post_date' => gmdate( 'Y-m-d H:i:s', $base + ( ( 20 + $i ) * MINUTE_IN_SECONDS ) ) )
			);
			update_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, 'true' );
			update_post_meta( $post_id, WellActually_Meta::STATUS_KEY, WellActually_Meta::STATUS_NEEDS_SETUP );
		}

		$this->assertSame( array_reverse( $eligible ), WellActually_AI::select_candidates( 10 ) );
	}

	/**
	 * Asking for N posts should queue N, even when earlier runs are still
	 * holding some of the obvious candidates. This is the "asked for 100,
	 * got 53" regression.
	 */
	public function test_enqueue_fills_the_batch_past_held_posts() {
		$posts = self::factory()->post->create_many( 40 );

		// An earlier run still holds 25 of them.
		$stale = array_slice( $posts, 0, 25 );
		WellActually_AI_Queue::admit( $stale, WellActually_AI_Queue::new_batch_id() );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$request->set_param( 'count', 10 );
		$request->set_param( 'cat', 0 );
		$request->set_param( 'batch', '' );

		$data = WellActually_AI::instance()->handle_enqueue( $request )->get_data();

		$this->assertSame( 10, $data['queued'], 'The batch should be topped up past posts another run holds.' );
		$this->assertEmpty( array_intersect( $data['ids'], $stale ), 'It must not take posts another run is holding.' );
	}

	/**
	 * Asking for more than exists returns what exists, and terminates.
	 */
	public function test_enqueue_stops_when_candidates_run_out() {
		self::factory()->post->create_many( 3 );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$request->set_param( 'count', 50 );
		$request->set_param( 'cat', 0 );
		$request->set_param( 'batch', '' );

		$data = WellActually_AI::instance()->handle_enqueue( $request )->get_data();

		$this->assertGreaterThan( 0, $data['queued'] );
		$this->assertLessThanOrEqual( 50, $data['queued'] );
	}

	/**
	 * Draft with AI must honor the author selected on the setup screen.
	 */
	public function test_enqueue_filters_candidates_by_author() {
		$selected_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author    = self::factory()->user->create( array( 'role' => 'author' ) );

		self::factory()->post->create_many( 3, array( 'post_author' => $selected_author ) );
		self::factory()->post->create_many( 3, array( 'post_author' => $other_author ) );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$request->set_param( 'count', 10 );
		$request->set_param( 'cat', 0 );
		$request->set_param( 'author', $selected_author );
		$request->set_param( 'batch', '' );

		$data = WellActually_AI::instance()->handle_enqueue( $request )->get_data();

		$this->assertSame( 3, $data['queued'] );
		foreach ( $data['ids'] as $post_id ) {
			$this->assertSame( $selected_author, (int) get_post_field( 'post_author', $post_id ) );
		}
	}

	/**
	 * The edit_posts capability is not permission to change every post on the
	 * site. An Author-level caller may draft their own published posts, but
	 * must not queue or mark another author's posts.
	 */
	public function test_enqueue_only_queues_posts_the_caller_can_edit() {
		$caller       = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$own_posts    = self::factory()->post->create_many(
			3,
			array(
				'post_author' => $caller,
				'post_status' => 'publish',
			)
		);
		$other_posts  = self::factory()->post->create_many(
			3,
			array(
				'post_author' => $other_author,
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $caller );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$request->set_param( 'count', 10 );
		$request->set_param( 'cat', 0 );
		$request->set_param( 'batch', '' );

		$data = WellActually_AI::instance()->handle_enqueue( $request )->get_data();

		$this->assertSame( 3, $data['queued'] );
		$this->assertEmpty( array_diff( $data['ids'], $own_posts ) );
		$this->assertEmpty( array_intersect( $data['ids'], $other_posts ) );
		foreach ( $other_posts as $post_id ) {
			$this->assertSame( '', get_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, true ) );
		}
	}

	/**
	 * Processing re-checks the exact post capability instead of treating a
	 * batch ID or the broad edit_posts capability as authorization.
	 */
	public function test_process_rejects_a_post_the_caller_cannot_edit() {
		$owner   = self::factory()->user->create( array( 'role' => 'author' ) );
		$caller  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => 'publish',
			)
		);
		$batch   = WellActually_AI_Queue::new_batch_id();

		WellActually_AI_Queue::admit( array( $post_id ), $batch );
		wp_set_current_user( $caller );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/process' );
		$request->set_param( 'batch', $batch );
		$response = WellActually_AI::instance()->handle_process( $request );

		$this->assertWPError( $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertSame( 1, WellActually_AI_Queue::batch_counts( $batch )['queued'] );
		$this->assertSame( '', get_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, true ) );
	}

	/**
	 * #28: a request must fill from eligible posts however deep in the archive
	 * they are, even when far more than a few pages of the *newest* posts are
	 * held by other live batches. The old fixed round budget could spend its
	 * whole scan among held posts and give up with plenty still eligible.
	 *
	 * The numbers matter: the old code scanned at most 8 rounds of
	 * (still_needed * 2) candidates — 160 for a count of 10 — so the held
	 * block must be deeper than that or this test also passes on the old
	 * code and proves nothing. 170 held posts, all newer than the 10
	 * eligible ones, put the eligible posts past the old scan horizon.
	 * Verified by mutation: restoring the old loop makes this test fail
	 * with 0 queued.
	 */
	public function test_enqueue_fills_past_many_pages_of_held_posts() {
		// Explicit ascending post_date, so "newest first" ordering is
		// deterministic rather than an accident of insert timing.
		$posts = array();
		$base  = strtotime( '2024-01-01 00:00:00' );
		for ( $i = 0; $i < 180; $i++ ) {
			$posts[] = self::factory()->post->create(
				array( 'post_date' => gmdate( 'Y-m-d H:i:s', $base + ( $i * MINUTE_IN_SECONDS ) ) )
			);
		}

		// Hold the newest 170 in another batch, leaving only the 10 oldest
		// eligible — beyond the old 160-candidate scan depth for count=10.
		$held = array_slice( $posts, 10 );
		WellActually_AI_Queue::admit( $held, WellActually_AI_Queue::new_batch_id() );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$request->set_param( 'count', 10 );
		$request->set_param( 'cat', 0 );
		$request->set_param( 'batch', '' );

		$data = WellActually_AI::instance()->handle_enqueue( $request )->get_data();

		$this->assertSame( 10, $data['queued'], 'Every eligible post should be reachable regardless of how many newer ones are held.' );
		$this->assertEmpty( array_intersect( $data['ids'], $held ), 'It must not take posts another run is holding.' );
		$this->assertEmpty( array_diff( $data['ids'], array_slice( $posts, 0, 10 ) ), 'Exactly the 10 unheld posts should have been queued.' );
	}

	/**
	 * #28: when fewer than the requested number of eligible, unclaimed posts
	 * exist, return exactly the number available — no more, and without
	 * looping forever trying to reach the target.
	 */
	public function test_enqueue_returns_exactly_what_is_available() {
		$posts = self::factory()->post->create_many( 5 );

		// Two of the five are already held elsewhere, leaving three eligible.
		WellActually_AI_Queue::admit( array_slice( $posts, 0, 2 ), WellActually_AI_Queue::new_batch_id() );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$request->set_param( 'count', 20 );
		$request->set_param( 'cat', 0 );
		$request->set_param( 'batch', '' );

		$data = WellActually_AI::instance()->handle_enqueue( $request )->get_data();

		$this->assertSame( 3, $data['queued'], 'Only the three genuinely eligible posts should be queued.' );
	}

	/**
	 * #28: two runs must never end up drafting the same post. The second
	 * request has to see the first's posts as unavailable and fill from
	 * elsewhere, exactly the property that keeps a post from being billed
	 * twice.
	 */
	public function test_back_to_back_enqueues_do_not_overlap() {
		self::factory()->post->create_many( 30 );

		$first = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$first->set_param( 'count', 10 );
		$first->set_param( 'cat', 0 );
		$first->set_param( 'batch', '' );
		$first_ids = WellActually_AI::instance()->handle_enqueue( $first )->get_data()['ids'];

		$second = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$second->set_param( 'count', 10 );
		$second->set_param( 'cat', 0 );
		$second->set_param( 'batch', '' );
		$second_ids = WellActually_AI::instance()->handle_enqueue( $second )->get_data()['ids'];

		$this->assertCount( 10, $first_ids );
		$this->assertCount( 10, $second_ids );
		$this->assertEmpty( array_intersect( $first_ids, $second_ids ), 'No post may be queued by two runs at once.' );
	}
}
