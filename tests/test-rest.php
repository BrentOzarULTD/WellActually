<?php
/**
 * REST contracts the front end and admin depend on.
 *
 * @package WellActually
 */

/**
 * Covers the deck, swipe scoring, and Quick Edit REST routes.
 */
class Test_WellActually_Rest extends WP_UnitTestCase {

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
	 * Ensure routes are registered for each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		WellActually_Rest::clear_total_cache();
	}

	/**
	 * Create a post that's eligible for the deck.
	 *
	 * @param string $verdict   Verdict to store.
	 * @param string $statement Statement text.
	 * @return int
	 */
	private function make_card( $verdict = 'true', $statement = 'A statement' ) {
		$post_id = self::factory()->post->create();
		WellActually_Meta::apply_meta( $post_id, $statement, $verdict );
		return $post_id;
	}

	/**
	 * The deck must never disclose the verdict — that is the answer the
	 * player is about to guess.
	 */
	public function test_deck_never_exposes_the_answer() {
		$this->make_card( 'true', 'Temp tables are faster than CTEs' );
		$this->make_card( 'false', 'NOLOCK makes queries safe' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wellactually/v1/deck' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data['cards'] );

		foreach ( $data['cards'] as $card ) {
			$this->assertArrayNotHasKey( 'verdict', $card );
			$this->assertArrayNotHasKey( 'pct_agreed', $card );
		}

		// Nor anywhere else in the serialized payload.
		$this->assertStringNotContainsString( '"verdict"', wp_json_encode( $data ) );
	}

	/**
	 * "% got this wrong" counts an unsure as wrong, is withheld below the
	 * sample threshold, and is never shown for a debatable card — where it
	 * would always be 0% and so give the verdict away.
	 */
	public function test_pct_wrong_rules() {
		$method = $this->private_method( 'WellActually_Rest', 'pct_wrong' );

		// true verdict: wrong = disagree + unsure = 9 of 12.
		$this->assertSame(
			75,
			$method->invoke(
				null,
				'true',
				array(
					'agree'    => 3,
					'disagree' => 8,
					'unsure'   => 1,
					'total'    => 12,
				)
			)
		);

		// false verdict: wrong = agree + unsure = 10 of 12.
		$this->assertSame(
			83,
			$method->invoke(
				null,
				'false',
				array(
					'agree'    => 9,
					'disagree' => 2,
					'unsure'   => 1,
					'total'    => 12,
				)
			)
		);

		$this->assertNull(
			$method->invoke(
				null,
				'debatable',
				array(
					'agree'    => 9,
					'disagree' => 2,
					'unsure'   => 1,
					'total'    => 12,
				)
			),
			'A debatable card has no wrong answer; showing 0% would reveal the verdict.'
		);

		$this->assertNull(
			$method->invoke(
				null,
				'true',
				array(
					'agree'    => 1,
					'disagree' => 3,
					'unsure'   => 0,
					'total'    => 4,
				)
			),
			'Below the sample threshold no figure should be shown.'
		);

		$this->assertNull( $method->invoke( null, 'true', null ) );
	}

	/**
	 * Scoring: agree is right on a true card, disagree on a false one, and
	 * anything goes on a debatable one.
	 */
	public function test_swipe_scoring() {
		$cases = array(
			array( 'true', 'agree', true ),
			array( 'true', 'disagree', false ),
			array( 'true', 'unsure', false ),
			array( 'false', 'disagree', true ),
			array( 'false', 'agree', false ),
			array( 'debatable', 'agree', true ),
			array( 'debatable', 'disagree', true ),
			array( 'debatable', 'unsure', true ),
		);

		foreach ( $cases as list( $verdict, $answer, $expected ) ) {
			$post_id = $this->make_card( $verdict );

			$request = new WP_REST_Request( 'POST', '/wellactually/v1/swipe' );
			$request->set_body_params(
				array(
					'post_id' => $post_id,
					'answer'  => $answer,
				)
			);

			$data = rest_do_request( $request )->get_data();

			$this->assertSame(
				$expected,
				$data['correct'],
				"Answering {$answer} on a {$verdict} card should be " . ( $expected ? 'correct' : 'incorrect' )
			);
		}
	}

	/**
	 * Quick Edit writes post data, so it must refuse anonymous callers.
	 */
	public function test_quick_edit_requires_permission() {
		$post_id = $this->make_card( 'true', 'Original headline' );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/report/quick-edit' );
		$request->set_body_params(
			array(
				'post_id'   => $post_id,
				'statement' => 'Rewritten by a stranger',
				'verdict'   => 'false',
			)
		);

		$this->assertSame( 401, rest_do_request( $request )->get_status() );
		$this->assertSame( 'Original headline', get_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, true ) );
	}

	/**
	 * An editor can save, and invalid input is rejected rather than stored.
	 */
	public function test_quick_edit_saves_and_validates() {
		$post_id = $this->make_card( 'true', 'Original headline' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/wellactually/v1/report/quick-edit' );
		$request->set_body_params(
			array(
				'post_id'   => $post_id,
				'statement' => 'Edited headline',
				'verdict'   => 'debatable',
			)
		);

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'Edited headline', get_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, true ) );
		$this->assertSame( 'debatable', get_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, true ) );

		// A verdict outside the allowed set must not be stored.
		$bad = new WP_REST_Request( 'POST', '/wellactually/v1/report/quick-edit' );
		$bad->set_body_params(
			array(
				'post_id'   => $post_id,
				'statement' => 'Edited headline',
				'verdict'   => 'excellent',
			)
		);

		$this->assertSame( 400, rest_do_request( $bad )->get_status() );
		$this->assertSame( 'debatable', get_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, true ) );
	}

	/**
	 * Skipped posts stay out of the deck.
	 */
	public function test_skipped_posts_are_not_dealt() {
		$kept    = $this->make_card( 'true', 'Kept in the deck' );
		$skipped = $this->make_card( 'true', 'Set aside for now' );
		update_post_meta( $skipped, WellActually_Meta::SKIP_KEY, '1' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wellactually/v1/deck' ) )->get_data();
		$ids  = wp_list_pluck( $data['cards'], 'id' );

		$this->assertContains( $kept, $ids );
		$this->assertNotContains( $skipped, $ids );
	}
}
