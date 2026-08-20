<?php
/**
 * Location targeting integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies country, state, and unknown-location eligibility.
 */
class Test_BumpMint_Location_Targeting extends BumpMint_Test_Case {

	/**
	 * Country matching prefers shipping and falls back to billing.
	 */
	public function test_country_include_exclude_and_address_precedence() {
		$customer = new WC_Customer( 0, true );
		$customer->set_billing_country( 'BR' );
		$customer->set_billing_state( 'SP' );
		$customer->set_shipping_country( '' );
		$customer->set_shipping_state( '' );

		$rule = array(
			'location_mode'             => BumpMint_Conditions::LOCATION_SPECIFIC,
			'location_operator'         => BumpMint_Conditions::LOCATION_INCLUDE,
			'location_unknown_behavior' => BumpMint_Conditions::LOCATION_UNKNOWN_HIDE,
			'location_country_codes'    => array( 'BR' ),
			'location_state_codes'      => array(),
		);

		$this->assertTrue( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$customer->set_shipping_country( 'US' );
		$customer->set_shipping_state( 'CA' );
		$this->assertFalse( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$rule['location_operator'] = BumpMint_Conditions::LOCATION_EXCLUDE;
		$this->assertTrue( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$customer->set_shipping_country( 'BR' );
		$customer->set_shipping_state( '' );
		$rule['location_operator']    = BumpMint_Conditions::LOCATION_INCLUDE;
		$rule['location_state_codes'] = array( 'BR:sp' );
		$this->assertTrue( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$rule['location_operator'] = BumpMint_Conditions::LOCATION_EXCLUDE;
		$this->assertFalse( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$customer->set_billing_state( 'RJ' );
		$this->assertTrue( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$rule['location_operator'] = BumpMint_Conditions::LOCATION_INCLUDE;
		$this->assertFalse( BumpMint_Conditions::matches_location( $rule, $customer ) );
	}

	/**
	 * State restrictions apply per country and unknown behavior is explicit.
	 */
	public function test_state_restrictions_and_unknown_location_behavior() {
		$customer = new WC_Customer( 0, true );
		$customer->set_shipping_country( '' );
		$customer->set_shipping_state( '' );
		$rule = array(
			'location_mode'             => BumpMint_Conditions::LOCATION_SPECIFIC,
			'location_operator'         => BumpMint_Conditions::LOCATION_INCLUDE,
			'location_unknown_behavior' => BumpMint_Conditions::LOCATION_UNKNOWN_HIDE,
			'location_country_codes'    => array( 'BR', 'PT' ),
			'location_state_codes'      => array( 'BR:sp' ),
		);

		$customer->set_billing_country( 'PT' );
		$customer->set_billing_state( '' );
		$this->assertTrue( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$customer->set_billing_country( 'BR' );
		$this->assertFalse( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$rule['location_unknown_behavior'] = BumpMint_Conditions::LOCATION_UNKNOWN_SHOW;
		$this->assertTrue( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$customer->set_billing_country( '' );
		$rule['location_unknown_behavior'] = BumpMint_Conditions::LOCATION_UNKNOWN_HIDE;
		$this->assertFalse( BumpMint_Conditions::matches_location( $rule, $customer ) );

		$rule['location_unknown_behavior'] = BumpMint_Conditions::LOCATION_UNKNOWN_SHOW;
		$this->assertTrue( BumpMint_Conditions::matches_location( $rule, $customer ) );
	}

	/**
	 * Location settings are validated and normalized before persistence.
	 */
	public function test_location_settings_are_validated_and_normalized() {
		$offer = $this->create_product( 100, 'Location validation offer' );
		$base  = array(
			'name'                      => 'Location validation',
			'condition_type'            => BumpMint_Conditions::ALWAYS,
			'bump_product_ids'          => array( $offer->get_id() ),
			'location_mode'             => BumpMint_Conditions::LOCATION_SPECIFIC,
			'location_operator'         => BumpMint_Conditions::LOCATION_INCLUDE,
			'location_unknown_behavior' => BumpMint_Conditions::LOCATION_UNKNOWN_HIDE,
		);

		$missing_country = BumpMint_Rules::save_rule( '', $base );
		$this->assertWPError( $missing_country );
		$this->assertSame( 'bumpmint_location_country_required', $missing_country->get_error_code() );

		$invalid_mode = BumpMint_Rules::save_rule( '', wp_parse_args( array( 'location_mode' => 'invalid' ), $base ) );
		$this->assertWPError( $invalid_mode );
		$this->assertSame( 'bumpmint_invalid_location_mode', $invalid_mode->get_error_code() );

		$invalid_operator = BumpMint_Rules::save_rule( '', wp_parse_args( array( 'location_operator' => 'invalid' ), $base ) );
		$this->assertWPError( $invalid_operator );
		$this->assertSame( 'bumpmint_invalid_location_operator', $invalid_operator->get_error_code() );

		$invalid_unknown = BumpMint_Rules::save_rule( '', wp_parse_args( array( 'location_unknown_behavior' => 'invalid' ), $base ) );
		$this->assertWPError( $invalid_unknown );
		$this->assertSame( 'bumpmint_invalid_location_unknown_behavior', $invalid_unknown->get_error_code() );

		$invalid_country = BumpMint_Rules::save_rule(
			'',
			wp_parse_args( array( 'location_country_codes' => array( 'BR', 'invalid' ) ), $base )
		);
		$this->assertWPError( $invalid_country );
		$this->assertSame( 'bumpmint_invalid_location_country', $invalid_country->get_error_code() );

		$invalid_state = BumpMint_Rules::save_rule(
			'',
			wp_parse_args(
				array(
					'location_country_codes' => array( 'BR' ),
					'location_state_codes'   => array( 'US:CA' ),
				),
				$base
			)
		);
		$this->assertWPError( $invalid_state );
		$this->assertSame( 'bumpmint_invalid_location_state', $invalid_state->get_error_code() );

		$valid = BumpMint_Rules::save_rule(
			'',
			wp_parse_args(
				array(
					'location_country_codes' => array( 'br', 'PT', 'BR' ),
					'location_state_codes'   => array( 'BR:SP', 'br:rj' ),
				),
				$base
			)
		);
		$this->assertNotWPError( $valid );
		$this->assertSame( array( 'BR', 'PT' ), $valid['location_country_codes'] );
		$this->assertSame( array( 'BR:rj', 'BR:sp' ), $valid['location_state_codes'] );
	}

	/**
	 * Legacy rules remain global and invalid explicit modes fail closed.
	 */
	public function test_location_targeting_normalizes_legacy_and_invalid_values_safely() {
		$offer = $this->create_product( 100, 'Legacy location offer' );
		$rule  = $this->create_rule( $offer );

		unset(
			$rule['location_mode'],
			$rule['location_operator'],
			$rule['location_unknown_behavior'],
			$rule['location_country_codes'],
			$rule['location_state_codes']
		);
		update_option( BUMPMINT_OPTION_KEY, array( $rule ), false );

		$legacy = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( BumpMint_Conditions::LOCATION_ALL, $legacy['location_mode'] );
		$this->assertTrue( BumpMint_Conditions::matches_location( $legacy ) );

		$legacy['location_mode']          = 'unsupported';
		$legacy['location_country_codes'] = array( 'BR' );
		update_option( BUMPMINT_OPTION_KEY, array( $legacy ), false );

		$invalid = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( BumpMint_Conditions::LOCATION_SPECIFIC, $invalid['location_mode'] );
		$this->assertSame( array(), $invalid['location_country_codes'] );
		$this->assertFalse( BumpMint_Conditions::matches_location( $invalid ) );

		$rule['location_mode']          = BumpMint_Conditions::LOCATION_SPECIFIC;
		$rule['location_operator']      = 'unsupported';
		$rule['location_country_codes'] = array( 'BR' );
		update_option( BUMPMINT_OPTION_KEY, array( $rule ), false );

		$invalid = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( array(), $invalid['location_country_codes'] );
		$this->assertFalse( BumpMint_Conditions::matches_location( $invalid ) );
	}

	/**
	 * Lost location eligibility removes only the BumpMint-created cart line.
	 */
	public function test_location_targeting_is_enforced_throughout_checkout() {
		$cart_product = $this->create_product( 25, 'Location cart product' );
		$offer        = $this->create_product( 100, 'São Paulo offer' );
		$rule         = $this->create_rule(
			$offer,
			array(
				'location_mode'             => BumpMint_Conditions::LOCATION_SPECIFIC,
				'location_operator'         => BumpMint_Conditions::LOCATION_INCLUDE,
				'location_unknown_behavior' => BumpMint_Conditions::LOCATION_UNKNOWN_HIDE,
				'location_country_codes'    => array( 'BR' ),
				'location_state_codes'      => array( 'BR:SP' ),
			)
		);
		WC()->customer->set_shipping_country( '' );
		WC()->customer->set_shipping_state( '' );
		WC()->customer->set_billing_country( 'BR' );
		WC()->customer->set_billing_state( 'SP' );
		WC()->cart->add_to_cart( $cart_product->get_id(), 1 );

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$this->assertCount( 1, $checkout->get_applicable_offers( WC()->cart ) );

		$bump_key = $this->add_bump_to_cart( $offer, $rule );
		WC()->cart->calculate_totals();
		$this->assertArrayHasKey( $bump_key, WC()->cart->get_cart() );

		WC()->customer->set_billing_state( 'RJ' );
		WC()->cart->calculate_totals();
		$this->assertArrayNotHasKey( $bump_key, WC()->cart->get_cart() );

		$pending = WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS, array() );
		$this->assertNotEmpty( $pending );
		$this->assertStringContainsString( 'current location', implode( ' ', $pending ) );
	}

	/**
	 * Location targeting differentiates otherwise equivalent rule fingerprints.
	 */
	public function test_location_targeting_is_part_of_duplicate_detection() {
		$offer = $this->create_product( 100, 'Location fingerprint offer' );
		$this->create_rule( $offer, array( 'name' => 'All locations' ) );

		$specific = $this->create_rule(
			$offer,
			array(
				'name'                      => 'Brazil offer',
				'location_mode'             => BumpMint_Conditions::LOCATION_SPECIFIC,
				'location_country_codes'    => array( 'BR' ),
				'location_unknown_behavior' => BumpMint_Conditions::LOCATION_UNKNOWN_HIDE,
			)
		);
		$this->assertSame( array( 'BR' ), $specific['location_country_codes'] );

		$duplicate_data         = $specific;
		$duplicate_data['name'] = 'Duplicate Brazil offer';
		unset( $duplicate_data['id'], $duplicate_data['created_at'] );
		$duplicate = BumpMint_Rules::save_rule( '', $duplicate_data );

		$this->assertWPError( $duplicate );
		$this->assertSame( 'bumpmint_duplicate_rule', $duplicate->get_error_code() );
	}

	/**
	 * The admin form renders allowed countries and selected WooCommerce states.
	 */
	public function test_admin_location_controls_render_allowed_countries_and_states() {
		$previous_get              = $_GET;
		$previous_user_id          = get_current_user_id();
		$previous_allowed          = get_option( 'woocommerce_allowed_countries' );
		$previous_specific_allowed = get_option( 'woocommerce_specific_allowed_countries' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'BR', 'PT' ) );

		$offer = $this->create_product( 100, 'Admin location offer' );
		$rule  = $this->create_rule(
			$offer,
			array(
				'location_mode'          => BumpMint_Conditions::LOCATION_SPECIFIC,
				'location_country_codes' => array( 'BR' ),
				'location_state_codes'   => array( 'BR:SP' ),
			)
		);
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin    = new WP_User( $admin_id );
		$admin->add_cap( BumpMint_Admin::CAPABILITY );
		wp_set_current_user( $admin_id );
		$_GET['page']   = BumpMint_Admin::PAGE_SLUG;
		$_GET['action'] = 'edit';
		$_GET['rule']   = $rule['id'];

		$reflection = new ReflectionClass( BumpMint_Admin::class );
		$controller = $reflection->newInstanceWithoutConstructor();
		ob_start();
		$controller->render_page();
		$html = (string) ob_get_clean();

		$_GET = $previous_get;
		wp_set_current_user( $previous_user_id );
		update_option( 'woocommerce_allowed_countries', $previous_allowed );
		update_option( 'woocommerce_specific_allowed_countries', $previous_specific_allowed );

		$this->assertStringContainsString( 'id="bumpmint-location-mode"', $html );
		$this->assertStringContainsString( 'id="bumpmint-location-countries"', $html );
		$this->assertStringContainsString( 'value="BR"', $html );
		$this->assertStringContainsString( 'value="PT"', $html );
		$this->assertStringContainsString( 'id="bumpmint-location-states"', $html );
		$this->assertStringContainsString( 'value="BR:sp"', $html );
		$this->assertStringContainsString( 'id="bumpmint-location-unknown"', $html );
	}
}
