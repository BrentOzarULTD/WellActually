<?php
/**
 * Aggregate stats: custom table tallying agree/disagree/unsure per post.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the wellactually_stats table and tally API.
 */
class WellActually_Stats {

	const DB_VERSION_OPTION = 'wellactually_db_version';
	const DB_VERSION        = '1.0';

	/**
	 * Singleton instance.
	 *
	 * @var WellActually_Stats|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WellActually_Stats
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
		add_action( 'wellactually_activate', array( $this, 'create_table' ) );

		// Deliberately `init` and not `plugins_loaded`: this callback is
		// registered from wellactually_init(), which itself runs on plugins_loaded
		// priority 10. WP_Hook iterates a copy of each priority's callback
		// array, so anything appended to the priority currently running is
		// skipped — on plugins_loaded this would never fire at all.
		add_action( 'init', array( $this, 'maybe_upgrade_table' ) );

		add_filter( 'manage_post_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_post_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Get the fully-qualified stats table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wellactually_stats';
	}

	/**
	 * Create (or update) the stats table via dbDelta.
	 */
	public function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			post_id BIGINT UNSIGNED NOT NULL,
			agree_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			disagree_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			unsure_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (post_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run the table creation/upgrade if the stored db version is stale.
	 * Safety net for sites where activation didn't fire (e.g. must-use
	 * installs or manual file copies).
	 */
	public function maybe_upgrade_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$this->create_table();
		}
	}

	/**
	 * Record a single swipe answer for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $answer  One of agree|disagree|unsure.
	 * @return bool True on success, false if the answer was invalid.
	 */
	public static function record( $post_id, $answer ) {
		global $wpdb;

		$columns = array(
			'agree'    => 'agree_count',
			'disagree' => 'disagree_count',
			'unsure'   => 'unsure_count',
		);

		if ( ! isset( $columns[ $answer ] ) ) {
			return false;
		}

		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		$column     = $columns[ $answer ];
		$table_name = self::table_name();

		// One atomic statement: insert the row or bump the existing counter.
		// %i binds the table and the whitelisted column as identifiers.
		return false !== $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (post_id, %i) VALUES (%d, 1)
				ON DUPLICATE KEY UPDATE %i = %i + 1',
				$table_name,
				$column,
				$post_id,
				$column,
				$column
			)
		);
	}

	/**
	 * Get the tally for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array {
	 *     @type int      $agree      Agree count.
	 *     @type int      $disagree   Disagree count.
	 *     @type int      $unsure     Unsure count.
	 *     @type int      $total      Sum of all three.
	 *     @type int|null $pct_agreed Percentage of agree/disagree that agreed, or null.
	 * }
	 */
	public static function get( $post_id ) {
		global $wpdb;

		$post_id    = absint( $post_id );
		$table_name = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT agree_count, disagree_count, unsure_count FROM %i WHERE post_id = %d',
				$table_name,
				$post_id
			),
			ARRAY_A
		);

		$agree    = $row ? (int) $row['agree_count'] : 0;
		$disagree = $row ? (int) $row['disagree_count'] : 0;
		$unsure   = $row ? (int) $row['unsure_count'] : 0;
		$decided  = $agree + $disagree;

		return array(
			'agree'      => $agree,
			'disagree'   => $disagree,
			'unsure'     => $unsure,
			'total'      => $agree + $disagree + $unsure,
			'pct_agreed' => $decided > 0 ? (int) round( ( $agree / $decided ) * 100 ) : null,
		);
	}

	/**
	 * Stats for many posts in one query.
	 *
	 * The deck hands out 20 cards at a time and wants a stat for each; this
	 * keeps that to a single primary-key lookup rather than 20 round trips.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array Keyed by post ID => { agree, disagree, unsure, total }.
	 */
	public static function get_many( array $post_ids ) {
		global $wpdb;

		$post_ids = array_filter( array_map( 'absint', $post_ids ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is only generated %d markers; every value is bound below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, agree_count, disagree_count, unsure_count
				 FROM %i WHERE post_id IN ( {$placeholders} )",
				array_merge( array( $table ), $post_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$agree    = (int) $row['agree_count'];
			$disagree = (int) $row['disagree_count'];
			$unsure   = (int) $row['unsure_count'];

			$out[ (int) $row['post_id'] ] = array(
				'agree'    => $agree,
				'disagree' => $disagree,
				'unsure'   => $unsure,
				'total'    => $agree + $disagree + $unsure,
			);
		}

		return $out;
	}

	/**
	 * Add the "Swipe stats" column to the Posts list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['wellactually_swipe_stats'] = __( 'Swipe stats', 'wellactually' );
		return $columns;
	}

	/**
	 * Render the "Swipe stats" column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'wellactually_swipe_stats' !== $column ) {
			return;
		}

		$verdict = get_post_meta( $post_id, WellActually_Meta::VERDICT_KEY, true );
		if ( '' === $verdict ) {
			echo '&#8212;';
			return;
		}

		$stats = self::get( $post_id );

		if ( 0 === $stats['total'] ) {
			esc_html_e( 'No swipes yet', 'wellactually' );
			return;
		}

		if ( null !== $stats['pct_agreed'] ) {
			printf(
				/* translators: 1: percent agreed, 2: total swipe count */
				esc_html__( '%1$d%% agree · %2$d swipes', 'wellactually' ),
				(int) $stats['pct_agreed'],
				(int) $stats['total']
			);
		} else {
			printf(
				/* translators: %d: total swipe count */
				esc_html__( '%d swipes', 'wellactually' ),
				(int) $stats['total']
			);
		}
	}
}
