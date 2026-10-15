<?php
/**
 * Order bump presentation presets and safe design settings.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the design schema shared by the editor and checkout renderers.
 */
class BumpMint_Design {

	const VERSION = 1;

	/**
	 * Prevents the shared stylesheet and generated preset rules being enqueued twice.
	 *
	 * @var bool
	 */
	private static $frontend_styles_enqueued = false;

	/**
	 * Returns the supported visual presets.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_presets() {
		return array(
			'classic'  => array(
				'label'       => __( 'Classic', 'bumpmint-order-bump-for-woocommerce' ),
				'description' => __( 'The current red offer card with a gradient banner and soft shadow.', 'bumpmint-order-bump-for-woocommerce' ),
			),
			'minimal'  => array(
				'label'       => __( 'Minimal', 'bumpmint-order-bump-for-woocommerce' ),
				'description' => __( 'A clean card with a subtle teal label and no shadow.', 'bumpmint-order-bump-for-woocommerce' ),
			),
			'soft'     => array(
				'label'       => __( 'Soft', 'bumpmint-order-bump-for-woocommerce' ),
				'description' => __( 'A spacious pastel card with rounded corners and a purple accent.', 'bumpmint-order-bump-for-woocommerce' ),
			),
			'outline'  => array(
				'label'       => __( 'Outline', 'bumpmint-order-bump-for-woocommerce' ),
				'description' => __( 'A coupon-inspired card with a dashed amber border.', 'bumpmint-order-bump-for-woocommerce' ),
			),
			'dark'     => array(
				'label'       => __( 'Dark', 'bumpmint-order-bump-for-woocommerce' ),
				'description' => __( 'A dark card with light text and a high-contrast green accent.', 'bumpmint-order-bump-for-woocommerce' ),
			),
			'compact'  => array(
				'label'       => __( 'Compact', 'bumpmint-order-bump-for-woocommerce' ),
				'description' => __( 'A smaller card with tighter spacing and a blue side accent.', 'bumpmint-order-bump-for-woocommerce' ),
			),
		);
	}

	/**
	 * Returns the translatable field schema used by PHP and the editor script.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_field_schema() {
		return array(
			'accent_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Accent color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-accent', 'default' => '#d71920',
			),
			'accent_hover_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Accent hover color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-accent-hover', 'default' => '#b91319',
			),
			'background_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Card background', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-background', 'default' => '#ffffff',
			),
			'selected_background_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Selected card background', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-selected-bg', 'default' => '#fff7f7',
			),
			'border_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Border color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-border', 'default' => '#dfe3e8',
			),
			'title_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Title color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-text', 'default' => '#1e293b',
			),
			'description_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Description color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-text-muted', 'default' => '#64748b',
			),
			'price_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Price color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-price-color', 'default' => '#b91319',
			),
			'banner_start_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Banner start color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-banner-start', 'default' => '#d71920',
			),
			'banner_end_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Banner end color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-banner-end', 'default' => '#b91319',
			),
			'banner_text_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Banner text color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-banner-text', 'default' => '#ffffff',
			),
			'button_text_color' => array(
				'group' => 'colors', 'type' => 'color', 'label' => __( 'Button text color', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-button-text', 'default' => '#ffffff',
			),
			'font_family' => array(
				'group' => 'typography', 'type' => 'enum', 'label' => __( 'Font family', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-font-family', 'default' => 'inherit',
				'options' => array(
					'inherit' => __( 'Inherit from checkout', 'bumpmint-order-bump-for-woocommerce' ),
					'system'  => __( 'System sans-serif', 'bumpmint-order-bump-for-woocommerce' ),
					'serif'   => __( 'Serif', 'bumpmint-order-bump-for-woocommerce' ),
				),
			),
			'title_size' => array(
				'group' => 'typography', 'type' => 'integer', 'label' => __( 'Title size', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-title-size', 'default' => 15, 'min' => 12, 'max' => 28, 'unit' => 'px',
			),
			'title_weight' => array(
				'group' => 'typography', 'type' => 'enum', 'label' => __( 'Title weight', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-title-weight', 'default' => '650',
				'options' => array( '400' => '400', '500' => '500', '600' => '600', '650' => '650', '700' => '700' ),
			),
			'description_size' => array(
				'group' => 'typography', 'type' => 'integer', 'label' => __( 'Description size', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-description-size', 'default' => 14, 'min' => 12, 'max' => 22, 'unit' => 'px',
			),
			'description_weight' => array(
				'group' => 'typography', 'type' => 'enum', 'label' => __( 'Description weight', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-description-weight', 'default' => '500',
				'options' => array( '400' => '400', '500' => '500', '600' => '600', '650' => '650', '700' => '700' ),
			),
			'price_size' => array(
				'group' => 'typography', 'type' => 'integer', 'label' => __( 'Price size', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-price-size', 'default' => 14, 'min' => 12, 'max' => 26, 'unit' => 'px',
			),
			'banner_size' => array(
				'group' => 'typography', 'type' => 'integer', 'label' => __( 'Banner size', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-banner-size', 'default' => 15, 'min' => 11, 'max' => 22, 'unit' => 'px',
			),
			'banner_transform' => array(
				'group' => 'typography', 'type' => 'enum', 'label' => __( 'Banner capitalization', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-banner-transform', 'default' => 'uppercase',
				'options' => array(
					'uppercase' => __( 'Uppercase', 'bumpmint-order-bump-for-woocommerce' ),
					'none'      => __( 'As entered', 'bumpmint-order-bump-for-woocommerce' ),
				),
			),
			'button_size' => array(
				'group' => 'typography', 'type' => 'integer', 'label' => __( 'Button size', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-button-size', 'default' => 14, 'min' => 12, 'max' => 20, 'unit' => 'px',
			),
			'card_padding' => array(
				'group' => 'spacing', 'type' => 'integer', 'label' => __( 'Card padding', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-card-padding', 'default' => 16, 'min' => 8, 'max' => 32, 'unit' => 'px',
			),
			'content_gap' => array(
				'group' => 'spacing', 'type' => 'integer', 'label' => __( 'Content gap', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-content-gap', 'default' => 14, 'min' => 8, 'max' => 24, 'unit' => 'px',
			),
			'border_width' => array(
				'group' => 'spacing', 'type' => 'integer', 'label' => __( 'Border width', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-border-width', 'default' => 1, 'min' => 0, 'max' => 3, 'unit' => 'px',
			),
			'border_style' => array(
				'group' => 'spacing', 'type' => 'enum', 'label' => __( 'Border style', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-border-style', 'default' => 'solid',
				'options' => array( 'solid' => __( 'Solid', 'bumpmint-order-bump-for-woocommerce' ), 'dashed' => __( 'Dashed', 'bumpmint-order-bump-for-woocommerce' ) ),
			),
			'border_radius' => array(
				'group' => 'spacing', 'type' => 'integer', 'label' => __( 'Corner radius', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-radius', 'default' => 14, 'min' => 0, 'max' => 28, 'unit' => 'px',
			),
			'image_size' => array(
				'group' => 'spacing', 'type' => 'integer', 'label' => __( 'Image size', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-image-size', 'default' => 58, 'min' => 40, 'max' => 96, 'unit' => 'px',
			),
			'image_radius' => array(
				'group' => 'spacing', 'type' => 'integer', 'label' => __( 'Image corner radius', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-image-radius', 'default' => 8, 'min' => 0, 'max' => 24, 'unit' => 'px',
			),
			'shadow' => array(
				'group' => 'spacing', 'type' => 'enum', 'label' => __( 'Shadow', 'bumpmint-order-bump-for-woocommerce' ),
				'css_var' => '--bumpmint-box-shadow', 'default' => 'soft',
				'options' => array( 'none' => __( 'None', 'bumpmint-order-bump-for-woocommerce' ), 'soft' => __( 'Soft', 'bumpmint-order-bump-for-woocommerce' ), 'medium' => __( 'Medium', 'bumpmint-order-bump-for-woocommerce' ) ),
			),
		);
	}

	/**
	 * Returns complete field values for a preset.
	 *
	 * @param string $preset Preset ID.
	 * @return array<string,mixed>
	 */
	public static function get_preset_defaults( $preset ) {
		$preset = is_scalar( $preset ) ? sanitize_key( (string) $preset ) : 'classic';
		$defaults = array();
		foreach ( self::get_field_schema() as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		$presets = array(
			'classic' => array(),
			'minimal' => array(
				'accent_color' => '#0f766e', 'accent_hover_color' => '#115e59', 'selected_background_color' => '#f0fdfa',
				'border_color' => '#e2e8f0', 'title_color' => '#0f172a', 'description_color' => '#475569', 'price_color' => '#0f766e',
				'banner_start_color' => '#ccfbf1', 'banner_end_color' => '#ccfbf1', 'banner_text_color' => '#115e59',
				'border_radius' => 8, 'shadow' => 'none',
			),
			'soft' => array(
				'accent_color' => '#6d28d9', 'accent_hover_color' => '#5b21b6', 'background_color' => '#f5f3ff',
				'selected_background_color' => '#ede9fe', 'border_color' => '#ddd6fe', 'title_color' => '#2e1065',
				'description_color' => '#5b5270', 'price_color' => '#6d28d9', 'banner_start_color' => '#ede9fe',
				'banner_end_color' => '#ede9fe', 'banner_text_color' => '#5b21b6', 'border_radius' => 20,
				'card_padding' => 20, 'image_size' => 64, 'image_radius' => 12, 'shadow' => 'medium',
			),
			'outline' => array(
				'accent_color' => '#b45309', 'accent_hover_color' => '#92400e', 'background_color' => '#fffbeb',
				'selected_background_color' => '#fef3c7', 'border_color' => '#d97706', 'title_color' => '#451a03',
				'description_color' => '#78350f', 'price_color' => '#92400e', 'banner_start_color' => '#fef3c7',
				'banner_end_color' => '#fef3c7', 'banner_text_color' => '#92400e', 'border_radius' => 12,
				'border_width' => 2, 'border_style' => 'dashed', 'shadow' => 'none',
			),
			'dark' => array(
				'accent_color' => '#86efac', 'accent_hover_color' => '#4ade80', 'background_color' => '#0f172a',
				'selected_background_color' => '#172f2a', 'border_color' => '#334155', 'title_color' => '#f8fafc',
				'description_color' => '#cbd5e1', 'price_color' => '#86efac', 'banner_start_color' => '#1e293b',
				'banner_end_color' => '#1e293b', 'banner_text_color' => '#86efac', 'button_text_color' => '#052e16',
				'card_padding' => 18, 'shadow' => 'medium',
			),
			'compact' => array(
				'accent_color' => '#1d4ed8', 'accent_hover_color' => '#1e40af', 'selected_background_color' => '#eff6ff',
				'border_color' => '#bfdbfe', 'title_color' => '#172554', 'description_color' => '#475569', 'price_color' => '#1d4ed8',
				'banner_start_color' => '#eff6ff', 'banner_end_color' => '#eff6ff', 'banner_text_color' => '#1e40af',
				'card_padding' => 12, 'image_size' => 46, 'content_gap' => 10, 'border_radius' => 8, 'shadow' => 'none',
			),
		);

		if ( ! isset( $presets[ $preset ] ) ) {
			$preset = 'classic';
		}

		return array_merge( $defaults, $presets[ $preset ] );
	}

	/**
	 * Normalizes a stored design without rejecting older campaign data.
	 *
	 * @param mixed $design Stored design data.
	 * @return array
	 */
	public static function normalize_stored( $design ) {
		$default = array( 'version' => self::VERSION, 'preset' => 'classic', 'overrides' => array() );
		if ( ! is_array( $design ) ) {
			return $default;
		}

		$version = isset( $design['version'] ) && is_scalar( $design['version'] ) ? absint( $design['version'] ) : 0;
		if ( $version > self::VERSION ) {
			return $design;
		}

		$preset = isset( $design['preset'] ) && is_scalar( $design['preset'] ) ? sanitize_key( (string) $design['preset'] ) : 'classic';
		if ( ! isset( self::get_presets()[ $preset ] ) ) {
			$preset = 'classic';
		}

		$values  = isset( $design['overrides'] ) && is_array( $design['overrides'] ) ? $design['overrides'] : array();
		$defaults = self::get_preset_defaults( $preset );
		$overrides = array();
		foreach ( self::get_field_schema() as $key => $field ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}

			$value = self::normalize_field_value( $key, $values[ $key ] );
			if ( null !== $value && $value !== $defaults[ $key ] ) {
				$overrides[ $key ] = $value;
			}
		}

		return array(
			'version'   => self::VERSION,
			'preset'    => $preset,
			'overrides' => $overrides,
		);
	}

