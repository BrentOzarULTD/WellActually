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
class WellActually_AI {

	const STATUS_QUEUED     = 'queued';
	const STATUS_PROCESSING = 'processing';
	const STATUS_READY      = 'ready';
	const STATUS_ERROR      = 'error';

	// A claim older than this is treated as abandoned (the browser tab was
	// closed mid-batch, a request timed out) and returned to the queue.
	const CLAIM_TIMEOUT = 300;

	// A batch with work still sitting untouched this long is considered dead
	// and its remaining items are released back to the general pool. Well
	// clear of any real run (a hundred posts drafts in under a minute), but
	// short enough that an abandoned one isn't holding posts back for long.
	const BATCH_TIMEOUT = 900;

	// Cap on how much post text we send. A one-line true/false/debatable
	// statement needs only the post's core argument, not the whole article —
	// keeping this small measurably speeds up drafting (input size drives
	// time-to-first-token) with no real loss in statement quality.
	const MAX_CONTENT_CHARS = 3000;

	/**
	 * Singleton instance.
	 *
	 * @var WellActually_AI|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WellActually_AI
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

		// Same reasoning as WellActually_Meta's backfill: activation-only setup misses
		// sites that were updated by copying files, so check cheaply on
		// admin_init (the option compare is a single cached lookup).
		add_action( 'admin_init', array( 'WellActually_AI_Queue', 'maybe_upgrade_table' ) );
		add_action( 'wellactually_activate', array( 'WellActually_AI_Queue', 'create_table' ) );
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
					'batch' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
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
				'args'                => array(
					'batch' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
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

		// Put back anything a previous run left mid-flight, and clear out
		// batches whose browser never returned — their rows would otherwise
		// block those posts from ever being drafted again.
		WellActually_AI_Queue::release_stale( self::CLAIM_TIMEOUT );
		WellActually_AI_Queue::collect_abandoned( self::BATCH_TIMEOUT );

		// Resuming: if the caller still has a batch with outstanding work,
		// carry on with it instead of starting a new one and orphaning it.
		$resume = (string) $request->get_param( 'batch' );
		if ( '' !== $resume ) {
			$counts = WellActually_AI_Queue::batch_counts( $resume );
			if ( $counts['remaining'] > 0 ) {
				$response = new WP_REST_Response(
					array(
						'batch'   => $resume,
						'queued'  => $counts['remaining'],
						'ids'     => array(),
						'resumed' => true,
					),
					200
				);
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
		}

		$batch_id = WellActually_AI_Queue::new_batch_id();

		// Keep looking until the batch is full or the archive genuinely runs
		// out of eligible posts.
		//
		// A candidate can be refused admission because some earlier run still
		// holds it (a closed tab, a run stopped by a rate limit). The fix is to
		// not offer those posts in the first place: seed the exclusion with
		// everything already in the queue, so the candidate query skips held
		// posts at the SQL level instead of selecting them only to have admit()
		// reject them. Without this, refusals came straight off the total — ask
		// for 100 with 147 posts held from before and you'd silently get 53 —
		// and a fixed page budget could exhaust its scan among held posts while
		// thousands of eligible ones sat further down the archive (issue #28).
		$admitted = array();
		$tried    = WellActually_AI_Queue::queued_post_ids();

		// Each round adds its candidates to $tried, so the next round returns
		// strictly new posts — the loop makes guaranteed progress and stops
		// when a round finds nothing (the real "exhausted" signal) or the batch
		// is full. The cap is only a safety net against an unforeseen
		// non-terminating case; it's far above any real fill (count maxes at
		// 200), so it never truncates a legitimate request the way the old
		// fixed eight rounds could.
		$max_rounds = 500;

		for ( $round = 0; $round < $max_rounds; $round++ ) {
			$still_needed = $count - count( $admitted );
			if ( $still_needed <= 0 ) {
				break;
			}

			$candidates = self::select_candidates( $still_needed * 2, $cat, $tried );
			if ( empty( $candidates ) ) {
				// Genuinely nothing left that matches.
				break;
			}

			$tried    = array_merge( $tried, $candidates );
			$admitted = array_merge( $admitted, WellActually_AI_Queue::admit( $candidates, $batch_id ) );
		}

		if ( $max_rounds === $round && WellActually_Settings::debug_logging_enabled() ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[wellactually] AI enqueue hit the %d-round safety cap with %d of %d admitted; investigate before raising it.',
					$max_rounds,
					count( $admitted ),
					$count
				)
			);
		}

		$admitted = array_slice( $admitted, 0, $count );

		// Anything admitted beyond what we're keeping is handed straight back,
		// then the posts this batch owns get their visible queued marker.
		self::release_unused( $batch_id, $admitted );
		self::mark_queued( $admitted );

		$response = new WP_REST_Response(
			array(
				'batch'  => $batch_id,
				'queued' => count( $admitted ),
				'ids'    => $admitted,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Drop anything admitted into a batch that we then decided not to use, so
	 * it's immediately available to the next run instead of being held.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param int[]  $keep     Post IDs to keep.
	 */
	private static function release_unused( $batch_id, array $keep ) {
		global $wpdb;

		$table = WellActually_AI_Queue::table_name();

		if ( empty( $keep ) ) {
			WellActually_AI_Queue::clear_batch( $batch_id );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- {$placeholders} is only generated %d markers; every value is bound via the single array argument, which the counting sniff cannot tally.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE batch_id = %s AND post_id NOT IN ( {$placeholders} )",
				array_merge( array( $table, $batch_id ), $keep )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * Put the visible "queued" marker on posts this batch owns.
	 *
	 * The queue table is the authority on who owns what; this meta only feeds
	 * the counts and badges the admin screens already show.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	private static function mark_queued( array $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, self::STATUS_QUEUED );
			delete_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY );
			delete_post_meta( $post_id, WellActually_Meta::AI_ERROR_TIME_KEY );
			// A post being re-queued may have had a ready suggestion, which
			// this supersedes.
			WellActually_Meta::recompute_status( $post_id, array( 'ai_status' => self::STATUS_QUEUED ) );
		}
	}

	/**
	 * REST: process the next queued post (one per call).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_process( WP_REST_Request $request ) {
		$batch_id = (string) $request->get_param( 'batch' );

		if ( '' === $batch_id ) {
			return new WP_Error( 'wellactually_missing_batch', __( 'A batch id is required.', 'wellactually' ), array( 'status' => 400 ) );
		}

		$claim = WellActually_AI_Queue::claim( $batch_id, WellActually_Settings::ai_concurrency() );

		// Ceiling reached (other tabs, other users, retries), or the claim
		// lock was momentarily held by another request ('busy'). Either way
		// this is a "come back shortly", not a failure — the work is still
		// queued and no state changed.
		if ( 'at_capacity' === $claim || 'busy' === $claim ) {
			$response = new WP_REST_Response(
				array(
					'status' => 'busy',
					'counts' => WellActually_AI_Queue::batch_counts( $batch_id ),
				),
				429
			);
			$response->header( 'Retry-After', '2' );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$processed = null;

		if ( is_array( $claim ) ) {
			$post_id = (int) $claim['post_id'];
			$result  = self::draft_for_post( $post_id, $claim['token'] );

			$processed = array_merge(
				array(
					'post_id' => $post_id,
					'title'   => WellActually_Meta::plain_text( get_the_title( $post_id ) ),
					'url'     => get_edit_post_link( $post_id, 'raw' ),
				),
				$result
			);
		}

		$payload = array(
			'processed' => $processed,
			'counts'    => WellActually_AI_Queue::batch_counts( $batch_id ),
		);

		// Deliberately a field rather than an HTTP 429: this endpoint already
		// answers 429 for its own concurrency ceiling, which the browser is
		// meant to wait out and retry. A provider rate limit is the opposite
		// instruction — stop the run — so it must not look the same.
		if ( is_array( $processed ) && isset( $processed['status'] ) && 'rate_limited' === $processed['status'] ) {
			$payload['abort'] = 'rate_limited';
		}

		// Batch state comes from the server, so the browser never has to infer
		// whether a run is finished from its own tally of responses (which a
		// lost response would silently corrupt).
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
			$provider  = wellactually_get_setting( 'ai_provider', '' );
			$available = '' !== $provider && WellActually_Settings::is_ai_provider_configured( $provider );
		}

		/**
		 * Filter whether AI drafting is available. Lets integrations (or tests)
		 * override the default provider-configured check.
		 *
		 * @param bool $available Whether drafting is available.
		 */
		return (bool) apply_filters( 'wellactually_ai_available', $available );
	}


	/**
	 * Select up to $limit "needs setup" post IDs to draft, optionally within a
	 * category. Skips posts that already have a queued or ready suggestion.
	 *
	 * @param int   $limit   Maximum number of posts.
	 * @param int   $cat     Category term id, or 0 for all.
	 * @param int[] $exclude Post IDs to leave out.
	 * @return int[]
	 */
	public static function select_candidates( $limit, $cat = 0, array $exclude = array() ) {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			// Deliberately broad. Swipe-meta changes cannot make this candidate
			// list stale; the authoritative status check below decides which
			// IDs are actually eligible.
			'cache_results'       => false,
		);

		if ( ! empty( $exclude ) ) {
			$args['post__not_in'] = array_map( 'absint', $exclude );
		}

		if ( $cat > 0 ) {
			$args['cat'] = (int) $cat;
		}

		// Via the helper, not the raw setting: it expands a skipped category
		// to its descendants, which the bulk-setup screen also relies on.
		$excluded_cats = WellActually_Settings::excluded_categories();
		if ( ! empty( $excluded_cats ) ) {
			$args['category__not_in'] = $excluded_cats;
		}

		$query = new WP_Query( $args );
		$ids   = WellActually_Meta::filter_post_ids_by_live_status(
			array_map( 'intval', $query->posts ),
			WellActually_Meta::STATUS_NEEDS_SETUP
		);

		return array_slice( $ids, 0, max( 1, (int) $limit ) );
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

		// Same scope and read semantics as the grid, so the panel can't
		// advertise suggestions the grid won't show: skipped posts are out,
		// skipped categories are out, and nothing is served from a cached
		// result set.
		$excluded_cats = WellActually_Settings::excluded_categories();

		foreach ( $values as $status => $meta_values ) {
			$args = array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'cache_results'  => false,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => WellActually_Meta::AI_STATUS_KEY,
						'value'   => $meta_values,
						'compare' => 'IN',
					),
					array(
						'key'     => WellActually_Meta::SKIP_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => WellActually_Meta::VERDICT_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			);

			if ( ! empty( $excluded_cats ) ) {
				$args['category__not_in'] = $excluded_cats;
			}

			$query             = new WP_Query( $args );
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
	 * Whether a provider error is a rate limit ("you're sending too many
	 * requests"), as opposed to something wrong with this particular post.
	 *
	 * The AI client flattens the provider's HTTP failure into a message, so
	 * this matches on what those messages contain. Nano-GPT produces
	 * "Too Many Requests (429) - Rate limit exceeded…"; the other spellings
	 * cover the common phrasings from other providers.
	 *
	 * @param string $message Error message from the provider call.
	 * @return bool
	 */
	private static function is_rate_limit_error( $message ) {
		$message = strtolower( (string) $message );

		foreach ( array( '429', 'too many requests', 'rate limit', 'rate_limit', 'ratelimit' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Wrap a provider failure, marking the ones that mean "slow down" rather
	 * than "this post can't be drafted".
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private static function describe_failure( $message ) {
		$out = array( 'error' => $message );

		if ( self::is_rate_limit_error( $message ) ) {
			$out['rate_limited'] = true;
		}

		return $out;
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
		$model = (string) wellactually_get_setting( 'ai_model', '' );
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
	 * @param int         $post_id     Post ID.
	 * @param string|null $claim_token Queue claim to fence the write with.
	 * @return array { status: 'ready'|'error', statement?: string, verdict?: string, error?: string }
	 */
	public static function draft_for_post( $post_id, $claim_token = null ) {
		$outcome = self::generate_draft( $post_id );

		// A rate limit says nothing about this post, so don't consume the
		// claim or record a drafting error against it — hand it back so a
		// later run picks it up untouched.
		if ( ! empty( $outcome['rate_limited'] ) ) {
			if ( null !== $claim_token ) {
				WellActually_AI_Queue::release( $post_id, $claim_token );
			}

			return array(
				'status' => 'rate_limited',
				'error'  => $outcome['error'],
			);
		}

		// Ownership fence. When drafting is running as queue work, a stale
		// sweep may have reclaimed this item while the provider call was in
		// flight — in which case someone else owns it now and this result is
		// stale. Give up the claim first and only store if we still held it,
		// so a late worker can never overwrite a newer result.
		if ( null !== $claim_token && ! WellActually_AI_Queue::complete( $post_id, $claim_token ) ) {
			return array(
				'status' => 'stale',
				'error'  => __( 'This post was reassigned to another drafting run; result discarded.', 'wellactually' ),
			);
		}

		if ( isset( $outcome['error'] ) ) {
			return self::store_error( $post_id, $outcome['error'] );
		}

		return self::store_result( $post_id, $outcome['data'] );
	}

	/**
	 * Run the provider call for a post and return what it produced, without
	 * touching any stored state.
	 *
	 * Kept separate from storing so the caller can decide, after the slow part
	 * is over, whether the result is still wanted (see the claim fence in
	 * draft_for_post()).
	 *
	 * @param int $post_id Post ID.
	 * @return array { data: array } on success, or { error: string }.
	 */
	private static function generate_draft( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return array( 'error' => __( 'Invalid post.', 'wellactually' ) );
		}

		$provider = wellactually_get_setting( 'ai_provider', '' );
		$model    = wellactually_get_setting( 'ai_model', '' );

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
		$pre = apply_filters( 'wellactually_ai_pre_draft', null, $post, $provider, $model );
		if ( is_array( $pre ) ) {
			return array( 'data' => $pre );
		}

		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return array( 'error' => __( 'AI is not available in this environment.', 'wellactually' ) );
		}
		if ( '' === $provider ) {
			return array( 'error' => __( 'No AI provider is selected in settings.', 'wellactually' ) );
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
			return self::describe_failure( $e->getMessage() );
		}

		if ( is_wp_error( $json ) ) {
			return self::describe_failure( $json->get_error_message() );
		}
		if ( ! is_string( $json ) ) {
			return array( 'error' => __( 'The AI returned an unexpected response type.', 'wellactually' ) );
		}

		$data = self::parse_json_object( $json );
		if ( ! is_array( $data ) || empty( $data['statement'] ) || empty( $data['verdict'] ) ) {
			return array( 'error' => __( 'The AI returned an unexpected response.', 'wellactually' ) );
		}

		return array( 'data' => $data );
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

		if ( '' === $statement || ! in_array( $verdict, WellActually_Meta::deck_verdicts(), true ) ) {
			return self::store_error( $post_id, __( 'The AI returned an incomplete draft.', 'wellactually' ) );
		}

		update_post_meta( $post_id, WellActually_Meta::AI_STATEMENT_KEY, $statement );
		update_post_meta( $post_id, WellActually_Meta::AI_VERDICT_KEY, $verdict );
		update_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, self::STATUS_READY );
		delete_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY );
		delete_post_meta( $post_id, WellActually_Meta::AI_ERROR_TIME_KEY );
		// Hand the status we just wrote to recompute rather than letting it
		// read the value back — a lagging read here would persist the wrong
		// derived status and hide this suggestion from review for good.
		WellActually_Meta::recompute_status( $post_id, array( 'ai_status' => self::STATUS_READY ) );

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
		update_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, self::STATUS_ERROR );
		update_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY, $message );
		update_post_meta( $post_id, WellActually_Meta::AI_ERROR_TIME_KEY, time() );
		WellActually_Meta::recompute_status( $post_id, array( 'ai_status' => self::STATUS_ERROR ) );

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
				'meta_key'       => WellActually_Meta::AI_ERROR_TIME_KEY,
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => WellActually_Meta::AI_STATUS_KEY,
						'value' => self::STATUS_ERROR,
					),
					array(
						'key'     => WellActually_Meta::AI_ERROR_TIME_KEY,
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
				'error'    => get_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY, true ),
				'time'     => (int) get_post_meta( $post_id, WellActually_Meta::AI_ERROR_TIME_KEY, true ),
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
						'key'   => WellActually_Meta::AI_STATUS_KEY,
						'value' => self::STATUS_ERROR,
					),
					array(
						'key'     => WellActually_Meta::AI_ERROR_TIME_KEY,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			delete_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY );
			delete_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY );
			delete_post_meta( $post_id, WellActually_Meta::AI_ERROR_TIME_KEY );
			WellActually_Meta::recompute_status( $post_id );
		}
	}

	/**
	 * The system instruction describing the drafting task.
	 *
	 * @return string
	 */
	private static function system_instruction() {
		$guidance = (string) wellactually_get_setting( 'ai_system_prompt', '' );

		if ( '' === trim( $guidance ) ) {
			$guidance = self::default_system_prompt();
		}

		// The response contract is appended rather than being part of the
		// editable text. Someone tuning the wording for their own voice
		// shouldn't be able to delete the one sentence that makes the reply
		// parseable and silently break every draft.
		$instruction = rtrim( $guidance ) . "\n\n" . self::response_contract();

		/**
		 * Filter the AI system instruction used for drafting swipe statements.
		 *
		 * @param string $instruction The system instruction.
		 */
		return (string) apply_filters( 'wellactually_ai_system_instruction', $instruction );
	}

	/**
	 * The default guidance: what a swipe statement is and how to pick a
	 * verdict. Editable in Settings so it can be tuned to a site's own voice.
	 *
	 * @return string
	 */
	public static function default_system_prompt() {
		return 'You write one-line "swipe statements" for a knowledge game on a technical blog (databases, SQL Server, performance). '
			. "Given one blog post, produce a single statement a reader can agree or disagree with, plus the correct verdict:\n"
			. "- \"true\": the statement is accurate and the post supports it.\n"
			. "- \"false\": the statement is a common misconception that the post debunks or corrects.\n"
			. "- \"debatable\": reasonable experts disagree, or the honest answer is \"it depends\".\n"
			. 'Choose whichever makes the most engaging swipe for THIS post; a varied mix across posts is good. '
			. 'Keep the statement concrete and under about 15 words, with no hedging and no question marks. '
			. 'Base it only on the post content.';
	}

	/**
	 * The output format the drafting code needs back, always appended to
	 * whatever guidance is in use.
	 *
	 * @return string
	 */
	public static function response_contract() {
		return 'Respond only as a JSON object with exactly two fields: '
			. '"statement" (a string) and "verdict" (one of "true", "false", or "debatable").';
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
