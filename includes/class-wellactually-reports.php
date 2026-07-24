<?php
/**
 * Reports tab: how each configured swipe is actually performing.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Reports tab and handles its inline editing.
 */
class WellActually_Reports {

	const PER_PAGE = 20;

	/**
	 * Singleton instance.
	 *
	 * @var WellActually_Reports|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WellActually_Reports
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * The verdict labels shown in the table and the inline editor.
	 *
	 * @return array Verdict key => translated label.
	 */
	private static function verdict_labels() {
		return array(
			'true'      => __( 'True', 'wellactually' ),
			'false'     => __( 'False', 'wellactually' ),
			'debatable' => __( 'Debatable', 'wellactually' ),
		);
	}

	/**
	 * Enqueue the Reports tab's stylesheet and inline-editing script. The tab
	 * lives on the plugin's settings screen, so it piggybacks on that screen's
	 * hook suffix and only loads when the Reports tab is showing.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$settings = WellActually_Settings::instance();
		if ( ! $settings->hook() || $hook_suffix !== $settings->hook() || 'reports' !== $settings->current_tab() ) {
			return;
		}

		wp_enqueue_style(
			'wellactually-admin-reports',
			WELLACTUALLY_PLUGIN_URL . 'assets/css/admin-reports.css',
			array(),
			WELLACTUALLY_VERSION
		);

		wp_enqueue_script(
			'wellactually-admin-reports',
			WELLACTUALLY_PLUGIN_URL . 'assets/js/admin-reports.js',
			array(),
			WELLACTUALLY_VERSION,
			array( 'in_footer' => true )
		);

		wp_localize_script(
			'wellactually-admin-reports',
			'wellactuallyReports',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'wellactually/v1/report/quick-edit' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'verdicts' => self::verdict_labels(),
				'i18n'     => array(
					'statementLabel' => __( 'Swipe headline', 'wellactually' ),
					'verdictLabel'   => __( 'Right answer', 'wellactually' ),
					'update'         => __( 'Update', 'wellactually' ),
					'cancel'         => __( 'Cancel', 'wellactually' ),
					'saving'         => __( 'Saving…', 'wellactually' ),
					'saveFailed'     => __( 'Could not save.', 'wellactually' ),
				),
			)
		);
	}

	/**
	 * Register the inline-edit endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'wellactually/v1',
			'/report/quick-edit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_quick_edit' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'post_id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'statement' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'verdict'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * REST: save an inline edit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_quick_edit( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		// The route-level check covers "can edit posts at all"; this covers
		// "can edit *this* post".
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'wellactually_forbidden', __( 'You are not allowed to edit that post.', 'wellactually' ), array( 'status' => 403 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'wellactually_invalid_post', __( 'Post not found.', 'wellactually' ), array( 'status' => 404 ) );
		}

		$statement = (string) $request->get_param( 'statement' );
		$verdict   = (string) $request->get_param( 'verdict' );

		if ( ! in_array( $verdict, WellActually_Meta::deck_verdicts(), true ) ) {
			return new WP_Error( 'wellactually_invalid_verdict', __( 'Pick True, False, or Debatable.', 'wellactually' ), array( 'status' => 400 ) );
		}

		if ( '' === trim( $statement ) ) {
			return new WP_Error( 'wellactually_empty_statement', __( 'The swipe headline can\'t be empty.', 'wellactually' ), array( 'status' => 400 ) );
		}

		$result = WellActually_Meta::apply_meta( $post_id, $statement, $verdict );

		// Deck eligibility can change here, so drop the cached total the
		// swipe page uses for its progress count.
		WellActually_Rest::clear_total_cache();

		$response = new WP_REST_Response(
			array(
				'result'    => $result,
				'statement' => WellActually_Meta::plain_text( $statement ),
				'verdict'   => $verdict,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Read the report's sort/paging state from the request.
	 *
	 * @return array
	 */
	private function current_args() {
		$orderby = isset( $_GET['wellactually_rep_orderby'] ) ? sanitize_key( wp_unslash( $_GET['wellactually_rep_orderby'] ) ) : 'swipes';
		if ( ! in_array( $orderby, array( 'swipes', 'pct_right', 'pct_wrong', 'title' ), true ) ) {
			$orderby = 'swipes';
		}

		$order = isset( $_GET['wellactually_rep_order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_GET['wellactually_rep_order'] ) ) ) ? 'ASC' : 'DESC';

		return array(
			'orderby' => $orderby,
			'order'   => $order,
			'paged'   => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
	}

	/**
	 * Fetch a page of the report.
	 *
	 * Deliberately one hand-written query rather than WP_Query: the figures
	 * being sorted on (swipe count, percent right/wrong) live in the stats
	 * table and depend on the post's verdict, so they can't be expressed as
	 * a meta_query orderby. Doing it directly also keeps it to a single
	 * round trip, driven by the indexed `_wellactually_status = configured` lookup that
	 * already backs the rest of the plugin.
	 *
	 * @param array $args Result of current_args().
	 * @return array { rows: array[], total: int }
	 */
	private function get_page( $args ) {
		global $wpdb;

		$stats_table = WellActually_Stats::table_name();

		// correct = the answer that matches the verdict; a debatable card
		// counts every answer as correct, which is how scoring works in the
		// game itself.
		$correct_sql = "CASE vd.meta_value
			WHEN 'true' THEN COALESCE( s.agree_count, 0 )
			WHEN 'false' THEN COALESCE( s.disagree_count, 0 )
			ELSE COALESCE( s.agree_count, 0 ) + COALESCE( s.disagree_count, 0 ) + COALESCE( s.unsure_count, 0 )
		END";

		$total_sql = '( COALESCE( s.agree_count, 0 ) + COALESCE( s.disagree_count, 0 ) + COALESCE( s.unsure_count, 0 ) )';

		$order_map = array(
			'swipes'    => $total_sql,
			'pct_right' => "( {$correct_sql} ) / NULLIF( {$total_sql}, 0 )",
			// Wrong is the mirror of right, so it's the same expression
			// inverted rather than a second calculation.
			'pct_wrong' => "1 - ( ( {$correct_sql} ) / NULLIF( {$total_sql}, 0 ) )",
			'title'     => 'p.post_title',
		);

		$order_by = $order_map[ $args['orderby'] ];
		$order    = 'ASC' === $args['order'] ? 'ASC' : 'DESC';

		// A post nobody has swiped has no percentage, so it belongs at the
		// bottom whichever way the column is sorted — otherwise "worst first"
		// leads with a page of posts that have no score at all. Sorting by
		// swipe count is left alone: there, zero is a real answer.
		$no_data_last = '';
		if ( in_array( $args['orderby'], array( 'pct_right', 'pct_wrong' ), true ) ) {
			$no_data_last = "( {$total_sql} = 0 ) ASC, ";
		}
		$offset = ( $args['paged'] - 1 ) * self::PER_PAGE;

		// The stats table binds as an %i identifier; the meta keys as values.
		$from = "
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} st
				ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value = %s
			INNER JOIN {$wpdb->postmeta} vd
				ON vd.post_id = p.ID AND vd.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} stm
				ON stm.post_id = p.ID AND stm.meta_key = %s
			LEFT JOIN %i s
				ON s.post_id = p.ID
			WHERE p.post_type = 'post' AND p.post_status = 'publish'
		";

		$params = array(
			WellActually_Meta::STATUS_KEY,
			WellActually_Meta::STATUS_CONFIGURED,
			WellActually_Meta::VERDICT_KEY,
			WellActually_Meta::STATEMENT_KEY,
			$stats_table,
		);

		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$from} is the literal fragment above: core table names plus placeholders, all bound from $params; the sniff cannot see placeholders inside the fragment.
			$wpdb->prepare( "SELECT COUNT(*) {$from}", $params )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders,PluginCheck.Security.DirectDB.UnescapedDBParameter -- every fragment is assembled above from literals: {$total_sql}/{$correct_sql} are fixed aggregate expressions, {$from} is core tables plus placeholders, {$no_data_last}/{$order_by} come from the fixed $order_map whitelist, and {$order} is strictly ASC or DESC. All values are bound. Nothing here is derived from a request, so there is no parameter to escape — these are SQL fragments, not values.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title,
					vd.meta_value AS verdict,
					stm.meta_value AS statement,
					{$total_sql} AS swipes,
					{$correct_sql} AS correct
				{$from}
				ORDER BY {$no_data_last}{$order_by} {$order}, p.ID ASC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( self::PER_PAGE, $offset ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * A sortable column heading.
	 *
	 * @param string $key     Sort key.
	 * @param string $label   Heading text.
	 * @param array  $args    Current args.
	 * @param string $classes Extra classes for the th.
	 */
	private function sortable_th( $key, $label, $args, $classes = '' ) {
		$is_current = ( $args['orderby'] === $key );
		// First click on a new column sorts biggest-first, which is what you
		// want from every column here; clicking the current one flips it.
		$next_order = ( $is_current && 'DESC' === $args['order'] ) ? 'ASC' : 'DESC';

		$url = add_query_arg(
			array(
				'page'                     => 'wellactually',
				'tab'                      => 'reports',
				'wellactually_rep_orderby' => $key,
				'wellactually_rep_order'   => $next_order,
			),
			admin_url( 'options-general.php' )
		);

		$indicator = '';
		if ( $is_current ) {
			$indicator = 'ASC' === $args['order'] ? ' <span aria-hidden="true">&uarr;</span>' : ' <span aria-hidden="true">&darr;</span>';
		}

		printf(
			'<th class="%s sortable %s"><a href="%s">%s%s</a></th>',
			esc_attr( $classes ),
			esc_attr( $is_current ? 'sorted' : '' ),
			esc_url( $url ),
			esc_html( $label ),
			wp_kses_post( $indicator )
		);
	}

	/**
	 * Render the Reports tab.
	 */
	public function render_tab() {
		$args = $this->current_args();
		$page = $this->get_page( $args );

		echo '<p class="description">' . esc_html__( 'Every post currently in the swipe deck, and how players are doing on it. Percentages only mean much once a statement has been swiped a few times.', 'wellactually' ) . '</p>';

		if ( empty( $page['rows'] ) ) {
			echo '<p>' . esc_html__( 'No posts are set up for swipe mode yet.', 'wellactually' ) . '</p>';
			return;
		}

		$verdict_labels = self::verdict_labels();
		?>
		<table class="widefat striped wa-reports-table">
			<thead>
				<tr>
					<?php $this->sortable_th( 'title', __( 'Post', 'wellactually' ), $args, 'wa-col-post' ); ?>
					<th class="wa-col-headline"><?php esc_html_e( 'Swipe headline', 'wellactually' ); ?></th>
					<th class="wa-col-answer"><?php esc_html_e( 'Right answer', 'wellactually' ); ?></th>
					<?php
					$this->sortable_th( 'swipes', __( 'Swipes', 'wellactually' ), $args, 'wa-col-num' );
					$this->sortable_th( 'pct_right', __( '% right', 'wellactually' ), $args, 'wa-col-num' );
					$this->sortable_th( 'pct_wrong', __( '% wrong', 'wellactually' ), $args, 'wa-col-num' );
					?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $page['rows'] as $row ) : ?>
					<?php
					$post_id   = (int) $row->ID;
					$swipes    = (int) $row->swipes;
					$correct   = (int) $row->correct;
					$statement = WellActually_Meta::plain_text( (string) $row->statement );
					$verdict   = (string) $row->verdict;

					$pct_right = $swipes > 0 ? (int) round( ( $correct / $swipes ) * 100 ) : null;
					$pct_wrong = null === $pct_right ? null : 100 - $pct_right;
					?>
					<tr class="wa-report-row" data-post="<?php echo esc_attr( $post_id ); ?>">
						<td class="wa-col-post">
							<strong>
								<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( WellActually_Meta::plain_text( get_the_title( $post_id ) ) ); ?>
								</a>
							</strong>
							<div class="row-actions">
								<span class="wa-quick-edit"><button type="button" class="button-link wa-quick-edit-btn"><?php esc_html_e( 'Quick Edit', 'wellactually' ); ?></button></span>
								<span class="wa-edit"> | <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Edit post', 'wellactually' ); ?></a></span>
							</div>
						</td>
						<td class="wa-col-headline">
							<span class="wa-headline-text"><?php echo esc_html( $statement ); ?></span>
						</td>
						<td class="wa-col-answer">
							<span class="wa-answer-text" data-verdict="<?php echo esc_attr( $verdict ); ?>">
								<?php echo esc_html( isset( $verdict_labels[ $verdict ] ) ? $verdict_labels[ $verdict ] : $verdict ); ?>
							</span>
						</td>
						<td class="wa-col-num"><?php echo esc_html( number_format_i18n( $swipes ) ); ?></td>
						<td class="wa-col-num"><?php echo null === $pct_right ? '&mdash;' : esc_html( $pct_right . '%' ); ?></td>
						<td class="wa-col-num"><?php echo null === $pct_wrong ? '&mdash;' : esc_html( $pct_wrong . '%' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$this->render_pagination( $args, $page['total'] );
	}

	/**
	 * Pagination under the table.
	 *
	 * @param array $args  Current args.
	 * @param int   $total Total matching posts.
	 */
	private function render_pagination( $args, $total ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'                     => 'wellactually',
				'tab'                      => 'reports',
				'wellactually_rep_orderby' => $args['orderby'],
				'wellactually_rep_order'   => $args['order'],
				'paged'                    => '%#%',
			),
			admin_url( 'options-general.php' )
		);

		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $args['paged'],
				'total'     => $pages,
				'prev_text' => __( '&laquo; Previous', 'wellactually' ),
				'next_text' => __( 'Next &raquo;', 'wellactually' ),
				'type'      => 'plain',
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			printf(
				'<span class="displaying-num">%s</span> ',
				esc_html( sprintf( /* translators: %s: number of posts */ _n( '%s post', '%s posts', $total, 'wellactually' ), number_format_i18n( $total ) ) )
			);
			echo wp_kses_post( $links );
			echo '</div></div>';
		}
	}
}