	/**
	 * Validates submitted design values and stores only differences from the preset.
	 *
	 * @param mixed $raw      Submitted design data.
	 * @param mixed $existing Existing stored design.
	 * @return array|WP_Error
	 */
	public static function validate_submission( $raw, $existing = null ) {
		if ( ! is_array( $raw ) ) {
			return self::design_error( 'bumpmint_design_invalid_payload', __( 'The design settings are invalid. Review the Design tab and try again.', 'bumpmint-order-bump-for-woocommerce' ) );
		}

		$existing = self::normalize_stored( $existing );
		if ( isset( $existing['version'] ) && $existing['version'] > self::VERSION ) {
			$existing = array( 'version' => self::VERSION, 'preset' => 'classic', 'overrides' => array() );
		}

		$preset = isset( $raw['preset'] ) && is_scalar( $raw['preset'] )
			? sanitize_key( (string) $raw['preset'] )
			: $existing['preset'];
		if ( ! isset( self::get_presets()[ $preset ] ) ) {
			return self::design_error( 'bumpmint_design_invalid_preset', __( 'Select one of the available design styles.', 'bumpmint-order-bump-for-woocommerce' ), 'preset' );
		}

		if ( array_key_exists( 'overrides', $raw ) && ! is_array( $raw['overrides'] ) ) {
			return self::design_error( 'bumpmint_design_invalid_values', __( 'One or more design values are invalid.', 'bumpmint-order-bump-for-woocommerce' ) );
		}

		$submitted = isset( $raw['overrides'] ) && is_array( $raw['overrides'] ) ? $raw['overrides'] : array();
		$defaults  = self::get_preset_defaults( $preset );
		$old_values = $preset === $existing['preset']
			? array_merge( self::get_preset_defaults( $preset ), $existing['overrides'] )
			: $defaults;
		$overrides = array();

		foreach ( self::get_field_schema() as $key => $field ) {
			$value = array_key_exists( $key, $submitted )
				? self::validate_field_value( $key, $submitted[ $key ] )
				: $old_values[ $key ];

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			if ( $value !== $defaults[ $key ] ) {
				$overrides[ $key ] = $value;
			}
		}

		return array(
			'version'   => self::VERSION,
			'preset'    => $preset,
			'overrides' => $overrides,
		);
	}

