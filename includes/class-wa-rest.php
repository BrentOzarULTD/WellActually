<?php
/**
 * REST API: well-actually/v1 namespace.
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

	const NAMESPACE_NAME  = 'well-actually/v1';
	const BATCH_SIZE      = 20;
	const MAX_ID_LIST_LEN = 5000;
	const TOTAL_TRANSIENT = 'wa_eligible_total';

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
		add_action( 'save_post', array( $this, 'invalidate_total_cache' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			'/deck',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_deck' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'exclude' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'include' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Shared WP_Query args for posts eligible for the swipe deck:
	 * published posts with both a statement and a valid verdict set.
	 *
	 * @return array
	 */
	public static function eligible_query_args() {
		return array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => WA_Meta::STATEMENT_KEY,
					'value'   => '',
					'compare' => '!=',
				),
				array(
					'key'     => WA_Meta::VERDICT_KEY,
					'value'   => array( 'true', 'false', 'debatable' ),
					'compare' => 'IN',
				),
			),
		);
	}

	/**
	 * Parse a comma-separated list of post IDs from a query param.
	 *
	 * @param string $raw Raw comma-separated string.
	 * @return int[]
	 */
	private static function parse_id_list( $raw ) {
		if ( '' === $raw ) {
			return array();
		}

		$parts = explode( ',', $raw );
		$parts = array_slice( $parts, 0, self::MAX_ID_LIST_LEN );
		$ids   = array_map( 'absint', $parts );
		$ids   = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Get the total count of eligible deck posts, cached for 5 minutes.
	 *
	 * @return int
	 */
	private static function get_eligible_total() {
		$cached = get_transient( self::TOTAL_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$args                  = self::eligible_query_args();
		$args['no_found_rows'] = false;
		$args['posts_per_page'] = 1;

		$query = new WP_Query( $args );
		$total = (int) $query->found_posts;

		set_transient( self::TOTAL_TRANSIENT, $total, 5 * MINUTE_IN_SECONDS );

		return $total;
	}

	/**
	 * Invalidate the cached eligible-post total when a post is saved.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public function invalidate_total_cache( $post_id ) {
		unset( $post_id );
		delete_transient( self::TOTAL_TRANSIENT );
	}

	/**
	 * GET /deck handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_get_deck( WP_REST_Request $request ) {
		$exclude = self::parse_id_list( $request->get_param( 'exclude' ) );
		$include = self::parse_id_list( $request->get_param( 'include' ) );

		$args = self::eligible_query_args();

		if ( ! empty( $include ) ) {
			$args['post__in'] = $include;
		} elseif ( ! empty( $exclude ) ) {
			$args['post__not_in'] = $exclude;
		}

		$args['orderby'] = 'rand';
		$args['posts_per_page'] = self::BATCH_SIZE;

		$query = new WP_Query( $args );
		$ids   = $query->posts;

		$cards = array();
		foreach ( $ids as $post_id ) {
			$statement = get_post_meta( $post_id, WA_Meta::STATEMENT_KEY, true );
			if ( '' === $statement ) {
				continue;
			}
			$cards[] = array(
				'id'        => (int) $post_id,
				'statement' => $statement,
			);
		}

		$total = ! empty( $include ) ? count( $include ) : self::get_eligible_total();

		return new WP_REST_Response(
			array(
				'total' => $total,
				'cards' => $cards,
			),
			200
		);
	}
}
