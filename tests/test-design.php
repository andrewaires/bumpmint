<?php
/**
 * Campaign design settings tests.
 *
 * @package BumpMint
 */

/**
 * Covers design validation, normalization, storage, and isolation.
 */
class Test_BumpMint_Design extends BumpMint_Test_Case {

	/**
	 * Older rules receive the Classic preset with no per-card CSS values.
	 */
	public function test_legacy_rule_uses_classic_design_without_overrides() {
		$product = $this->create_product( 40 );
		$rule    = $this->create_rule( $product );

		$this->assertSame(
			array( 'version' => BumpMint_Design::VERSION, 'preset' => 'classic', 'overrides' => array() ),
			$rule['design']
		);

		$render_data = BumpMint_Design::get_render_data( $rule['design'] );
		$this->assertSame( 'classic', $render_data['preset'] );
		$this->assertInstanceOf( stdClass::class, $render_data['css_variables'] );
	}

	/**
	 * The server accepts known values, expands short hex colors, and stores sparse overrides.
	 */
	public function test_design_submission_normalizes_hex_and_stores_only_differences() {
		$design = BumpMint_Design::validate_submission(
			array(
				'preset' => 'minimal',
				'overrides' => array(
					'accent_color' => '#ABC',
					'title_size' => '16',
					'description_weight' => '500',
					'border_radius' => '0',
				),
			)
		);

		$this->assertNotWPError( $design );
		$this->assertSame( 'minimal', $design['preset'] );
		$this->assertSame( '#aabbcc', $design['overrides']['accent_color'] );
		$this->assertSame( 16, $design['overrides']['title_size'] );
		$this->assertArrayNotHasKey( 'description_weight', $design['overrides'] );
		$this->assertSame( 0, $design['overrides']['border_radius'] );
	}

	/**
	 * Invalid colors, enums, numeric types, and nested payload shapes fail closed.
	 *
	 * @dataProvider invalid_design_provider
	 * @param array $payload Invalid settings.
	 */
	public function test_invalid_design_values_are_rejected( $payload ) {
		$result = BumpMint_Design::validate_submission( $payload );

		$this->assertWPError( $result );
		$this->assertStringStartsWith( 'bumpmint_design_', $result->get_error_code() );
		$this->assertSame( 'design', $result->get_error_data()['tab'] );
	}

	/**
	 * Invalid submissions used by the design validation test.
	 *
	 * @return array<string,array{0:array}>
	 */
	public function invalid_design_provider() {
		return array(
			'invalid preset' => array( array( 'preset' => 'custom-css', 'overrides' => array() ) ),
			'injected color' => array( array( 'preset' => 'classic', 'overrides' => array( 'accent_color' => 'red;background:url(x)' ) ) ),
			'out of range' => array( array( 'preset' => 'classic', 'overrides' => array( 'title_size' => '999' ) ) ),
			'fractional size' => array( array( 'preset' => 'classic', 'overrides' => array( 'title_size' => '16.5' ) ) ),
			'array value' => array( array( 'preset' => 'classic', 'overrides' => array( 'title_size' => array( '16' ) ) ) ),
			'non array overrides' => array( array( 'preset' => 'classic', 'overrides' => 'title_size:16px' ) ),
		);
	}

	/**
	 * A design-only change does not create a commercially distinct duplicate rule.
	 */
	public function test_design_is_excluded_from_duplicate_rule_fingerprint() {
		$product = $this->create_product( 40 );
		$first   = $this->create_rule( $product );
		$data    = $this->rule_submission_from_saved_rule( $first );
		$data['name'] = 'Different appearance';
		$data['design_touched'] = '1';
		$data['design'] = array(
			'preset' => 'dark',
			'overrides' => BumpMint_Design::get_preset_defaults( 'dark' ),
		);

		$result = BumpMint_Rules::save_rule( '', $data );

		$this->assertWPError( $result );
		$this->assertSame( 'bumpmint_duplicate_rule', $result->get_error_code() );
	}

