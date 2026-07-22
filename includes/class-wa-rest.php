<?php
/**
 * REST API: well-actually/v1 namespace.
 *
 * GET /deck implemented in issue #6, POST /swipe in issue #7.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the plugin's REST endpoints.
 */
class WA_Rest {

	const NAMESPACE_NAME = 'well-actually/v1';

	/**
	 * Singleton instance.
	 *
	 * @var WA_Rest|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_Rest
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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes. Filled in by issues #6 and #7.
	 */
	public function register_routes() {
		// GET /deck and POST /swipe are registered here.
	}
}
