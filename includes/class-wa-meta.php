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
	const AI_ERROR_KEY     = '_wa_ai_error';

	// "Skip for now": keeps the post's content but holds it out of the deck
	// and the review lists. Distinct from the permanent 'excluded' verdict.
	const SKIP_KEY = '_wa_skip';

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
	}

	/**
	 * Register the meta box on the post edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'wa_swipe_meta_box',
			__( 'WellActually Swipe', 'wellactually' ),
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

		$statement = get_post_meta( $post->ID, self::STATEMENT_KEY, true );
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
			return 'excluded';
		}

		$has_statement   = '' !== $statement;
		$has_deck_verdict = in_array( $verdict, self::deck_verdicts(), true );

		if ( $has_statement && $has_deck_verdict ) {
			update_post_meta( $post_id, self::STATEMENT_KEY, $statement );
			update_post_meta( $post_id, self::VERDICT_KEY, $verdict );
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
		return 'cleared';
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
			'needs_setup' => __( 'Needs setup', 'wellactually' ),
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
	 * An OR-group matching posts that are NOT skipped (skip flag absent or not 1).
	 *
	 * @return array
	 */
	private static function clause_not_skipped() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::SKIP_KEY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::SKIP_KEY,
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	/**
	 * An OR-group matching posts with no verdict set yet.
	 *
	 * @return array
	 */
	private static function clause_no_verdict() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::VERDICT_KEY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::VERDICT_KEY,
				'value'   => '',
				'compare' => '=',
			),
		);
	}

	/**
	 * An OR-group matching posts that do NOT have a ready AI suggestion.
	 *
	 * @return array
	 */
	private static function clause_no_ready_ai() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::AI_STATUS_KEY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::AI_STATUS_KEY,
				'value'   => 'ready',
				'compare' => '!=',
			),
		);
	}

	/**
	 * Build a meta_query for a given swipe status. Shared by the Posts list
	 * filter and the bulk-setup screen so both agree on what each status means.
	 *
	 * @param string $status One of needs_setup|has_ai|in_deck|true|false|debatable|excluded|skipped.
	 * @return array A WP_Query 'meta_query' array, or empty array for "all".
	 */
	public static function status_meta_query( $status ) {
		switch ( $status ) {
			case 'needs_setup':
				// Untouched: no verdict, no ready AI suggestion, not skipped.
				// These are the candidates to send to the AI.
				return array(
					'relation' => 'AND',
					self::clause_no_verdict(),
					self::clause_no_ready_ai(),
					self::clause_not_skipped(),
				);

			case 'has_ai':
				// A ready AI suggestion awaiting review, not skipped.
				return array(
					'relation' => 'AND',
					array(
						'key'   => self::AI_STATUS_KEY,
						'value' => 'ready',
					),
					self::clause_not_skipped(),
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
					'relation' => 'AND',
					array(
						'key'     => self::VERDICT_KEY,
						'value'   => self::deck_verdicts(),
						'compare' => 'IN',
					),
					self::clause_not_skipped(),
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
}
