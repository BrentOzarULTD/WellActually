<?php
/**
 * Rewrite rule + full-screen /swipe template takeover.
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

	const QUERY_VAR = 'wa_swipe';

	/**
	 * REST namespace handed to the frontend. The JS joins routes onto this,
	 * so it must not carry a trailing slash of its own.
	 */
	const NAMESPACE_ROUTE = 'well-actually/v1';

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
		add_action( 'init', array( $this, 'register_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_include', array( $this, 'maybe_take_over_template' ) );
		add_filter( 'wp_robots', array( $this, 'maybe_noindex' ) );
	}

	/**
	 * Register the rewrite rule for the swipe page slug.
	 *
	 * Called on init and whenever the slug setting changes (and once
	 * during plugin activation before the initial flush).
	 */
	public function register_rewrite_rule() {
		$slug = wa_get_setting( 'slug', 'swipe' );

		add_rewrite_rule(
			'^' . preg_quote( $slug, '#' ) . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Register our custom query var.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Check whether the current request is the swipe page.
	 *
	 * @return bool
	 */
	public static function is_swipe_page() {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Take over the template when the swipe query var is set.
	 *
	 * @param string $template Template path WordPress intended to load.
	 * @return string
	 */
	public function maybe_take_over_template( $template ) {
		if ( ! self::is_swipe_page() ) {
			return $template;
		}

		status_header( 200 );

		return WA_PLUGIN_DIR . 'templates/swipe-page.php';
	}

	/**
	 * Mark the swipe page as noindex.
	 *
	 * @param array $robots Existing robots directives.
	 * @return array
	 */
	public function maybe_noindex( $robots ) {
		if ( self::is_swipe_page() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * Register the swipe page's assets and their inline config.
	 *
	 * Called directly by the template (not hooked to wp_enqueue_scripts,
	 * since the template intentionally skips wp_head()/wp_footer() to
	 * avoid theme asset bleed).
	 */
	private function register_assets() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		wp_register_style( 'wa-swipe', WA_PLUGIN_URL . 'assets/css/swipe.css', array(), WA_VERSION );
		wp_register_script(
			'wa-swipe',
			WA_PLUGIN_URL . 'assets/js/swipe.js',
			array(),
			WA_VERSION,
			array( 'in_footer' => true )
		);

		wp_localize_script(
			'wa-swipe',
			'waSwipe',
			array(
				'restUrl'    => esc_url_raw( rest_url( self::NAMESPACE_ROUTE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn' => is_user_logged_in(),
				'homeUrl'    => esc_url_raw( home_url( '/' ) ),
				'siteName'   => get_bloginfo( 'name' ),
			)
		);
	}

	/**
	 * Print the stylesheet. Called from the template's <head>.
	 */
	public function print_head_assets() {
		$this->register_assets();
		wp_print_styles( array( 'wa-swipe' ) );
	}

	/**
	 * Print the script. Called from the template just before </body> — the
	 * script queries #wa-app on load, so it must not run before the body
	 * has been parsed.
	 */
	public function print_footer_assets() {
		$this->register_assets();
		wp_print_scripts( array( 'wa-swipe' ) );
	}
}
