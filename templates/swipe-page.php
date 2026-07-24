<?php
/**
 * Full-screen swipe mode takeover template.
 *
 * Intentionally does NOT call wp_head()/wp_footer() so no theme CSS/JS
 * bleeds into the page — only our own assets are printed.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<?php WellActually_Template::instance()->print_robots_meta(); ?>
	<?php
	/* translators: %s: site name */
	$wellactually_title = sprintf( __( 'Swipe – %s', 'wellactually' ), get_bloginfo( 'name' ) );
	?>
	<title><?php echo esc_html( $wellactually_title ); ?></title>
	<?php WellActually_Template::instance()->print_head_assets(); ?>
</head>
<body class="wa-swipe-body">
	<a class="wa-home-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
	</a>

	<div id="wa-app" aria-live="polite">
		<div class="wa-loading"><?php esc_html_e( 'Loading…', 'wellactually' ); ?></div>
	</div>

	<noscript>
		<p class="wa-noscript"><?php esc_html_e( 'Swipe mode needs JavaScript.', 'wellactually' ); ?></p>
	</noscript>

	<?php WellActually_Template::instance()->print_footer_assets(); ?>
</body>
</html>