	/**
	 * Updates from older callers preserve the saved design when no design marker is posted.
	 */
	public function test_rule_update_without_design_fields_preserves_saved_design() {
		$product = $this->create_product( 40 );
		$rule    = $this->create_rule( $product );
		$data    = $this->rule_submission_from_saved_rule( $rule );
		$data['design_touched'] = '1';
		$data['design'] = array(
			'preset' => 'soft',
			'overrides' => array_merge( BumpMint_Design::get_preset_defaults( 'soft' ), array( 'title_size' => 18 ) ),
		);
		$saved = BumpMint_Rules::save_rule( $rule['id'], $data );
		$this->assertNotWPError( $saved );
		$this->assertSame( 'soft', $saved['design']['preset'] );

		$legacy_update = $this->rule_submission_from_saved_rule( $saved );
		unset( $legacy_update['design'], $legacy_update['design_touched'] );
		$legacy_update['name'] = 'Updated by an older caller';
		$updated = BumpMint_Rules::save_rule( $saved['id'], $legacy_update );

		$this->assertNotWPError( $updated );
		$this->assertSame( $saved['design'], $updated['design'] );
	}

	/**
	 * Stored designs normalize idempotently and do not lose a future format on read.
	 */
	public function test_design_normalization_is_idempotent_and_preserves_future_formats() {
		$normalized = BumpMint_Design::normalize_stored(
			array(
				'version' => 1,
				'preset' => 'classic',
				'overrides' => array( 'title_size' => 15, 'border_radius' => 0, 'unknown_css' => 'position:fixed' ),
			)
		);

		$this->assertSame( $normalized, BumpMint_Design::normalize_stored( $normalized ) );
		$this->assertArrayNotHasKey( 'title_size', $normalized['overrides'] );
		$this->assertSame( 0, $normalized['overrides']['border_radius'] );
		$this->assertArrayNotHasKey( 'unknown_css', $normalized['overrides'] );

		$future = array( 'version' => BumpMint_Design::VERSION + 1, 'preset' => 'future', 'private' => array( 'kept' => true ) );
		$this->assertSame( $future, BumpMint_Design::normalize_stored( $future ) );
		$this->assertSame( 'classic', BumpMint_Design::get_render_data( $future )['preset'] );
	}

	/**
	 * CSS serialization accepts only generated property names and values.
	 */
	public function test_css_variable_serializer_rejects_unknown_properties_and_values() {
		$serialized = BumpMint_Design::serialize_variables(
			array(
				'--bumpmint-accent' => '#aabbcc',
				'--bumpmint-title-size' => '16px',
				'--bumpmint-background' => 'url(javascript:alert(1))',
				'--attacker' => 'red',
			)
		);

		$this->assertSame( '--bumpmint-accent:#aabbcc;--bumpmint-title-size:16px', $serialized );
	}

	/**
	 * Each preset keeps shadows and internal feedback colors in separate declarations.
	 */
	public function test_generated_presets_keep_all_declarations_separate() {
		$config = BumpMint_Design::get_editor_config();
		preg_match_all( '/\.bumpmint-bump-box\.bumpmint-design-([a-z]+)\{([^}]+)\}/', BumpMint_Design::get_presets_css(), $matches, PREG_SET_ORDER );
		$this->assertCount( count( BumpMint_Design::get_presets() ), $matches );

		foreach ( $matches as $match ) {
			$properties = array();
			foreach ( explode( ';', $match[2] ) as $declaration ) {
				$parts = explode( ':', $declaration, 2 );
				if ( 2 === count( $parts ) ) {
					$properties[ trim( $parts[0] ) ] = trim( $parts[1] );
				}
			}
			$defaults = BumpMint_Design::get_preset_defaults( $match[1] );
			$this->assertSame( $config['cssValues']['shadow'][ $defaults['shadow'] ], $properties['--bumpmint-box-shadow'] );
			$this->assertArrayHasKey( '--bumpmint-error-color', $properties );
			$this->assertSame( 'dark' === $match[1] ? '#fca5a5' : '#b32d2e', $properties['--bumpmint-error-color'] );
		}
	}

	/**
	 * A complete form can save supported designs even when JavaScript never marks it touched.
	 */
	public function test_editor_presence_saves_design_without_javascript() {
		$product = $this->create_product( 40 );
		$rule    = $this->create_rule( $product );
		$data    = $this->rule_submission_from_saved_rule( $rule );
		$data['design_present'] = '1';
		$data['design_touched'] = '0';
		$data['design'] = array( 'preset' => 'minimal', 'overrides' => array( 'price_color' => '#123456' ) );

		$saved = BumpMint_Rules::save_rule( $rule['id'], $data );

		$this->assertNotWPError( $saved );
		$this->assertSame( 'minimal', $saved['design']['preset'] );
		$this->assertSame( '#123456', $saved['design']['overrides']['price_color'] );
	}