	/**
	 * Provides safe form values and reports a design written by a newer plugin version.
	 *
	 * @param mixed $design Stored design.
	 * @return array{preset:string,values:array,is_future_version:bool}
	 */
	public static function get_editor_data( $design ) {
		$normalized = self::normalize_stored( $design );
		$is_future  = isset( $normalized['version'] ) && $normalized['version'] > self::VERSION;
		if ( $is_future ) {
			$normalized = array( 'version' => self::VERSION, 'preset' => 'classic', 'overrides' => array() );
		}

		return array(
			'preset'            => $normalized['preset'],
			'values'            => array_merge( self::get_preset_defaults( $normalized['preset'] ), $normalized['overrides'] ),
			'is_future_version' => $is_future,
		);
	}

	/**
	 * Returns only per-campaign values that override the selected preset.
	 *
	 * @param mixed $design Stored design.
	 * @return array{preset:string,css_variables:array|stdClass}
	 */
	public static function get_render_data( $design ) {
		$normalized = self::normalize_stored( $design );
		if ( isset( $normalized['version'] ) && $normalized['version'] > self::VERSION ) {
			$normalized = array( 'version' => self::VERSION, 'preset' => 'classic', 'overrides' => array() );
		}

		$schema    = self::get_field_schema();
		$variables = array();
		foreach ( $normalized['overrides'] as $key => $value ) {
			if ( isset( $schema[ $key ] ) ) {
				$variables[ $schema[ $key ]['css_var'] ] = self::value_to_css( $key, $value );
			}
		}

		return array(
			'preset'        => $normalized['preset'],
			'css_variables' => empty( $variables ) ? new stdClass() : $variables,
		);
	}

