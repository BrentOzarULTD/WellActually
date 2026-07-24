<?php
/**
 * Rewrite rule + full-screen /swipe template takeover.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the swipe page rewrite rule and template takeover.
 */
class WellActually_Template {

	const QUERY_VAR = 'wellactually_swipe';

	/**
	 * REST namespace handed to the frontend. The JS joins routes onto this,
	 * so it must not carry a trailing slash of its own.
	 */
	const NAMESPACE_ROUTE = 'wellactually/v1';

	/**
	 * Singleton instance.
	 *
	 * @var WellActually_Template|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WellActually_Template
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
		add_action( 'init', array( $this, 'register_rewrite_rule' ), 10 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 11 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_include', array( $this, 'maybe_take_over_template' ) );
		add_filter( 'wp_robots', array( $this, 'maybe_noindex' ) );
	}

	/**
	 * The rewrite-rule pattern for the current slug.
	 *
	 * @return string
	 */
	private function rule_pattern() {
		$slug = wellactually_get_setting( 'slug', 'swipe' );
		return '^' . preg_quote( $slug, '#' ) . '/?$';
	}

	/**
	 * Self-healing flush: if the persisted rewrite rules don't yet contain
	 * the current slug's rule, flush so they do. Runs on init at priority 11,
	 * after register_rewrite_rule() has added the rule to the in-memory
	 * rewrite object at priority 10.
	 *
	 * This intentionally does not depend on catching the settings-change
	 * event. Whenever the slug changes (or rules are otherwise stale — after
	 * activation, an import, or another plugin flushing), the next request
	 * notices the mismatch and flushes exactly once; subsequent requests find
	 * the rule already present and do nothing.
	 */
	public function maybe_flush_rewrite_rules() {
		$rules = get_option( 'rewrite_rules' );

		// If rules haven't been generated yet, WordPress will build them
		// (including ours) on demand; nothing to heal.
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return;
		}

		$pattern = $this->rule_pattern();

		// Both halves matter. A missing pattern is the slug having changed;
		// a pattern whose target names a different query var is a stored rule
		// from an older version of this plugin (the query var was renamed in
		// 1.8.0). The second case still matches the URL, so without this
		// check it would look healthy while serving a 404.
		$stale = ! isset( $rules[ $pattern ] )
			|| false === strpos( (string) $rules[ $pattern ], self::QUERY_VAR . '=' );

