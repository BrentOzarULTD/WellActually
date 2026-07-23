<?php
/**
 * The privacy-tools integration.
 *
 * Regression cover for issue #32: WordPress's export tool must include a
 * user's stored swipe progress, and the erasure tool must remove it without
 * touching anything else.
 *
 * @package WellActually
 */

/**
 * Covers the exporter, eraser, and their registration.
 */
class Test_WA_Privacy extends WP_UnitTestCase {

	/**
	 * A progress blob as the plugin stores it.
	 *
	 * @return array
	 */
	private function sample_progress() {
		return array(
			'seen'           => array( 11, 12, 13 ),
			'wrong'          => array( 12 ),
			'correct_count'  => 2,
			'answered_count' => 3,
		);
	}

	/**
	 * The suggested policy text must actually register with core (which only
	 * accepts it in admin context) and cover each kind of data we handle.
	 */
	public function test_policy_text_registers_and_covers_the_data() {
		set_current_screen( 'dashboard' );
		$had_admin_init = did_action( 'admin_init' ) > 0;
		try {
			// Core only accepts policy text in admin context on/after
			// admin_init. Firing the whole action here would run unrelated
			// core admin_init callbacks (some send headers, which errors under
			// PHPUnit), so satisfy the did_action() guard directly and call
			// just our own callback.
			if ( ! $had_admin_init ) {
				$GLOBALS['wp_actions']['admin_init'] = 1;
			}
			WA_Privacy::instance()->add_privacy_policy_content();

			$ref = new ReflectionProperty( 'WP_Privacy_Policy_Content', 'policy_content' );
			if ( PHP_VERSION_ID < 80100 ) {
				$ref->setAccessible( true );
			}

			// Core stores a numeric list of {plugin_name, policy_text} pairs.
			$ours = wp_list_pluck( $ref->getValue(), 'policy_text', 'plugin_name' );

			$this->assertArrayHasKey( 'Well, Actually...', $ours );
			$text = $ours['Well, Actually...'];

			foreach ( array( 'local storage', 'personal-data export', 'hashed form', 'AI provider' ) as $needle ) {
				$this->assertStringContainsStringIgnoringCase( $needle, $text, "Policy text must cover: {$needle}" );
			}
		} finally {
			if ( ! $had_admin_init ) {
				unset( $GLOBALS['wp_actions']['admin_init'] );
			}
			set_current_screen( 'front' );
		}
	}

	/**
	 * Both handlers must be registered under the core filters, or the tools
	 * simply never call us.
	 */
	public function test_handlers_are_registered() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'wellactually-progress', $exporters );
		$this->assertArrayHasKey( 'wellactually-progress', $erasers );
		$this->assertIsCallable( $exporters['wellactually-progress']['callback'] );
		$this->assertIsCallable( $erasers['wellactually-progress']['callback'] );
	}

	/**
	 * The export must contain the user's stored progress, keyed to their
	 * email, and finish in one page.
	 */
	public function test_export_includes_stored_progress() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'player@example.org' ) );
		update_user_meta( $user_id, WA_User_Progress::META_KEY, $this->sample_progress() );

		$export = WA_Privacy::instance()->export_progress( 'player@example.org' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );

		$fields = wp_list_pluck( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 3, $fields['Statements answered'] );
		$this->assertSame( 2, $fields['Answered correctly'] );
		$this->assertSame( '11, 12, 13', $fields['Post IDs seen'] );
		$this->assertSame( '12', $fields['Post IDs answered incorrectly'] );
	}

	/**
	 * No stored progress (or no such user) exports nothing rather than an
	 * empty group.
	 */
	public function test_export_is_empty_without_progress() {
		self::factory()->user->create( array( 'user_email' => 'fresh@example.org' ) );

		$this->assertSame( array(), WA_Privacy::instance()->export_progress( 'fresh@example.org' )['data'] );
		$this->assertSame( array(), WA_Privacy::instance()->export_progress( 'nobody@example.org' )['data'] );
	}

	/**
	 * Erasure removes exactly the progress meta — other user meta and other
	 * users' progress survive.
	 */
	public function test_erase_removes_only_this_users_progress() {
		$player = self::factory()->user->create( array( 'user_email' => 'erase-me@example.org' ) );
		$other  = self::factory()->user->create( array( 'user_email' => 'bystander@example.org' ) );

		update_user_meta( $player, WA_User_Progress::META_KEY, $this->sample_progress() );
		update_user_meta( $player, 'unrelated_meta', 'keep me' );
		update_user_meta( $other, WA_User_Progress::META_KEY, $this->sample_progress() );

		$result = WA_Privacy::instance()->erase_progress( 'erase-me@example.org' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertFalse( metadata_exists( 'user', $player, WA_User_Progress::META_KEY ), 'The progress meta must be gone.' );
		$this->assertSame( 'keep me', get_user_meta( $player, 'unrelated_meta', true ), 'Unrelated meta must survive.' );
		$this->assertTrue( metadata_exists( 'user', $other, WA_User_Progress::META_KEY ), "Another user's progress must survive." );

		// A second pass has nothing to remove and says so.
		$this->assertFalse( WA_Privacy::instance()->erase_progress( 'erase-me@example.org' )['items_removed'] );
	}
}