	/**
	 * Serializes already-normalized variables with a strict property/value allowlist.
	 *
	 * @param mixed $variables CSS variable map.
	 * @return string
	 */
	public static function serialize_variables( $variables ) {
		if ( is_object( $variables ) ) {
			$variables = get_object_vars( $variables );
		}
		if ( ! is_array( $variables ) ) {
			return '';
		}

		$allowed = array();
		foreach ( self::get_field_schema() as $key => $field ) {
			$allowed[ $field['css_var'] ] = $key;
		}
		$declarations = array();
		foreach ( $variables as $property => $value ) {
			if ( ! is_string( $property ) || ! isset( $allowed[ $property ] ) || ! is_string( $value ) ) {
				continue;
			}
			$key = $allowed[ $property ];
			$expected = self::value_to_css( $key, self::normalize_field_value( $key, self::css_to_value( $key, $value ) ) );
			if ( null === $expected || $expected !== $value ) {
				continue;
			}
			$declarations[] = $property . ':' . $value;
		}

		return implode( ';', $declarations );
	}

	/**
	 * Builds the defaults for all presets from the same PHP registry as the editor.
	 *
	 * @return string
	 */
	public static function get_presets_css() {
		$rules = array();
		foreach ( self::get_presets() as $preset => $definition ) {
			unset( $definition );
			$defaults  = self::get_preset_defaults( $preset );
			$variables = self::values_to_variables( $defaults );
			$rules[]   = '.bumpmint-bump-box.bumpmint-design-' . $preset . '{' . self::serialize_variables( $variables ) . ';' . self::get_internal_preset_variables( $preset ) . '}';
		}

		return implode( "\n", $rules );
	}

