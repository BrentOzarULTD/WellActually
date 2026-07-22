<?php
/**
 * Logged-in user progress sync (GET/PUT /progress).
 *
 * Implemented in full in issue #12.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles reading/writing the _wa_progress user meta over REST.
 */
class WA_User_Progress {

	/**
	 * Singleton instance.
	 *
	 * @var WA_User_Progress|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_User_Progress
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// /progress REST routes are registered here in issue #12.
	}
}
