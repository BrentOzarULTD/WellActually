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
			__( 'Swipe Setup', 'well-actually' ),
			__( 'Swipe Setup', 'well-actually' ),
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
		$valid  = array( 'needs_setup', 'in_deck', 'excluded', 'all' );
		if ( ! in_array( $status, $valid, true ) ) {
			$status = 'needs_setup';
		}

		$order = isset( $_REQUEST['wa_order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['wa_order'] ) ) ) ? 'ASC' : 'DESC';

		return array(
			'status' => $status,
			'cat'    => isset( $_REQUEST['wa_cat'] ) ? absint( $_REQUEST['wa_cat'] ) : 0,
			'order'  => $order,
			'paged'  => isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1,
		);
	}

	/**
	 * Build the WP_Query for the current view.
	 *
	 * @param array $args Result of current_args().
	 * @return WP_Query
	 */
	private function build_query( $args ) {
		$query_args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $args['paged'],
			'orderby'        => 'date',
			'order'          => $args['order'],
			'ignore_sticky_posts' => true,
		);

		if ( $args['cat'] > 0 ) {
			$query_args['cat'] = $args['cat'];
		}

		if ( 'all' !== $args['status'] ) {
			$meta_query = WA_Meta::status_meta_query( $args['status'] );
			if ( ! empty( $meta_query ) ) {
				$query_args['meta_query'] = $meta_query;
			}
		}

		return new WP_Query( $query_args );
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
			wp_die( esc_html__( 'You are not allowed to edit posts.', 'well-actually' ) );
		}

		$rows = isset( $_POST['wa_bulk'] ) && is_array( $_POST['wa_bulk'] ) ? wp_unslash( $_POST['wa_bulk'] ) : array();

		$counts = array(
			'configured' => 0,
			'excluded'   => 0,
			'cleared'    => 0,
			'incomplete' => 0,
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

			$exclude   = ! empty( $fields['exclude'] );
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
				'paged'         => $args['paged'],
				'wa_configured' => $counts['configured'],
				'wa_excluded'   => $counts['excluded'],
				'wa_cleared'    => $counts['cleared'],
				'wa_incomplete' => $counts['incomplete'],
				'wa_saved'      => 1,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
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
		echo '<h1>' . esc_html__( 'Swipe Setup', 'well-actually' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Add a swipe statement and mark it True, False, or Debatable — or exclude a post from swipe mode entirely. Fill in as many as you like, then Save at the bottom.', 'well-actually' ) . '</p>';

		$this->render_notice();
		$this->render_filters( $args, $query );
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

		$parts = array();
		if ( $configured ) {
			/* translators: %d: number of posts */
			$parts[] = sprintf( _n( '%d post set up', '%d posts set up', $configured, 'well-actually' ), $configured );
		}
		if ( $excluded ) {
			/* translators: %d: number of posts */
			$parts[] = sprintf( _n( '%d excluded', '%d excluded', $excluded, 'well-actually' ), $excluded );
		}
		if ( $cleared ) {
			/* translators: %d: number of posts */
			$parts[] = sprintf( _n( '%d cleared', '%d cleared', $cleared, 'well-actually' ), $cleared );
		}

		$message = $parts ? implode( ', ', $parts ) . '.' : __( 'No changes were made.', 'well-actually' );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';

		if ( $incomplete ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of posts */
					_n(
						'%d post needs both a statement and a verdict — left unchanged.',
						'%d posts need both a statement and a verdict — left unchanged.',
						$incomplete,
						'well-actually'
					),
					$incomplete
				)
			) . '</p></div>';
		}
	}

	/**
	 * Render the filter bar (a GET form).
	 *
	 * @param array    $args  Current args.
	 * @param WP_Query $query The results query (for the count).
	 */
	private function render_filters( $args, $query ) {
		$statuses = array(
			'needs_setup' => __( 'Needs setup', 'well-actually' ),
			'in_deck'     => __( 'In swipe deck', 'well-actually' ),
			'excluded'    => __( 'Excluded', 'well-actually' ),
			'all'         => __( 'All posts', 'well-actually' ),
		);
		?>
		<form method="get" class="wa-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />

			<label for="wa_status" class="screen-reader-text"><?php esc_html_e( 'Status', 'well-actually' ); ?></label>
			<select name="wa_status" id="wa_status">
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $args['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="wa_cat" class="screen-reader-text"><?php esc_html_e( 'Category', 'well-actually' ); ?></label>
			<?php
			wp_dropdown_categories(
				array(
					'show_option_all' => __( 'All categories', 'well-actually' ),
					'name'            => 'wa_cat',
					'id'              => 'wa_cat',
					'selected'        => $args['cat'],
					'hierarchical'    => true,
					'hide_empty'      => false,
					'orderby'         => 'name',
				)
			);
			?>

			<label for="wa_order" class="screen-reader-text"><?php esc_html_e( 'Order', 'well-actually' ); ?></label>
			<select name="wa_order" id="wa_order">
				<option value="DESC" <?php selected( $args['order'], 'DESC' ); ?>><?php esc_html_e( 'Newest first', 'well-actually' ); ?></option>
				<option value="ASC" <?php selected( $args['order'], 'ASC' ); ?>><?php esc_html_e( 'Oldest first', 'well-actually' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'well-actually' ), 'secondary', '', false ); ?>

			<span class="wa-count">
				<?php
				printf(
					/* translators: %d: number of matching posts */
					esc_html( _n( '%d post', '%d posts', (int) $query->found_posts, 'well-actually' ) ),
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
			echo '<p>' . esc_html__( 'No posts match this filter.', 'well-actually' ) . '</p>';
			return;
		}

		$verdict_options = array(
			''          => __( '— pick one —', 'well-actually' ),
			'true'      => __( 'True', 'well-actually' ),
			'false'     => __( 'False', 'well-actually' ),
			'debatable' => __( 'Debatable', 'well-actually' ),
		);
		?>
		<form method="post" class="wa-bulk-form">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wa_status" value="<?php echo esc_attr( $args['status'] ); ?>" />
			<input type="hidden" name="wa_cat" value="<?php echo esc_attr( $args['cat'] ); ?>" />
			<input type="hidden" name="wa_order" value="<?php echo esc_attr( $args['order'] ); ?>" />
			<input type="hidden" name="paged" value="<?php echo esc_attr( $args['paged'] ); ?>" />

			<table class="widefat striped wa-bulk-table">
				<thead>
					<tr>
						<th class="wa-col-post"><?php esc_html_e( 'Post', 'well-actually' ); ?></th>
						<th class="wa-col-statement"><?php esc_html_e( 'Swipe Statement', 'well-actually' ); ?></th>
						<th class="wa-col-verdict"><?php esc_html_e( 'Verdict', 'well-actually' ); ?></th>
						<th class="wa-col-exclude"><?php esc_html_e( 'Never', 'well-actually' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						$post_id   = get_the_ID();
						$statement = get_post_meta( $post_id, WA_Meta::STATEMENT_KEY, true );
						$verdict   = get_post_meta( $post_id, WA_Meta::VERDICT_KEY, true );
						$excluded  = ( WA_Meta::VERDICT_EXCLUDED === $verdict );
						$name      = 'wa_bulk[' . $post_id . ']';
						?>
						<tr class="wa-row <?php echo $excluded ? 'wa-row-excluded' : ''; ?>" data-post="<?php echo esc_attr( $post_id ); ?>">
							<td class="wa-col-post">
								<strong>
									<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title() ? get_the_title() : __( '(no title)', 'well-actually' ) ); ?></a>
								</strong>
								<div class="wa-post-meta">
									<?php echo esc_html( get_the_date() ); ?>
								</div>
								<div class="wa-post-preview"><?php echo esc_html( $this->post_preview( get_post() ) ); ?></div>
							</td>
							<td class="wa-col-statement">
								<textarea
									name="<?php echo esc_attr( $name ); ?>[statement]"
									rows="2"
									class="wa-statement-input"
									placeholder="<?php esc_attr_e( 'e.g. Temp tables are faster than CTEs', 'well-actually' ); ?>"
								><?php echo esc_textarea( $excluded ? '' : $statement ); ?></textarea>
							</td>
							<td class="wa-col-verdict">
								<select name="<?php echo esc_attr( $name ); ?>[verdict]" class="wa-verdict-input">
									<?php foreach ( $verdict_options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $excluded ? '' : $verdict, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td class="wa-col-exclude">
								<label class="wa-exclude-label">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exclude]" value="1" class="wa-exclude-input" <?php checked( $excluded ); ?> />
									<span class="screen-reader-text"><?php esc_html_e( 'Never set up for swipe', 'well-actually' ); ?></span>
								</label>
							</td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>

			<div class="wa-bulk-footer">
				<?php $this->render_pagination( $args, $query ); ?>
				<?php submit_button( __( 'Save all on this page', 'well-actually' ), 'primary', 'wa_bulk_submit', false ); ?>
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
				'page'      => self::MENU_SLUG,
				'wa_status' => $args['status'],
				'wa_cat'    => $args['cat'],
				'wa_order'  => $args['order'],
				'paged'     => '%#%',
			),
			admin_url( 'edit.php' )
		);

		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $args['paged'],
				'total'     => $total_pages,
				'prev_text' => __( '&laquo; Previous', 'well-actually' ),
				'next_text' => __( 'Next &raquo;', 'well-actually' ),
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
			return wp_strip_all_tags( get_the_excerpt( $post ) );
		}

		$content = get_the_content( '', false, $post );
		$content = strip_shortcodes( $content );
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}
		$content = wp_strip_all_tags( $content );
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
			.wa-bulk-table { margin-top: 8px; }
			.wa-bulk-table th.wa-col-post,
			.wa-bulk-table td.wa-col-post { width: 40%; }
			.wa-bulk-table th.wa-col-statement,
			.wa-bulk-table td.wa-col-statement { width: 34%; }
			.wa-bulk-table th.wa-col-verdict,
			.wa-bulk-table td.wa-col-verdict { width: 16%; }
			.wa-bulk-table th.wa-col-exclude,
			.wa-bulk-table td.wa-col-exclude { width: 10%; text-align: center; }
			.wa-bulk-table .wa-post-meta { color: #646970; font-size: 12px; margin: 2px 0; }
			.wa-bulk-table .wa-post-preview { color: #50575e; font-size: 13px; line-height: 1.5; }
			.wa-bulk-table .wa-statement-input { width: 100%; }
			.wa-bulk-table .wa-verdict-input { width: 100%; max-width: 160px; }
			.wa-bulk-table tr.wa-row-excluded .wa-statement-input,
			.wa-bulk-table tr.wa-row-excluded .wa-verdict-input { opacity: .4; }
			.wa-bulk-footer { display: flex; justify-content: space-between; align-items: center; margin: 16px 0; flex-wrap: wrap; gap: 12px; }
			.wa-bulk-footer .wa-pagination { margin: 0; }
			.wa-bulk-footer .button-primary { font-size: 15px; padding: 6px 20px; height: auto; }
		</style>
		<?php
	}

	/**
	 * Print the screen's small progressive-enhancement script: dim a row's
	 * statement/verdict inputs when its "Never" box is checked.
	 */
	private function print_scripts() {
		?>
		<script>
		( function () {
			document.querySelectorAll( '.wa-bulk-table .wa-exclude-input' ).forEach( function ( box ) {
				function sync() {
					var row = box.closest( '.wa-row' );
					if ( ! row ) { return; }
					row.classList.toggle( 'wa-row-excluded', box.checked );
					var s = row.querySelector( '.wa-statement-input' );
					var v = row.querySelector( '.wa-verdict-input' );
					if ( s ) { s.disabled = box.checked; }
					if ( v ) { v.disabled = box.checked; }
				}
				box.addEventListener( 'change', sync );
				sync();
			} );
		} )();
		</script>
		<?php
	}
}
