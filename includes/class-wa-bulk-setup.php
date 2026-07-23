<?php
/**
 * Bulk Swipe Setup screen: configure the statement/verdict (or mark as
 * excluded) for many existing posts at once.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Posts → Swipe Setup screen and handles its bulk save.
 */
class WA_Bulk_Setup {

	const MENU_SLUG    = 'wa-swipe-setup';
	const NONCE_ACTION = 'wa_bulk_save';
	const NONCE_NAME   = 'wa_bulk_nonce';
	const PER_PAGE     = 20;

	// How many chunks build_query() will read while skipping past rows whose
	// stored status has gone stale. Bounds the worst case (a whole run of
	// stale rows) to a handful of fast indexed queries.
	const MAX_SCAN_CHUNKS = 10;

	/**
	 * Singleton instance.
	 *
	 * @var WA_Bulk_Setup|null
	 */
	private static $instance = null;

	/**
	 * The screen's hook suffix, from add_submenu_page().
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_Bulk_Setup
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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Register the Posts → Swipe Setup submenu.
	 */
	public function register_page() {
		$this->hook = add_submenu_page(
			'edit.php',
			__( 'Well, Actually...', 'wellactually' ),
			__( 'Well, Actually...', 'wellactually' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook ) {
			// Handle the save before any output so we can redirect (PRG).
			add_action( 'load-' . $this->hook, array( $this, 'handle_save' ) );
		}
	}

	/**
	 * Read the current filter/paging state from the request.
	 *
	 * @return array
	 */
	private function current_args() {
		$status = isset( $_REQUEST['wa_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['wa_status'] ) ) : 'needs_setup';
		$valid  = array( 'needs_setup', 'has_ai', 'in_deck', 'excluded', 'skipped', 'all' );
		if ( ! in_array( $status, $valid, true ) ) {
			$status = 'needs_setup';
		}

		$order = isset( $_REQUEST['wa_order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['wa_order'] ) ) ) ? 'ASC' : 'DESC';

		// All three sort fields are indexed core wp_posts columns (or, for
		// 'wa_status', the single denormalized meta key already used
		// elsewhere) — none of them require the multi-key/JOIN queries this
		// screen avoids everywhere else.
		$orderby = isset( $_REQUEST['wa_orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['wa_orderby'] ) ) : 'date';
		if ( ! in_array( $orderby, array( 'date', 'modified', 'comment_count' ), true ) ) {
			$orderby = 'date';
		}

		return array(
			'status'  => $status,
			'cat'     => isset( $_REQUEST['wa_cat'] ) ? absint( $_REQUEST['wa_cat'] ) : 0,
			'order'   => $order,
			'orderby' => $orderby,
			'paged'   => isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1,
		);
	}

	/**
	 * Statuses this screen can independently verify a row against, mapped to
	 * the STATUS_KEY value a matching post must currently compute to.
	 *
	 * @param string $status Requested status view.
	 * @return string|null The expected status, or null when the view can't be
	 *                     verified (e.g. "all", which filters on nothing).
	 */
	private function expected_status_for( $status ) {
		$map = array(
			'needs_setup' => WA_Meta::STATUS_NEEDS_SETUP,
			'has_ai'      => WA_Meta::STATUS_HAS_AI,
			'in_deck'     => WA_Meta::STATUS_CONFIGURED,
			'excluded'    => WA_Meta::STATUS_EXCLUDED,
			'skipped'     => WA_Meta::STATUS_SKIPPED,
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : null;
	}

	/**
	 * Build the list of posts for the current view.
	 *
	 * The filtered views select on the denormalized STATUS_KEY meta, because
	 * that's the only shape of query that stays fast on a large archive. But
	 * a SQL result and a freshly-read post meta value can legitimately
	 * disagree for a while: on managed hosting, reads may be served by a
	 * database replica that hasn't caught up with the write that just
	 * happened, and object caches add their own lag. Right after saving a
	 * page, that's exactly the situation — the rows just handled can still
	 * look unhandled to the query.
	 *
	 * Earlier versions queried one page, dropped the rows whose live meta
	 * disagreed, and rendered whatever survived. When a whole page was stale
	 * that left "No posts match this filter" on screen above an accurate,
	 * non-zero count — the bug this screen kept coming back with.
	 *
	 * So the page is no longer whatever one query happened to return. We scan
	 * forward from the requested offset, keeping only rows whose live meta
	 * still agrees, until a full page is assembled or the results run out.
	 * Stale rows cost an extra chunk fetch, not an empty screen.
	 *
	 * @param array $args Result of current_args().
	 * @return WP_Query A query whose posts are the verified page.
	 */
	private function build_query( $args ) {
		$query_args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => self::PER_PAGE,
			'orderby'             => $args['orderby'],
			'order'               => $args['order'],
			'ignore_sticky_posts' => true,
			// Always read live. WP's post-query cache is keyed to a marker
			// that only moves when a POST changes, never when its meta does,
			// and this screen filters entirely on meta.
			'cache_results'       => false,
		);

		if ( $args['cat'] > 0 ) {
			$query_args['cat'] = $args['cat'];
		}

		// Categories marked "Skip This Category" in Settings → Categories are
		// never eligible here, in any status view — they're meant to be
		// treated as if they don't exist for this screen at all.
		$excluded_cats = WA_Settings::excluded_categories();
		if ( ! empty( $excluded_cats ) ) {
			$query_args['category__not_in'] = $excluded_cats;
		}

		if ( 'all' !== $args['status'] ) {
			$meta_query = WA_Meta::status_meta_query( $args['status'] );
			if ( ! empty( $meta_query ) ) {
				$query_args['meta_query'] = $meta_query;
			}
		}

		$expected = $this->expected_status_for( $args['status'] );

		// Unverifiable view ("all"): nothing to reconcile, one plain page.
		if ( null === $expected ) {
			$query_args['paged'] = $args['paged'];
			$query               = new WP_Query( $query_args );
			if ( ! empty( $query->posts ) ) {
				update_meta_cache( 'post', wp_list_pluck( $query->posts, 'ID' ) );
			}
			return $query;
		}

		$start    = ( max( 1, (int) $args['paged'] ) - 1 ) * self::PER_PAGE;
		$carrier  = null;
		$verified = array();
		$dropped  = 0;
		$scanned  = 0;

		// Bounded so a pathologically stale archive can't spin: worst case
		// this reads MAX_SCAN_CHUNKS pages of IDs, still a handful of fast
		// indexed queries.
		for ( $chunk = 0; $chunk < self::MAX_SCAN_CHUNKS; $chunk++ ) {
			$chunk_args           = $query_args;
			$chunk_args['offset'] = $start + $scanned;

			$query = new WP_Query( $chunk_args );

			if ( null === $carrier ) {
				$carrier = $query;
			}

			if ( empty( $query->posts ) ) {
				break;
			}

			$batch = $query->posts;
			update_meta_cache( 'post', wp_list_pluck( $batch, 'ID' ) );

			foreach ( $batch as $post ) {
				$scanned++;

				if ( WA_Meta::compute_status( $post->ID ) === $expected ) {
					$verified[] = $post;
				} else {
					// Correct the stored value so this row stops coming back.
					WA_Meta::recompute_status( $post->ID );
					$dropped++;
				}

				if ( count( $verified ) >= self::PER_PAGE ) {
					break 2;
				}
			}

			// Short batch means we reached the end of the results.
			if ( count( $batch ) < self::PER_PAGE ) {
				break;
			}
		}

		if ( null === $carrier ) {
			$carrier = new WP_Query( array_merge( $query_args, array( 'post__in' => array( 0 ) ) ) );
		}

		$found = max( 0, (int) $carrier->found_posts - $dropped );

		$carrier->posts         = $verified;
		$carrier->post_count    = count( $verified );
		$carrier->found_posts   = $found;
		$carrier->max_num_pages = $found > 0 ? (int) ceil( $found / self::PER_PAGE ) : 0;
		$carrier->current_post  = -1;

		$this->log_page_build( $args, $verified, $dropped, $scanned, $found );

		return $carrier;
	}

	/**
	 * Record what a page build actually did, when diagnostics are turned on.
	 *
	 * Off unless "Logging" is ticked on Settings → "Well, Actually..." (or a
	 * `wa_debug_setup` filter forces it on), so it's safe to switch on in
	 * production for a few page loads to see real numbers instead of
	 * guessing: how many rows the query returned, how many had stale status
	 * data, and how many made the page.
	 *
	 * @param array $args     Current view args.
	 * @param array $verified Verified posts for the page.
	 * @param int   $dropped  Rows whose live status disagreed with the query.
	 * @param int   $scanned  Rows examined to fill the page.
	 * @param int   $found    Adjusted total.
	 */
	private function log_page_build( $args, $verified, $dropped, $scanned, $found ) {
		$enabled = WA_Settings::debug_logging_enabled();

		/**
		 * Filter whether the "Well, Actually..." screen logs how it built a page.
		 *
		 * @param bool  $enabled Whether to log.
		 * @param array $args    Current view args.
		 */
		if ( ! apply_filters( 'wa_debug_setup', $enabled, $args ) ) {
			return;
		}

		error_log(
			sprintf(
				'[wellactually] page build: status=%s paged=%d scanned=%d stale=%d shown=%d found=%d',
				$args['status'],
				(int) $args['paged'],
				(int) $scanned,
				(int) $dropped,
				count( $verified ),
				(int) $found
			)
		);
	}

	/**
	 * Handle the bulk save (PRG): process, then redirect back to the same view.
	 */
	public function handle_save() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit posts.', 'wellactually' ) );
		}

		$rows = isset( $_POST['wa_bulk'] ) && is_array( $_POST['wa_bulk'] ) ? wp_unslash( $_POST['wa_bulk'] ) : array();

		$counts = array(
			'configured' => 0,
			'excluded'   => 0,
			'cleared'    => 0,
			'incomplete' => 0,
			'skipped'    => 0,
		);

		foreach ( $rows as $post_id => $fields ) {
			$post_id = absint( $post_id );
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || 'post' !== $post->post_type ) {
				continue;
			}

			$skip    = ! empty( $fields['skip'] );
			$exclude = ! empty( $fields['exclude'] );

			// "Skip for now" holds the post without touching its content or any
			// pending AI suggestion — it just sets it aside. Exclude wins if both.
			if ( $skip && ! $exclude ) {
				update_post_meta( $post_id, WA_Meta::SKIP_KEY, '1' );
				WA_Meta::recompute_status( $post_id );
				$counts['skipped']++;
				continue;
			}

			// Not held: make sure the skip flag is cleared. Recompute the
			// denormalized status here explicitly rather than relying on
			// apply_meta() below — if the post has no statement/verdict to
			// change, apply_meta() takes its 'unchanged' branch and never
			// touches status, which would leave a just-cleared skip flag's
			// old 'skipped' status stale.
			delete_post_meta( $post_id, WA_Meta::SKIP_KEY );
			WA_Meta::recompute_status( $post_id );

			$statement = isset( $fields['statement'] ) ? sanitize_textarea_field( $fields['statement'] ) : '';
			$verdict   = isset( $fields['verdict'] ) ? sanitize_text_field( $fields['verdict'] ) : '';

			if ( $exclude ) {
				$verdict = WA_Meta::VERDICT_EXCLUDED;
			} elseif ( ! in_array( $verdict, WA_Meta::deck_verdicts(), true ) ) {
				$verdict = '';
			}

			$result = WA_Meta::apply_meta( $post_id, $statement, $verdict );

			if ( isset( $counts[ $result ] ) ) {
				$counts[ $result ]++;
			}

			// Once a post is configured or excluded, any pending AI suggestion
			// has been resolved — clear it so it leaves the review list.
			if ( in_array( $result, array( 'configured', 'excluded' ), true ) ) {
				$this->clear_ai_suggestion( $post_id );
			}
		}

		// Bulk edits change deck-eligibility directly (no save_post fires),
		// so clear the cached eligible-post total the deck endpoint uses.
		if ( array_sum( $counts ) > 0 ) {
			WA_Rest::clear_total_cache();
		}

		$args = $this->current_args();

		$redirect = add_query_arg(
			array(
				'page'          => self::MENU_SLUG,
				'wa_status'     => $args['status'],
				'wa_cat'        => $args['cat'],
				'wa_order'      => $args['order'],
				'wa_orderby'    => $args['orderby'],
				'paged'         => $args['paged'],
				'wa_configured' => $counts['configured'],
				'wa_excluded'   => $counts['excluded'],
				'wa_cleared'    => $counts['cleared'],
				'wa_incomplete' => $counts['incomplete'],
				'wa_skipped'    => $counts['skipped'],
				'wa_saved'      => 1,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Remove a post's pending AI suggestion meta.
	 *
	 * @param int $post_id Post ID.
	 */
	private function clear_ai_suggestion( $post_id ) {
		delete_post_meta( $post_id, WA_Meta::AI_STATEMENT_KEY );
		delete_post_meta( $post_id, WA_Meta::AI_VERDICT_KEY );
		delete_post_meta( $post_id, WA_Meta::AI_STATUS_KEY );
		delete_post_meta( $post_id, WA_Meta::AI_ERROR_KEY );
	}

	/**
	 * Render the screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$args  = $this->current_args();
		$query = $this->build_query( $args );

		echo '<div class="wrap wa-bulk-setup">';
		echo '<h1>' . esc_html__( 'Well, Actually...', 'wellactually' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Add a swipe statement and mark it True, False, or Debatable — or exclude a post from swipe mode entirely. Fill in as many as you like, then Save at the bottom.', 'wellactually' ) . '</p>';

		$this->render_notice();
		$this->render_filters( $args, $query );
		$this->render_ai_panel( $args );
		$this->render_form( $args, $query );

		echo '</div>';

		$this->print_styles();
		$this->print_scripts();

		wp_reset_postdata();
	}

	/**
	 * Show the post-save summary notice.
	 */
	private function render_notice() {
		if ( empty( $_GET['wa_saved'] ) ) {
			return;
		}

		$configured = isset( $_GET['wa_configured'] ) ? absint( $_GET['wa_configured'] ) : 0;
		$excluded   = isset( $_GET['wa_excluded'] ) ? absint( $_GET['wa_excluded'] ) : 0;
		$cleared    = isset( $_GET['wa_cleared'] ) ? absint( $_GET['wa_cleared'] ) : 0;
		$incomplete = isset( $_GET['wa_incomplete'] ) ? absint( $_GET['wa_incomplete'] ) : 0;
		$skipped    = isset( $_GET['wa_skipped'] ) ? absint( $_GET['wa_skipped'] ) : 0;

		$parts = array();
		if ( $configured ) {
			/* translators: %d: number of posts */
			$parts[] = sprintf( _n( '%d post set up', '%d posts set up', $configured, 'wellactually' ), $configured );
		}
		if ( $excluded ) {
			/* translators: %d: number of posts */
			$parts[] = sprintf( _n( '%d excluded', '%d excluded', $excluded, 'wellactually' ), $excluded );
		}
		if ( $skipped ) {
			/* translators: %d: number of posts */
			$parts[] = sprintf( _n( '%d skipped', '%d skipped', $skipped, 'wellactually' ), $skipped );
		}
		if ( $cleared ) {
			/* translators: %d: number of posts */
			$parts[] = sprintf( _n( '%d cleared', '%d cleared', $cleared, 'wellactually' ), $cleared );
		}

		$message = $parts ? implode( ', ', $parts ) . '.' : __( 'No changes were made.', 'wellactually' );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';

		if ( $incomplete ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of posts */
					_n(
						'%d post needs both a statement and a verdict — left unchanged.',
						'%d posts need both a statement and a verdict — left unchanged.',
						$incomplete,
						'wellactually'
					),
					$incomplete
				)
			) . '</p></div>';
		}
	}

	/**
	 * Render the "Draft with AI" panel.
	 *
	 * @param array $args Current args.
	 */
	private function render_ai_panel( $args ) {
		$providers = WA_Settings::ai_providers();

		// Nothing to offer if the environment has no AI support at all.
		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return;
		}

		echo '<div class="wa-ai-panel">';
		echo '<h2>' . esc_html__( 'Draft with AI', 'wellactually' ) . '</h2>';

		if ( ! WA_AI::is_available() ) {
			$settings_url = admin_url( 'options-general.php?page=wellactually' );
			echo '<p>';
			if ( empty( $providers ) ) {
				esc_html_e( 'No AI provider is configured yet.', 'wellactually' );
			} else {
				esc_html_e( 'Choose a configured AI provider and model to enable drafting.', 'wellactually' );
			}
			echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Well, Actually... settings', 'wellactually' ) . '</a>';
			echo '</p></div>';
			return;
		}

		$provider_id   = wa_get_setting( 'ai_provider', '' );
		$provider_name = isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : $provider_id;
		$model         = wa_get_setting( 'ai_model', '' );
		$counts        = WA_AI::queue_counts();

		if ( '' === $model ) {
			$default_model = WA_AI::preferred_model_for_provider( $provider_id );
			$model_label   = '' !== $default_model
				/* translators: %s: model id */
				? sprintf( __( '%s, the provider default', 'wellactually' ), $default_model )
				: __( 'provider default', 'wellactually' );
		} else {
			$model_label = $model;
		}

		echo '<p class="description">';
		printf(
			/* translators: 1: provider name, 2: model id */
			esc_html__( 'Sends needs-setup posts to %1$s (%2$s) to draft a statement and verdict. Suggestions appear here for your review — nothing goes live until you Save it.', 'wellactually' ),
			'<strong>' . esc_html( $provider_name ) . '</strong>',
			esc_html( $model_label )
		);
		echo '</p>';

		$effective = WA_AI::effective_model( $provider_id );
		if ( '' !== $effective && false === WA_AI::model_supports_drafting( $provider_id, $effective ) ) {
			echo '<p class="wa-ai-model-warning">';
			printf(
				/* translators: %s: model id */
				esc_html__( '%s does not advertise schema-enforced JSON output. Drafting still works — it asks for JSON in the prompt instead — but results can be less consistent.', 'wellactually' ),
				'<strong>' . esc_html( $effective ) . '</strong>'
			);
			echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=wellactually&tab=setup' ) ) . '">' . esc_html__( 'Change the model', 'wellactually' ) . '</a>';
			echo '</p>';
		}
		?>
		<div class="wa-ai-controls">
			<label for="wa-ai-count"><?php esc_html_e( 'How many to draft:', 'wellactually' ); ?></label>
			<input type="number" id="wa-ai-count" value="10" min="1" max="200" step="1" />
			<?php if ( $args['cat'] > 0 ) : ?>
				<span class="wa-ai-cat-note"><?php esc_html_e( '(limited to the selected category)', 'wellactually' ); ?></span>
			<?php endif; ?>
			<button type="button" class="button button-secondary" id="wa-ai-draft-btn"><?php esc_html_e( 'Draft with AI', 'wellactually' ); ?></button>
			<span class="wa-ai-progress" id="wa-ai-progress" aria-live="polite"></span>
		</div>
		<?php
		if ( $counts['ready'] > 0 || $counts['error'] > 0 ) {
			$review_url = add_query_arg(
				array(
					'page'      => self::MENU_SLUG,
					'wa_status' => 'has_ai',
				),
				admin_url( 'edit.php' )
			);
			echo '<p class="wa-ai-standing">';
			printf(
				/* translators: %d: number of ready suggestions */
				esc_html( _n( '%d suggestion ready to review.', '%d suggestions ready to review.', $counts['ready'], 'wellactually' ) ),
				(int) $counts['ready']
			);
			echo ' <a href="' . esc_url( $review_url ) . '">' . esc_html__( 'Review them', 'wellactually' ) . '</a>';
			echo '</p>';
		}

		// Config for the drafting JS.
		$config = array(
			'restUrl'  => esc_url_raw( rest_url( 'wellactually/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'cat'      => (int) $args['cat'],
			'concurrency' => WA_Settings::ai_concurrency(),
			'reviewUrl' => esc_url_raw(
				add_query_arg(
					array(
						'page'      => self::MENU_SLUG,
						'wa_status' => 'has_ai',
					),
					admin_url( 'edit.php' )
				)
			),
		);
		echo '<script>window.waAiConfig = ' . wp_json_encode( $config ) . ';</script>';

		echo '</div>';
	}

	/**
	 * Render the filter bar (a GET form).
	 *
	 * @param array    $args  Current args.
	 * @param WP_Query $query The results query (for the count).
	 */
	private function render_filters( $args, $query ) {
		$statuses = array(
			'needs_setup' => __( 'Needs to be set up', 'wellactually' ),
			'has_ai'      => __( 'Has AI suggestions', 'wellactually' ),
			'in_deck'     => __( 'In swipe deck', 'wellactually' ),
			'skipped'     => __( 'Skipped for Now', 'wellactually' ),
			'excluded'    => __( 'Excluded', 'wellactually' ),
			'all'         => __( 'All posts', 'wellactually' ),
		);
		?>
		<form method="get" class="wa-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />

			<label for="wa_status" class="screen-reader-text"><?php esc_html_e( 'Status', 'wellactually' ); ?></label>
			<select name="wa_status" id="wa_status">
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $args['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="wa_cat" class="screen-reader-text"><?php esc_html_e( 'Category', 'wellactually' ); ?></label>
			<?php
			wp_dropdown_categories(
				array(
					'show_option_all' => __( 'All categories', 'wellactually' ),
					'name'            => 'wa_cat',
					'id'              => 'wa_cat',
					'selected'        => $args['cat'],
					'hierarchical'    => true,
					'hide_empty'      => false,
					'orderby'         => 'name',
				)
			);
			?>

			<label for="wa_orderby" class="screen-reader-text"><?php esc_html_e( 'Sort by', 'wellactually' ); ?></label>
			<select name="wa_orderby" id="wa_orderby">
				<option value="date" <?php selected( $args['orderby'], 'date' ); ?>><?php esc_html_e( 'Date published', 'wellactually' ); ?></option>
				<option value="modified" <?php selected( $args['orderby'], 'modified' ); ?>><?php esc_html_e( 'Date modified', 'wellactually' ); ?></option>
				<option value="comment_count" <?php selected( $args['orderby'], 'comment_count' ); ?>><?php esc_html_e( 'Comment count', 'wellactually' ); ?></option>
			</select>

			<?php
			$order_labels = array(
				'date'          => array( 'DESC' => __( 'Newest first', 'wellactually' ), 'ASC' => __( 'Oldest first', 'wellactually' ) ),
				'modified'      => array( 'DESC' => __( 'Recently updated first', 'wellactually' ), 'ASC' => __( 'Least recently updated first', 'wellactually' ) ),
				'comment_count' => array( 'DESC' => __( 'Most comments first', 'wellactually' ), 'ASC' => __( 'Fewest comments first', 'wellactually' ) ),
			);
			$labels = $order_labels[ $args['orderby'] ];
			?>
			<label for="wa_order" class="screen-reader-text"><?php esc_html_e( 'Order', 'wellactually' ); ?></label>
			<select name="wa_order" id="wa_order">
				<option value="DESC" <?php selected( $args['order'], 'DESC' ); ?>><?php echo esc_html( $labels['DESC'] ); ?></option>
				<option value="ASC" <?php selected( $args['order'], 'ASC' ); ?>><?php echo esc_html( $labels['ASC'] ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'wellactually' ), 'secondary', '', false ); ?>

			<span class="wa-count">
				<?php
				printf(
					/* translators: %d: number of matching posts */
					esc_html( _n( '%d post', '%d posts', (int) $query->found_posts, 'wellactually' ) ),
					(int) $query->found_posts
				);
				?>
			</span>
		</form>
		<?php
	}

	/**
	 * Render the editable table + Save button (a POST form).
	 *
	 * @param array    $args  Current args.
	 * @param WP_Query $query The results query.
	 */
	private function render_form( $args, $query ) {
		if ( ! $query->have_posts() ) {
			echo '<p>' . esc_html__( 'No posts match this filter.', 'wellactually' ) . '</p>';
			return;
		}

		$verdict_options = array(
			''          => __( '— pick one —', 'wellactually' ),
			'true'      => __( 'True', 'wellactually' ),
			'false'     => __( 'False', 'wellactually' ),
			'debatable' => __( 'Debatable', 'wellactually' ),
		);
		?>
		<form method="post" class="wa-bulk-form">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wa_status" value="<?php echo esc_attr( $args['status'] ); ?>" />
			<input type="hidden" name="wa_cat" value="<?php echo esc_attr( $args['cat'] ); ?>" />
			<input type="hidden" name="wa_order" value="<?php echo esc_attr( $args['order'] ); ?>" />
			<input type="hidden" name="wa_orderby" value="<?php echo esc_attr( $args['orderby'] ); ?>" />
			<input type="hidden" name="paged" value="<?php echo esc_attr( $args['paged'] ); ?>" />

			<table class="widefat striped wa-bulk-table">
				<thead>
					<tr>
						<th class="wa-col-post"><?php esc_html_e( 'Post', 'wellactually' ); ?></th>
						<th class="wa-col-statement"><?php esc_html_e( 'Swipe Statement', 'wellactually' ); ?></th>
						<th class="wa-col-verdict"><?php esc_html_e( 'Verdict', 'wellactually' ); ?></th>
						<th class="wa-col-skip"><?php esc_html_e( 'Skip for Now', 'wellactually' ); ?></th>
						<th class="wa-col-exclude"><?php esc_html_e( 'Never', 'wellactually' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						$post_id   = get_the_ID();
						// Decoded for editing: an author should see (and save) a
						// real apostrophe, not a stored "&#8217;".
						$statement = WA_Meta::plain_text( get_post_meta( $post_id, WA_Meta::STATEMENT_KEY, true ) );
						$verdict   = get_post_meta( $post_id, WA_Meta::VERDICT_KEY, true );
						$excluded  = ( WA_Meta::VERDICT_EXCLUDED === $verdict );
						$skipped   = ( '1' === get_post_meta( $post_id, WA_Meta::SKIP_KEY, true ) );
						$name      = 'wa_bulk[' . $post_id . ']';

						// If there's no accepted content yet but a ready AI draft
						// exists, pre-fill the inputs with the suggestion.
						$ai_status = get_post_meta( $post_id, WA_Meta::AI_STATUS_KEY, true );
						$ai_error  = get_post_meta( $post_id, WA_Meta::AI_ERROR_KEY, true );
						$is_ai     = false;
						if ( '' === $statement && '' === $verdict && WA_AI::STATUS_READY === $ai_status ) {
							$ai_stmt = get_post_meta( $post_id, WA_Meta::AI_STATEMENT_KEY, true );
							$ai_vdct = get_post_meta( $post_id, WA_Meta::AI_VERDICT_KEY, true );
							if ( '' !== $ai_stmt && in_array( $ai_vdct, WA_Meta::deck_verdicts(), true ) ) {
								$statement = WA_Meta::plain_text( $ai_stmt );
								$verdict   = $ai_vdct;
								$is_ai     = true;
							}
						}

						$row_classes = 'wa-row';
						if ( $excluded ) {
							$row_classes .= ' wa-row-excluded';
						}
						if ( $skipped ) {
							$row_classes .= ' wa-row-skipped';
						}
						if ( $is_ai ) {
							$row_classes .= ' wa-row-ai';
						}
						?>
						<tr class="<?php echo esc_attr( $row_classes ); ?>" data-post="<?php echo esc_attr( $post_id ); ?>">
							<td class="wa-col-post">
								<strong>
									<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title() ? WA_Meta::plain_text( get_the_title() ) : __( '(no title)', 'wellactually' ) ); ?></a>
								</strong>
								<div class="wa-post-meta">
									<?php echo esc_html( get_the_date() ); ?>
								</div>
								<div class="wa-post-preview"><?php echo esc_html( $this->post_preview( get_post() ) ); ?></div>
							</td>
							<td class="wa-col-statement">
								<?php if ( $is_ai ) : ?>
									<span class="wa-ai-badge"><?php esc_html_e( 'AI suggestion', 'wellactually' ); ?></span>
								<?php elseif ( WA_AI::STATUS_ERROR === $ai_status && '' !== $ai_error ) : ?>
									<a
										class="wa-ai-error"
										href="<?php echo esc_url( admin_url( 'options-general.php?page=wellactually&tab=errors' ) ); ?>"
										target="_blank"
										rel="noopener"
										title="<?php echo esc_attr( $ai_error ); ?>"
									><?php esc_html_e( 'AI error', 'wellactually' ); ?></a>
								<?php endif; ?>
								<textarea
									name="<?php echo esc_attr( $name ); ?>[statement]"
									rows="2"
									class="wa-statement-input"
									placeholder="<?php esc_attr_e( 'e.g. Temp tables are faster than CTEs', 'wellactually' ); ?>"
								><?php echo esc_textarea( $excluded ? '' : $statement ); ?></textarea>
							</td>
							<td class="wa-col-verdict">
								<select name="<?php echo esc_attr( $name ); ?>[verdict]" class="wa-verdict-input">
									<?php foreach ( $verdict_options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $excluded ? '' : $verdict, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td class="wa-col-skip">
								<label class="wa-skip-label">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[skip]" value="1" class="wa-skip-input" <?php checked( $skipped ); ?> />
									<span class="screen-reader-text"><?php esc_html_e( 'Skip for now (keep content)', 'wellactually' ); ?></span>
								</label>
							</td>
							<td class="wa-col-exclude">
								<label class="wa-exclude-label">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exclude]" value="1" class="wa-exclude-input" <?php checked( $excluded ); ?> />
									<span class="screen-reader-text"><?php esc_html_e( 'Never set up for swipe', 'wellactually' ); ?></span>
								</label>
							</td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>

			<div class="wa-bulk-footer">
				<?php $this->render_pagination( $args, $query ); ?>
				<?php submit_button( __( 'Save all on this page', 'wellactually' ), 'primary', 'wa_bulk_submit', false ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Render pagination links, preserving the current filters.
	 *
	 * @param array    $args  Current args.
	 * @param WP_Query $query The results query.
	 */
	private function render_pagination( $args, $query ) {
		$total_pages = (int) $query->max_num_pages;
		if ( $total_pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'       => self::MENU_SLUG,
				'wa_status'  => $args['status'],
				'wa_cat'     => $args['cat'],
				'wa_order'   => $args['order'],
				'wa_orderby' => $args['orderby'],
				'paged'      => '%#%',
			),
			admin_url( 'edit.php' )
		);

		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $args['paged'],
				'total'     => $total_pages,
				'prev_text' => __( '&laquo; Previous', 'wellactually' ),
				'next_text' => __( 'Next &raquo;', 'wellactually' ),
				'type'      => 'plain',
			)
		);

		if ( $links ) {
			echo '<div class="wa-pagination tablenav-pages">' . wp_kses_post( $links ) . '</div>';
		}
	}

	/**
	 * A plain-text preview of a post: its manual excerpt, or the start of the
	 * content when there's no excerpt.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function post_preview( $post ) {
		if ( has_excerpt( $post ) ) {
			return WA_Meta::plain_text( wp_strip_all_tags( get_the_excerpt( $post ) ) );
		}

		$content = get_the_content( '', false, $post );
		$content = strip_shortcodes( $content );
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}
		$content = wp_strip_all_tags( $content );
		// Decode before trimming so a word-trim can't slice an entity in half.
		$content = WA_Meta::plain_text( $content );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		return wp_trim_words( $content, 55, '…' );
	}

	/**
	 * Print the screen's CSS.
	 */
	private function print_styles() {
		?>
		<style>
			.wa-bulk-setup .wa-filters { margin: 12px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
			.wa-bulk-setup .wa-count { color: #646970; }
			.wa-ai-panel { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; padding: 8px 16px 16px; margin: 12px 0; }
			.wa-ai-panel h2 { margin: 12px 0 4px; }
			.wa-ai-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 8px 0; }
			.wa-ai-controls #wa-ai-count { width: 70px; }
			.wa-ai-cat-note, .wa-ai-progress { color: #646970; }
			.wa-ai-model-warning { background: #fcf9e8; border-left: 4px solid #dba617; padding: 8px 12px; margin: 8px 0; }
			.wa-ai-standing { margin: 4px 0 0; }
			.wa-bulk-table { margin-top: 8px; }
			.wa-bulk-table th.wa-col-post,
			.wa-bulk-table td.wa-col-post { width: 38%; }
			.wa-bulk-table th.wa-col-statement,
			.wa-bulk-table td.wa-col-statement { width: 32%; }
			.wa-bulk-table th.wa-col-verdict,
			.wa-bulk-table td.wa-col-verdict { width: 14%; }
			.wa-bulk-table th.wa-col-skip,
			.wa-bulk-table td.wa-col-skip { width: 8%; text-align: center; }
			.wa-bulk-table th.wa-col-exclude,
			.wa-bulk-table td.wa-col-exclude { width: 8%; text-align: center; }
			.wa-bulk-table .wa-post-meta { color: #646970; font-size: 12px; margin: 2px 0; }
			.wa-bulk-table .wa-post-preview { color: #50575e; font-size: 13px; line-height: 1.5; }
			.wa-bulk-table .wa-statement-input { width: 100%; }
			.wa-bulk-table .wa-verdict-input { width: 100%; max-width: 160px; }
			.wa-ai-badge { display: inline-block; background: #2271b1; color: #fff; font-size: 11px; font-weight: 600; padding: 1px 8px; border-radius: 3px; margin-bottom: 4px; }
			.wa-ai-error { display: inline-block; background: #d63638; color: #fff; font-size: 11px; font-weight: 600; padding: 1px 8px; border-radius: 3px; margin-bottom: 4px; text-decoration: none; }
			.wa-ai-error:hover, .wa-ai-error:focus { background: #e65054; color: #fff; }
			.wa-bulk-table tr.wa-row-ai { background: #f0f6fc; }
			.wa-bulk-table tr.wa-row-excluded .wa-statement-input,
			.wa-bulk-table tr.wa-row-excluded .wa-verdict-input,
			.wa-bulk-table tr.wa-row-skipped .wa-statement-input,
			.wa-bulk-table tr.wa-row-skipped .wa-verdict-input { opacity: .4; }
			.wa-bulk-footer { display: flex; justify-content: space-between; align-items: center; margin: 16px 0; flex-wrap: wrap; gap: 12px; }
			.wa-bulk-footer .wa-pagination { margin: 0; }
			.wa-bulk-footer .button-primary { font-size: 15px; padding: 6px 20px; height: auto; }
		</style>
		<?php
	}

	/**
	 * Print the screen's progressive-enhancement scripts: dim a row's inputs
	 * when it's excluded or skipped, and drive the AI drafting loop.
	 */
	private function print_scripts() {
		?>
		<script>
		( function () {
			// Dim statement/verdict when "Never" or "Skip" is checked. Both
			// leave the content untouched on save (Never clears it, Skip holds
			// it), so disabling the inputs is purely a visual cue.
			function syncRow( row ) {
				if ( ! row ) { return; }
				var exclude = row.querySelector( '.wa-exclude-input' );
				var skip = row.querySelector( '.wa-skip-input' );
				var off = ( exclude && exclude.checked ) || ( skip && skip.checked );
				row.classList.toggle( 'wa-row-excluded', !! ( exclude && exclude.checked ) );
				row.classList.toggle( 'wa-row-skipped', !! ( skip && skip.checked ) );
				var s = row.querySelector( '.wa-statement-input' );
				var v = row.querySelector( '.wa-verdict-input' );
				if ( s ) { s.disabled = off; }
				if ( v ) { v.disabled = off; }
			}

			document.querySelectorAll( '.wa-bulk-table .wa-row' ).forEach( function ( row ) {
				var exclude = row.querySelector( '.wa-exclude-input' );
				var skip = row.querySelector( '.wa-skip-input' );
				// Never and Skip are mutually exclusive in intent; unchecking the
				// other keeps the UI unambiguous.
				if ( exclude ) {
					exclude.addEventListener( 'change', function () {
						if ( exclude.checked && skip ) { skip.checked = false; }
						syncRow( row );
					} );
				}
				if ( skip ) {
					skip.addEventListener( 'change', function () {
						if ( skip.checked && exclude ) { exclude.checked = false; }
						syncRow( row );
					} );
				}
				syncRow( row );
			} );

			// AI drafting loop: enqueue N posts, then process one at a time,
			// showing live progress. Browser-driven so it never blocks a single
			// request and the user watches results as they arrive.
			var cfg = window.waAiConfig;
			var btn = document.getElementById( 'wa-ai-draft-btn' );
			var progress = document.getElementById( 'wa-ai-progress' );
			if ( ! cfg || ! btn || ! progress ) { return; }

			function api( path, body ) {
				return fetch( cfg.restUrl + '/' + path, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cfg.nonce
					},
					body: JSON.stringify( body || {} )
				} ).then( function ( r ) {
					if ( ! r.ok ) { throw new Error( 'Request failed: ' + r.status ); }
					return r.json();
				} );
			}

			var running = false;

			btn.addEventListener( 'click', function () {
				if ( running ) { return; }
				var countEl = document.getElementById( 'wa-ai-count' );
				var count = Math.max( 1, Math.min( 200, parseInt( countEl && countEl.value, 10 ) || 10 ) );

				running = true;
				btn.disabled = true;
				progress.textContent = 'Queuing…';

				// Hand back the previous batch id. If that run stopped early
				// with work outstanding, the server resumes it instead of
				// starting a new batch and stranding the old one's posts.
				var previous = '';
				try { previous = window.sessionStorage.getItem( 'waAiBatch' ) || ''; } catch ( e ) {}

				api( 'ai/enqueue', { count: count, cat: cfg.cat, batch: previous } ).then( function ( res ) {
					var batch = res.batch;
					var total = res.queued || 0;

					try { window.sessionStorage.setItem( 'waAiBatch', batch ); } catch ( e ) {}

					if ( ! total ) {
						progress.textContent = 'No un-drafted posts match this filter.';
						running = false;
						btn.disabled = false;
						return;
					}

					// Counted separately and never subtracted from one another:
					// a lost response is not a failed draft, and inferring one
					// from the other is what produced negative totals before.
					var drafted = 0, failed = 0, unknown = 0;

					function summary() {
						var parts = [ drafted + ' drafted' ];
						if ( failed ) { parts.push( failed + ' error' + ( failed > 1 ? 's' : '' ) ); }
						if ( unknown ) { parts.push( unknown + ' unconfirmed' ); }
						return parts.join( ', ' );
					}

					function report() {
						progress.textContent = 'Drafting… ' + ( drafted + failed ) + ' of ' + total + ' — ' + summary();
					}

					function finish( remaining ) {
						if ( remaining > 0 ) {
							// The server still holds work for this batch, so
							// this run stopped short rather than finished.
							progress.innerHTML = 'Stopped early — ' + summary() + ', ' + remaining +
								' still queued. Click Draft with AI again to resume. ' +
								'<a href="' + cfg.reviewUrl + '">Review suggestions</a>';
						} else {
							try { window.sessionStorage.removeItem( 'waAiBatch' ); } catch ( e ) {}
							progress.innerHTML = 'Done — ' + summary() +
								'. <a href="' + cfg.reviewUrl + '">Review suggestions</a>';
						}
						running = false;
						btn.disabled = false;
					}

					var workers = Math.max( 1, Math.min( 20, parseInt( cfg.concurrency, 10 ) || 5 ) );
					var alive = Math.min( workers, total );
					var lastRemaining = total;

					function retire() {
						alive--;
						if ( alive <= 0 ) { finish( lastRemaining ); }
					}

					function worker() {
						fetch( cfg.restUrl + '/ai/process', {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': cfg.nonce
							},
							body: JSON.stringify( { batch: batch } )
						} ).then( function ( r ) {
							// Site-wide ceiling is full (other tabs, other
							// users). The work is still queued, so wait and
							// retry instead of counting a failure.
							if ( r.status === 429 ) {
								var wait = ( parseInt( r.headers.get( 'Retry-After' ), 10 ) || 2 ) * 1000;
								setTimeout( worker, wait );
								return null;
							}
							if ( ! r.ok ) { throw new Error( 'Request failed: ' + r.status ); }
							return r.json();
						} ).then( function ( out ) {
							if ( ! out ) { return; }

							if ( out.counts && typeof out.counts.remaining !== 'undefined' ) {
								lastRemaining = out.counts.remaining;
							}

							if ( out.processed ) {
								if ( 'ready' === out.processed.status ) {
									drafted++;
								} else if ( 'stale' === out.processed.status ) {
									// Reassigned to another run mid-flight;
									// whoever owns it now reports the outcome.
								} else {
									failed++;
								}
								report();
								worker();
							} else {
								retire();
							}
						} ).catch( function () {
							// The request didn't come back. The server may or
							// may not have drafted it, so this is neither a
							// success nor a failure — record it as unconfirmed
							// and let the server's batch counts decide whether
							// the run is actually finished.
							unknown++;
							report();
							retire();
						} );
					}

					report();
					for ( var w = 0; w < alive; w++ ) {
						worker();
					}
				} ).catch( function () {
					progress.textContent = 'Could not start drafting. Please try again.';
					running = false;
					btn.disabled = false;
				} );
			} );
		} )();
		</script>
		<?php
	}
}
