<?php
/**
 * Aggregate stats: custom table tallying agree/disagree/unsure per post.
 *
 * Implemented in full in issue #4.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the wa_stats table and tally API.
 */
class WA_Stats {

	/**
	 * Singleton instance.
	 *
	 * @var WA_Stats|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_Stats
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
		// Table creation on wa_activate, record()/get() API, and admin column
		// are wired up here in issue #4.
	}
}
