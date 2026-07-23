<?php
/**
 * AI drafting engine: turn a blog post into a suggested swipe statement +
 * verdict using WordPress 7's AI client, stored as a reviewable draft.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds prompts, calls the AI provider, and stores draft suggestions.
 */
class WA_AI {

	const STATUS_QUEUED     = 'queued';
	const STATUS_PROCESSING = 'processing';
	const STATUS_READY      = 'ready';
	const STATUS_ERROR      = 'error';

	// A claim older than this is treated as abandoned (the browser tab was
	// closed mid-batch, a request timed out) and returned to the queue.
	const CLAIM_TIMEOUT = 300;

	// Cap on how much post text we send. A one-line true/false/debatable
	// statement needs only the post's core argument, not the whole article —
	// keeping this small measurably speeds up drafting (input size drives
	// time-to-first-token) with no real loss in statement quality.
	const MAX_CONTENT_CHARS = 3000;

	/**
	 * Singleton instance.
	 *
	 * @var WA_AI|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_AI
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
	 * Register the admin-only AI REST routes.
	 */
	public function register_routes() {
		$permission = function () {
			return current_user_can( 'edit_posts' );
		};

		register_rest_route(
			'wellactually/v1',
			'/ai/enqueue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_enqueue' ),
				'permission_callback' => $permission,
				'args'                => array(
					'count' => array(
						'type'              => 'integer',
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
					'cat'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'wellactually/v1',
			'/ai/process',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_process' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * REST: queue up to N needs-setup posts for drafting.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_enqueue( WP_REST_Request $request ) {
		$count = min( 200, max( 1, (int) $request->get_param( 'count' ) ) );
		$cat   = (int) $request->get_param( 'cat' );

		// Release anything a previous batch abandoned before picking new work.
		self::requeue_stale_processing();

		$ids    = self::select_candidates( $count, $cat );
		$queued = self::enqueue( $ids );

		$response = new WP_REST_Response(
			array(
				'queued' => $queued,
				'ids'    => $ids,
				'counts' => self::queue_counts(),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * REST: process the next queued post (one per call).
	 *
	 * @return WP_REST_Response
	 */
	public function handle_process() {
		$post_id = self::claim_next_queued();

		$processed = null;
		if ( $post_id ) {
			$result = self::draft_for_post( $post_id );
			delete_post_meta( $post_id, WA_Meta::AI_CLAIMED_KEY );

			$processed = array_merge(
				array(
					'post_id' => $post_id,
					'title'   => WA_Meta::plain_text( get_the_title( $post_id ) ),
					'url'     => get_edit_post_link( $post_id, 'raw' ),
				),
				$result
			);
		}

		$payload = array( 'processed' => $processed );

		// queue_counts() is three meta queries. The drafting loop doesn't use
		// them while it's running, and with several workers in flight that's
		// pure contention on a large archive — so only spend them on the last
		// response, when the queue has run dry.
		if ( null === $processed ) {
			$payload['counts'] = self::queue_counts();
		}

		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Whether AI drafting is usable right now (support + a configured provider).
	 *
	 * @return bool
	 */
	public static function is_available() {
		$available = true;

		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			$available = false;
		} else {
			$provider  = wa_get_setting( 'ai_provider', '' );
			$available = '' !== $provider && WA_Settings::is_ai_provider_configured( $provider );
		}

		/**
		 * Filter whether AI drafting is available. Lets integrations (or tests)
		 * override the default provider-configured check.
		 *
		 * @param bool $available Whether drafting is available.
		 */
		return (bool) apply_filters( 'wa_ai_available', $available );
	}

	/**
	 * Mark posts as queued for AI drafting.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int Number of posts queued.
	 */
	public static function enqueue( array $post_ids ) {
		$queued = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				continue;
			}
			update_post_meta( $post_id, WA_Meta::AI_STATUS_KEY, self::STATUS_QUEUED );
			delete_post_meta( $post_id, WA_Meta::AI_ERROR_KEY );
			delete_post_meta( $post_id, WA_Meta::AI_ERROR_TIME_KEY );
			delete_post_meta( $post_id, WA_Meta::AI_CLAIMED_KEY );
			$queued++;
		}
		return $queued;
	}

	/**
	 * Select up to $limit "needs setup" post IDs to draft, optionally within a
	 * category. Skips posts that already have a queued or ready suggestion.
	 *
	 * @param int $limit Maximum number of posts.
	 * @param int $cat   Category term id, or 0 for all.
	 * @return int[]
	 */
	public static function select_candidates( $limit, $cat = 0 ) {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'fields'              => 'ids',
			'posts_per_page'      => max( 1, (int) $limit ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'meta_query'          => WA_Meta::status_meta_query( 'needs_setup' ),
			// Always read live: this filters on a meta-backed status, which
			// WP's post-query cache doesn't invalidate on (see the same note
			// in WA_Bulk_Setup::build_query()). A stale list here would spend
			// real AI calls re-drafting posts that were just set up.
			'cache_results'       => false,
		);

		if ( $cat > 0 ) {
			$args['cat'] = (int) $cat;
		}

		// Via the helper, not the raw setting: it expands a skipped category
		// to its descendants, which the bulk-setup screen also relies on.
		$excluded_cats = WA_Settings::excluded_categories();
		if ( ! empty( $excluded_cats ) ) {
			$args['category__not_in'] = $excluded_cats;
		}

		$query = new WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Take ownership of the next queued post, or 0 when the queue is empty.
	 *
	 * Drafting runs several requests at once, so "read the next queued id and
	 * start work on it" isn't safe on its own — two workers would read the
	 * same id and both pay for the same AI call. The claim is therefore a
	 * compare-and-swap: flip the status row from queued to processing with
	 * the old value in the WHERE clause, and treat the update's affected-row
	 * count as the answer. Exactly one worker can win; the losers simply try
	 * the next post.
	 *
	 * @return int Post ID now owned by this caller, or 0.
	 */
	public static function claim_next_queued() {
		global $wpdb;

		// Walk a batch of candidates rather than re-querying per attempt.
		// Re-querying would be both slower and wrong: the lookup is a normal
		// cacheable query, so on a site with a persistent object cache every
		// retry would be served the same already-claimed post, and the worker
		// would give up and report an empty queue while work remained.
		for ( $round = 0; $round < 3; $round++ ) {
			$candidates = self::queued_ids( 50 );
			if ( empty( $candidates ) ) {
				return 0;
			}

			foreach ( $candidates as $post_id ) {
				$claimed = $wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => self::STATUS_PROCESSING ),
					array(
						'post_id'    => $post_id,
						'meta_key'   => WA_Meta::AI_STATUS_KEY,
						'meta_value' => self::STATUS_QUEUED,
					),
					array( '%s' ),
					array( '%d', '%s', '%s' )
				);

				// Written round WordPress's meta API, so drop the cached copy.
				wp_cache_delete( $post_id, 'post_meta' );

				if ( $claimed ) {
					update_post_meta( $post_id, WA_Meta::AI_CLAIMED_KEY, time() );
					return (int) $post_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Queued post IDs, oldest first, read live.
	 *
	 * Deliberately uncached: claiming races against other workers, so a
	 * cached list of "what's queued" is worse than useless here.
	 *
	 * @param int $limit How many to fetch.
	 * @return int[]
	 */
	private static function queued_ids( $limit ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => max( 1, (int) $limit ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'cache_results'  => false,
				'meta_query'     => array(
					array(
						'key'   => WA_Meta::AI_STATUS_KEY,
						'value' => self::STATUS_QUEUED,
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Return abandoned claims to the queue.
	 *
	 * A worker that never reports back — closed tab, timed-out request, fatal
	 * error — would otherwise leave its post stuck in "processing" forever.
	 * Called when a batch is enqueued, which is the natural moment to sweep.
	 *
	 * @param int $older_than Seconds after which a claim counts as abandoned.
	 * @return int Number of posts returned to the queue.
	 */
	public static function requeue_stale_processing( $older_than = self::CLAIM_TIMEOUT ) {
		global $wpdb;

		$cutoff = time() - max( 1, (int) $older_than );

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.post_id
				 FROM {$wpdb->postmeta} s
				 LEFT JOIN {$wpdb->postmeta} c
				   ON c.post_id = s.post_id AND c.meta_key = %s
				 WHERE s.meta_key = %s
				   AND s.meta_value = %s
				   AND ( c.meta_value IS NULL OR CAST( c.meta_value AS SIGNED ) < %d )",
				WA_Meta::AI_CLAIMED_KEY,
				WA_Meta::AI_STATUS_KEY,
				self::STATUS_PROCESSING,
				$cutoff
			)
		);

		foreach ( $post_ids as $post_id ) {
			update_post_meta( (int) $post_id, WA_Meta::AI_STATUS_KEY, self::STATUS_QUEUED );
			delete_post_meta( (int) $post_id, WA_Meta::AI_CLAIMED_KEY );
		}

		return count( $post_ids );
	}

	/**
	 * Get the next queued post id (oldest queued first), or 0 if none.
	 *
	 * @return int
	 */
	public static function next_queued_id() {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => WA_Meta::AI_STATUS_KEY,
						'value' => self::STATUS_QUEUED,
					),
				),
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Count posts in each AI status.
	 *
	 * @return array { queued: int, ready: int, error: int }
	 */
	public static function queue_counts() {
		$counts = array(
			'queued' => 0,
			'ready'  => 0,
			'error'  => 0,
		);

		// A post being drafted right now still counts as queued from the
		// outside — it's outstanding work, not a finished result.
		$values = array(
			'queued' => array( self::STATUS_QUEUED, self::STATUS_PROCESSING ),
			'ready'  => array( self::STATUS_READY ),
			'error'  => array( self::STATUS_ERROR ),
		);

		foreach ( $values as $status => $meta_values ) {
			$query             = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => false,
					'meta_query'     => array(
						array(
							'key'     => WA_Meta::AI_STATUS_KEY,
							'value'   => $meta_values,
							'compare' => 'IN',
						),
					),
				)
			);
			$counts[ $status ] = (int) $query->found_posts;
		}

		return $counts;
	}

	/**
	 * Look up a provider's own default text model, via the 'wpai_preferred_text_models'
	 * filter that provider plugins (e.g. Nano-GPT's DefaultModelPreferences) use to
	 * publish the model the site owner picked in that provider's own settings screen.
	 * Used when this plugin's own ai_model setting is left blank: without this,
	 * using_provider() alone pins the provider with no model preference at all,
	 * which some providers (Nano-GPT included) reject outright.
	 *
	 * @param string $provider Provider id.
	 * @return string Model id, or '' if the provider has no published preference.
	 */
	public static function preferred_model_for_provider( $provider ) {
		$preferences = apply_filters( 'wpai_preferred_text_models', array() );
		if ( ! is_array( $preferences ) ) {
			return '';
		}

		foreach ( $preferences as $pref ) {
			if ( is_array( $pref ) && 2 === count( $pref ) && $provider === $pref[0] && '' !== $pref[1] ) {
				return (string) $pref[1];
			}
		}

		return '';
	}

	/**
	 * The model id drafting will actually request for a provider: this
	 * plugin's own setting when set, otherwise the provider's published
	 * default. Empty string when neither is available.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function effective_model( $provider ) {
		$model = (string) wa_get_setting( 'ai_model', '' );
		if ( '' !== $model ) {
			return $model;
		}
		return self::preferred_model_for_provider( $provider );
	}

	/**
	 * Get a concrete model instance so drafting can pin it, or null if the
	 * client/provider can't produce one.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id.
	 * @return object|null A ModelInterface instance, or null.
	 */
	private static function model_instance( $provider, $model ) {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return null;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( $provider ) ) {
				return null;
			}
			return $registry->getProviderModel( $provider, $model );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether a model advertises the schema-enforced JSON output drafting
	 * prefers, for UI that wants to say so before a batch is run.
	 *
	 * Drafting still works without it — draft_for_post() drops the schema
	 * argument and leans on the explicit JSON instruction plus the tolerant
	 * parser — but results are less reliably well-formed, which is worth
	 * mentioning while the model is being chosen.
	 *
	 * Deliberately answers via the same check the drafting path uses
	 * (model_supports_response_schema) so the warning can never disagree
	 * with what actually happens at run time.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id.
	 * @return bool|null True/false, or null if it couldn't be determined
	 *                   (provider not registered, model unknown, unfamiliar
	 *                   client shape) — callers should stay quiet, not guess.
	 */
	public static function model_supports_drafting( $provider, $model ) {
		if ( '' === $provider || '' === $model ) {
			return null;
		}

		$instance = self::model_instance( $provider, $model );
		if ( null === $instance ) {
			return null;
		}

		try {
			return self::model_supports_response_schema( $instance );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Draft a suggestion for a single post: build the prompt, call the AI, and
	 * store the result (or an error) in the post's AI meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array { status: 'ready'|'error', statement?: string, verdict?: string, error?: string }
	 */
	public static function draft_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return self::store_error( $post_id, __( 'Invalid post.', 'wellactually' ) );
		}

		$provider = wa_get_setting( 'ai_provider', '' );
		$model    = wa_get_setting( 'ai_model', '' );

		/**
		 * Short-circuit the AI call with a pre-built result. Return an array
		 * with 'statement' and 'verdict' to bypass the provider entirely (used
		 * for testing and for custom integrations); return null to proceed.
		 *
		 * @param array|null $result   The drafted result, or null to run the provider.
		 * @param WP_Post    $post     The post being drafted.
		 * @param string     $provider Selected provider id.
		 * @param string     $model    Selected model id.
		 */
		$pre = apply_filters( 'wa_ai_pre_draft', null, $post, $provider, $model );
		if ( is_array( $pre ) ) {
			return self::store_result( $post_id, $pre );
		}

		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return self::store_error( $post_id, __( 'AI is not available in this environment.', 'wellactually' ) );
		}
		if ( '' === $provider ) {
			return self::store_error( $post_id, __( 'No AI provider is selected in settings.', 'wellactually' ) );
		}

		// The WP AI client wrapper uses snake_case method names (it translates
		// them to the underlying builder's camelCase), and returns a WP_Error
		// from generating methods on failure rather than throwing.
		try {
			$builder         = wp_ai_client_prompt( self::user_prompt( $post ) )
				->using_system_instruction( self::system_instruction() );
			$supports_schema = true;

			if ( '' === $model ) {
				$model = self::preferred_model_for_provider( $provider );
			}

			$selected_model = '' !== $model ? self::model_instance( $provider, $model ) : null;

			if ( null !== $selected_model ) {
				/*
				 * A model preference is allowed to fall back silently when the
				 * preferred model does not advertise every requested option.
				 * That made the UI report one model while WordPress selected
				 * another. Resolve an explicit model instance so the selected
				 * provider/model pair is the one that receives the request.
				 */
				$builder         = $builder->using_model( $selected_model );
				$supports_schema = self::model_supports_response_schema( $selected_model );
			} elseif ( '' !== $model ) {
				// No instance available for the configured model (unregistered
				// provider, unknown id). Name it as a preference rather than
				// dropping the choice altogether.
				$builder = $builder->using_model_preference( array( $provider, $model ) );
			} else {
				$builder = $builder->using_provider( $provider );
			}

			/*
			 * Use native schema enforcement when the selected model advertises
			 * it. Otherwise rely on the explicit JSON instruction and the
			 * tolerant parser, without excluding or replacing that model.
			 */
			if ( $supports_schema ) {
				$builder = $builder->as_json_response( self::response_schema() );
			}
			$json = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return self::store_error( $post_id, $e->getMessage() );
		}

		if ( is_wp_error( $json ) ) {
			return self::store_error( $post_id, $json->get_error_message() );
		}
		if ( ! is_string( $json ) ) {
			return self::store_error( $post_id, __( 'The AI returned an unexpected response type.', 'wellactually' ) );
		}

		$data = self::parse_json_object( $json );
		if ( ! is_array( $data ) || empty( $data['statement'] ) || empty( $data['verdict'] ) ) {
			return self::store_error( $post_id, __( 'The AI returned an unexpected response.', 'wellactually' ) );
		}

		return self::store_result( $post_id, $data );
	}

	/**
	 * Parse a JSON object from a model response, tolerating the common ways
	 * LLMs deviate from clean JSON: leading/trailing prose, or a ```json
	 * fenced code block.
	 *
	 * @param string $raw Raw model output.
	 * @return array|null Decoded associative array, or null if none found.
	 */
	private static function parse_json_object( $raw ) {
		$raw = trim( $raw );

		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		// Strip a Markdown code fence if present (```json … ``` or ``` … ```).
		if ( preg_match( '/```(?:json)?\s*(.+?)```/is', $raw, $m ) ) {
			$data = json_decode( trim( $m[1] ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		// Fall back to the first {...} object in the string.
		if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
			$data = json_decode( $m[0], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return null;
	}

	/**
	 * Store a successful draft.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    { statement: string, verdict: string }.
	 * @return array
	 */
	private static function store_result( $post_id, $data ) {
		$statement = isset( $data['statement'] ) ? sanitize_textarea_field( $data['statement'] ) : '';
		$verdict   = isset( $data['verdict'] ) ? sanitize_text_field( $data['verdict'] ) : '';

		if ( '' === $statement || ! in_array( $verdict, WA_Meta::deck_verdicts(), true ) ) {
			return self::store_error( $post_id, __( 'The AI returned an incomplete draft.', 'wellactually' ) );
		}

		update_post_meta( $post_id, WA_Meta::AI_STATEMENT_KEY, $statement );
		update_post_meta( $post_id, WA_Meta::AI_VERDICT_KEY, $verdict );
		update_post_meta( $post_id, WA_Meta::AI_STATUS_KEY, self::STATUS_READY );
		delete_post_meta( $post_id, WA_Meta::AI_ERROR_KEY );
		delete_post_meta( $post_id, WA_Meta::AI_ERROR_TIME_KEY );
		WA_Meta::recompute_status( $post_id );

		return array(
			'status'    => self::STATUS_READY,
			'statement' => $statement,
			'verdict'   => $verdict,
		);
	}

	/**
	 * Store a draft error.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Error message.
	 * @return array
	 */
	private static function store_error( $post_id, $message ) {
		$message = sanitize_text_field( $message );
		update_post_meta( $post_id, WA_Meta::AI_STATUS_KEY, self::STATUS_ERROR );
		update_post_meta( $post_id, WA_Meta::AI_ERROR_KEY, $message );
		update_post_meta( $post_id, WA_Meta::AI_ERROR_TIME_KEY, time() );
		WA_Meta::recompute_status( $post_id );

		return array(
			'status' => self::STATUS_ERROR,
			'error'  => $message,
		);
	}

	/**
	 * Get drafting errors from the last $days days, newest first. Powers the
	 * Settings → Errors tab.
	 *
	 * @param int $days How many days back to look.
	 * @return array[] { post_id, title, edit_url, error, time } per errored post.
	 */
	public static function get_recent_errors( $days = 7 ) {
		$cutoff = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'orderby'        => 'meta_value_num',
				'meta_key'       => WA_Meta::AI_ERROR_TIME_KEY,
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => WA_Meta::AI_STATUS_KEY,
						'value' => self::STATUS_ERROR,
					),
					array(
						'key'     => WA_Meta::AI_ERROR_TIME_KEY,
						'value'   => $cutoff,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$errors = array();
		foreach ( $query->posts as $post_id ) {
			$errors[] = array(
				'post_id'  => $post_id,
				'title'    => get_the_title( $post_id ),
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'error'    => get_post_meta( $post_id, WA_Meta::AI_ERROR_KEY, true ),
				'time'     => (int) get_post_meta( $post_id, WA_Meta::AI_ERROR_TIME_KEY, true ),
			);
		}

		return $errors;
	}

	/**
	 * Delete drafting-error meta older than $days days, resetting those posts
	 * back to a plain (retryable) needs-setup state. Only the Errors tab's
	 * display is time-limited by get_recent_errors(); this actually prunes
	 * the underlying data so it doesn't accumulate forever. Cheap enough to
	 * run every time the Errors tab is viewed rather than on a schedule.
	 *
	 * @param int $days Errors older than this many days are removed.
	 */
	public static function prune_old_errors( $days = 7 ) {
		$cutoff = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => WA_Meta::AI_STATUS_KEY,
						'value' => self::STATUS_ERROR,
					),
					array(
						'key'     => WA_Meta::AI_ERROR_TIME_KEY,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			delete_post_meta( $post_id, WA_Meta::AI_STATUS_KEY );
			delete_post_meta( $post_id, WA_Meta::AI_ERROR_KEY );
			delete_post_meta( $post_id, WA_Meta::AI_ERROR_TIME_KEY );
			WA_Meta::recompute_status( $post_id );
		}
	}

	/**
	 * The system instruction describing the drafting task.
	 *
	 * @return string
	 */
	private static function system_instruction() {
		$instruction = 'You write one-line "swipe statements" for a knowledge game on a technical blog (databases, SQL Server, performance). '
			. "Given one blog post, produce a single statement a reader can agree or disagree with, plus the correct verdict:\n"
			. "- \"true\": the statement is accurate and the post supports it.\n"
			. "- \"false\": the statement is a common misconception that the post debunks or corrects.\n"
			. "- \"debatable\": reasonable experts disagree, or the honest answer is \"it depends\".\n"
			. "Choose whichever makes the most engaging swipe for THIS post; a varied mix across posts is good. "
			. 'Keep the statement concrete and under about 15 words, with no hedging and no question marks. '
			. 'Base it only on the post content. Respond only as a JSON object with exactly two fields: '
			. '"statement" (a string) and "verdict" (one of "true", "false", or "debatable").';

		/**
		 * Filter the AI system instruction used for drafting swipe statements.
		 *
		 * @param string $instruction The system instruction.
		 */
		return (string) apply_filters( 'wa_ai_system_instruction', $instruction );
	}

	/**
	 * Build the user prompt (title + plain-text body) for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function user_prompt( $post ) {
		$content = self::prompt_content( $post );

		return 'Title: ' . get_the_title( $post ) . "\n\nContent:\n" . $content;
	}

	/**
	 * The plain-text body to send: a manual excerpt when the post has one
	 * (it's already a short, hand-picked summary — exactly what a one-line
	 * statement needs), otherwise the post content. Code samples are
	 * dropped entirely before capping — they eat characters without adding
	 * claim-worthy prose, so keeping them would just crowd out actual
	 * argument text under the cap.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function prompt_content( $post ) {
		if ( has_excerpt( $post ) ) {
			$content = get_the_excerpt( $post );
		} else {
			$content = get_the_content( '', false, $post );
		}

		$content = strip_shortcodes( $content );
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}

		// Drop <pre>...</pre> code blocks (both the block editor's
		// wp-block-code markup and classic <pre><code>) before stripping
		// tags, while tag boundaries are still there to find them by.
		$content = preg_replace( '#<pre\b[^>]*>.*?</pre>#is', '', $content );

		$content = wp_strip_all_tags( $content );
		$content = trim( preg_replace( '/\n{3,}/', "\n\n", $content ) );

		if ( strlen( $content ) > self::MAX_CONTENT_CHARS ) {
			$content = substr( $content, 0, self::MAX_CONTENT_CHARS );
		}

		return $content;
	}

	/**
	 * Whether a selected model advertises schema-constrained JSON output.
	 *
	 * @param object $model AI Client model instance.
	 * @return bool
	 */
	private static function model_supports_response_schema( $model ) {
		if ( ! method_exists( $model, 'metadata' ) ) {
			return false;
		}
		$metadata = $model->metadata();
		if ( ! is_object( $metadata ) || ! method_exists( $metadata, 'getSupportedOptions' ) ) {
			return false;
		}
		foreach ( $metadata->getSupportedOptions() as $option ) {
			if (
				is_object( $option )
				&& method_exists( $option, 'getName' )
				&& $option->getName()->isOutputSchema()
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The JSON output schema for models that advertise native support.
	 *
	 * @return array
	 */
	private static function response_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'statement' => array(
					'type'        => 'string',
					'description' => 'A one-line statement the reader can agree or disagree with.',
				),
				'verdict'   => array(
					'type'        => 'string',
					'enum'        => array( 'true', 'false', 'debatable' ),
					'description' => 'The correct answer for the statement.',
				),
			),
			'required'             => array( 'statement', 'verdict' ),
			'additionalProperties' => false,
		);
	}

}
