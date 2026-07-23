<?php
/**
 * Settings page: Settings → Well, Actually...
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the wa_settings option and its tabbed admin settings page
 * (Setup / Categories / Errors).
 */
class WA_Settings {

	const OPTION_NAME = 'wa_settings';

	/**
	 * Singleton instance.
	 *
	 * @var WA_Settings|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WA_Settings
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
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_wa_rebuild_status', array( $this, 'handle_rebuild_status' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WA_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add a "Settings" link to this plugin's row on the Plugins list page.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=wellactually' ) ) . '">' . esc_html__( 'Settings', 'wellactually' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'slug'                => 'swipe',
			'ai_provider'         => '',
			'ai_model'            => '',
			'excluded_categories' => array(),
			'ai_concurrency'      => 5,
			'debug_logging'       => false,
		);
	}

	/**
	 * Registered AI providers, keyed by provider id => display name.
	 *
	 * @return array
	 */
	public static function ai_providers() {
		$providers = array();

		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return $providers;
		}

		foreach ( wp_get_connectors() as $id => $connector ) {
			$type = isset( $connector['type'] ) ? $connector['type'] : '';
			if ( 'ai_provider' !== $type ) {
				continue;
			}
			$providers[ $id ] = isset( $connector['name'] ) ? $connector['name'] : $id;
		}

