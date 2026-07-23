<?php
/**
 * AI drafting behaviour that doesn't require calling a provider.
 *
 * @package WellActually
 */

class Test_WA_Drafting extends WP_UnitTestCase {

	/**
	 * Reach a private static method for testing.
	 *
	 * setAccessible() is required on PHP < 8.1 and deprecated from 8.5, and
	 * this plugin supports 7.4, so it's called only where it's needed.
	 *
	 * @param string $class  Class name.
	 * @param string $method Method name.
	 * @return ReflectionMethod
	 */
	private function private_method( $class, $method ) {
		$reflected = new ReflectionMethod( $class, $method );
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
		$wpdb->query( 'TRUNCATE ' . WA_AI_Queue::table_name() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A provider saying "slow down" must be told apart from a problem with
	 * the post, because the two are handled in opposite ways: one stops the
	 * whole run, the other is recorded against that post and moves on.
	 */
	public function test_rate_limit_detection() {
		$method = $this->private_method( 'WA_AI', 'is_rate_limit_error' );

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
		$method = $this->private_method( 'WA_AI', 'system_instruction' );

		$settings = WA_Settings::get_settings();

		// Default when unset.
		update_option( 'wa_settings', array_merge( $settings, array( 'ai_system_prompt' => '' ) ) );
		$this->assertStringContainsString( 'swipe statements', $method->invoke( null ) );
		$this->assertStringContainsString( WA_AI::response_contract(), $method->invoke( null ) );

		// A complete rewrite still carries the contract.
		update_option( 'wa_settings', array_merge( $settings, array( 'ai_system_prompt' => 'Write like a grumpy DBA.' ) ) );
		$instruction = $method->invoke( null );

		$this->assertStringContainsString( 'grumpy DBA', $instruction );
		$this->assertStringNotContainsString( 'swipe statements', $instruction );
		$this->assertStringContainsString( WA_AI::response_contract(), $instruction );
	}

	/**
	 * Posts that failed to draft previously must remain eligible — a past
	 * failure says nothing about whether it can be drafted now.
	 */
	public function test_previously_errored_posts_stay_eligible() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, WA_Meta::AI_STATUS_KEY, 'error' );
		update_post_meta( $post_id, WA_Meta::AI_ERROR_KEY, 'an earlier failure' );
		WA_Meta::recompute_status( $post_id, array( 'ai_status' => 'error' ) );

		$this->assertContains( $post_id, WA_AI::select_candidates( 100 ) );
	}

	/**
	 * Posts already set up, excluded, or set aside are not offered to
	 * drafting.
	 */
	public function test_candidates_exclude_resolved_posts() {
		$needs_setup = self::factory()->post->create();

		$configured = self::factory()->post->create();
		WA_Meta::apply_meta( $configured, 'Already written', 'true' );

		$excluded = self::factory()->post->create();
		WA_Meta::apply_meta( $excluded, '', WA_Meta::VERDICT_EXCLUDED );

		$skipped = self::factory()->post->create();
		update_post_meta( $skipped, WA_Meta::SKIP_KEY, '1' );
		WA_Meta::recompute_status( $skipped, array( 'skipped' => true ) );

		$candidates = WA_AI::select_candidates( 100 );

		$this->assertContains( $needs_setup, $candidates );
		$this->assertNotContains( $configured, $candidates );
		$this->assertNotContains( $excluded, $candidates );
		$this->assertNotContains( $skipped, $candidates );
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
		WA_AI_Queue::admit( $stale, WA_AI_Queue::new_batch_id() );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/ai/enqueue' );
		$request->set_param( 'count', 10 );
		$request->set_param( 'cat', 0 );
		$request->set_param( 'batch', '' );

		$data = WA_AI::instance()->handle_enqueue( $request )->get_data();

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

		$data = WA_AI::instance()->handle_enqueue( $request )->get_data();

		$this->assertGreaterThan( 0, $data['queued'] );
		$this->assertLessThanOrEqual( 50, $data['queued'] );
	}
}
