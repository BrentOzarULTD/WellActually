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
}
