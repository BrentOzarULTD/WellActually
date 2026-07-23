<?php
/**
 * Plugin Name:       Well, Actually...
 * Plugin URI:        https://github.com/BrentOzarULTD/WellActually
 * Description:       Swipe mode for your blog. Show readers a statement, let them swipe agree/disagree/not sure, and reveal the truth (and the post behind it) when they're wrong.
 * Version:           1.8.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Brent Ozar
 * License:            GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wellactually
 * Domain Path:       /languages
 *
 * @package WellActually
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WELLACTUALLY_VERSION', '1.8.0' );
define( 'WELLACTUALLY_PLUGIN_FILE', __FILE__ );
define( 'WELLACTUALLY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WELLACTUALLY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-migrate.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-settings.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-meta.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-bulk-setup.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-reports.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-ai-queue.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-ai.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-template.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-stats.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-rest.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-user-progress.php';
require_once WELLACTUALLY_PLUGIN_DIR . 'includes/class-wellactually-privacy.php';

/**
 * Boot the plugin.
 */
function wellactually_init() {
	load_plugin_textdomain( 'wellactually', false, dirname( plugin_basename( WELLACTUALLY_PLUGIN_FILE ) ) . '/languages' );

	// Before any component registers a hook or reads an option: on a site
	// upgrading from 1.7.0 or earlier, every name below still refers to data
	// stored under the old `wa_` prefix until this has run.
	WellActually_Migrate::maybe_migrate();

	WellActually_Settings::instance();
	WellActually_Meta::instance();
	WellActually_Bulk_Setup::instance();
	WellActually_Reports::instance();
	WellActually_AI::instance();
	WellActually_Template::instance();
	WellActually_Stats::instance();
	WellActually_Rest::instance();
	WellActually_User_Progress::instance();
	WellActually_Privacy::instance();
}
add_action( 'plugins_loaded', 'wellactually_init' );

/**
 * Plugin activation: register rewrite rules, flush them, and let other
 * components (e.g. the stats table) hook in their own setup.
 */
function wellactually_activate() {
	// plugins_loaded has already fired by the time WordPress includes and
	// activates a plugin, so wellactually_init() has not run and none of the
	// components have registered their hooks yet. Boot them by hand first,
	// or the wellactually_activate action below fires into the void.
	wellactually_init();

	// Make sure the rewrite rule exists before we flush.
	WellActually_Template::instance()->register_rewrite_rule();

	/**
	 * Fires on plugin activation, after the rewrite rule is registered
	 * but before rewrite rules are flushed. Other components (e.g. the
	 * stats table) hook in their own setup here.
	 */
	do_action( 'wellactually_activate' );

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wellactually_activate' );

/**
 * Plugin deactivation: clean up rewrite rules only. Data is preserved;
 * see uninstall.php for full removal.
 */
function wellactually_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wellactually_deactivate' );