		return $providers;
	}

	/**
	 * Whether a given AI provider currently has usable credentials.
	 *
	 * @param string $provider_id Provider id.
	 * @return bool
	 */
	public static function is_ai_provider_configured( $provider_id ) {
		if ( '' === $provider_id || ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return false;
		}

		// Some providers' isProviderConfigured() does a live round-trip to the
		// provider's API (e.g. validating the key or fetching account/balance
		// info) rather than just checking that a key string is present. That
		// call runs once per provider on the settings page (looped over every
		// registered provider) and once per page load of Posts → "Well,
		// Actually...", which made both screens visibly slow to load. Cache
		// the result briefly so repeat page loads don't repeat that
		// round-trip; a save on the Setup tab clears it immediately (see
		// sanitize_settings()) so a key/provider change is reflected right away.
		$cache_key = 'wa_ai_provider_configured_' . $provider_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		try {
			$registry   = \WordPress\AiClient\AiClient::defaultRegistry();
			$configured = $registry->hasProvider( $provider_id ) && $registry->isProviderConfigured( $provider_id );
		} catch ( \Throwable $e ) {
			$configured = false;
		}

		set_transient( $cache_key, $configured ? '1' : '0', 5 * MINUTE_IN_SECONDS );

		return $configured;
	}

	/**
	 * Clear the cached provider-configured check(s). Called after a Setup-tab
	 * save so a provider/key change is reflected immediately rather than
	 * waiting out the cache TTL.
	 *
	 * @param string $provider_id Specific provider to clear, or '' for all
	 *                            registered providers.
	 */
	public static function clear_provider_configured_cache( $provider_id = '' ) {
		if ( '' !== $provider_id ) {
			delete_transient( 'wa_ai_provider_configured_' . $provider_id );
			return;
		}

		foreach ( array_keys( self::ai_providers() ) as $id ) {
			delete_transient( 'wa_ai_provider_configured_' . $id );
		}
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return wp_parse_args( $settings, self::defaults() );
	}

	/**
	 * Category term IDs to skip entirely — their posts never count as
	 * eligible in Posts → "Well, Actually..." (needs setup, AI drafting
	 * candidates, or any of its other status views).
	 *
	 * @return int[]
	 */
	public static function excluded_categories() {
		$ids = wa_get_setting( 'excluded_categories', array() );
		$ids = is_array( $ids ) ? array_filter( array_map( 'absint', $ids ) ) : array();

		if ( empty( $ids ) ) {
			return array();
		}

		// Skipping a category skips everything under it. Expanding here rather
		// than storing the descendants means a child category created *after*
		// the parent was checked is covered automatically, and it's required
		// for correctness regardless: WP_Query's category__not_in matches only
		// the exact terms given, with no descendant expansion of its own (in
		// contrast to the positive `cat` argument, which does include
		// children). get_term_children() returns all descendants, not just
		// direct children, so a whole branch comes along.
		$all = $ids;
		foreach ( $ids as $id ) {
			$children = get_term_children( $id, 'category' );
			if ( is_array( $children ) ) {
				$all = array_merge( $all, array_map( 'absint', $children ) );
			}
		}

		return array_values( array_unique( array_filter( $all ) ) );
	}

	/**
	 * Register the submenu page under Settings.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Well, Actually...', 'wellactually' ),
			__( 'Well, Actually...', 'wellactually' ),
			'manage_options',
			'wellactually',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * The current tab, defaulting to and falling back to 'setup'.
	 *
	 * @return string One of setup|categories|errors.
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'setup';
		return in_array( $tab, array( 'setup', 'categories', 'reports', 'errors' ), true ) ? $tab : 'setup';
	}

	/**
	 * Register the settings, sections, and fields for the Setup tab.
	 */
	public function register_settings() {
		register_setting(
			'wa_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'wa_settings_section',
			'',
			'__return_false',
			'wellactually_setup'
		);

		add_settings_field(
			'wa_slug',
			__( 'Swipe page slug', 'wellactually' ),
			array( $this, 'render_slug_field' ),
			'wellactually_setup',
			'wa_settings_section'
		);

		add_settings_section(
			'wa_ai_section',
			__( 'AI drafting', 'wellactually' ),
			array( $this, 'render_ai_section_intro' ),
			'wellactually_setup'
		);

		add_settings_field(
			'wa_ai_provider',
			__( 'AI provider', 'wellactually' ),
			array( $this, 'render_ai_provider_field' ),
			'wellactually_setup',
			'wa_ai_section'
		);

		add_settings_field(
			'wa_ai_model',
			__( 'AI model', 'wellactually' ),
			array( $this, 'render_ai_model_field' ),
			'wellactually_setup',
			'wa_ai_section'
		);

		add_settings_field(
			'wa_ai_concurrency',
			__( 'Parallel requests', 'wellactually' ),
			array( $this, 'render_ai_concurrency_field' ),
			'wellactually_setup',
			'wa_ai_section'
		);

		add_settings_section(
			'wa_diagnostics_section',
			__( 'Diagnostics', 'wellactually' ),
			'__return_false',
			'wellactually_setup'
		);

		add_settings_field(
			'wa_debug_logging',
			__( 'Logging', 'wellactually' ),
			array( $this, 'render_debug_logging_field' ),
			'wellactually_setup',
			'wa_diagnostics_section'
		);

	}

	/**
	 * Rebuild the status index on demand, then return to the Setup tab.
	 *
	 * Its own form (not part of the settings form) so it can't be triggered
	 * by an ordinary save.
	 */
	public function handle_rebuild_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wellactually' ) );
		}

		check_admin_referer( 'wa_rebuild_status' );

		$fixed = WA_Meta::repair_statuses();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'wellactually',
					'tab'        => 'setup',
					'wa_rebuilt' => (int) $fixed,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the status-index rebuild control.
	 *
	 * Deliberately rendered after the settings form rather than as a settings
	 * field: it needs its own form posting to admin-post.php, and a form
	 * nested inside another form is invalid markup that browsers silently
	 * flatten into the outer one.
	 */
	public function render_rebuild_status_block() {
		echo '<h2>' . esc_html__( 'Status index', 'wellactually' ) . '</h2>';

		if ( isset( $_GET['wa_rebuilt'] ) ) {
			$fixed = absint( $_GET['wa_rebuilt'] );
			echo '<p class="wa-rebuilt-notice">';
			echo esc_html(
				$fixed > 0
					/* translators: %d: number of posts corrected */
					? sprintf( _n( 'Corrected %d post.', 'Corrected %d posts.', $fixed, 'wellactually' ), $fixed )
					: __( 'Everything already matched — nothing to correct.', 'wellactually' )
			);
			echo '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wa_rebuild_status" />
			<?php wp_nonce_field( 'wa_rebuild_status' ); ?>
			<?php submit_button( __( 'Rebuild status index', 'wellactually' ), 'secondary', 'submit', false ); ?>
		</form>
		<p class="description">
			<?php esc_html_e( 'The "Well, Actually..." screen sorts posts into its Needs to be set up / Has AI suggestions / In swipe deck views using a summary field kept alongside each post. If that summary ever gets out of step — a post showing up as needing setup when it\'s already excluded, say, or a drafted suggestion you can\'t reach — this rebuilds it from scratch. Safe to run at any time.', 'wellactually' ); ?>
		</p>
		<?php
	}

	/**
	 * How many drafting requests may run at once.
	 *
	 * @return int Between 1 and 20.
	 */
	public static function ai_concurrency() {
		$value = (int) wa_get_setting( 'ai_concurrency', 5 );

		/**
		 * Filter how many AI drafting requests run in parallel.
		 *
		 * @param int $value Configured concurrency.
		 */
		$value = (int) apply_filters( 'wa_ai_concurrency', $value );

		return max( 1, min( 20, $value ) );
	}

	/**
	 * Render the drafting concurrency field.
	 */
	public function render_ai_concurrency_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_concurrency]"
			value="<?php echo esc_attr( (int) $settings['ai_concurrency'] ); ?>"
			min="1"
			max="20"
			step="1"
			class="small-text"
		/>
		<p class="description">
			<?php esc_html_e( 'How many posts to draft at the same time. Drafting spends nearly all of its time waiting on the AI provider, so sending several requests at once is much faster than one after another — 5 is roughly five times quicker than 1. Lower this if your provider starts refusing requests for being too frequent.', 'wellactually' ); ?>
		</p>
		<?php
	}

	/**
	 * Whether diagnostic logging for the "Well, Actually..." screen is on.
	 *
	 * @return bool
	 */
	public static function debug_logging_enabled() {
		return (bool) wa_get_setting( 'debug_logging', false );
	}

	/**
	 * Render the diagnostic logging checkbox.
	 */
	public function render_debug_logging_field() {
		$settings = self::get_settings();
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_logging]"
				value="1"
				<?php checked( ! empty( $settings['debug_logging'] ) ); ?>
			/>
			<?php esc_html_e( 'Log how the "Well, Actually..." screen builds each page', 'wellactually' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Writes one line to your site\'s error log per page load of that screen, recording how many posts were examined, how many had out-of-date status information, how many were shown, and the total. Useful when that screen shows the wrong posts (or none at all) and you want real numbers rather than guesswork. Leave off for normal use.', 'wellactually' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize the settings array on save. Only the fields belonging to the
	 * tab that was actually submitted (a hidden `_tab` field distinguishes
	 * them) are touched; everything else is carried over from the currently
	 * stored option. This matters most for the Categories tab: unchecked
	 * checkboxes send nothing at all, so without this a save from that tab
	 * with zero categories checked would be indistinguishable from "don't
	 * change anything" — and, more importantly, a save from ANY one tab must
	 * never blow away the other tabs' values.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$existing = self::get_settings();
		$output   = $existing;
		$tab      = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : 'setup';

		if ( 'categories' === $tab ) {
			$raw                            = ( isset( $input['excluded_categories'] ) && is_array( $input['excluded_categories'] ) ) ? $input['excluded_categories'] : array();
			$output['excluded_categories']  = array_values( array_unique( array_map( 'absint', $raw ) ) );
			return $output;
		}

		// Setup tab.
		$slug           = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '';
		$output['slug'] = ( '' !== $slug ) ? $slug : $existing['slug'];

		// AI provider must be one of the registered AI providers.
		$provider              = isset( $input['ai_provider'] ) ? sanitize_text_field( $input['ai_provider'] ) : '';
		$output['ai_provider'] = array_key_exists( $provider, self::ai_providers() ) ? $provider : '';

		// Model id is free text (providers like Nano-GPT proxy many models).
		$output['ai_model'] = isset( $input['ai_model'] ) ? sanitize_text_field( $input['ai_model'] ) : '';

		// How many drafting requests may be in flight at once.
		$concurrency              = isset( $input['ai_concurrency'] ) ? absint( $input['ai_concurrency'] ) : 5;
		$output['ai_concurrency'] = max( 1, min( 20, $concurrency ) );

		// Set explicitly rather than only when present: an unchecked checkbox
		// submits nothing, so without this a box could be ticked but never
		// un-ticked.
		$output['debug_logging'] = ! empty( $input['debug_logging'] );

		// A Setup save always clears the cached provider-configured check, so
		// a provider/key change is reflected immediately rather than waiting
		// out the transient's TTL.
		self::clear_provider_configured_cache();

		return $output;
	}

	/**
	 * Intro text for the AI drafting section.
	 */
	public function render_ai_section_intro() {
		if ( function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			$providers = self::ai_providers();
			if ( empty( $providers ) ) {
				echo '<p>' . esc_html__( 'No AI providers are registered yet. Install and configure an AI provider (with an API key) to enable drafting.', 'wellactually' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Pick the provider and model used to draft swipe statements on the "Well, Actually..." screen (under Posts). You can change these between batches.', 'wellactually' ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'AI features are not available in this environment.', 'wellactually' ) . '</p>';
		}
	}

	/**
	 * Render the AI provider dropdown.
	 */
	public function render_ai_provider_field() {
		$settings  = self::get_settings();
		$providers = self::ai_providers();
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_provider]">
			<option value=""><?php esc_html_e( '— Select a provider —', 'wellactually' ); ?></option>
			<?php foreach ( $providers as $id => $name ) : ?>
				<?php $configured = self::is_ai_provider_configured( $id ); ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['ai_provider'], $id ); ?>>
					<?php
					echo esc_html( $name );
					if ( ! $configured ) {
						echo ' ' . esc_html__( '(no API key set)', 'wellactually' );
					}
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Providers come from your WordPress AI connector settings.', 'wellactually' ); ?></p>
		<?php
	}

	/**
	 * Render the AI model text field.
	 */
	public function render_ai_model_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_model]" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. google/gemini-3.5-flash', 'wellactually' ); ?>" />
		<p class="description"><?php esc_html_e( 'The model id to request from the provider. Leave blank to use the default model you\'ve picked in that provider\'s own settings, if it has one.', 'wellactually' ); ?></p>
		<?php
		$provider  = $settings['ai_provider'];
		$effective = '' !== $provider ? WA_AI::effective_model( $provider ) : '';

		if ( '' !== $effective && false === WA_AI::model_supports_drafting( $provider, $effective ) ) {
			echo '<p class="wa-model-warning">';
			printf(
				/* translators: %s: model id */
				esc_html__( 'Heads up: %s does not advertise support for schema-enforced JSON output. Drafting still works — it asks for JSON in the prompt instead — but results can be less consistent. A model that supports JSON/structured output is more reliable.', 'wellactually' ),
				'<strong>' . esc_html( $effective ) . '</strong>'
			);
			echo '</p>';
		}
	}

	/**
	 * Render the slug field.
	 */
	public function render_slug_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[slug]" value="<?php echo esc_attr( $settings['slug'] ); ?>" class="regular-text" />
		<p class="description">
			<?php
			printf(
				/* translators: %s: example URL */
				esc_html__( 'Visitors will play swipe mode at %s.', 'wellactually' ),
				'<code>' . esc_html( home_url( '/' ) ) . '<strong>' . esc_html( $settings['slug'] ) . '</strong></code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the tabbed settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab       = $this->current_tab();
		$base_url  = admin_url( 'options-general.php?page=wellactually' );
		$tab_names = array(
			'setup'      => __( 'Setup', 'wellactually' ),
			'categories' => __( 'Categories', 'wellactually' ),
			'reports'    => __( 'Reports', 'wellactually' ),
			'errors'     => __( 'Errors', 'wellactually' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Well, Actually...', 'wellactually' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tab_names as $tab_key => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, $base_url ) ); ?>"
						class="nav-tab <?php echo ( $tab_key === $tab ) ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'errors' === $tab ) : ?>
				<div class="wa-tab-panel">
					<?php $this->render_errors_tab(); ?>
				</div>
			<?php elseif ( 'reports' === $tab ) : ?>
				<div class="wa-tab-panel">
					<?php WA_Reports::instance()->render_tab(); ?>
				</div>
			<?php else : ?>
				<form action="options.php" method="post" class="wa-tab-panel">
					<?php
					settings_fields( 'wa_settings_group' );
					echo '<input type="hidden" name="' . esc_attr( self::OPTION_NAME ) . '[_tab]" value="' . esc_attr( $tab ) . '" />';

					if ( 'categories' === $tab ) {
						$this->render_categories_tab();
					} else {
						do_settings_sections( 'wellactually_setup' );
					}

					submit_button();
					?>
				</form>
				<?php
				// Outside the settings form: its own form, posting elsewhere.
				if ( 'setup' === $tab ) {
					$this->render_rebuild_status_block();
				}
				?>
			<?php endif; ?>
		</div>
		<style>
			.wa-tab-panel { margin-top: 16px; }
			.wa-rebuilt-notice { background: #edfaef; border-left: 4px solid #00a32a; padding: 8px 12px; margin: 0 0 8px; max-width: 600px; }
			.wa-model-warning { background: #fcf9e8; border-left: 4px solid #dba617; padding: 8px 12px; margin: 8px 0; max-width: 600px; }
			.wa-categories-table th.wa-col-count,
			.wa-categories-table td.wa-col-count { width: 90px; }
			.wa-categories-table th.wa-col-skip,
			.wa-categories-table td.wa-col-skip { width: 140px; text-align: center; }
			.wa-errors-table td { vertical-align: top; }
		</style>
		<?php
	}

	/**
	 * Render the Categories tab: every category, indented by parent/child,
	 * with its post count and a "Skip This Category" checkbox.
	 */
	private function render_categories_tab() {
		echo '<p class="description">' . esc_html__( 'Posts in a checked category are skipped entirely — they never show up as eligible in Posts → "Well, Actually..." (needs setup, AI drafting candidates, or any other status view there).', 'wellactually' ) . '</p>';

		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( empty( $categories ) ) {
			echo '<p>' . esc_html__( 'No categories yet.', 'wellactually' ) . '</p>';
			return;
		}

		$by_parent = array();
		foreach ( $categories as $category ) {
			$by_parent[ $category->parent ][] = $category;
		}

		$excluded = self::excluded_categories();
		?>
		<table class="widefat striped wa-categories-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'wellactually' ); ?></th>
					<th class="wa-col-count"><?php esc_html_e( 'Count', 'wellactually' ); ?></th>
					<th class="wa-col-skip"><?php esc_html_e( 'Skip This Category', 'wellactually' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php $this->render_category_rows( $by_parent, 0, 0, $excluded ); ?>
			</tbody>
		</table>
		<script>
		( function () {
			var boxes = Array.prototype.slice.call( document.querySelectorAll( '.wa-cat-checkbox' ) );
			if ( ! boxes.length ) { return; }

			// term id -> its direct child checkboxes.
			var childrenOf = {};
			boxes.forEach( function ( box ) {
				var parent = box.getAttribute( 'data-parent-id' );
				if ( ! childrenOf[ parent ] ) { childrenOf[ parent ] = []; }
				childrenOf[ parent ].push( box );
			} );

			// Skipping a category skips everything beneath it, so show the
			// descendants as checked and lock them while the parent is. They
			// submit nothing while disabled, which is fine — only the parent
			// needs storing, and the server expands it back out to the whole
			// branch (so categories added under it later are covered too).
			function apply( box ) {
				var kids = childrenOf[ box.getAttribute( 'data-term-id' ) ] || [];
				kids.forEach( function ( kid ) {
					if ( box.checked || box.disabled ) {
						kid.checked = true;
						kid.disabled = true;
					} else {
						kid.disabled = false;
					}
					apply( kid );
				} );
			}

			boxes.forEach( function ( box ) {
				box.addEventListener( 'change', function () { apply( box ); } );
			} );

			// Top-level first, so locking cascades down the tree on load.
			( childrenOf[ '0' ] || [] ).forEach( apply );
		} )();
		</script>
		<?php
	}

	/**
	 * Recursively render one level of the category tree.
	 *
	 * @param array $by_parent Categories grouped by their parent term ID.
	 * @param int   $parent_id Parent term ID to render children of (0 = top level).
	 * @param int   $depth     Current nesting depth, for indentation.
	 * @param int[] $excluded  Currently-excluded category term IDs.
	 */
	private function render_category_rows( $by_parent, $parent_id, $depth, $excluded ) {
		if ( empty( $by_parent[ $parent_id ] ) ) {
			return;
		}

		foreach ( $by_parent[ $parent_id ] as $category ) {
			$checkbox_id = 'wa-cat-' . $category->term_id;
			?>
			<tr>
				<td style="padding-left: <?php echo esc_attr( 12 + ( $depth * 24 ) ); ?>px;">
					<?php if ( $depth > 0 ) : ?>
						<span aria-hidden="true">&#8212;&nbsp;</span>
					<?php endif; ?>
					<label for="<?php echo esc_attr( $checkbox_id ); ?>"><?php echo esc_html( $category->name ); ?></label>
				</td>
				<td class="wa-col-count"><?php echo (int) $category->count; ?></td>
				<td class="wa-col-skip">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $checkbox_id ); ?>"
						class="wa-cat-checkbox"
						name="<?php echo esc_attr( self::OPTION_NAME ); ?>[excluded_categories][]"
						value="<?php echo esc_attr( $category->term_id ); ?>"
						data-term-id="<?php echo esc_attr( $category->term_id ); ?>"
						data-parent-id="<?php echo esc_attr( $category->parent ); ?>"
						<?php checked( in_array( $category->term_id, $excluded, true ) ); ?>
					/>
				</td>
			</tr>
			<?php
			$this->render_category_rows( $by_parent, $category->term_id, $depth + 1, $excluded );
		}
	}

	/**
	 * Render the Errors tab: AI drafting errors from the last 7 days.
	 * Prunes anything older first, resetting those posts back to a plain,
	 * retryable needs-setup state.
	 */
	private function render_errors_tab() {
		WA_AI::prune_old_errors( 7 );
		$errors = WA_AI::get_recent_errors( 7 );

		echo '<p class="description">' . esc_html__( 'AI drafting errors from the last 7 days. Older errors are cleared automatically and the post becomes available to draft again.', 'wellactually' ) . '</p>';

		if ( empty( $errors ) ) {
			echo '<p>' . esc_html__( 'No drafting errors in the last 7 days.', 'wellactually' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped wa-errors-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'wellactually' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'wellactually' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'wellactually' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $errors as $error ) {
			$title = '' !== $error['title'] ? $error['title'] : __( '(no title)', 'wellactually' );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $error['edit_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a></td>';
			echo '<td>' . esc_html( $error['error'] ) . '</td>';
			echo '<td>' . esc_html(
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "3 hours" */
					__( '%s ago', 'wellactually' ),
					human_time_diff( $error['time'], time() )
				)
			) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}

/**
 * Get a single Well, Actually... setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback value if the key isn't set.
 * @return mixed
 */
function wa_get_setting( $key, $default = null ) {
	$settings = WA_Settings::get_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}
