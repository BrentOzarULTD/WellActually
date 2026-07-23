<?php
/**
 * Plugin Name:       Well, Actually...
 * Plugin URI:        https://github.com/BrentOzarULTD/WellActually
 * Description:       Swipe mode for your blog. Show readers a statement, let them swipe agree/disagree/not sure, and reveal the truth (and the post behind it) when they're wrong.
 * Version:           1.3.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Brent Ozar
 * License:            MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wellactually
 * Domain Path:       /languages
 *
 * @package WellActually
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WA_VERSION', '1.3.3' );
define( 'WA_PLUGIN_FILE', __FILE__ );
define( 'WA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WA_PLUGIN_DIR . 'includes/class-wa-settings.php';
require_once WA_PLUGIN_DIR . 'includes/class-wa-meta.php';
require_once WA_PLUGIN_DIR . 'includes/class-wa-bulk-setup.php';
require_once WA_PLUGIN_DIR . 'includes/class-wa-ai.php';
require_once WA_PLUGIN_DIR . 'includes/class-wa-template.php';
require_once WA_PLUGIN_DIR . 'includes/class-wa-stats.php';
require_once WA_PLUGIN_DIR . 'includes/class-wa-rest.php';
require_once WA_PLUGIN_DIR . 'includes/class-wa-user-progress.php';

/**
 * Boot the plugin.
 */
function wa_init() {
	load_plugin_textdomain( 'wellactually', false, dirname( plugin_basename( WA_PLUGIN_FILE ) ) . '/languages' );

	WA_Settings::instance();
	WA_Meta::instance();
	WA_Bulk_Setup::instance();
	WA_AI::instance();
	WA_Template::instance();
	WA_Stats::instance();
	WA_Rest::instance();
	WA_User_Progress::instance();
}
add_action( 'plugins_loaded', 'wa_init' );

/**
 * Plugin activation: register rewrite rules, flush them, and let other
 * components (e.g. the stats table) hook in their own setup.
 */
function wa_activate() {
	// plugins_loaded has already fired by the time WordPress includes and
	// activates a plugin, so wa_init() has not run and none of the
	// components have registered their hooks yet. Boot them by hand first,
	// or the wa_activate action below fires into the void.
	wa_init();

	// Make sure the rewrite rule exists before we flush.
	WA_Template::instance()->register_rewrite_rule();

	/**
	 * Fires on plugin activation, after the rewrite rule is registered
	 * but before rewrite rules are flushed. Other components (e.g. the
	 * stats table) hook in their own setup here.
	 */
	do_action( 'wa_activate' );

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wa_activate' );

/**
 * Plugin deactivation: clean up rewrite rules only. Data is preserved;
 * see uninstall.php for full removal.
 */
function wa_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wa_deactivate' );
