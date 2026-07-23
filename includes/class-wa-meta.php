<?php
/**
 * Post meta box: Swipe Statement + verdict. Admin column and filter.
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

	const STATEMENT_KEY   = '_wa_statement';
	const VERDICT_KEY     = '_wa_verdict';
	const VERDICT_EXCLUDED = 'excluded';
	const NONCE_ACTION    = 'wa_save_meta';
	const NONCE_NAME      = 'wa_meta_nonce';

	// AI draft suggestions (awaiting human review; not live until accepted).
	const AI_STATEMENT_KEY = '_wa_ai_statement';
	const AI_VERDICT_KEY   = '_wa_ai_verdict';
	const AI_STATUS_KEY    = '_wa_ai_status';    // queued | ready | error
	const AI_ERROR_KEY      = '_wa_ai_error';
	const AI_ERROR_TIME_KEY = '_wa_ai_error_time'; // unix timestamp, for the Settings → Errors tab's 7-day retention.
	const AI_CLAIMED_KEY    = '_wa_ai_claimed';    // unix timestamp a worker claimed the post, so an abandoned claim can be released.

	// "Skip for now": keeps the post's content but holds it out of the deck
	// and the review lists. Distinct from the permanent 'excluded' verdict.
	const SKIP_KEY = '_wa_skip';

	// A single denormalized status, recomputed by recompute_status()
	// whenever the underlying verdict/AI-status/skip meta changes. Exists
	// purely so the "Needs setup" / "Has AI suggestions" / "In swipe deck"
	// filters (used on every load of Well Actually Setup) are a single
	// indexed meta_key lookup instead of a multi-key, multi-join meta_query —
	// at real archive scale (thousands of posts, tens of thousands of
	// postmeta rows from other plugins) the multi-join version measured in
	// the seconds per query; this measures in milliseconds. See
	// recompute_status() and status_meta_query().
	const STATUS_KEY = '_wa_status';

	const STATUS_NEEDS_SETUP = 'needs_setup';
	const STATUS_HAS_AI      = 'has_ai';
	const STATUS_CONFIGURED  = 'configured';
	const STATUS_EXCLUDED    = 'excluded';
	const STATUS_SKIPPED     = 'skipped';

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
	 * Get the meta-box verdict dropdown options, keyed by stored value.
	 *
	 * @return array
	 */
	public static function verdict_choices() {
		return array(
			''                     => __( '— Not set up yet —', 'wellactually' ),
			'true'                 => __( 'True (agree is correct)', 'wellactually' ),
			'false'                => __( 'False (disagree is correct)', 'wellactually' ),
			'debatable'            => __( 'Debatable (any answer is fine)', 'wellactually' ),
			self::VERDICT_EXCLUDED => __( 'Never — exclude from swipe', 'wellactually' ),
		);
	}

	/**
	 * The verdict values that place a post in the swipe deck.
	 *
	 * @return string[]
	 */
	public static function deck_verdicts() {
		return array( 'true', 'false', 'debatable' );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );

		add_filter( 'manage_post_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_post_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-post_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_verdict' ) );

		add_action( 'restrict_manage_posts', array( $this, 'render_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_by_verdict' ) );

		// Deliberately not activation-only: a plain file update (no
		// deactivate/reactivate) needs this to run too, so it's hooked here
		// with a get_option() guard that makes every call after the first a
		// no-op — see maybe_backfill_status().
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_status' ) );
	}

	/**
	 * Register the meta box on the post edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'wa_swipe_meta_box',
			__( 'Well, Actually... Swipe', 'wellactually' ),
			array( $this, 'render_meta_box' ),
			'post',
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box fields.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		// Decoded for editing: show a real apostrophe, not a stored "&#8217;".
		$statement = self::plain_text( get_post_meta( $post->ID, self::STATEMENT_KEY, true ) );
		$verdict   = get_post_meta( $post->ID, self::VERDICT_KEY, true );
		?>
		<p>
			<label for="wa_statement"><strong><?php esc_html_e( 'Swipe Statement', 'wellactually' ); ?></strong></label><br />
			<textarea
				id="wa_statement"
				name="wa_statement"
				rows="2"
				style="width:100%;"
				placeholder="<?php esc_attr_e( 'A one-line statement readers will agree or disagree with, e.g. Temp tables are faster than CTEs.', 'wellactually' ); ?>"
			><?php echo esc_textarea( $statement ); ?></textarea>
		</p>
		<p>
			<label for="wa_verdict"><strong><?php esc_html_e( 'Verdict', 'wellactually' ); ?></strong></label><br />
			<select id="wa_verdict" name="wa_verdict">
				<?php foreach ( self::verdict_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $verdict, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Save the meta box fields.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$statement = isset( $_POST['wa_statement'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wa_statement'] ) ) : '';
		$verdict   = isset( $_POST['wa_verdict'] ) ? sanitize_text_field( wp_unslash( $_POST['wa_verdict'] ) ) : '';

		$valid_verdicts = array_keys( self::verdict_choices() );
		if ( ! in_array( $verdict, $valid_verdicts, true ) ) {
			$verdict = '';
		}

		self::apply_meta( $post_id, $statement, $verdict );
	}

	/**
	 * Apply a statement/verdict pair to a post, normalizing the four
	 * possible states. Shared by the meta box and the bulk-setup screen.
	 *
	 * - Excluded: verdict is stored, statement cleared (no statement needed).
	 * - Configured: a deck verdict plus a non-empty statement stores both.
	 * - Anything else (blank, or incomplete): both keys are removed, so the
	 *   post falls back to the "not set up yet" state.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $statement Sanitized statement text.
	 * @param string $verdict   Sanitized verdict value.
	 * @return string One of 'excluded' | 'configured' | 'cleared' | 'incomplete' | 'unchanged'.
	 */
	public static function apply_meta( $post_id, $statement, $verdict ) {
		if ( self::VERDICT_EXCLUDED === $verdict ) {
			update_post_meta( $post_id, self::VERDICT_KEY, self::VERDICT_EXCLUDED );
			delete_post_meta( $post_id, self::STATEMENT_KEY );
			self::recompute_status( $post_id );
			return 'excluded';
		}

		$has_statement   = '' !== $statement;
		$has_deck_verdict = in_array( $verdict, self::deck_verdicts(), true );

		if ( $has_statement && $has_deck_verdict ) {
			update_post_meta( $post_id, self::STATEMENT_KEY, $statement );
			update_post_meta( $post_id, self::VERDICT_KEY, $verdict );
			self::recompute_status( $post_id );
			return 'configured';
		}

		// A statement without a verdict (or vice versa) is incomplete: don't
		// silently discard a half-entered row — report it and leave the post
		// as it was.
		if ( $has_statement || $has_deck_verdict ) {
			return 'incomplete';
		}

		// Nothing entered. Clear any existing swipe meta, but only report a
		// "clear" when there was actually something to remove — an untouched
		// blank row on the Needs-setup screen is a no-op, not a change.
		$had_meta = ( '' !== (string) get_post_meta( $post_id, self::STATEMENT_KEY, true ) )
			|| ( '' !== (string) get_post_meta( $post_id, self::VERDICT_KEY, true ) );

		if ( ! $had_meta ) {
			return 'unchanged';
		}

		delete_post_meta( $post_id, self::STATEMENT_KEY );
		delete_post_meta( $post_id, self::VERDICT_KEY );
		self::recompute_status( $post_id );
		return 'cleared';
	}

	/**
	 * Recompute and store the single denormalized _wa_status value for a
	 * post, from its current verdict/AI-status/skip meta. Call this
	 * whenever any of those three change — apply_meta() covers the
	 * statement/verdict/exclude paths; WA_Bulk_Setup's skip toggle and
	 * WA_AI's store_result()/store_error() call it directly since they
	 * write their meta outside of apply_meta().
	 *
	 * Priority when more than one could apply: skipped wins (a skipped post
	 * never shows under needs_setup/has_ai/in_deck regardless of its other
	 * meta), then excluded/configured (a resolved verdict), then a ready AI
	 * suggestion, else needs_setup.
	 *
	 * Also bumps WordPress's posts "last changed" cache marker. On a site
	 * with a persistent object cache, WP_Query caches its post-ID result
	 * lists keyed to that marker — but the marker only bumps automatically
	 * when a post itself changes (title, status, date…), not when post meta
	 * does. Without this, every meta_query built on STATUS_KEY (this
	 * screen's filters, AI candidate selection, the swipe deck) can keep
	 * serving a stale ID list — including IDs that no longer match — after
	 * any save here, until something unrelated happens to bump it.
	 *
	 * @param int $post_id Post ID.
	 * @return string The stored status value.
	 */
	public static function recompute_status( $post_id ) {
		$status = self::compute_status( $post_id );

		update_post_meta( $post_id, self::STATUS_KEY, $status );
		wp_cache_set_posts_last_changed();

		return $status;
	}

	/**
	 * Decode HTML entities into real characters, for text that's about to be
	 * handed to something that will do its own escaping (the swipe frontend's
	 * JSON payloads, an esc_html() call in an admin template).
	 *
	 * Post titles and excerpts routinely arrive entity-encoded — WordPress
	 * stores them that way and get_the_title()/get_the_excerpt() apply
	 * filters that add more — so escaping them a second time is what turns a
	 * curly apostrophe into a literal "&#8217;" on screen. Note this has to
	 * be html_entity_decode(), not wp_specialchars_decode(): the latter only
	 * handles &amp;/&lt;/&gt;/&quot;/&#039; and would leave &#8217; alone.
	 *
	 * @param string $text Possibly entity-encoded text.
	 * @return string Plain text.
	 */
	public static function plain_text( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Work out what a post's status *should* be right now, from its live
	 * verdict/AI-status/skip meta — without writing anything. Used both by
	 * recompute_status() (which persists the result) and by callers that
	 * just need to verify a stored STATUS_KEY value is still accurate, e.g.
	 * a filtered listing self-healing a page of stale rows on read.
	 *
	 * @param int $post_id Post ID.
	 * @return string One of the STATUS_* constants.
	 */
	public static function compute_status( $post_id ) {
		$skipped   = '1' === get_post_meta( $post_id, self::SKIP_KEY, true );
		$verdict   = get_post_meta( $post_id, self::VERDICT_KEY, true );
		$ai_status = get_post_meta( $post_id, self::AI_STATUS_KEY, true );

		if ( $skipped ) {
			return self::STATUS_SKIPPED;
		}
		if ( self::VERDICT_EXCLUDED === $verdict ) {
			return self::STATUS_EXCLUDED;
		}
		if ( in_array( $verdict, self::deck_verdicts(), true ) ) {
			return self::STATUS_CONFIGURED;
		}
		if ( 'ready' === $ai_status ) {
			return self::STATUS_HAS_AI;
		}
		return self::STATUS_NEEDS_SETUP;
	}

	/**
	 * Add the "Swipe" column to the Posts list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		$columns['wa_swipe'] = __( 'Swipe', 'wellactually' );
		return $columns;
	}

	/**
	 * Render the "Swipe" column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'wa_swipe' !== $column ) {
			return;
		}

		$verdict = get_post_meta( $post_id, self::VERDICT_KEY, true );
		if ( '' === $verdict ) {
			echo '&#8212;';
			return;
		}

		$statement = get_post_meta( $post_id, self::STATEMENT_KEY, true );
		$icons     = array(
			'true'                 => '&#10003; ' . __( 'True', 'wellactually' ),
			'false'                => '&#10007; ' . __( 'False', 'wellactually' ),
			'debatable'            => '~ ' . __( 'Debatable', 'wellactually' ),
			self::VERDICT_EXCLUDED => '&#8856; ' . __( 'Excluded', 'wellactually' ),
		);
		$label     = isset( $icons[ $verdict ] ) ? $icons[ $verdict ] : $verdict;

		printf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( $statement ),
			esc_html( $label )
		);
	}

	/**
	 * Register the "Swipe" column as sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['wa_swipe'] = 'wa_swipe';
		return $columns;
	}

	/**
	 * Sort the Posts list by verdict when requested.
	 *
	 * @param WP_Query $query Current query.
	 */
	public function sort_by_verdict( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'wa_swipe' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', self::VERDICT_KEY );
		$query->set( 'orderby', 'meta_value' );
	}

	/**
	 * Render the "Swipe" filter dropdown above the Posts list.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_filter_dropdown( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}

		$current = isset( $_GET['wa_swipe_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['wa_swipe_filter'] ) ) : '';

		$options = array(
			''            => __( 'All swipe statuses', 'wellactually' ),
			'needs_setup' => __( 'Needs to be set up', 'wellactually' ),
			'in_deck'     => __( 'In swipe deck', 'wellactually' ),
			'true'        => __( 'True', 'wellactually' ),
			'false'       => __( 'False', 'wellactually' ),
			'debatable'   => __( 'Debatable', 'wellactually' ),
			'excluded'    => __( 'Excluded', 'wellactually' ),
		);
		?>
		<select name="wa_swipe_filter">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the "Swipe" filter to the Posts list query.
	 *
	 * @param WP_Query $query Current query.
	 */
	public function filter_by_verdict( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( empty( $_GET['wa_swipe_filter'] ) ) {
			return;
		}

		$filter = sanitize_text_field( wp_unslash( $_GET['wa_swipe_filter'] ) );

		$meta_query = self::status_meta_query( $filter );
		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Build a meta_query for a given swipe status. Shared by the Posts list
	 * filter and the bulk-setup screen so both agree on what each status means.
	 *
	 * needs_setup/has_ai/in_deck/skipped/(status-based)excluded all resolve
	 * against the single denormalized STATUS_KEY (maintained by
	 * recompute_status()) rather than combining verdict/ai-status/skip meta
	 * live: at archive scale, a meta_query spanning 3 keys with NOT EXISTS
	 * branches on each requires several LEFT JOINs against the entire
	 * wp_postmeta table (which holds every other plugin's meta too) and
	 * measured in the seconds per query on a ~3,000-post, ~50,000-row
	 * postmeta table in testing; a single indexed meta_key lookup on
	 * STATUS_KEY measured in milliseconds on the same data. true/false/
	 * debatable stay direct _wa_verdict lookups since STATUS_KEY only
	 * distinguishes "configured" in general, not which of the three verdicts.
	 *
	 * @param string $status One of needs_setup|has_ai|in_deck|true|false|debatable|excluded|skipped.
	 * @return array A WP_Query 'meta_query' array, or empty array for "all".
	 */
	public static function status_meta_query( $status ) {
		switch ( $status ) {
			case 'needs_setup':
				// Untouched posts never get a STATUS_KEY row until something
				// changes them, so "needs setup" also has to catch "no status
				// meta at all" — the common case for most of a real archive.
				return array(
					'relation' => 'OR',
					array(
						'key'     => self::STATUS_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => self::STATUS_KEY,
						'value' => self::STATUS_NEEDS_SETUP,
					),
				);

			case 'has_ai':
				return array(
					array(
						'key'   => self::STATUS_KEY,
						'value' => self::STATUS_HAS_AI,
					),
				);

			case 'skipped':
				return array(
					array(
						'key'   => self::SKIP_KEY,
						'value' => '1',
					),
				);

			case 'in_deck':
				return array(
					array(
						'key'   => self::STATUS_KEY,
						'value' => self::STATUS_CONFIGURED,
					),
				);

			case 'excluded':
				return array(
					array(
						'key'   => self::VERDICT_KEY,
						'value' => self::VERDICT_EXCLUDED,
					),
				);

			case 'true':
			case 'false':
			case 'debatable':
				return array(
					array(
						'key'   => self::VERDICT_KEY,
						'value' => $status,
					),
				);
		}

		return array();
	}

	/**
	 * One-time migration: populate STATUS_KEY for every post that already
	 * has swipe-related meta but predates the STATUS_KEY field (upgrading
	 * from a version before it existed). Guarded by an option flag so it
	 * only ever runs once. Safe to run multiple times if needed — it's
	 * idempotent — but the flag keeps it off the hot path.
	 */
	public static function maybe_backfill_status() {
		if ( get_option( 'wa_status_backfilled' ) ) {
			return;
		}

		global $wpdb;

		// Every post with ANY swipe-related meta but no STATUS_KEY row yet.
		// This is the same kind of multi-key query being retired from the hot
		// path, but it only ever runs once (guarded above), not per page load.
		$sql = "
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm
				ON pm.post_id = p.ID
				AND pm.meta_key IN (%s, %s, %s)
			LEFT JOIN {$wpdb->postmeta} existing
				ON existing.post_id = p.ID
				AND existing.meta_key = %s
			WHERE p.post_type = 'post'
			AND existing.meta_id IS NULL
		";

		$post_ids = $wpdb->get_col(
			$wpdb->prepare( $sql, self::VERDICT_KEY, self::AI_STATUS_KEY, self::SKIP_KEY, self::STATUS_KEY )
		);

		foreach ( $post_ids as $post_id ) {
			self::recompute_status( (int) $post_id );
		}

		update_option( 'wa_status_backfilled', 1, false );
	}
}