	/**
	 * Enqueues the shared checkout CSS and generated preset defaults once per request.
	 *
	 * @return void
	 */
	public static function enqueue_frontend_styles() {
		if ( self::$frontend_styles_enqueued ) {
			return;
		}

		$style_path = BUMPMINT_PLUGIN_DIR . 'assets/css/frontend.css';
		$version    = file_exists( $style_path ) ? (string) filemtime( $style_path ) : BUMPMINT_VERSION;
		wp_enqueue_style( 'bumpmint-frontend', BUMPMINT_PLUGIN_URL . 'assets/css/frontend.css', array(), $version );
		wp_add_inline_style( 'bumpmint-frontend', self::get_presets_css() );
		self::$frontend_styles_enqueued = true;
	}

	/**
	 * Returns safely serialized editor metadata and defaults for the JavaScript controller.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_editor_config() {
		$defaults = array();
		$css_values = array();
		foreach ( self::get_presets() as $preset => $definition ) {
			unset( $definition );
			$defaults[ $preset ] = self::get_preset_defaults( $preset );
		}
		foreach ( self::get_field_schema() as $key => $field ) {
			if ( 'enum' !== $field['type'] ) {
				continue;
			}
			foreach ( $field['options'] as $option => $label ) {
				unset( $label );
				$css_values[ $key ][ $option ] = self::value_to_css( $key, $option );
			}
		}

		return array(
			'presets' => self::get_presets(),
			'defaults' => $defaults,
			'fields' => self::get_field_schema(),
			'cssValues' => $css_values,
		);
	}

	/**
	 * Converts schema values to allowlisted CSS custom-property values.
	 *
	 * @param array $values Field values.
	 * @return array<string,string>
	 */
	private static function values_to_variables( array $values ) {
		$variables = array();
		foreach ( self::get_field_schema() as $key => $field ) {
			if ( isset( $values[ $key ] ) || array_key_exists( $key, $values ) ) {
				$variables[ $field['css_var'] ] = self::value_to_css( $key, $values[ $key ] );
			}
		}
		return $variables;
	}

