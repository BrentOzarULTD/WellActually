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
 * Handles the wa_stats table and tally API.
 */
class WA_Stats {

	const DB_VERSION_OPTION = 'wa_db_version';
	const DB_VERSION        = '1.0';

	/**
	 * Singleton instance.
	 *
	 * @var WA_Stats|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_Stats
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
		add_action( 'wa_activate', array( $this, 'create_table' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_table' ) );

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
		return $wpdb->prefix . 'wa_stats';
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is from a fixed whitelist above, not user input.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table_name} (post_id, {$column}) VALUES (%d, 1)
			ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
			$post_id
		);

		return false !== $wpdb->query( $sql );
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
				"SELECT agree_count, disagree_count, unsure_count FROM {$table_name} WHERE post_id = %d",
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
	 * Add the "Swipe stats" column to the Posts list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['wa_swipe_stats'] = __( 'Swipe stats', 'well-actually' );
		return $columns;
	}

	/**
	 * Render the "Swipe stats" column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'wa_swipe_stats' !== $column ) {
			return;
		}

		$verdict = get_post_meta( $post_id, WA_Meta::VERDICT_KEY, true );
		if ( '' === $verdict ) {
			echo '&#8212;';
			return;
		}

		$stats = self::get( $post_id );

		if ( 0 === $stats['total'] ) {
			esc_html_e( 'No swipes yet', 'well-actually' );
			return;
		}

		if ( null !== $stats['pct_agreed'] ) {
			printf(
				/* translators: 1: percent agreed, 2: total swipe count */
				esc_html__( '%1$d%% agree · %2$d swipes', 'well-actually' ),
				(int) $stats['pct_agreed'],
				(int) $stats['total']
			);
		} else {
			printf(
				/* translators: %d: total swipe count */
				esc_html__( '%d swipes', 'well-actually' ),
				(int) $stats['total']
			);
		}
	}
}
