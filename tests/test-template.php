<?php
/**
 * Swipe page template and social sharing metadata.
 *
 * @package WellActually
 */

/**
 * Covers the isolated swipe template's directly-rendered head metadata.
 */
class Test_WellActually_Template extends WP_UnitTestCase {

	/**
	 * Original site-level values restored after each test.
	 *
	 * @var mixed[]
	 */
	private $original = array();

	/**
	 * Keep tests isolated from the suite's shared options.
	 */
	public function set_up() {
		parent::set_up();

		$this->original = array(
			'blogname'  => get_option( 'blogname' ),
			'settings'  => get_option( WellActually_Settings::OPTION_NAME ),
			'site_icon' => get_option( 'site_icon' ),
		);

		update_option( 'blogname', 'Example Blog' );
		delete_option( 'site_icon' );
		update_option(
			WellActually_Settings::OPTION_NAME,
			array_merge(
				WellActually_Settings::defaults(),
				array( 'slug' => 'actually' )
			)
		);
	}

	/**
	 * Restore site-level values changed by a test.
	 */
	public function tear_down() {
		update_option( 'blogname', $this->original['blogname'] );

		if ( false === $this->original['settings'] ) {
			delete_option( WellActually_Settings::OPTION_NAME );
		} else {
			update_option( WellActually_Settings::OPTION_NAME, $this->original['settings'] );
		}

		if ( false === $this->original['site_icon'] ) {
			delete_option( 'site_icon' );
		} else {
			update_option( 'site_icon', $this->original['site_icon'] );
		}

		parent::tear_down();
	}

	/**
	 * Create enough attachment metadata for WordPress to resolve a full image.
	 *
	 * @return int Attachment post ID.
	 */
	private function create_image_attachment() {
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Social preview',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
				'guid'           => 'https://example.org/social-preview.png',
			)
		);

		update_post_meta( $attachment_id, '_wp_attached_file', 'social-preview.png' );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 1200,
				'height' => 630,
				'file'   => 'social-preview.png',
				'sizes'  => array(),
			)
		);

		return $attachment_id;
	}

	/**
	 * Blank settings still produce a useful title and description.
	 */
	public function test_social_meta_uses_dynamic_defaults() {
		ob_start();
		WellActually_Template::instance()->print_social_meta();
		$html = ob_get_clean();
		$url  = home_url( user_trailingslashit( 'actually' ) );

		$this->assertStringContainsString( 'property="og:title" content="Well, Actually... — Example Blog"', $html );
		$this->assertStringContainsString( 'property="og:description" content="How well do you know Example Blog?', $html );
		$this->assertStringContainsString( 'property="og:url" content="' . esc_url( $url ) . '"', $html );
		$this->assertStringContainsString( 'name="twitter:card" content="summary"', $html );
		$this->assertStringNotContainsString( 'property="og:image"', $html );
	}

	/**
	 * A site icon supplies an automatic image without pretending it is wide.
	 */
	public function test_social_meta_falls_back_to_site_icon() {
		$image_id = $this->create_image_attachment();
		update_option( 'site_icon', $image_id );

		$image_url = get_site_icon_url( 512 );

		ob_start();
		WellActually_Template::instance()->print_social_meta();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'property="og:image" content="' . esc_url( $image_url ) . '"', $html );
		$this->assertStringContainsString( 'name="twitter:card" content="summary"', $html );
		$this->assertStringNotContainsString( 'name="twitter:card" content="summary_large_image"', $html );
	}

	/**
	 * A configured image and copy are reflected in both metadata families.
	 */
	public function test_social_meta_uses_configured_copy_and_image() {
		$image_id = $this->create_image_attachment();
		update_post_meta( $image_id, '_wp_attachment_image_alt', 'A stack of quiz cards' );

		update_option(
			WellActually_Settings::OPTION_NAME,
			array_merge(
				WellActually_Settings::defaults(),
				array(
					'slug'               => 'actually',
					'social_title'       => 'The DBA "Truth" Test',
					'social_description' => 'Agree, disagree & learn something.',
					'social_image_id'    => $image_id,
				)
			)
		);

		$image_url = wp_get_attachment_image_url( $image_id, 'full' );

		ob_start();
		WellActually_Template::instance()->print_social_meta();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'property="og:title" content="The DBA &quot;Truth&quot; Test"', $html );
		$this->assertStringContainsString( 'property="og:description" content="Agree, disagree &amp; learn something."', $html );
		$this->assertStringContainsString( 'property="og:image" content="' . esc_url( $image_url ) . '"', $html );
		$this->assertStringContainsString( 'property="og:image:width" content="1200"', $html );
		$this->assertStringContainsString( 'property="og:image:height" content="630"', $html );
		$this->assertStringContainsString( 'property="og:image:alt" content="A stack of quiz cards"', $html );
		$this->assertStringContainsString( 'name="twitter:card" content="summary_large_image"', $html );
		$this->assertStringContainsString( 'name="twitter:image" content="' . esc_url( $image_url ) . '"', $html );
	}

	/**
	 * The save callback limits copy and refuses non-image attachment IDs.
	 */
	public function test_social_settings_are_sanitized() {
		$settings = WellActually_Settings::instance()->sanitize_settings(
			array(
				'_tab'               => 'setup',
				'slug'               => 'actually',
				'social_title'       => '<b>' . str_repeat( 'T', 250 ) . '</b>',
				'social_description' => '<script>' . str_repeat( 'D', 350 ) . '</script>',
				'social_image_id'    => self::factory()->post->create(),
				'ai_provider'        => '',
				'ai_model'           => '',
				'ai_system_prompt'   => '',
				'ai_concurrency'     => 5,
			)
		);

		$this->assertSame( 200, mb_strlen( $settings['social_title'] ) );
		$this->assertSame( 300, mb_strlen( $settings['social_description'] ) );
		$this->assertStringNotContainsString( '<', $settings['social_title'] );
		$this->assertStringNotContainsString( '<', $settings['social_description'] );
		$this->assertSame( 0, $settings['social_image_id'] );
	}
}