	/**
	 * Merely posting the fallback controls must not overwrite a newer stored format.
	 */
	public function test_untouched_editor_preserves_future_design_format() {
		$product = $this->create_product( 40 );
		$rule    = $this->create_rule( $product );
		$future  = array( 'version' => BumpMint_Design::VERSION + 1, 'preset' => 'future', 'private' => array( 'kept' => true ) );
		$rule['design'] = $future;
		update_option( BUMPMINT_OPTION_KEY, array( $rule ), false );
		$data = $this->rule_submission_from_saved_rule( $rule );
		$data['design_present'] = '1';
		$data['design_touched'] = '0';
		$data['design'] = array( 'preset' => 'classic', 'overrides' => BumpMint_Design::get_preset_defaults( 'classic' ) );

		$saved = BumpMint_Rules::save_rule( $rule['id'], $data );

		$this->assertNotWPError( $saved );
		$this->assertSame( $future, $saved['design'] );
	}

	/**
	 * Correcting a settings error retains the design that was shown in the failed form.
	 */
	public function test_failed_admin_save_retains_design_on_resubmission() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$product = $this->create_product( 40 );
		$rule    = $this->create_rule( $product );
		$data    = $this->rule_submission_from_saved_rule( $rule );
		$data['condition_type'] = 'invalid-condition';
		$data['design_present'] = '1';
		$data['design_touched'] = '1';
		$data['design'] = array( 'preset' => 'dark', 'overrides' => array( 'title_size' => 18 ) );
		$previous_get  = $_GET;
		$previous_post = $_POST;
		$previous_user = get_current_user_id();
		$previous_errors = isset( $GLOBALS['wp_settings_errors'] ) ? $GLOBALS['wp_settings_errors'] : null;

		try {
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$admin_user = new WP_User( $admin_id );
			$admin_user->add_cap( BumpMint_Admin::CAPABILITY );
			wp_set_current_user( $admin_id );
			$_GET = array( 'page' => BumpMint_Admin::PAGE_SLUG, 'action' => 'edit', 'rule' => $rule['id'] );
			$_POST = array(
				'bumpmint_nonce_save' => wp_create_nonce( 'bumpmint_save_rule' ),
				'bumpmint_rule_id' => $rule['id'],
				'bumpmint_active_tab' => 'design',
				'bumpmint_design_present' => '1',
				'bumpmint_design_touched' => '1',
				'bumpmint_rule' => wp_slash( $data ),
			);
			$GLOBALS['wp_settings_errors'] = array();
			$admin = ( new ReflectionClass( BumpMint_Admin::class ) )->newInstanceWithoutConstructor();
			$admin->handle_actions();
			$this->assertNotEmpty( get_settings_errors( 'bumpmint' ) );
			$form = $this->parse_html( $this->render_admin_form( $admin, 'edit' ) );
			$xpath = new DOMXPath( $form );
			$touched = $xpath->query( '//input[@name="bumpmint_design_touched"]' )->item( 0 );
			$present = $xpath->query( '//input[@name="bumpmint_design_present"]' )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $touched );
			$this->assertInstanceOf( DOMElement::class, $present );
			$this->assertSame( '1', $touched->getAttribute( 'value' ) );
			$this->assertSame( '1', $present->getAttribute( 'value' ) );
			$this->assertSame( 'dark', $xpath->query( '//select[@name="bumpmint_rule[design][preset]"]/option[@selected]' )->item( 0 )->getAttribute( 'value' ) );

