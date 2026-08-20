<?php
/**
 * Coupon targeting integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies WooCommerce coupon eligibility and persistence.
 */
class Test_BumpMint_Coupon_Targeting extends BumpMint_Test_Case {

	/**
	 * No-coupon, any-coupon, and specific-coupon modes use the live cart state.
	 */
	public function test_coupon_modes_match_applied_woocommerce_coupons() {
		$product       = $this->create_product( 50, 'Coupon cart product' );
		$first_coupon  = $this->create_coupon( 'bumpmint-first' );
		$second_coupon = $this->create_coupon( 'bumpmint-second' );
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$none_rule  = array( 'coupon_mode' => BumpMint_Conditions::COUPON_NONE );
		$any_rule   = array( 'coupon_mode' => BumpMint_Conditions::COUPON_ANY );
		$first_rule = array(
			'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
			'coupon_ids'  => array( $first_coupon->get_id(), $second_coupon->get_id() ),
		);
		$second_rule = array(
			'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
			'coupon_ids'  => array( $second_coupon->get_id() ),
		);

		$this->assertTrue( BumpMint_Conditions::matches_coupon( $none_rule, WC()->cart ) );
		$this->assertFalse( BumpMint_Conditions::matches_coupon( $any_rule, WC()->cart ) );
		$this->assertFalse( BumpMint_Conditions::matches_coupon( $first_rule, WC()->cart ) );

		$this->assertTrue( WC()->cart->apply_coupon( $first_coupon->get_code() ) );
		$this->assertTrue( BumpMint_Conditions::matches_coupon( $any_rule, WC()->cart ) );
		$this->assertTrue( BumpMint_Conditions::matches_coupon( $first_rule, WC()->cart ) );
		$this->assertFalse( BumpMint_Conditions::matches_coupon( $second_rule, WC()->cart ) );

		WC()->cart->remove_coupon( $first_coupon->get_code() );
		$this->assertFalse( BumpMint_Conditions::matches_coupon( $any_rule, WC()->cart ) );
		$this->assertFalse( BumpMint_Conditions::matches_coupon( $first_rule, WC()->cart ) );

		$this->assertTrue( WC()->cart->apply_coupon( $second_coupon->get_code() ) );
		$this->assertTrue( BumpMint_Conditions::matches_coupon( $first_rule, WC()->cart ) );
		$this->assertTrue( BumpMint_Conditions::matches_coupon( $second_rule, WC()->cart ) );
	}

