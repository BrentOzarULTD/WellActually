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
class WellActually_Bulk_Setup {

	const MENU_SLUG    = 'wellactually-swipe-setup';
	const NONCE_ACTION = 'wellactually_bulk_save';
	const NONCE_NAME   = 'wellactually_bulk_nonce';
	const PER_PAGE     = 20;

	/**
	 * Singleton instance.
	 *
	 * @var WellActually_Bulk_Setup|null
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
	 * @return WellActually_Bulk_Setup
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
	 * Enqueue the screen's stylesheet and script, and hand the script its
	 * runtime config and translated strings.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'wellactually-admin-bulk-setup',
			WELLACTUALLY_PLUGIN_URL . 'assets/css/admin-bulk-setup.css',
			array(),
			wellactually_asset_version( 'assets/css/admin-bulk-setup.css' )
		);

		wp_enqueue_script(
			'wellactually-admin-bulk-setup',
			WELLACTUALLY_PLUGIN_URL . 'assets/js/admin-bulk-setup.js',
			array(),
			wellactually_asset_version( 'assets/js/admin-bulk-setup.js' ),
			array( 'in_footer' => true )
		);

		$args = $this->current_args();

		wp_localize_script(
			'wellactually-admin-bulk-setup',
			'wellactuallyBulkSetup',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'wellactually/v1' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'cat'         => (int) $args['cat'],
				'author'      => (int) $args['author'],
				'concurrency' => WellActually_Settings::ai_concurrency(),
				'reviewUrl'   => esc_url_raw(
					add_query_arg(
						array(
							'page'                => self::MENU_SLUG,
							'wellactually_status' => 'has_ai',
							'wellactually_cat'    => (int) $args['cat'],
							'wellactually_author' => (int) $args['author'],
						),
						admin_url( 'edit.php' )
					)
				),
				'i18n'        => array(
					'queuing'           => __( 'Queuing…', 'wellactually' ),
					'nothingToDraft'    => __( 'No un-drafted posts match this filter.', 'wellactually' ),
					/* translators: %1$s: number of posts drafted so far */
					'countDrafted'      => __( '%1$s drafted', 'wellactually' ),
					/* translators: %1$s: number of posts that failed, always 1 here */
					'countError'        => __( '%1$s error', 'wellactually' ),
					/* translators: %1$s: number of posts that failed */
					'countErrors'       => __( '%1$s errors', 'wellactually' ),
					/* translators: %1$s: number of posts whose outcome is unknown */
					'countUnconfirmed'  => __( '%1$s unconfirmed', 'wellactually' ),
					/* translators: separator between the parts of a progress summary, e.g. "3 drafted, 1 error" */
					'listSeparator'     => __( ', ', 'wellactually' ),
					/* translators: 1: posts handled so far, 2: posts in this run, 3: summary such as "3 drafted, 1 error" */
					'drafting'          => __( 'Drafting… %1$s of %2$s — %3$s', 'wellactually' ),
					'rateLimited'       => __( 'Your AI provider said you’re sending too many requests at a time, so we stopped here. Try again later.', 'wellactually' ),
					/* translators: 1: summary such as "3 drafted, 1 error", 2: number of posts still queued */
					'stoppedEarly'      => __( 'Stopped early — %1$s, %2$s still queued. Click Draft with AI again to resume.', 'wellactually' ),
					/* translators: %1$s: summary such as "3 drafted, 1 error" */
					'finished'          => __( 'Done — %1$s.', 'wellactually' ),
					'reviewSuggestions' => __( 'Review suggestions', 'wellactually' ),
					'startFailed'       => __( 'Could not start drafting. Please try again.', 'wellactually' ),
				),
			)
		);
	}

	/**
	 * Read the current filter/paging state from the request.
	 *
	 * @return array
	 */
	private function current_args() {
		$status = isset( $_REQUEST['wellactually_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['wellactually_status'] ) ) : 'needs_setup';
		$valid  = array( 'needs_setup', 'has_ai', 'in_deck', 'excluded', 'skipped', 'all' );
		if ( ! in_array( $status, $valid, true ) ) {
			$status = 'needs_setup';
		}

		$order = isset( $_REQUEST['wellactually_order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['wellactually_order'] ) ) ) ? 'ASC' : 'DESC';

		// The core sort fields are indexed wp_posts columns. Views are sorted
		// separately against Jetpack's cached 30-day totals after the matching
		// post IDs have been selected.
		$orderby = isset( $_REQUEST['wellactually_orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['wellactually_orderby'] ) ) : 'date';
		if ( ! in_array( $orderby, array( 'date', 'modified', 'comment_count', 'views_30' ), true ) ) {
			$orderby = 'date';
		}

		// Posts the save that redirected here just dealt with. Bounded by the
		// page size, and deliberately not carried on any other link, so it
		// only ever applies to the single page load straight after a save.
		$done = array();
		if ( isset( $_REQUEST['wellactually_done'] ) ) {
			$done = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['wellactually_done'] ) ) ) ) );
			$done = array_slice( $done, 0, self::PER_PAGE );
		}

		return array(
			'status'  => $status,
			'done'    => $done,
			'cat'     => isset( $_REQUEST['wellactually_cat'] ) ? absint( $_REQUEST['wellactually_cat'] ) : 0,
			'author'  => isset( $_REQUEST['wellactually_author'] ) ? absint( $_REQUEST['wellactually_author'] ) : 0,
			'order'   => $order,
			'orderby' => $orderby,
			'paged'   => isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1,
		);
	}

	/**
	 * Build the query for the current view.
	 *
	 * A note on what used to be here, because it caused the same visible bug
	 * twice and the fix is to do less, not more.
	 *
	 * This screen selects rows on the denormalized `_wellactually_status` meta. Earlier
	 * versions then re-derived each row's status from its *other* meta keys
	 * and discarded any row where the two disagreed, on the theory that the
	 * stored value might have drifted. It hadn't: every write path updates
	 * `_wellactually_status` in the same breath as the meta it's derived from (see
	 * WellActually_Meta::recompute_status()), so the stored value is correct.
	 *
	 * What actually differs is *when each read sees it*. The row select and
	 * the per-row meta reads are separate trips to the database, and on
	 * managed hosting they can be served by replicas at different points in
	 * time, or from an object cache primed a moment apart. So the check was
	 * comparing two clocks and throwing away rows whenever they disagreed —
	 * which emptied the screen right after a bulk save (query behind), and
	 * again right after AI drafting (meta reads behind), each time under an
	 * accurate non-zero count.
	 *
	 * There is no verification here now. A momentarily out-of-date row is a
	 * normal, self-correcting condition that every WordPress list table
	 * lives with; an empty screen is not. Correcting the stored value from
	 * those reads would be worse still — it would write stale data into the
	 * field the whole screen depends on.
	 *
	 * @param array $args Result of current_args().
	 * @return WP_Query
	 */
	private function build_query( $args ) {
		if ( 'needs_setup' === $args['status'] ) {
			return $this->build_live_needs_setup_query( $args );
		}

		$query_args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => self::PER_PAGE,
			'paged'               => max( 1, (int) $args['paged'] ),
			'orderby'             => $args['orderby'],
			'order'               => $args['order'],
			'ignore_sticky_posts' => true,
			// Read live. WP's post-query cache is keyed to a marker that only
			// moves when a POST changes, never when its meta does, and this
			// screen filters entirely on meta.
			'cache_results'       => false,
		);

		if ( $args['cat'] > 0 ) {
			$query_args['cat'] = $args['cat'];
		}

		if ( $args['author'] > 0 ) {
			$query_args['author'] = $args['author'];
		}

		// Categories marked "Skip This Category" in Settings → Categories are
		// never eligible here, in any status view.
		$excluded_cats = WellActually_Settings::excluded_categories();
		if ( ! empty( $excluded_cats ) ) {
			$query_args['category__not_in'] = $excluded_cats;
		}

		if ( 'all' !== $args['status'] ) {
			$meta_query = WellActually_Meta::status_meta_query( $args['status'] );
			if ( ! empty( $meta_query ) ) {
				$query_args['meta_query'] = $meta_query;
			}

			// Hide what the save that redirected here just handled.
			//
			// This is the part that no amount of query tuning could fix. A
			// filtered view asks the database which posts still need
			// attention; immediately after a save, a read served by a replica
			// that hasn't caught up truthfully answers with the posts just
			// dealt with, and they reappear as though nothing happened. We
			// already know which posts those are — the save just made the
			// changes — so the answer is carried across the redirect instead
			// of being asked for again. It applies to this one page load, and
			// only to the view they were handled on, so they still show up
			// normally under "All posts" or whichever status they moved to.
			if ( ! empty( $args['done'] ) ) {
				$query_args['post__not_in'] = $args['done'];
			}
		}

		if ( 'views_30' === $args['orderby'] ) {
			$query_args['fields']                 = 'ids';
			$query_args['posts_per_page']         = -1;
			$query_args['paged']                  = 1;
			$query_args['no_found_rows']          = true;
			$query_args['orderby']                = 'ID';
			$query_args['order']                  = 'DESC';
			$query_args['update_post_meta_cache'] = false;
			$query_args['update_post_term_cache'] = false;

			$id_query = new WP_Query( $query_args );
			return $this->paginate_post_ids_by_views( $id_query->posts, $args );
		}

		$query = new WP_Query( $query_args );

		// cache_results=false also skips WP's bulk meta priming, and the
		// render loop reads several meta keys per row. Prime them in one
		// query instead of ~20.
		if ( ! empty( $query->posts ) ) {
			update_meta_cache( 'post', wp_list_pluck( $query->posts, 'ID' ) );
		}

		$this->log_page_build( $args, $query );

		return $query;
	}

	/**
	 * Build the Needs Setup page directly from authoritative meta.
	 *
	 * This bypasses WP_Query's result cache and the denormalized status index,
	 * and asks only for the exact count plus the current 20-row page. The three
	 * NOT EXISTS checks mirror WellActually_Meta::derive_status(): a post needs
	 * setup only when it is not skipped, has no resolved verdict, and has no
	 * ready AI suggestion.
	 *
	 * @param array $args Result of current_args().
	 * @return WP_Query
	 */
	private function build_live_needs_setup_query( $args ) {
		global $wpdb;

		$deck_verdicts = WellActually_Meta::deck_verdicts();
		$deck_markers  = implode( ',', array_fill( 0, count( $deck_verdicts ), '%s' ) );

		$where = array(
			'p.post_type = %s',
			'p.post_status = %s',
			"NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} wa_skip
				WHERE wa_skip.post_id = p.ID
				  AND wa_skip.meta_key = %s
				  AND wa_skip.meta_value = '1'
			)",
			"NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} wa_verdict
				WHERE wa_verdict.post_id = p.ID
				  AND wa_verdict.meta_key = %s
				  AND (
					wa_verdict.meta_value = %s
					OR wa_verdict.meta_value IN ( {$deck_markers} )
				  )
			)",
			"NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} wa_ai
				WHERE wa_ai.post_id = p.ID
				  AND wa_ai.meta_key = %s
				  AND wa_ai.meta_value = %s
			)",
		);

		$params = array_merge(
			array(
				'post',
				'publish',
				WellActually_Meta::SKIP_KEY,
				WellActually_Meta::VERDICT_KEY,
				WellActually_Meta::VERDICT_EXCLUDED,
			),
			$deck_verdicts,
			array(
				WellActually_Meta::AI_STATUS_KEY,
				'ready',
			)
		);

		if ( $args['cat'] > 0 ) {
			$category_children = get_term_children( (int) $args['cat'], 'category' );
			if ( ! is_array( $category_children ) ) {
				$category_children = array();
			}

			$category_ids = array_merge(
				array( (int) $args['cat'] ),
				$category_children
			);
			$category_ids = array_values( array_unique( array_map( 'absint', $category_ids ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
			$where[]      = "EXISTS (
				SELECT 1
				FROM {$wpdb->term_relationships} wa_tr
				INNER JOIN {$wpdb->term_taxonomy} wa_tt
					ON wa_tt.term_taxonomy_id = wa_tr.term_taxonomy_id
				WHERE wa_tr.object_id = p.ID
				  AND wa_tt.taxonomy = %s
				  AND wa_tt.term_id IN ( {$placeholders} )
			)";
			$params       = array_merge( $params, array( 'category' ), $category_ids );
		}

		if ( $args['author'] > 0 ) {
			$where[]  = 'p.post_author = %d';
			$params[] = (int) $args['author'];
		}

		$excluded_cats = WellActually_Settings::excluded_categories();
		if ( ! empty( $excluded_cats ) ) {
			$excluded_cats = array_values( array_unique( array_map( 'absint', $excluded_cats ) ) );
			$placeholders  = implode( ',', array_fill( 0, count( $excluded_cats ), '%d' ) );
			$where[]       = "NOT EXISTS (
				SELECT 1
				FROM {$wpdb->term_relationships} wa_ex_tr
				INNER JOIN {$wpdb->term_taxonomy} wa_ex_tt
					ON wa_ex_tt.term_taxonomy_id = wa_ex_tr.term_taxonomy_id
				WHERE wa_ex_tr.object_id = p.ID
				  AND wa_ex_tt.taxonomy = %s
				  AND wa_ex_tt.term_id IN ( {$placeholders} )
			)";
			$params        = array_merge( $params, array( 'category' ), $excluded_cats );
		}

		if ( ! empty( $args['done'] ) ) {
			$done         = array_values( array_unique( array_map( 'absint', $args['done'] ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $done ), '%d' ) );
			$where[]      = "p.ID NOT IN ( {$placeholders} )";
			$params       = array_merge( $params, $done );
		}

		$where_sql = implode( "\nAND ", $where );
		$from_sql  = "FROM {$wpdb->posts} p";

		if ( 'views_30' === $args['orderby'] ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table fragments are core names; every dynamic value and generated placeholder is bound through the parameter array.
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID {$from_sql}
					WHERE {$where_sql}
					ORDER BY p.ID DESC",
					$params
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders,PluginCheck.Security.DirectDB.UnescapedDBParameter

			return $this->paginate_post_ids_by_views( $post_ids, $args );
		}

		// current_args() allow-lists both values; the map turns the public sort
		// name into its core wp_posts column.
		$order_columns = array(
			'date'          => 'post_date',
			'modified'      => 'post_modified',
			'comment_count' => 'comment_count',
		);
		$order_column  = $order_columns[ $args['orderby'] ];
		$order         = 'ASC' === $args['order'] ? 'ASC' : 'DESC';
		$offset        = ( max( 1, (int) $args['paged'] ) - 1 ) * self::PER_PAGE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column/order fragments are core names or allow-listed literals; every dynamic value and generated placeholder is bound through the parameter arrays.
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) {$from_sql} WHERE {$where_sql}",
				$params
			)
		);
		$page  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID {$from_sql}
				WHERE {$where_sql}
				ORDER BY p.{$order_column} {$order}, p.ID {$order}
				LIMIT %d OFFSET %d",
				array_merge( $params, array( self::PER_PAGE, $offset ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$posts = array_values( array_filter( array_map( 'get_post', array_map( 'intval', $page ) ) ) );

		// An empty WP_Query gives the renderer the normal loop API without
		// issuing another status-dependent query that a host could cache.
		$query                = new WP_Query();
		$query->posts         = $posts;
		$query->post_count    = count( $posts );
		$query->found_posts   = $found;
		$query->max_num_pages = (int) ceil( $found / self::PER_PAGE );

		if ( ! empty( $posts ) ) {
			update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
		}

		$this->log_page_build( $args, $query );

		return $query;
	}

	/**
	 * Sort matching post IDs by Jetpack's 30-day views, then build one page.
	 *
	 * Jetpack caches stats_get_csv() for five minutes, so this remains one
	 * cached stats request even when thousands of posts match. Posts omitted
	 * from the response are treated as having zero views.
	 *
	 * @param int[] $post_ids Matching post IDs.
	 * @param array $args     Current screen args.
	 * @return WP_Query
	 */
	private function paginate_post_ids_by_views( $post_ids, $args ) {
		$post_ids  = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$views     = $this->jetpack_views_30_days();
		$direction = 'ASC' === $args['order'] ? 1 : -1;

		usort(
			$post_ids,
			static function ( $left, $right ) use ( $views, $direction ) {
				$left_views  = isset( $views[ $left ] ) ? $views[ $left ] : 0;
				$right_views = isset( $views[ $right ] ) ? $views[ $right ] : 0;

				if ( $left_views !== $right_views ) {
					return $direction * ( $left_views <=> $right_views );
				}

				return $direction * ( $left <=> $right );
			}
		);

		$found  = count( $post_ids );
		$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * self::PER_PAGE;
		$page   = array_slice( $post_ids, $offset, self::PER_PAGE );
		$posts  = array_values( array_filter( array_map( 'get_post', $page ) ) );

		$query                = new WP_Query();
		$query->posts         = $posts;
		$query->post_count    = count( $posts );
		$query->found_posts   = $found;
		$query->max_num_pages = (int) ceil( $found / self::PER_PAGE );

		if ( ! empty( $posts ) ) {
			update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
		}

		$this->log_page_build( $args, $query );

		return $query;
	}

	/**
	 * Get Jetpack's cached 30-day view totals, keyed by post ID.
	 *
	 * With a list of IDs, use the same one-request endpoint as Jetpack's Posts
	 * column. With no IDs, request the full ranking needed for global sorting.
	 *
	 * @param int[] $post_ids Optional post IDs for a page-only request.
	 * @return int[]
	 */
	private function jetpack_views_30_days( $post_ids = array() ) {
		$views       = array();
		$post_ids    = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$stats_class = '\Automattic\Jetpack\Stats\WPCOM_Stats';

		if ( ! empty( $post_ids ) && class_exists( $stats_class ) ) {
			$stats    = new $stats_class();
			$response = $stats->get_total_post_views(
				array(
					'num'      => 30,
					'post_ids' => implode( ',', $post_ids ),
				)
			);

			if ( ! is_wp_error( $response ) && ! empty( $response['posts'] ) && is_array( $response['posts'] ) ) {
				foreach ( $response['posts'] as $post ) {
					$post_id = isset( $post['ID'] ) ? absint( $post['ID'] ) : 0;
					if ( $post_id > 0 ) {
						$views[ $post_id ] = isset( $post['views'] ) ? absint( $post['views'] ) : 0;
					}
				}
			}
		} elseif ( function_exists( 'stats_get_csv' ) ) {
			$rows = stats_get_csv(
				'postviews',
				array(
					'days'  => 30,
					'limit' => -1,
				)
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
					if ( $post_id > 0 ) {
						$views[ $post_id ] = isset( $row['views'] ) ? absint( str_replace( ',', '', (string) $row['views'] ) ) : 0;
					}
				}
			}
		}

		/**
		 * Filter the 30-day view totals used by the Swipe Setup screen.
		 *
		 * @param int[] $views View totals keyed by post ID.
		 */
		$views = apply_filters( 'wellactually_jetpack_views_30_days', $views );
		$clean = array();

		if ( is_array( $views ) ) {
			foreach ( $views as $post_id => $count ) {
				$post_id = absint( $post_id );
				if ( $post_id > 0 ) {
					$clean[ $post_id ] = absint( $count );
				}
			}
		}

		return $clean;
	}

	/**
	 * Whether a stats provider can supply values for the Views column.
	 *
	 * @return bool
	 */
	private function has_jetpack_views() {
		return class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' )
			|| function_exists( 'stats_get_csv' )
			|| false !== has_filter( 'wellactually_jetpack_views_30_days' );
	}

	/**
	 * Format a view count using the same compact K/M style as Jetpack.
	 *
	 * @param int $views View count.
	 * @return string
	 */
	private function format_view_count( $views ) {
		$views = absint( $views );

		if ( $views >= 10000000 ) {
			return round( $views / 1000000 ) . 'M';
		}
		if ( $views >= 1000000 ) {
			$views = round( $views / 1000000, 1 );
			return preg_replace( '/\.0$/', '', (string) $views ) . 'M';
		}
		if ( $views >= 10000 ) {
			return round( $views / 1000 ) . 'K';
		}
		if ( $views >= 1000 ) {
			$views = round( $views / 1000, 1 );
			return preg_replace( '/\.0$/', '', (string) $views ) . 'K';
		}

		return (string) $views;
	}

	/**
	 * Record what a page build actually did, when diagnostics are turned on.
	 *
	 * Off unless "Logging" is ticked on Settings → "Well, Actually...", so
	 * it's safe to switch on in production for a few page loads.
	 *
	 * @param array    $args  Current view args.
	 * @param WP_Query $query The page's query.
	 */
	private function log_page_build( $args, $query ) {
		$enabled = WellActually_Settings::debug_logging_enabled();

		/**
		 * Filter whether the "Well, Actually..." screen logs how it built a page.
		 *
		 * @param bool  $enabled Whether to log.
		 * @param array $args    Current view args.
		 */
		if ( ! apply_filters( 'wellactually_debug_setup', $enabled, $args ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Explicitly opt-in production diagnostics configured by an administrator.
		error_log(
			sprintf(
				'[wellactually] page build: status=%s author=%d paged=%d shown=%d found=%d',
				$args['status'],
				(int) $args['author'],
				(int) $args['paged'],
				(int) $query->post_count,
				(int) $query->found_posts
			)
		);
	}

	/**
	 * Handle the bulk save (PRG): process, then redirect back to the same view.
	 */
	public function handle_save() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit posts.', 'wellactually' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized at the point of use below, and the verdict is allow-listed.
		$rows = isset( $_POST['wellactually_bulk'] ) && is_array( $_POST['wellactually_bulk'] ) ? wp_unslash( $_POST['wellactually_bulk'] ) : array();

		$counts = array(
			'configured' => 0,
			'excluded'   => 0,
			'cleared'    => 0,
			'incomplete' => 0,
			'skipped'    => 0,
		);

		// The posts this save actually changed. Carried through the redirect
		// so the next page can hide them without having to ask the database
		// to confirm a change it may not be able to see yet.
		$handled = array();

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
				update_post_meta( $post_id, WellActually_Meta::SKIP_KEY, '1' );
				WellActually_Meta::recompute_status( $post_id, array( 'skipped' => true ) );
				++$counts['skipped'];
				$handled[] = $post_id;
				continue;
			}

			// Not held. Only clear the skip flag (and recompute) if it was
			// actually set — apply_meta() below takes an 'unchanged' branch
			// for a row with nothing to change, and rewriting the derived
			// status for every untouched row on the page is exactly how one
			// stale read corrupts rows nobody edited.
			if ( '1' === get_post_meta( $post_id, WellActually_Meta::SKIP_KEY, true ) ) {
				delete_post_meta( $post_id, WellActually_Meta::SKIP_KEY );
				WellActually_Meta::recompute_status( $post_id, array( 'skipped' => false ) );
			}

			$statement = isset( $fields['statement'] ) ? sanitize_textarea_field( $fields['statement'] ) : '';
			$verdict   = isset( $fields['verdict'] ) ? sanitize_text_field( $fields['verdict'] ) : '';

			if ( $exclude ) {
				$verdict = WellActually_Meta::VERDICT_EXCLUDED;
			} elseif ( ! in_array( $verdict, WellActually_Meta::deck_verdicts(), true ) ) {
				$verdict = '';
			}

			$result = WellActually_Meta::apply_meta( $post_id, $statement, $verdict );

			if ( isset( $counts[ $result ] ) ) {
				++$counts[ $result ];
			}

			if ( in_array( $result, array( 'configured', 'excluded', 'cleared' ), true ) ) {
				$handled[] = $post_id;
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
			WellActually_Rest::clear_total_cache();
		}

		$args = $this->current_args();

		$redirect = add_query_arg(
			array(
				'page'                    => self::MENU_SLUG,
				'wellactually_status'     => $args['status'],
				'wellactually_cat'        => $args['cat'],
				'wellactually_author'     => $args['author'],
				'wellactually_order'      => $args['order'],
				'wellactually_orderby'    => $args['orderby'],
				'paged'                   => $args['paged'],
				'wellactually_configured' => $counts['configured'],
				'wellactually_excluded'   => $counts['excluded'],
				'wellactually_cleared'    => $counts['cleared'],
				'wellactually_incomplete' => $counts['incomplete'],
				'wellactually_skipped'    => $counts['skipped'],
				'wellactually_saved'      => 1,
				'wellactually_done'       => implode( ',', $handled ),
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
		delete_post_meta( $post_id, WellActually_Meta::AI_STATEMENT_KEY );
		delete_post_meta( $post_id, WellActually_Meta::AI_VERDICT_KEY );
		delete_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY );
		delete_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY );
		WellActually_Meta::recompute_status( $post_id, array( 'ai_status' => '' ) );
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

		wp_reset_postdata();
	}

	/**
	 * Show the post-save summary notice.
	 */
	private function render_notice() {
		if ( empty( $_GET['wellactually_saved'] ) ) {
			return;
		}

		$configured = isset( $_GET['wellactually_configured'] ) ? absint( $_GET['wellactually_configured'] ) : 0;
		$excluded   = isset( $_GET['wellactually_excluded'] ) ? absint( $_GET['wellactually_excluded'] ) : 0;
		$cleared    = isset( $_GET['wellactually_cleared'] ) ? absint( $_GET['wellactually_cleared'] ) : 0;
		$incomplete = isset( $_GET['wellactually_incomplete'] ) ? absint( $_GET['wellactually_incomplete'] ) : 0;
		$skipped    = isset( $_GET['wellactually_skipped'] ) ? absint( $_GET['wellactually_skipped'] ) : 0;

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
		$providers = WellActually_Settings::ai_providers();

		// Nothing to offer if the environment has no AI support at all.
		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return;
		}

		echo '<div class="wa-ai-panel">';
		echo '<h2>' . esc_html__( 'Draft with AI', 'wellactually' ) . '</h2>';

		if ( ! WellActually_AI::is_available() ) {
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

		$provider_id   = wellactually_get_setting( 'ai_provider', '' );
		$provider_name = isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : $provider_id;
		$model         = wellactually_get_setting( 'ai_model', '' );
		$counts        = WellActually_AI::queue_counts();

		if ( '' === $model ) {
			$default_model = WellActually_AI::preferred_model_for_provider( $provider_id );
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

		$effective = WellActually_AI::effective_model( $provider_id );
		if ( '' !== $effective && false === WellActually_AI::model_supports_drafting( $provider_id, $effective ) ) {
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
			<?php if ( $args['cat'] > 0 && $args['author'] > 0 ) : ?>
				<span class="wa-ai-filter-note"><?php esc_html_e( '(limited to the selected category and author)', 'wellactually' ); ?></span>
			<?php elseif ( $args['cat'] > 0 ) : ?>
				<span class="wa-ai-filter-note"><?php esc_html_e( '(limited to the selected category)', 'wellactually' ); ?></span>
			<?php elseif ( $args['author'] > 0 ) : ?>
				<span class="wa-ai-filter-note"><?php esc_html_e( '(limited to the selected author)', 'wellactually' ); ?></span>
			<?php endif; ?>
			<button type="button" class="button button-secondary" id="wa-ai-draft-btn"><?php esc_html_e( 'Draft with AI', 'wellactually' ); ?></button>
			<span class="wa-ai-progress" id="wa-ai-progress" aria-live="polite"></span>
		</div>
		<?php
		// Only when there's actually something to review — the line used to
		// appear on any error count too, which read as "0 suggestions ready
		// to review" with a link to an empty screen.
		if ( $counts['ready'] > 0 ) {
			$review_url = add_query_arg(
				array(
					'page'                => self::MENU_SLUG,
					'wellactually_status' => 'has_ai',
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

			<label for="wellactually_status" class="screen-reader-text"><?php esc_html_e( 'Status', 'wellactually' ); ?></label>
			<select name="wellactually_status" id="wellactually_status">
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $args['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="wellactually_cat" class="screen-reader-text"><?php esc_html_e( 'Category', 'wellactually' ); ?></label>
			<?php
			wp_dropdown_categories(
				array(
					'show_option_all' => __( 'All categories', 'wellactually' ),
					'name'            => 'wellactually_cat',
					'id'              => 'wellactually_cat',
					'selected'        => $args['cat'],
					'hierarchical'    => true,
					'hide_empty'      => false,
					'orderby'         => 'name',
				)
			);
			?>

			<label for="wellactually_author" class="screen-reader-text"><?php esc_html_e( 'Author', 'wellactually' ); ?></label>
			<?php
			wp_dropdown_users(
				array(
					'show_option_all'  => __( 'All authors', 'wellactually' ),
					'name'             => 'wellactually_author',
					'id'               => 'wellactually_author',
					'selected'         => $args['author'],
					'capability'       => array( 'edit_posts' ),
					'include_selected' => true,
					'orderby'          => 'display_name',
					'order'            => 'ASC',
				)
			);
			?>

			<label for="wellactually_orderby" class="screen-reader-text"><?php esc_html_e( 'Sort by', 'wellactually' ); ?></label>
			<select name="wellactually_orderby" id="wellactually_orderby">
				<option value="date" <?php selected( $args['orderby'], 'date' ); ?>><?php esc_html_e( 'Date published', 'wellactually' ); ?></option>
				<option value="modified" <?php selected( $args['orderby'], 'modified' ); ?>><?php esc_html_e( 'Date modified', 'wellactually' ); ?></option>
				<option value="comment_count" <?php selected( $args['orderby'], 'comment_count' ); ?>><?php esc_html_e( 'Comment count', 'wellactually' ); ?></option>
				<option value="views_30" <?php selected( $args['orderby'], 'views_30' ); ?>><?php esc_html_e( 'Views: 30 days', 'wellactually' ); ?></option>
			</select>

			<label for="wellactually_order" class="screen-reader-text"><?php esc_html_e( 'Order', 'wellactually' ); ?></label>
			<select name="wellactually_order" id="wellactually_order">
				<option value="DESC" <?php selected( $args['order'], 'DESC' ); ?>><?php esc_html_e( 'Descending', 'wellactually' ); ?></option>
				<option value="ASC" <?php selected( $args['order'], 'ASC' ); ?>><?php esc_html_e( 'Ascending', 'wellactually' ); ?></option>
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
		$page_post_ids   = wp_list_pluck( $query->posts, 'ID' );
		$view_post_ids   = 'views_30' === $args['orderby'] ? array() : $page_post_ids;
		$views           = $this->jetpack_views_30_days( $view_post_ids );
		$has_views       = $this->has_jetpack_views();
		?>
		<form method="post" class="wa-bulk-form">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wellactually_status" value="<?php echo esc_attr( $args['status'] ); ?>" />
			<input type="hidden" name="wellactually_cat" value="<?php echo esc_attr( $args['cat'] ); ?>" />
			<input type="hidden" name="wellactually_author" value="<?php echo esc_attr( $args['author'] ); ?>" />
			<input type="hidden" name="wellactually_order" value="<?php echo esc_attr( $args['order'] ); ?>" />
			<input type="hidden" name="wellactually_orderby" value="<?php echo esc_attr( $args['orderby'] ); ?>" />
			<input type="hidden" name="paged" value="<?php echo esc_attr( $args['paged'] ); ?>" />

			<table class="widefat striped wa-bulk-table">
				<thead>
					<tr>
						<th class="wa-col-post"><?php esc_html_e( 'Post', 'wellactually' ); ?></th>
						<th class="wa-col-views"><?php esc_html_e( 'Views: 30 days', 'wellactually' ); ?></th>
						<th class="wa-col-statement"><?php esc_html_e( 'Swipe Statement', 'wellactually' ); ?></th>
						<th class="wa-col-verdict"><?php esc_html_e( 'Verdict', 'wellactually' ); ?></th>
						<th class="wa-col-skip">
							<?php esc_html_e( 'Skip for Now', 'wellactually' ); ?>
							<label class="wa-check-all-label">
								<input type="checkbox" class="wa-check-all-skip" />
								<span><?php esc_html_e( 'All', 'wellactually' ); ?></span>
							</label>
						</th>
						<th class="wa-col-exclude">
							<?php esc_html_e( 'Never', 'wellactually' ); ?>
							<label class="wa-check-all-label">
								<input type="checkbox" class="wa-check-all-exclude" />
								<span><?php esc_html_e( 'All', 'wellactually' ); ?></span>
							</label>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						$post_id = get_the_ID();
						// Decoded for editing: an author should see (and save) a
						// real apostrophe, not a stored "&#8217;".
						$statement = WellActually_Meta::plain_text( get_post_meta( $post_id, WellActually_Meta::STATEMENT_KEY, true ) );
						$verdict   = get_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, true );
						$excluded  = ( WellActually_Meta::VERDICT_EXCLUDED === $verdict );
						$skipped   = ( '1' === get_post_meta( $post_id, WellActually_Meta::SKIP_KEY, true ) );
						$name      = 'wellactually_bulk[' . $post_id . ']';

						// If there's no accepted content yet but a ready AI draft
						// exists, pre-fill the inputs with the suggestion.
						$ai_status = get_post_meta( $post_id, WellActually_Meta::AI_STATUS_KEY, true );
						$ai_error  = get_post_meta( $post_id, WellActually_Meta::AI_ERROR_KEY, true );
						$is_ai     = false;
						if ( '' === $statement && '' === $verdict && WellActually_AI::STATUS_READY === $ai_status ) {
							$ai_stmt = get_post_meta( $post_id, WellActually_Meta::AI_STATEMENT_KEY, true );
							$ai_vdct = get_post_meta( $post_id, WellActually_Meta::AI_VERDICT_KEY, true );
							if ( '' !== $ai_stmt && in_array( $ai_vdct, WellActually_Meta::deck_verdicts(), true ) ) {
								$statement = WellActually_Meta::plain_text( $ai_stmt );
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
									<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title() ? WellActually_Meta::plain_text( get_the_title() ) : __( '(no title)', 'wellactually' ) ); ?></a>
								</strong>
								<div class="wa-post-meta">
									<?php
									printf(
										/* translators: 1: post date, 2: post author */
										esc_html__( '%1$s - %2$s', 'wellactually' ),
										esc_html( get_the_date() ),
										esc_html( get_the_author() )
									);
									?>
								</div>
								<div class="wa-post-preview"><?php echo esc_html( $this->post_preview( get_post() ) ); ?></div>
							</td>
							<td class="wa-col-views">
								<?php if ( $has_views && array_key_exists( $post_id, $views ) ) : ?>
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Views in the last 30 days:', 'wellactually' ); ?></span>
									<?php echo esc_html( $this->format_view_count( $views[ $post_id ] ) ); ?>
								<?php elseif ( $has_views ) : ?>
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'No stats', 'wellactually' ); ?></span>
								<?php else : ?>
									<span aria-hidden="true">&mdash;</span>
									<span class="screen-reader-text"><?php esc_html_e( 'Jetpack Stats is unavailable.', 'wellactually' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="wa-col-statement">
								<?php if ( $is_ai ) : ?>
									<span class="wa-ai-badge"><?php esc_html_e( 'AI suggestion', 'wellactually' ); ?></span>
								<?php elseif ( WellActually_AI::STATUS_ERROR === $ai_status && '' !== $ai_error ) : ?>
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
				<?php submit_button( __( 'Save all on this page', 'wellactually' ), 'primary', 'wellactually_bulk_submit', false ); ?>
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
				'page'                 => self::MENU_SLUG,
				'wellactually_status'  => $args['status'],
				'wellactually_cat'     => $args['cat'],
				'wellactually_author'  => $args['author'],
				'wellactually_order'   => $args['order'],
				'wellactually_orderby' => $args['orderby'],
				'paged'                => '%#%',
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
			return WellActually_Meta::plain_text( wp_strip_all_tags( get_the_excerpt( $post ) ) );
		}

		$content = get_the_content( '', false, $post );
		$content = strip_shortcodes( $content );
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}
		$content = wp_strip_all_tags( $content );
		// Decode before trimming so a word-trim can't slice an entity in half.
		$content = WellActually_Meta::plain_text( $content );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		return wp_trim_words( $content, 55, '…' );
	}
}
