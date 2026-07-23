<?php
/**
 * Logged-in user progress sync (GET/PUT /progress).
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles reading/writing the _wa_progress user meta over REST.
 */
class WellActually_User_Progress {

	const META_KEY       = '_wellactually_progress';
	const MAX_SEEN       = 10000;
	const NAMESPACE_NAME = 'wellactually/v1';

	/**
	 * Singleton instance.
	 *
	 * @var WellActually_User_Progress|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WellActually_User_Progress
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
	 * A fresh, empty progress object.
	 *
	 * @return array
	 */
	public static function fresh_progress() {
		return array(
			'seen'           => array(),
			'wrong'          => array(),
			'correct_count'  => 0,
			'answered_count' => 0,
		);
	}

	/**
	 * Register the /progress REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			'/progress',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_progress' ),
					'permission_callback' => array( $this, 'require_login' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'handle_put_progress' ),
					'permission_callback' => array( $this, 'require_login' ),
					'args'                => array(
						'seen'           => array( 'required' => true ),
						'wrong'          => array( 'required' => true ),
						'correct_count'  => array( 'required' => false ),
						'answered_count' => array( 'required' => false ),
					),
				),
			)
		);
	}

	/**
	 * Permission callback: must be logged in.
	 *
	 * @return bool
	 */
	public function require_login() {
		return is_user_logged_in();
	}

	/**
	 * GET /progress handler.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get_progress() {
		$progress = get_user_meta( get_current_user_id(), self::META_KEY, true );

		if ( ! is_array( $progress ) ) {
			$progress = self::fresh_progress();
		} else {
			$progress = self::sanitize_progress( $progress );
		}

		$response = new WP_REST_Response( $progress, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * PUT /progress handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_put_progress( WP_REST_Request $request ) {
		if ( ! WellActually_Rest::check_rate_limit( 'progress_put', 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'wellactually_rate_limited', __( 'Too many updates, slow down.', 'wellactually' ), array( 'status' => 429 ) );
		}

		$params   = $request->get_json_params();
		$progress = self::sanitize_progress( is_array( $params ) ? $params : array() );

		update_user_meta( get_current_user_id(), self::META_KEY, $progress );

		$response = new WP_REST_Response( $progress, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Strictly validate/normalize a progress payload. Counts are always
	 * recomputed from the arrays rather than trusted from the client.
	 *
	 * @param array $raw Raw progress data.
	 * @return array
	 */
	public static function sanitize_progress( $raw ) {
		$seen  = isset( $raw['seen'] ) && is_array( $raw['seen'] ) ? $raw['seen'] : array();
		$wrong = isset( $raw['wrong'] ) && is_array( $raw['wrong'] ) ? $raw['wrong'] : array();

		$seen = self::sanitize_id_list( $seen, self::MAX_SEEN );

		$seen_lookup = array_flip( $seen );
		$wrong       = self::sanitize_id_list( $wrong, self::MAX_SEEN );
		$wrong       = array_values(
			array_filter(
				$wrong,
				function ( $id ) use ( $seen_lookup ) {
					return isset( $seen_lookup[ $id ] );
				}
			)
		);

		return array(
			'seen'           => $seen,
			'wrong'          => $wrong,
			'answered_count' => count( $seen ),
			'correct_count'  => max( 0, count( $seen ) - count( $wrong ) ),
		);
	}

	/**
	 * Sanitize a list of post IDs: positive ints only, deduped, capped.
	 *
	 * @param array $list Raw list.
	 * @param int   $cap  Maximum entries to keep.
	 * @return int[]
	 */
	private static function sanitize_id_list( $list, $cap ) {
		$ids = array();
		foreach ( $list as $value ) {
			$int = absint( $value );
			if ( $int > 0 ) {
				$ids[ $int ] = true;
			}
		}

		$ids = array_keys( $ids );
		return array_slice( $ids, 0, $cap );
	}
}