	/**
	 * Converts one validated setting to its fixed CSS representation.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return string|null
	 */
	private static function value_to_css( $key, $value ) {
		$schema = self::get_field_schema();
		if ( ! isset( $schema[ $key ] ) ) {
			return null;
		}

		$field = $schema[ $key ];
		if ( 'color' === $field['type'] ) {
			return self::normalize_color( $value );
		}
		if ( 'integer' === $field['type'] ) {
			$value = self::normalize_integer( $value, $field['min'], $field['max'] );
			return null === $value ? null : $value . 'px';
		}
		if ( 'enum' !== $field['type'] || ! is_scalar( $value ) ) {
			return null;
		}

		$value = (string) $value;
		$maps  = array(
			'font_family' => array(
				'inherit' => 'inherit',
				'system'  => '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
				'serif'   => 'Georgia,"Times New Roman",serif',
			),
			'title_weight' => array( '400' => '400', '500' => '500', '600' => '600', '650' => '650', '700' => '700' ),
			'description_weight' => array( '400' => '400', '500' => '500', '600' => '600', '650' => '650', '700' => '700' ),
			'banner_transform' => array( 'uppercase' => 'uppercase', 'none' => 'none' ),
			'border_style' => array( 'solid' => 'solid', 'dashed' => 'dashed' ),
			'shadow' => array(
				'none'   => 'none',
				'soft'   => '0 2px 6px rgba(15,23,42,0.06)',
				'medium' => '0 5px 16px rgba(15,23,42,0.12)',
			),
		);
		if ( 'title_weight' === $key || 'description_weight' === $key ) {
			return isset( $maps[ $key ][ $value ] ) ? $maps[ $key ][ $value ] : null;
		}
		return isset( $maps[ $key ][ $value ] ) ? $maps[ $key ][ $value ] : null;
	}

	/**
	 * Converts an allowlisted CSS value back to its stored schema value.
	 *
	 * @param string $key   Setting key.
	 * @param string $value CSS value.
	 * @return mixed|null
	 */
	private static function css_to_value( $key, $value ) {
		$schema = self::get_field_schema();
		if ( ! isset( $schema[ $key ] ) || ! is_string( $value ) ) {
			return null;
		}
		if ( 'color' === $schema[ $key ]['type'] ) {
			return self::normalize_color( $value );
		}
		if ( 'integer' === $schema[ $key ]['type'] && preg_match( '/^([0-9]+)px$/', $value, $matches ) ) {
			return (int) $matches[1];
		}
		if ( 'enum' === $schema[ $key ]['type'] ) {
			foreach ( $schema[ $key ]['options'] as $option => $label ) {
				unset( $label );
				if ( self::value_to_css( $key, $option ) === $value ) {
					return $option;
				}
			}
		}
		return null;
	}

	/**
	 * Returns fixed internal tokens that are not exposed as user controls.
	 *
	 * @param string $preset Preset identifier.
	 * @return string
	 */
	private static function get_internal_preset_variables( $preset ) {
		$values = array(
			'classic' => array( '--bumpmint-error-color' => '#b32d2e', '--bumpmint-hover-border' => '#c7cdd4', '--bumpmint-hover-shadow' => '0 5px 16px rgba(15,23,42,0.1)' ),
			'minimal' => array( '--bumpmint-error-color' => '#b32d2e', '--bumpmint-hover-border' => '#99f6e4', '--bumpmint-hover-shadow' => 'none' ),
			'soft'    => array( '--bumpmint-error-color' => '#b32d2e', '--bumpmint-hover-border' => '#c4b5fd', '--bumpmint-hover-shadow' => '0 7px 20px rgba(46,16,101,0.14)' ),
			'outline' => array( '--bumpmint-error-color' => '#b32d2e', '--bumpmint-hover-border' => '#b45309', '--bumpmint-hover-shadow' => '0 4px 12px rgba(120,53,15,0.12)' ),
			'dark'    => array( '--bumpmint-error-color' => '#fca5a5', '--bumpmint-hover-border' => '#86efac', '--bumpmint-hover-shadow' => '0 7px 20px rgba(0,0,0,0.28)' ),
			'compact' => array( '--bumpmint-error-color' => '#b32d2e', '--bumpmint-hover-border' => '#93c5fd', '--bumpmint-hover-shadow' => '0 4px 12px rgba(30,64,175,0.12)' ),
		);
		$declarations = array();
		foreach ( isset( $values[ $preset ] ) ? $values[ $preset ] : $values['classic'] as $property => $value ) {
			$declarations[] = $property . ':' . $value;
		}
		return implode( ';', $declarations );
	}