	/**
	 * Coupon settings are validated and normalized before persistence.
	 */
	public function test_coupon_settings_are_validated_and_normalized() {
		$offer  = $this->create_product( 100, 'Coupon validation offer' );
		$coupon = $this->create_coupon( 'bumpmint-validation' );
		$draft_coupon = $this->create_coupon( 'bumpmint-draft' );
		$draft_coupon->set_status( 'draft' );
		$draft_coupon->save();
		$base   = array(
			'name'             => 'Coupon validation',
			'condition_type'   => BumpMint_Conditions::ALWAYS,
			'bump_product_ids' => array( $offer->get_id() ),
		);

		$invalid_mode = BumpMint_Rules::save_rule( '', wp_parse_args( array( 'coupon_mode' => 'invalid' ), $base ) );
		$this->assertWPError( $invalid_mode );
		$this->assertSame( 'bumpmint_invalid_coupon_mode', $invalid_mode->get_error_code() );

		$missing_coupon = BumpMint_Rules::save_rule( '', wp_parse_args( array( 'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC ), $base ) );
		$this->assertWPError( $missing_coupon );
		$this->assertSame( 'bumpmint_coupon_required', $missing_coupon->get_error_code() );

		$nested_coupon = BumpMint_Rules::save_rule(
			'',
			wp_parse_args(
				array(
					'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
					'coupon_ids'  => array( array( $coupon->get_id() ) ),
				),
				$base
			)
		);
		$this->assertWPError( $nested_coupon );
		$this->assertSame( 'bumpmint_coupon_required', $nested_coupon->get_error_code() );

		$invalid_coupon = BumpMint_Rules::save_rule(
			'',
			wp_parse_args(
				array(
					'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
					'coupon_ids'  => array( $offer->get_id() ),
				),
				$base
			)
		);
		$this->assertWPError( $invalid_coupon );
		$this->assertSame( 'bumpmint_invalid_coupon', $invalid_coupon->get_error_code() );

		$unpublished_coupon = BumpMint_Rules::save_rule(
			'',
			wp_parse_args(
				array(
					'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
					'coupon_ids'  => array( $draft_coupon->get_id() ),
				),
				$base
			)
		);
		$this->assertWPError( $unpublished_coupon );
		$this->assertSame( 'bumpmint_invalid_coupon', $unpublished_coupon->get_error_code() );

		$valid = BumpMint_Rules::save_rule(
			'',
			wp_parse_args(
				array(
					'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
					'coupon_ids'  => array( $coupon->get_id(), $coupon->get_id() ),
				),
				$base
			)
		);
		$this->assertNotWPError( $valid );
		$this->assertSame( array( $coupon->get_id() ), $valid['coupon_ids'] );

		$any = BumpMint_Rules::save_rule(
			$valid['id'],
			wp_parse_args(
				array(
					'coupon_mode' => BumpMint_Conditions::COUPON_ANY,
					'coupon_ids'  => array( $coupon->get_id() ),
				),
				$base
			)
		);
		$this->assertNotWPError( $any );
		$this->assertSame( array(), $any['coupon_ids'] );
	}

	/**
	 * Legacy rules remain unrestricted and corrupt explicit modes fail closed.
	 */
	public function test_coupon_targeting_normalizes_legacy_and_invalid_values_safely() {
		$offer = $this->create_product( 100, 'Legacy coupon offer' );
		$rule  = $this->create_rule( $offer );

		unset( $rule['coupon_mode'], $rule['coupon_ids'] );
		update_option( BUMPMINT_OPTION_KEY, array( $rule ), false );

		$legacy = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( BumpMint_Conditions::COUPON_NONE, $legacy['coupon_mode'] );
		$this->assertSame( array(), $legacy['coupon_ids'] );
		$this->assertTrue( BumpMint_Conditions::matches_coupon( $legacy, WC()->cart ) );

		$legacy['coupon_mode'] = 'unsupported';
		$legacy['coupon_ids']  = array( 123 );
		update_option( BUMPMINT_OPTION_KEY, array( $legacy ), false );

		$invalid = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( BumpMint_Conditions::COUPON_SPECIFIC, $invalid['coupon_mode'] );
		$this->assertSame( array(), $invalid['coupon_ids'] );
		$this->assertFalse( BumpMint_Conditions::matches_coupon( $invalid, WC()->cart ) );
	}

	/**
	 * Losing a required coupon removes only the BumpMint-created cart line.
	 */
	public function test_coupon_targeting_is_enforced_throughout_checkout() {
		$cart_product = $this->create_product( 50, 'Coupon cart product' );
		$offer        = $this->create_product( 100, 'Coupon-only offer' );
		$coupon       = $this->create_coupon( 'bumpmint-required' );
		$rule         = $this->create_rule(
			$offer,
			array(
				'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
				'coupon_ids'  => array( $coupon->get_id() ),
			)
		);
		WC()->cart->add_to_cart( $cart_product->get_id(), 1 );

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$this->assertSame( array(), $checkout->get_applicable_offers( WC()->cart ) );

		$this->assertTrue( WC()->cart->apply_coupon( $coupon->get_code() ) );
		$this->assertCount( 1, $checkout->get_applicable_offers( WC()->cart ) );

		$bump_key = $this->add_bump_to_cart( $offer, $rule );
		WC()->cart->calculate_totals();
		$this->assertArrayHasKey( $bump_key, WC()->cart->get_cart() );

		WC()->cart->remove_coupon( $coupon->get_code() );
		WC()->cart->calculate_totals();
		$this->assertArrayNotHasKey( $bump_key, WC()->cart->get_cart() );

		$pending = WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS, array() );
		$this->assertNotEmpty( $pending );
		$this->assertStringContainsString( 'selected for this offer', implode( ' ', $pending ) );
	}

	/**
	 * Coupon targeting differentiates otherwise equivalent rule fingerprints.
	 */
	public function test_coupon_targeting_is_part_of_duplicate_detection() {
		$offer  = $this->create_product( 100, 'Coupon fingerprint offer' );
		$coupon = $this->create_coupon( 'bumpmint-fingerprint' );
		$this->create_rule( $offer, array( 'name' => 'No-coupon offer' ) );

		$specific = $this->create_rule(
			$offer,
			array(
				'name'        => 'Coupon-only offer',
				'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
				'coupon_ids'  => array( $coupon->get_id() ),
			)
		);
		$this->assertSame( array( $coupon->get_id() ), $specific['coupon_ids'] );

		$duplicate_data         = $specific;
		$duplicate_data['name'] = 'Duplicate coupon-only offer';
		unset( $duplicate_data['id'], $duplicate_data['created_at'] );
		$duplicate = BumpMint_Rules::save_rule( '', $duplicate_data );

		$this->assertWPError( $duplicate );
		$this->assertSame( 'bumpmint_duplicate_rule', $duplicate->get_error_code() );
	}

	/**
	 * The admin form preserves a selected coupon code for SelectWoo.
	 */
	public function test_admin_coupon_controls_render_selected_coupon() {
		$previous_get     = $_GET;
		$previous_user_id = get_current_user_id();
		$offer            = $this->create_product( 100, 'Admin coupon offer' );
		$coupon           = $this->create_coupon( 'bumpmint-admin-coupon' );
		$rule             = $this->create_rule(
			$offer,
			array(
				'coupon_mode' => BumpMint_Conditions::COUPON_SPECIFIC,
				'coupon_ids'  => array( $coupon->get_id() ),
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

		$this->assertStringContainsString( 'id="bumpmint-coupon-mode"', $html );
		$this->assertMatchesRegularExpression( '/<option value="specific"[^>]*selected=/', $html );
		$this->assertStringContainsString( 'id="bumpmint-coupon-ids"', $html );
		$this->assertStringContainsString( 'bumpmint-admin-coupon', $html );
	}
}