			$data['condition_type'] = BumpMint_Conditions::ALWAYS;
			$data['design_touched'] = $touched->getAttribute( 'value' );
			$data['design_present'] = $present->getAttribute( 'value' );
			$saved = BumpMint_Rules::save_rule( $rule['id'], $data );
			$this->assertNotWPError( $saved );
			$this->assertSame( 'dark', $saved['design']['preset'] );
			$this->assertSame( 18, $saved['design']['overrides']['title_size'] );
		} finally {
			$_GET = $previous_get;
			$_POST = $previous_post;
			wp_set_current_user( $previous_user );
			if ( null === $previous_errors ) {
				unset( $GLOBALS['wp_settings_errors'] );
			} else {
				$GLOBALS['wp_settings_errors'] = $previous_errors;
			}
		}
	}

	/**
	 * Encoded offer text remains text through the template and srcdoc parsing passes.
	 */
	public function test_preview_document_keeps_encoded_markup_inert() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$admin = ( new ReflectionClass( BumpMint_Admin::class ) )->newInstanceWithoutConstructor();
		$submitted = new ReflectionProperty( BumpMint_Admin::class, 'submitted_rule_data' );
		$submitted->setAccessible( true );
		$submitted->setValue( $admin, array(
			'badge_text' => sanitize_text_field( '&lt;/div&gt;&lt;strong id=&quot;review-injected&quot;&gt;extra&lt;/strong&gt;&lt;div&gt;' ),
		) );
		$form = $this->parse_html( $this->render_admin_form( $admin, 'new' ) );
		$template = $form->getElementsByTagName( 'template' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $template );
		$preview = $this->parse_html( $template->textContent );
		$xpath = new DOMXPath( $preview );

		$this->assertSame( 0, $xpath->query( '//*[@id="review-injected"]' )->length );
		$badge = $xpath->query( '//*[@data-bumpmint-preview-badge]' )->item( 0 );
		$this->assertStringContainsString( '<strong id="review-injected">extra</strong>', $badge->textContent );
	}

	/**
	 * The iframe uses WordPress-generated stylesheet tags without changing the admin queue.
	 */
	public function test_preview_stylesheet_is_isolated_from_admin_styles() {
		$admin  = ( new ReflectionClass( BumpMint_Admin::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( BumpMint_Admin::class, 'get_design_preview_srcdoc' );
		$method->setAccessible( true );
		$styles = wp_styles();
		$queue  = $styles->queue;
		$done   = $styles->done;

		$html = $method->invoke( $admin, array(), BumpMint_Design::get_editor_data( null ) );
		$document = $this->parse_html( $html );
		$xpath = new DOMXPath( $document );
		$links = $xpath->query( '//head/link[@rel="stylesheet"]' );
		$this->assertSame( 1, $links->length );
		$this->assertSame( 'bumpmint-design-preview-css', $links->item( 0 )->getAttribute( 'id' ) );
		$this->assertStringContainsString( 'assets/css/frontend.css?ver=', $links->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( $queue, $styles->queue );
		$this->assertSame( $done, $styles->done );
		$this->assertFalse( wp_style_is( 'bumpmint-design-preview', 'registered' ) );
		$this->assertSame( 1, $xpath->query( '//*[@data-bumpmint-preview-card]' )->length );
	}

	/**
	 * Prefixing partial variables preserves the live card's IDs, price, and selection state.
	 */
	public function test_classic_card_renders_with_prefixed_partial_variables() {
		$product = $this->create_product( 100, 'Preview regression product' );
		$rule = $this->create_rule( $product, array( 'discount_type' => 'percentage', 'discount_value' => 10 ) );
		$checkout = ( new ReflectionClass( BumpMint_Checkout::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( BumpMint_Checkout::class, 'render_card' );
		$method->setAccessible( true );
		ob_start();
		try {
			$method->invoke( $checkout, $rule, $product, null );
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$document = $this->parse_html( $html );
		$xpath = new DOMXPath( $document );
		$this->assertSame( 0, $xpath->query( '//*[@data-bumpmint-preview-card]' )->length );
		$card = $xpath->query( '//*[@data-bumpmint-rule-id]' )->item( 0 );
		$this->assertSame( $rule['id'], $card->getAttribute( 'data-bumpmint-rule-id' ) );
		$checkbox = $xpath->query( '//input[@type="checkbox"]' )->item( 0 );
		$this->assertSame( (string) $product->get_id(), $checkbox->getAttribute( 'data-product-id' ) );
		$this->assertFalse( $checkbox->hasAttribute( 'checked' ) );
		$this->assertStringContainsString( 'Preview regression product', $html );
		$this->assertStringContainsString( '90.00', $html );
		$this->assertSame( 1, $xpath->query( '//img' )->length );
	}

	/**
	 * Parses rendered HTML without executing scripts or retrieving resources.
	 *
	 * @param string $html HTML source.
	 * @return DOMDocument
	 */
	private function parse_html( $html ) {
		$document = new DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( $html, LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
		}
		return $document;
	}

	/**
	 * Renders the existing form without adding another set of administration hooks.
	 *
	 * @param BumpMint_Admin $admin  Administration service.
	 * @param string        $action Form action.
	 * @return string
	 */
	private function render_admin_form( $admin, $action ) {
		$method = new ReflectionMethod( BumpMint_Admin::class, 'render_form_page' );
		$method->setAccessible( true );
		ob_start();
		try {
			$method->invoke( $admin, $action );
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Creates the full old-style payload needed to update a saved rule.
	 *
	 * @param array $rule Saved rule.
	 * @return array
	 */
	private function rule_submission_from_saved_rule( array $rule ) {
		unset( $rule['id'], $rule['created_at'], $rule['design'] );
		return $rule;
	}
}
