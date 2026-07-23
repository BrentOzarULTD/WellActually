<?php
/**
 * REST API: wellactually/v1 namespace.
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

	const NAMESPACE_NAME  = 'wellactually/v1';
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

		register_rest_route(
			self::NAMESPACE_NAME,
			'/swipe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_post_swipe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'answer'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'replay'  => array(
						'type'              => 'boolean',
						'default'           => false,
					),
				),
			)
		);
	}

	/**
	 * Check (and increment) a simple per-IP rate limit.
	 *
	 * @param string $bucket    Bucket name, so different endpoints don't share a budget.
	 * @param int    $max_calls Max calls allowed per window.
	 * @param int    $window    Window length in seconds.
	 * @return bool True if within the limit (and the call is now counted), false if over.
	 */
	public static function check_rate_limit( $bucket, $max_calls, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'wa_rl_' . $bucket . '_' . md5( $ip );

		$count = get_transient( $key );
		if ( false === $count ) {
			set_transient( $key, 1, $window );
			return true;
		}

		if ( (int) $count >= $max_calls ) {
			return false;
		}

		set_transient( $key, (int) $count + 1, $window );
		return true;
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
				// "Skip for now" posts keep their content but stay out of the deck.
				array(
					'relation' => 'OR',
					array(
						'key'     => WA_Meta::SKIP_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => WA_Meta::SKIP_KEY,
						'value'   => '1',
						'compare' => '!=',
					),
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
		self::clear_total_cache();
	}

	/**
	 * Clear the cached eligible-post total. Call this after changing swipe
	 * meta outside of a normal post save (e.g. the bulk-setup screen, which
	 * writes meta directly without firing save_post).
	 */
	public static function clear_total_cache() {
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

		// One lookup for the whole batch (see WA_Stats::get_many()).
		$stats = WA_Stats::get_many( $ids );

		$cards = array();
		foreach ( $ids as $post_id ) {
			$statement = get_post_meta( $post_id, WA_Meta::STATEMENT_KEY, true );
			if ( '' === $statement ) {
				continue;
			}

			$card = array(
				'id'        => (int) $post_id,
				'statement' => WA_Meta::plain_text( $statement ),
			);

			$pct_wrong = self::pct_wrong(
				get_post_meta( $post_id, WA_Meta::VERDICT_KEY, true ),
				isset( $stats[ $post_id ] ) ? $stats[ $post_id ] : null
			);

			// Only the number goes out, never the verdict it was derived
			// from — that would hand the player the answer.
			if ( null !== $pct_wrong ) {
				$card['pct_wrong'] = $pct_wrong;
			}

			$cards[] = $card;
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

	/**
	 * POST /swipe handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_post_swipe( WP_REST_Request $request ) {
		if ( ! self::check_rate_limit( 'swipe', 60, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'wa_rate_limited', __( 'Too many swipes, slow down.', 'wellactually' ), array( 'status' => 429 ) );
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$answer  = (string) $request->get_param( 'answer' );
		$replay  = (bool) $request->get_param( 'replay' );

		$valid_answers = array( 'agree', 'disagree', 'unsure' );
		if ( ! in_array( $answer, $valid_answers, true ) ) {
			return new WP_Error( 'wa_invalid_answer', __( 'Invalid answer.', 'wellactually' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
			return new WP_Error( 'wa_invalid_post', __( 'Post not found.', 'wellactually' ), array( 'status' => 404 ) );
		}

		$statement = get_post_meta( $post_id, WA_Meta::STATEMENT_KEY, true );
		$verdict   = get_post_meta( $post_id, WA_Meta::VERDICT_KEY, true );

		if ( '' === $statement || ! in_array( $verdict, array( 'true', 'false', 'debatable' ), true ) ) {
			return new WP_Error( 'wa_invalid_post', __( 'Post is not in the swipe deck.', 'wellactually' ), array( 'status' => 404 ) );
		}

		$correct = $this->is_correct( $verdict, $answer );
		$show_post = ! $correct || 'debatable' === $verdict;

		if ( ! $replay ) {
			WA_Stats::record( $post_id, $answer );
		}

		$stats = WA_Stats::get( $post_id );

		// Decode entities before trimming, so a word-trim can never cut an
		// entity in half — and before handing off to the frontend, which
		// escapes these itself.
		$excerpt = wp_strip_all_tags( strip_shortcodes( get_the_excerpt( $post ) ) );
		$excerpt = WA_Meta::plain_text( $excerpt );
		$excerpt = wp_trim_words( $excerpt, 40, '…' );

		$response = array(
			'verdict'    => $verdict,
			'correct'    => $correct,
			'show_post'  => $show_post,
			'title'      => WA_Meta::plain_text( get_the_title( $post ) ),
			'excerpt'    => $excerpt,
			'url'        => get_permalink( $post ),
			'pct_agreed' => $stats['pct_agreed'],
		);

		$rest_response = new WP_REST_Response( $response, 200 );
		$rest_response->header( 'Cache-Control', 'no-store' );

		return $rest_response;
	}

	// Below this many swipes a percentage is noise rather than information
	// ("100% got this wrong" off a single answer), so the card shows nothing.
	// Low enough that a new quiz starts showing figures quickly; raise it with
	// the wa_min_swipes_for_stat filter to wait for a steadier sample.
	const MIN_SWIPES_FOR_STAT = 5;

	/**
	 * What share of players got a card wrong, or null when it shouldn't be
	 * shown.
	 *
	 * Wrong means "not the correct answer", so an unsure counts as wrong on a
	 * true/false card. A debatable card has no wrong answer, so it never
	 * shows a figure (and showing 0% would quietly reveal the verdict).
	 *
	 * @param string     $verdict The post's verdict.
	 * @param array|null $stat    Row from WA_Stats::get_many().
	 * @return int|null Percentage 0-100, or null.
	 */
	private static function pct_wrong( $verdict, $stat ) {
		/**
		 * Filter how many swipes a card needs before it shows what share of
		 * players got it wrong.
		 *
		 * @param int $minimum Minimum recorded swipes.
		 */
		$minimum = (int) apply_filters( 'wa_min_swipes_for_stat', self::MIN_SWIPES_FOR_STAT );

		if ( empty( $stat ) || $stat['total'] < max( 1, $minimum ) ) {
			return null;
		}

		if ( 'true' === $verdict ) {
			$wrong = $stat['disagree'] + $stat['unsure'];
		} elseif ( 'false' === $verdict ) {
			$wrong = $stat['agree'] + $stat['unsure'];
		} else {
			return null;
		}

		return (int) round( ( $wrong / $stat['total'] ) * 100 );
	}

	/**
	 * Determine whether an answer is correct for a given verdict.
	 *
	 * @param string $verdict One of true|false|debatable.
	 * @param string $answer  One of agree|disagree|unsure.
	 * @return bool
	 */
	private function is_correct( $verdict, $answer ) {
		if ( 'debatable' === $verdict ) {
			return true;
		}
		if ( 'true' === $verdict ) {
			return 'agree' === $answer;
		}
		if ( 'false' === $verdict ) {
			return 'disagree' === $answer;
		}
		return false;
	}
}