		if ( $stale ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Register the rewrite rule for the swipe page slug.
	 *
	 * Called on init and once during plugin activation before the initial
	 * flush. The self-healing check above handles slug changes.
	 */
	public function register_rewrite_rule() {
		add_rewrite_rule(
			$this->rule_pattern(),
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Register our custom query var.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Check whether the current request is the swipe page.
	 *
	 * @return bool
	 */
	public static function is_swipe_page() {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Take over the template when the swipe query var is set.
	 *
	 * @param string $template Template path WordPress intended to load.
	 * @return string
	 */
	public function maybe_take_over_template( $template ) {
		if ( ! self::is_swipe_page() ) {
			return $template;
		}

		status_header( 200 );

		return WELLACTUALLY_PLUGIN_DIR . 'templates/swipe-page.php';
	}

	/**
	 * Mark the swipe page as noindex.
	 *
	 * @param array $robots Existing robots directives.
	 * @return array
	 */
	public function maybe_noindex( $robots ) {
		if ( self::is_swipe_page() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * Built-in social preview title.
	 *
	 * @return string
	 */
	public static function default_social_title() {
		return sprintf(
			/* translators: %s: site name */
			__( 'Well, Actually... — %s', 'wellactually' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * Built-in social preview description.
	 *
	 * @return string
	 */
	public static function default_social_description() {
		return sprintf(
			/* translators: %s: site name */
			__( 'How well do you know %s? Swipe through bold statements, make your call, and discover the posts that settle it.', 'wellactually' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * Effective social preview title, including the built-in fallback.
	 *
	 * @return string
	 */
	public static function social_title() {
		$title = trim( (string) wellactually_get_setting( 'social_title', '' ) );
		return '' !== $title ? $title : self::default_social_title();
	}

	/**
	 * Effective social preview description, including the built-in fallback.
	 *
	 * @return string
	 */
	public static function social_description() {
		$description = trim( (string) wellactually_get_setting( 'social_description', '' ) );
		return '' !== $description ? $description : self::default_social_description();
	}

	/**
	 * Resolve the configured preview image, falling back to the site icon.
	 *
	 * @param string $fallback_alt Text to use when the attachment has no alt.
	 * @return array|null URL, dimensions, MIME type, alt text, and card size.
	 */
	private function social_image_data( $fallback_alt ) {
		$image_id = absint( wellactually_get_setting( 'social_image_id', 0 ) );

		if ( $image_id && wp_attachment_is_image( $image_id ) ) {
			$image = wp_get_attachment_image_src( $image_id, 'full' );
			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				$alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
				return array(
					'url'        => $image[0],
					'width'      => absint( $image[1] ),
					'height'     => absint( $image[2] ),
					'mime'       => (string) get_post_mime_type( $image_id ),
					'alt'        => '' !== $alt ? $alt : $fallback_alt,
					'large_card' => true,
				);
			}
		}

		$site_icon = get_site_icon_url( 512 );
		if ( ! $site_icon ) {
			return null;
		}

		$filetype = wp_check_filetype( $site_icon );
		return array(
			'url'        => $site_icon,
			'width'      => 512,
			'height'     => 512,
			'mime'       => isset( $filetype['type'] ) ? $filetype['type'] : '',
			'alt'        => $fallback_alt,
			'large_card' => false,
		);
	}

	/**
	 * Print Open Graph and Twitter/X card metadata for the swipe page.
	 *
	 * The takeover template deliberately skips wp_head(), so this metadata
	 * must be rendered directly instead of relying on a theme or SEO plugin.
	 */
	public function print_social_meta() {
		$title       = self::social_title();
		$description = self::social_description();
		$slug        = wellactually_get_setting( 'slug', 'swipe' );
		$url         = home_url( user_trailingslashit( $slug ) );
		$site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$image       = $this->social_image_data( $title );
		?>
		<meta name="description" content="<?php echo esc_attr( $description ); ?>" />
		<meta property="og:type" content="website" />
		<meta property="og:title" content="<?php echo esc_attr( $title ); ?>" />
		<meta property="og:description" content="<?php echo esc_attr( $description ); ?>" />
		<meta property="og:url" content="<?php echo esc_url( $url ); ?>" />
		<meta property="og:site_name" content="<?php echo esc_attr( $site_name ); ?>" />
		<meta property="og:locale" content="<?php echo esc_attr( get_locale() ); ?>" />
		<meta name="twitter:card" content="<?php echo esc_attr( $image && $image['large_card'] ? 'summary_large_image' : 'summary' ); ?>" />
		<meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>" />
		<meta name="twitter:description" content="<?php echo esc_attr( $description ); ?>" />
		<?php if ( $image ) : ?>
			<meta property="og:image" content="<?php echo esc_url( $image['url'] ); ?>" />
			<?php if ( $image['width'] && $image['height'] ) : ?>
				<meta property="og:image:width" content="<?php echo esc_attr( $image['width'] ); ?>" />
				<meta property="og:image:height" content="<?php echo esc_attr( $image['height'] ); ?>" />
			<?php endif; ?>
			<?php if ( '' !== $image['mime'] ) : ?>
				<meta property="og:image:type" content="<?php echo esc_attr( $image['mime'] ); ?>" />
			<?php endif; ?>
			<meta property="og:image:alt" content="<?php echo esc_attr( $image['alt'] ); ?>" />
			<meta name="twitter:image" content="<?php echo esc_url( $image['url'] ); ?>" />
			<meta name="twitter:image:alt" content="<?php echo esc_attr( $image['alt'] ); ?>" />
		<?php endif; ?>
		<?php
	}

	/**
	 * Register the swipe page's assets and their inline config.
	 *
	 * Called directly by the template (not hooked to wp_enqueue_scripts,
	 * since the template intentionally skips wp_head()/wp_footer() to
	 * avoid theme asset bleed).
	 */
	private function register_assets() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		wp_register_style(
			'wellactually-swipe',
			WELLACTUALLY_PLUGIN_URL . 'assets/css/swipe.css',
			array(),
			wellactually_asset_version( 'assets/css/swipe.css' )
		);
		wp_register_script(
			'wellactually-swipe',
			WELLACTUALLY_PLUGIN_URL . 'assets/js/swipe.js',
			array(),
			wellactually_asset_version( 'assets/js/swipe.js' ),
			array( 'in_footer' => true )
		);

		wp_localize_script(
			'wellactually-swipe',
			'wellactuallySwipe',
			array(
				'restUrl'    => esc_url_raw( rest_url( self::NAMESPACE_ROUTE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn' => is_user_logged_in(),
				'homeUrl'    => esc_url_raw( home_url( '/' ) ),
				// get_bloginfo( 'name' ) returns HTML-entity-encoded text (e.g. a
				// straight apostrophe becomes &#039;) even with no 'display'
				// filter requested. Decode it back to plain text here so the
				// frontend's own HTML-escaping (when building the share text)
				// doesn't double-encode it into a literal "&#039;" on screen.
				'siteName'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'i18n'       => self::script_strings(),
			)
		);
	}

	/**
	 * Every user-visible string in swipe.js, translated. The script keeps
	 * English literals as a fallback, so a key missing here degrades to
	 * English rather than to a blank screen.
	 *
	 * Placeholders are PHP-style (%1$s, %2$s…) and filled in JavaScript, so
	 * translators can reorder them.
	 *
	 * @return array
	 */
	private static function script_strings() {
		return array(
			'loading'            => __( 'Loading…', 'wellactually' ),
			'error'              => __( 'Something went wrong loading swipe mode.', 'wellactually' ),
			'retry'              => __( 'Try again', 'wellactually' ),
			'agree'              => __( 'Agree', 'wellactually' ),
			'disagree'           => __( 'Disagree', 'wellactually' ),
			'unsure'             => __( 'Not sure', 'wellactually' ),
			/* translators: 1: number answered correctly, 2: number answered */
			'scoreLabel'         => __( '%1$s/%2$s correct', 'wellactually' ),
			/* translators: 1: number answered correctly, 2: number answered */
			'scoreSoFar'         => __( '%1$s/%2$s correct so far', 'wellactually' ),
			/* translators: 1: number answered correctly, 2: number answered, 3: percentage correct */
			'finalScore'         => __( '%1$s/%2$s correct (%3$s%)', 'wellactually' ),
			/* translators: 1: current card number, 2: number of cards being replayed */
			'replayPosition'     => __( 'Replay: %1$s of %2$s', 'wellactually' ),
			/* translators: 1: current card number, 2: number of cards in the deck */
			'deckPosition'       => __( '%1$s of %2$s', 'wellactually' ),
			/* translators: %1$s: percentage of readers who got this card wrong */
			'pctWrong'           => __( '%1$s% got this one wrong', 'wellactually' ),
			'startOver'          => __( 'Start over', 'wellactually' ),
			'confirmReset'       => __( 'Start over? This clears your saved progress.', 'wellactually' ),
			'keepSwiping'        => __( 'Keep swiping', 'wellactually' ),
			'emptyDeck'          => __( 'No swipe statements yet — check back soon.', 'wellactually' ),
			/* translators: %1$s: number of cards answered incorrectly */
			'replayWrong'        => __( 'Replay the ones you got wrong (%1$s)', 'wellactually' ),
			/* translators: %1$s: number of newly added statements */
			'moreAvailable'      => __( 'New statements have been added — %1$s more await', 'wellactually' ),
			'copy'               => __( 'Copy', 'wellactually' ),
			'copied'             => __( 'Copied!', 'wellactually' ),
			'shareTextLabel'     => __( 'Share text', 'wellactually' ),
			/* translators: 1: number answered correctly, 2: number answered, 3: percentage correct, 4: site name, 5: swipe page URL */
			'shareText'          => __( 'I scored %1$s/%2$s (%3$s%) on the "Well, Actually..." swipe quiz on %4$s. Try it yourself: %5$s', 'wellactually' ),
			'thisBlog'           => __( 'this blog', 'wellactually' ),
			'tierGreat'          => __( 'Well, actually… you should be writing this blog.', 'wellactually' ),
			'tierGood'           => __( 'Solid instincts. A few well-actuallys to go.', 'wellactually' ),
			'tierOk'             => __( 'Halfway there — the archives are calling.', 'wellactually' ),
			'tierPoor'           => __( 'Time to hit the archives.', 'wellactually' ),
			'answerFailed'       => __( 'Couldn’t submit that — check your connection and try again.', 'wellactually' ),
			'storageUnavailable' => __( 'Your progress won’t be saved in this browser.', 'wellactually' ),
			'revealDebatable'    => __( '🤔 It’s debatable', 'wellactually' ),
			'revealUnsure'       => __( 'Not sure? Here’s the answer', 'wellactually' ),
			'revealWrong'        => __( '✗ Well, actually…', 'wellactually' ),
			/* translators: %1$s: the verdict, "TRUE" or "FALSE" */
			'revealVerdict'      => __( 'This one’s %1$s.', 'wellactually' ),
			'verdictTrue'        => _x( 'TRUE', 'verdict shown in the reveal, deliberately shouty', 'wellactually' ),
			'verdictFalse'       => _x( 'FALSE', 'verdict shown in the reveal, deliberately shouty', 'wellactually' ),
			'readFullPost'       => __( 'Read the full post →', 'wellactually' ),
			'continue'           => __( 'Continue', 'wellactually' ),
		);
	}

	/**
	 * Print the stylesheet. Called from the template's <head>.
	 */
	public function print_head_assets() {
		$this->register_assets();
		wp_print_styles( array( 'wellactually-swipe' ) );
	}

	/**
	 * Print the robots meta tag. The template skips wp_head() entirely (to
	 * avoid theme asset bleed), so wp_robots() — normally hooked to wp_head —
	 * never runs on its own; the wp_robots filter registered in the
	 * constructor would otherwise have no effect. Called from the template's
	 * <head>.
	 */
	public function print_robots_meta() {
		if ( function_exists( 'wp_robots' ) ) {
			wp_robots();
		}
	}

	/**
	 * Print the script. Called from the template just before </body> — the
	 * script queries #wa-app on load, so it must not run before the body
	 * has been parsed.
	 */
	public function print_footer_assets() {
		$this->register_assets();
		wp_print_scripts( array( 'wellactually-swipe' ) );
	}
}
