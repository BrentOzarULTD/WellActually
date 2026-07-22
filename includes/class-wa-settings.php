<?php
/**
 * Settings page: Settings → WellActually.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the wa_settings option and its admin settings page.
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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION_NAME, array( $this, 'maybe_flush_rewrite_rules' ), 10, 2 );
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'slug' => 'swipe',
		);
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
	 * Register the submenu page under Settings.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'WellActually', 'well-actually' ),
			__( 'WellActually', 'well-actually' ),
			'manage_options',
			'well-actually',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the settings, section, and field.
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
			'well-actually'
		);

		add_settings_field(
			'wa_slug',
			__( 'Swipe page slug', 'well-actually' ),
			array( $this, 'render_slug_field' ),
			'well-actually',
			'wa_settings_section'
		);
	}

	/**
	 * Sanitize the settings array on save.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$output   = array();

		$slug            = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '';
		$output['slug']  = ( '' !== $slug ) ? $slug : $defaults['slug'];

		return $output;
	}

	/**
	 * When the option changes, flush rewrite rules if the slug changed.
	 *
	 * @param array $old_value Previous option value.
	 * @param array $new_value New option value.
	 */
	public function maybe_flush_rewrite_rules( $old_value, $new_value ) {
		$old_slug = isset( $old_value['slug'] ) ? $old_value['slug'] : '';
		$new_slug = isset( $new_value['slug'] ) ? $new_value['slug'] : '';

		if ( $old_slug !== $new_slug ) {
			WA_Template::instance()->register_rewrite_rule();
			flush_rewrite_rules();
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
				esc_html__( 'Visitors will play swipe mode at %s.', 'well-actually' ),
				'<code>' . esc_html( home_url( '/' ) ) . '<strong>' . esc_html( $settings['slug'] ) . '</strong></code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WellActually', 'well-actually' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'wa_settings_group' );
				do_settings_sections( 'well-actually' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

/**
 * Get a single WellActually setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback value if the key isn't set.
 * @return mixed
 */
function wa_get_setting( $key, $default = null ) {
	$settings = WA_Settings::get_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}
