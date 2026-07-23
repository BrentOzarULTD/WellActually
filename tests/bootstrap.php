<?php
/**
 * PHPUnit bootstrap: load the WordPress test library, then this plugin.
 *
 * @package WellActually
 */

// The WordPress test suite needs the PHPUnit Polyfills loaded before its own
// bootstrap runs. Composer puts them in vendor/; a standalone phpunit.phar
// run can point at a checkout with WP_TESTS_PHPUNIT_POLYFILLS_PATH instead.
$wa_vendor_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $wa_vendor_autoload ) ) {
	require_once $wa_vendor_autoload;
} else {
	$wa_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
	if ( $wa_polyfills && file_exists( $wa_polyfills . '/phpunitpolyfills-autoload.php' ) ) {
		require_once $wa_polyfills . '/phpunitpolyfills-autoload.php';
	}
}

$wa_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wa_tests_dir ) {
	$wa_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI bootstrap; WordPress isn't loaded yet.
if ( ! file_exists( $wa_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$wa_tests_dir}." . PHP_EOL;
	echo 'Set WP_TESTS_DIR, or run bin/install-wp-tests.sh first.' . PHP_EOL;
	exit( 1 );
}
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

require_once $wa_tests_dir . '/includes/functions.php';

/**
 * Load the plugin before WordPress finishes booting, so its hooks are
 * registered the way they are on a real site.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/wellactually.php';
	}
);

require $wa_tests_dir . '/includes/bootstrap.php';

// The plugin creates its tables on activation / admin_init, neither of which
// happens in the test bootstrap, so create them up front.
WA_Stats::instance()->create_table();
WA_AI_Queue::create_table();
