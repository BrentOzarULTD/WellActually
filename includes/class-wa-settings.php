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
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'slug'        => 'swipe',
			'ai_provider' => '',
			'ai_model'    => '',
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
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			return $registry->hasProvider( $provider_id ) && $registry->isProviderConfigured( $provider_id );
		} catch ( \Throwable $e ) {
			return false;
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

		add_settings_section(
			'wa_ai_section',
			__( 'AI drafting', 'well-actually' ),
			array( $this, 'render_ai_section_intro' ),
			'well-actually'
		);

		add_settings_field(
			'wa_ai_provider',
			__( 'AI provider', 'well-actually' ),
			array( $this, 'render_ai_provider_field' ),
			'well-actually',
			'wa_ai_section'
		);

		add_settings_field(
			'wa_ai_model',
			__( 'AI model', 'well-actually' ),
			array( $this, 'render_ai_model_field' ),
			'well-actually',
			'wa_ai_section'
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

		// AI provider must be one of the registered AI providers.
		$provider              = isset( $input['ai_provider'] ) ? sanitize_text_field( $input['ai_provider'] ) : '';
		$output['ai_provider'] = array_key_exists( $provider, self::ai_providers() ) ? $provider : '';

		// Model id is free text (providers like Nano-GPT proxy many models).
		$output['ai_model'] = isset( $input['ai_model'] ) ? sanitize_text_field( $input['ai_model'] ) : '';

		return $output;
	}

	/**
	 * Intro text for the AI drafting section.
	 */
	public function render_ai_section_intro() {
		if ( function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			$providers = self::ai_providers();
			if ( empty( $providers ) ) {
				echo '<p>' . esc_html__( 'No AI providers are registered yet. Install and configure an AI provider (with an API key) to enable drafting.', 'well-actually' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Pick the provider and model used to draft swipe statements on the Swipe Setup screen. You can change these between batches.', 'well-actually' ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'AI features are not available in this environment.', 'well-actually' ) . '</p>';
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
			<option value=""><?php esc_html_e( '— Select a provider —', 'well-actually' ); ?></option>
			<?php foreach ( $providers as $id => $name ) : ?>
				<?php $configured = self::is_ai_provider_configured( $id ); ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['ai_provider'], $id ); ?>>
					<?php
					echo esc_html( $name );
					if ( ! $configured ) {
						echo ' ' . esc_html__( '(no API key set)', 'well-actually' );
					}
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Providers come from your WordPress AI connector settings.', 'well-actually' ); ?></p>
		<?php
	}

	/**
	 * Render the AI model text field.
	 */
	public function render_ai_model_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ai_model]" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. gpt-4o-mini', 'well-actually' ); ?>" />
		<p class="description"><?php esc_html_e( 'The model id to request from the provider. Leave blank to use the provider default.', 'well-actually' ); ?></p>
		<?php
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
