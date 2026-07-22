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

	const STATEMENT_KEY = '_wa_statement';
	const VERDICT_KEY   = '_wa_verdict';
	const NONCE_ACTION  = 'wa_save_meta';
	const NONCE_NAME    = 'wa_meta_nonce';

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
	 * Get the allowed verdict values, keyed by stored value.
	 *
	 * @return array
	 */
	public static function verdict_choices() {
		return array(
			''          => __( '— Not in swipe deck —', 'well-actually' ),
			'true'      => __( 'True (agree is correct)', 'well-actually' ),
			'false'     => __( 'False (disagree is correct)', 'well-actually' ),
			'debatable' => __( 'Debatable (any answer is fine)', 'well-actually' ),
		);
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
			__( 'WellActually Swipe', 'well-actually' ),
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
			<label for="wa_statement"><strong><?php esc_html_e( 'Swipe Statement', 'well-actually' ); ?></strong></label><br />
			<textarea
				id="wa_statement"
				name="wa_statement"
				rows="2"
				style="width:100%;"
				placeholder="<?php esc_attr_e( 'A one-line statement readers will agree or disagree with, e.g. Temp tables are faster than CTEs.', 'well-actually' ); ?>"
			><?php echo esc_textarea( $statement ); ?></textarea>
		</p>
		<p>
			<label for="wa_verdict"><strong><?php esc_html_e( 'Verdict', 'well-actually' ); ?></strong></label><br />
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

		if ( '' === $statement || '' === $verdict ) {
			delete_post_meta( $post_id, self::STATEMENT_KEY );
			delete_post_meta( $post_id, self::VERDICT_KEY );
			return;
		}

		update_post_meta( $post_id, self::STATEMENT_KEY, $statement );
		update_post_meta( $post_id, self::VERDICT_KEY, $verdict );
	}

	/**
	 * Add the "Swipe" column to the Posts list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		$columns['wa_swipe'] = __( 'Swipe', 'well-actually' );
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
			'true'      => '&#10003; ' . __( 'True', 'well-actually' ),
			'false'     => '&#10007; ' . __( 'False', 'well-actually' ),
			'debatable' => '~ ' . __( 'Debatable', 'well-actually' ),
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
			''          => __( 'All swipe statuses', 'well-actually' ),
			'in_deck'   => __( 'In swipe deck', 'well-actually' ),
			'true'      => __( 'True', 'well-actually' ),
			'false'     => __( 'False', 'well-actually' ),
			'debatable' => __( 'Debatable', 'well-actually' ),
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

		if ( 'in_deck' === $filter ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => self::VERDICT_KEY,
						'value'   => array( 'true', 'false', 'debatable' ),
						'compare' => 'IN',
					),
				)
			);
		} elseif ( in_array( $filter, array( 'true', 'false', 'debatable' ), true ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => self::VERDICT_KEY,
						'value' => $filter,
					),
				)
			);
		}
	}
}
