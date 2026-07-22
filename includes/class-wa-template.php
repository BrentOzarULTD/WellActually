<?php
/**
 * Rewrite rule + full-screen /swipe template takeover.
 *
 * Implemented in full in issue #5.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the swipe page rewrite rule and template takeover.
 */
class WA_Template {

	/**
	 * Singleton instance.
	 *
	 * @var WA_Template|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_Template
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
		// Rewrite rule registration and template_include takeover are wired
		// up here in issue #5.
	}

	/**
	 * Register the rewrite rule for the swipe page slug.
	 *
	 * Called on activation and whenever the slug setting changes.
	 * Full implementation lands in issue #5; stubbed now so
	 * well-actually.php and WA_Settings can call it safely.
	 */
	public function register_rewrite_rule() {
		// Implemented in issue #5.
	}
}
