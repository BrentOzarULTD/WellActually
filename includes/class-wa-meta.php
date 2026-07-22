<?php
/**
 * Post meta box: Swipe Statement + verdict. Admin column and filter.
 *
 * Implemented in full in issue #3.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the swipe statement/verdict meta box and admin list column.
 */
class WA_Meta {

	/**
	 * Singleton instance.
	 *
	 * @var WA_Meta|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_Meta
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
		// Meta box, save handler, admin column, and filter are registered here in issue #3.
	}
}
