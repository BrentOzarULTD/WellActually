<?php
/**
 * WordPress privacy tooling: suggested policy text, and the personal-data
 * exporter/eraser for logged-in swipe progress.
 *
 * The only personal data this plugin stores server-side is the `_wellactually_progress`
 * user meta for logged-in players (which posts they've seen and gotten wrong,
 * plus two counters). Everything else is either anonymous by construction
 * (aggregate per-post counts with no user attached), confined to the
 * visitor's own browser (localStorage), or ephemeral (rate-limit transients
 * keyed on a hashed IP that expire within minutes).
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers privacy-policy text and personal-data export/erase handlers.
 */
class WellActually_Privacy {

	/**
	 * Singleton instance.
	 *
	 * @var WellActually_Privacy|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WellActually_Privacy
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
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Suggested text for Settings → Privacy, kept consistent with the
	 * External Services section of readme.txt.
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content =
			'<h2>' . __( 'Well, Actually... swipe game', 'well-actually' ) . '</h2>' .
			'<p>' . __( 'Anonymous visitors: your swipe progress (which statements you have seen and how you answered) is stored only in your own browser\'s local storage. It never leaves your device, and clearing your browser data resets it.', 'well-actually' ) . '</p>' .
			'<p>' . __( 'Logged-in users: the same progress is saved to your account on this site so it follows you between devices. You can obtain or erase it with the personal-data export and erasure tools on this site.', 'well-actually' ) . '</p>' .
			'<p>' . __( 'Aggregate statistics: each swipe increments an anonymous per-post counter (how many visitors agreed, disagreed, or were unsure). These totals contain no account, name, or address, and cannot be traced back to any person.', 'well-actually' ) . '</p>' .
			'<p>' . __( 'Rate limiting: to prevent abuse, a short-lived counter keyed to a hashed form of the visitor\'s IP address is kept for a few minutes and then expires. The IP address itself is not stored.', 'well-actually' ) . '</p>' .
			'<p>' . __( 'AI drafting (site editors only): when an administrator uses the optional "Draft with AI" feature, the affected post\'s title and an excerpt of its content are sent to the AI provider the administrator has configured. No visitor or account data is included. The chosen provider\'s own privacy policy governs that transfer.', 'well-actually' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Well, Actually...', 'well-actually' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['wellactually-progress'] = array(
			'exporter_friendly_name' => __( 'Well, Actually... swipe progress', 'well-actually' ),
			'callback'               => array( $this, 'export_progress' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['wellactually-progress'] = array(
			'eraser_friendly_name' => __( 'Well, Actually... swipe progress', 'well-actually' ),
			'callback'             => array( $this, 'erase_progress' ),
		);
		return $erasers;
	}

	/**
	 * Export a user's stored swipe progress.
	 *
	 * Everything fits one item, so this is always a single page.
	 *
	 * @param string $email_address Email of the person being exported.
	 * @param int    $page          Page number (unused; one page).
	 * @return array { data: array, done: bool }
	 */
	public function export_progress( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		$export = array(
			'data' => array(),
			'done' => true,
		);

		if ( ! $user ) {
			return $export;
		}

		$progress = get_user_meta( $user->ID, WellActually_User_Progress::META_KEY, true );
		if ( ! is_array( $progress ) || empty( $progress ) ) {
			return $export;
		}

		$progress = WellActually_User_Progress::sanitize_progress( $progress );

		$export['data'][] = array(
			'group_id'    => 'wellactually-progress',
			'group_label' => __( 'Well, Actually... swipe progress', 'well-actually' ),
			'item_id'     => 'wellactually-progress-' . $user->ID,
			'data'        => array(
				array(
					'name'  => __( 'Statements answered', 'well-actually' ),
					'value' => (int) $progress['answered_count'],
				),
				array(
					'name'  => __( 'Answered correctly', 'well-actually' ),
					'value' => (int) $progress['correct_count'],
				),
				array(
					'name'  => __( 'Post IDs seen', 'well-actually' ),
					'value' => implode( ', ', array_map( 'intval', $progress['seen'] ) ),
				),
				array(
					'name'  => __( 'Post IDs answered incorrectly', 'well-actually' ),
					'value' => implode( ', ', array_map( 'intval', $progress['wrong'] ) ),
				),
			),
		);

		return $export;
	}

	/**
	 * Erase a user's stored swipe progress — just the `_wellactually_progress` meta,
	 * nothing else. The anonymous aggregate counters are left alone: they
	 * were never attributable to the user, and removing this player's share
	 * from a public tally is neither possible nor required.
	 *
	 * @param string $email_address Email of the person being erased.
	 * @param int    $page          Page number (unused; one page).
	 * @return array { items_removed: bool, items_retained: bool, messages: string[], done: bool }
	 */
	public function erase_progress( $email_address, $page = 1 ) {
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $result;
		}

		if ( metadata_exists( 'user', $user->ID, WellActually_User_Progress::META_KEY ) ) {
			delete_user_meta( $user->ID, WellActually_User_Progress::META_KEY );
			$result['items_removed'] = true;
		}

		return $result;
	}
}