	/**
	 * Validates one value for a known design field.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return mixed|WP_Error
	 */
	private static function validate_field_value( $key, $value ) {
		$schema = self::get_field_schema();
		if ( ! isset( $schema[ $key ] ) ) {
			return null;
		}
		$field = $schema[ $key ];
		if ( ! is_scalar( $value ) ) {
			/* translators: %s: design field label. */
			return self::design_error( 'bumpmint_design_invalid_value', sprintf( __( 'Enter a valid value for %s.', 'bumpmint-order-bump-for-woocommerce' ), $field['label'] ), $key );
		}

		if ( 'color' === $field['type'] ) {
			$normalized = self::normalize_color( $value );
			if ( null === $normalized ) {
				/* translators: %s: design field label. */
				return self::design_error( 'bumpmint_design_invalid_color', sprintf( __( 'Enter a valid hexadecimal color for %s.', 'bumpmint-order-bump-for-woocommerce' ), $field['label'] ), $key );
			}
			return $normalized;
		}

		if ( 'integer' === $field['type'] ) {
			$normalized = self::normalize_integer( $value, $field['min'], $field['max'] );
			if ( null === $normalized ) {
				return self::design_error(
					'bumpmint_design_invalid_integer',
					/* translators: 1: design field label, 2: minimum allowed value, 3: maximum allowed value. */
					sprintf( __( '%1$s must be a whole number between %2$d and %3$d.', 'bumpmint-order-bump-for-woocommerce' ), $field['label'], $field['min'], $field['max'] ),
					$key
				);
			}
			return $normalized;
		}

		$value = (string) $value;
		if ( ! array_key_exists( $value, $field['options'] ) ) {
			/* translators: %s: design field label. */
			return self::design_error( 'bumpmint_design_invalid_option', sprintf( __( 'Select a valid option for %s.', 'bumpmint-order-bump-for-woocommerce' ), $field['label'] ), $key );
		}
		return $value;
	}

	/**
	 * Tolerantly normalizes a stored field value.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Stored value.
	 * @return mixed|null
	 */
	private static function normalize_field_value( $key, $value ) {
		$schema = self::get_field_schema();
		if ( ! isset( $schema[ $key ] ) || ! is_scalar( $value ) ) {
			return null;
		}
		$field = $schema[ $key ];
		if ( 'color' === $field['type'] ) {
			return self::normalize_color( $value );
		}
		if ( 'integer' === $field['type'] ) {
			return self::normalize_integer( $value, $field['min'], $field['max'] );
		}
		$value = (string) $value;
		return array_key_exists( $value, $field['options'] ) ? $value : null;
	}

	/**
	 * Normalizes a strict integer without coercing invalid values or negatives.
	 *
	 * @param mixed $value Value to parse.
	 * @param int   $min   Inclusive minimum.
	 * @param int   $max   Inclusive maximum.
	 * @return int|null
	 */
	private static function normalize_integer( $value, $min, $max ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/', (string) $value ) ) {
			return null;
		}
		$value = (int) $value;
		return $value >= $min && $value <= $max ? $value : null;
	}

	/**
	 * Validates and expands three- or six-digit hexadecimal colors.
	 *
	 * @param mixed $color Color value.
	 * @return string|null
	 */
	private static function normalize_color( $color ) {
		if ( ! is_scalar( $color ) ) {
			return null;
		}
		$color = strtolower( trim( (string) $color ) );
		if ( ! preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/', $color ) ) {
			return null;
		}
		if ( 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}
		return $color;
	}

	/**
	 * Creates a design validation error and identifies the affected editor field.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param string $field   Optional field key.
	 * @return WP_Error
	 */
	private static function design_error( $code, $message, $field = '' ) {
		$error = new WP_Error( $code, $message );
		$error->add_data( array( 'tab' => 'design', 'field' => $field ), $code );
		return $error;
	}
}
