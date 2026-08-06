<?php
/**
 * Checkout eligibility integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies removal, review notices, and quantity protection.
 */
class Test_BumpMint_Checkout_Eligibility extends BumpMint_Test_Case {

	/**
	 * Losing subtotal eligibility removes only the line created by BumpMint.
	 */
	public function test_subtotal_change_removes_only_bump_line_and_blocks_stale_submission() {
		$trigger = $this->create_product( 100, 'Trigger product' );
		$offer   = $this->create_product( 50, 'Independent offer product' );
		$rule    = $this->create_rule(
			$offer,
			array(
				'condition_type'     => BumpMint_Conditions::CART_SUBTOTAL,
				'condition_operator' => 'greater_than',
				'condition_value'    => '200',
			)
		);

		$trigger_key = WC()->cart->add_to_cart( $trigger->get_id(), 2 );
		$normal_key  = WC()->cart->add_to_cart( $offer->get_id(), 1 );
		$bump_key    = $this->add_bump_to_cart( $offer, $rule );
		WC()->cart->calculate_totals();

		$this->assertArrayHasKey( $bump_key, WC()->cart->get_cart() );
		WC()->cart->set_quantity( $trigger_key, 1, false );
		WC()->cart->calculate_totals();

		$cart = WC()->cart->get_cart();
		$this->assertArrayHasKey( $normal_key, $cart );
		$this->assertArrayNotHasKey( $bump_key, $cart );

		$pending = WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS, array() );
		$this->assertNotEmpty( $pending );

		$errors = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array(), $errors );
		$this->assertTrue( $errors->has_errors() );
		$this->assertStringContainsString( 'more than', $errors->get_error_message( 'bumpmint_offer_no_longer_eligible' ) );

		do_action( 'woocommerce_review_order_after_order_total' );
		$this->assertNull( WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS ) );
	}

	/**
	 * Manipulated quantities are clamped before the discount is calculated.
	 */
	public function test_discounted_quantity_is_clamped_to_saved_limit() {
		$offer   = $this->create_product( 100, 'Limited product' );
		$rule    = $this->create_rule(
			$offer,
			array(
				'max_quantity' => '2',
			)
		);
		$bump_key = $this->add_bump_to_cart( $offer, $rule, 5 );

		WC()->cart->calculate_totals();
		$bump_item = WC()->cart->get_cart_item( $bump_key );

		$this->assertSame( 2, (int) $bump_item['quantity'] );
		$this->assertEqualsWithDelta( 180.0, (float) $bump_item['line_total'], 0.001 );
	}
}
