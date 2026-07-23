<?php
/**
 * The AI drafting queue.
 *
 * Regression cover for the concurrency defects found in code review
 * (issues #21-#25): a second run stealing posts another run was drafting,
 * a recovery sweep reverting a finished result, runs consuming each other's
 * work, and the concurrency ceiling being ignored server-side.
 *
 * @package WellActually
 */

/**
 * Covers admission, claiming, fencing, and recovery in WA_AI_Queue.
 */
class Test_WA_AI_Queue extends WP_UnitTestCase {

	/**
	 * Start each test from an empty queue.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'TRUNCATE ' . WA_AI_Queue::table_name() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * #21: admission must never disturb a post another run already holds.
	 */
	public function test_admission_cannot_steal_another_batch() {
		$posts = self::factory()->post->create_many( 3 );

		$batch_a = WA_AI_Queue::new_batch_id();
		$batch_b = WA_AI_Queue::new_batch_id();

		$this->assertCount( 3, WA_AI_Queue::admit( $posts, $batch_a ) );

		$claim = WA_AI_Queue::claim( $batch_a, 5 );
		$this->assertIsArray( $claim );

		// Batch B tries to take the same posts while A is drafting one.
		$this->assertSame( array(), WA_AI_Queue::admit( $posts, $batch_b ), 'Posts held by another batch must be refused.' );

		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT batch_id, status, claim_token FROM ' . WA_AI_Queue::table_name() . ' WHERE post_id = %d', $claim['post_id'] )
		);

		$this->assertSame( $batch_a, $row->batch_id );
		$this->assertSame( 'processing', $row->status );
		$this->assertSame( $claim['token'], $row->claim_token, "The original run's claim must be untouched." );
	}

	/**
	 * Each claim hands out a different post, and the batch reports empty
	 * once drained.
	 */
	public function test_claims_are_exclusive() {
		$posts = self::factory()->post->create_many( 3 );
		$batch = WA_AI_Queue::new_batch_id();
		WA_AI_Queue::admit( $posts, $batch );

		$claimed = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$claim = WA_AI_Queue::claim( $batch, 99 );
			$this->assertIsArray( $claim );
			$claimed[] = $claim['post_id'];
		}

		$this->assertSame( $claimed, array_unique( $claimed ), 'No post may be handed out twice.' );
		$this->assertSame( 'empty', WA_AI_Queue::claim( $batch, 99 ) );
	}

	/**
	 * #22: a worker that lost its claim must not be able to finish.
	 */
	public function test_completion_is_fenced_by_claim_token() {
		$post_id = self::factory()->post->create();
		$batch   = WA_AI_Queue::new_batch_id();
		WA_AI_Queue::admit( array( $post_id ), $batch );

		$first = WA_AI_Queue::claim( $batch, 5 );

		// Pretend the claim went stale and was reaped.
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'UPDATE ' . WA_AI_Queue::table_name() . ' SET claimed_at = %d WHERE post_id = %d', time() - 9999, $post_id )
		);
		$this->assertSame( 1, WA_AI_Queue::release_stale( 300 ) );

		$second = WA_AI_Queue::claim( $batch, 5 );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['token'], $second['token'] );

		$this->assertFalse( WA_AI_Queue::complete( $post_id, $first['token'] ), 'The superseded worker must not be able to finish.' );
		$this->assertTrue( WA_AI_Queue::complete( $post_id, $second['token'] ), 'The current owner must be able to finish.' );
	}

	/**
	 * A sweep must not disturb work that finished in the meantime — the row
	 * is already gone, so there is nothing to revert.
	 */
	public function test_stale_sweep_ignores_finished_work() {
		$post_id = self::factory()->post->create();
		$batch   = WA_AI_Queue::new_batch_id();
		WA_AI_Queue::admit( array( $post_id ), $batch );

		$claim = WA_AI_Queue::claim( $batch, 5 );
		$this->assertTrue( WA_AI_Queue::complete( $post_id, $claim['token'] ) );

		$this->assertSame( 0, WA_AI_Queue::release_stale( 0 ) );
		$this->assertSame( 0, WA_AI_Queue::batch_counts( $batch )['remaining'] );
	}

	/**
	 * #23: a run may only claim work belonging to its own batch.
	 */
	public function test_batches_are_isolated() {
		$a_posts = self::factory()->post->create_many( 2 );
		$b_posts = self::factory()->post->create_many( 2 );

		$batch_a = WA_AI_Queue::new_batch_id();
		$batch_b = WA_AI_Queue::new_batch_id();
		WA_AI_Queue::admit( $a_posts, $batch_a );
		WA_AI_Queue::admit( $b_posts, $batch_b );

		$claimed = array();
		while ( is_array( $claim = WA_AI_Queue::claim( $batch_a, 99 ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$claimed[] = $claim['post_id'];
			WA_AI_Queue::complete( $claim['post_id'], $claim['token'] );
		}

		sort( $claimed );
		sort( $a_posts );
		$this->assertSame( $a_posts, $claimed, 'A batch must drain only its own posts.' );
		$this->assertSame( 2, WA_AI_Queue::batch_counts( $batch_b )['remaining'], 'The other batch must be untouched.' );
	}

	/**
	 * #25: the concurrency ceiling is enforced server-side, not just by one
	 * browser tab.
	 */
	public function test_concurrency_ceiling_is_enforced() {
		$posts = self::factory()->post->create_many( 6 );
		$batch = WA_AI_Queue::new_batch_id();
		WA_AI_Queue::admit( $posts, $batch );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertIsArray( WA_AI_Queue::claim( $batch, 3 ) );
		}

		$this->assertSame( 'at_capacity', WA_AI_Queue::claim( $batch, 3 ), 'A fourth in-flight claim must be refused when the limit is 3.' );

		global $wpdb;
		$processing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			'SELECT COUNT(*) FROM ' . WA_AI_Queue::table_name() . " WHERE status = 'processing'"
		);
		$this->assertSame( 3, $processing );
	}

	/**
	 * Releasing hands a post back without consuming it — used when the
	 * provider rate-limits us, which says nothing about the post.
	 */
	public function test_release_returns_work_to_the_queue() {
		$post_id = self::factory()->post->create();
		$batch   = WA_AI_Queue::new_batch_id();
		WA_AI_Queue::admit( array( $post_id ), $batch );

		$claim = WA_AI_Queue::claim( $batch, 5 );
		$this->assertTrue( WA_AI_Queue::release( $post_id, $claim['token'] ) );

		$counts = WA_AI_Queue::batch_counts( $batch );
		$this->assertSame( 1, $counts['queued'] );
		$this->assertSame( 0, $counts['processing'] );

		// And it can be claimed again.
		$this->assertIsArray( WA_AI_Queue::claim( $batch, 5 ) );
	}

	/**
	 * Abandoned, never-started work is collected so it stops blocking those
	 * posts from entering a later run.
	 */
	public function test_abandoned_queued_work_is_collected() {
		$post_id = self::factory()->post->create();
		$batch   = WA_AI_Queue::new_batch_id();
		WA_AI_Queue::admit( array( $post_id ), $batch );

		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'UPDATE ' . WA_AI_Queue::table_name() . ' SET created_at = %d WHERE post_id = %d', time() - 9999, $post_id )
		);

		$this->assertSame( 1, WA_AI_Queue::collect_abandoned( 900 ) );
		$this->assertSame( 0, WA_AI_Queue::batch_counts( $batch )['remaining'] );
	}
}
