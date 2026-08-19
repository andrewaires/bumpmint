<?php
/**
 * Order bump status integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies status persistence and checkout enforcement.
 */
class Test_BumpMint_Rule_Status extends BumpMint_Test_Case {

	/**
	 * Rules default to active and accept only supported status transitions.
	 */
	public function test_status_defaults_validates_and_persists() {
		$offer = $this->create_product( 100, 'Status offer' );
		$rule  = $this->create_rule( $offer );

		$this->assertSame( BumpMint_Rules::STATUS_ACTIVE, $rule['status'] );

		$inactive = BumpMint_Rules::set_status( $rule['id'], BumpMint_Rules::STATUS_INACTIVE );
		$this->assertNotWPError( $inactive );
		$this->assertSame( BumpMint_Rules::STATUS_INACTIVE, $inactive['status'] );
		$this->assertSame( BumpMint_Rules::STATUS_INACTIVE, BumpMint_Rules::get_rule( $rule['id'] )['status'] );

		$data = $inactive;
		unset( $data['status'] );
		$preserved = BumpMint_Rules::save_rule( $rule['id'], $data );
		$this->assertNotWPError( $preserved );
		$this->assertSame( BumpMint_Rules::STATUS_INACTIVE, $preserved['status'] );

		$invalid_transition = BumpMint_Rules::set_status( $rule['id'], 'paused' );
		$this->assertWPError( $invalid_transition );
		$this->assertSame( 'bumpmint_invalid_status', $invalid_transition->get_error_code() );

		$data['status'] = 'paused';
		$invalid_save   = BumpMint_Rules::save_rule( $rule['id'], $data );
		$this->assertWPError( $invalid_save );
		$this->assertSame( 'bumpmint_invalid_status', $invalid_save->get_error_code() );
	}

	/**
	 * Legacy rules stay active, while corrupt explicit statuses fail closed.
	 */
	public function test_status_normalization_preserves_legacy_behavior() {
		$offer = $this->create_product( 100, 'Legacy status offer' );
		$rule  = $this->create_rule( $offer );
		$rules = get_option( BUMPMINT_OPTION_KEY, array() );

		unset( $rules[0]['status'] );
		update_option( BUMPMINT_OPTION_KEY, $rules, false );

		$this->assertSame( BumpMint_Rules::STATUS_ACTIVE, BumpMint_Rules::get_rule( $rule['id'] )['status'] );
		$migrated = get_option( BUMPMINT_OPTION_KEY, array() );
		$this->assertSame( BumpMint_Rules::STATUS_ACTIVE, $migrated[0]['status'] );

		$migrated[0]['status'] = 'paused';
		update_option( BUMPMINT_OPTION_KEY, $migrated, false );

		$this->assertSame( BumpMint_Rules::STATUS_INACTIVE, BumpMint_Rules::get_rule( $rule['id'] )['status'] );
		$normalized = get_option( BUMPMINT_OPTION_KEY, array() );
		$this->assertSame( BumpMint_Rules::STATUS_INACTIVE, $normalized[0]['status'] );
	}

	/**
	 * Inactive rules cannot be shown or selected and lose existing BumpMint lines.
	 */
	public function test_inactive_rule_is_enforced_throughout_checkout() {
		$cart_product = $this->create_product( 25, 'Cart product' );
		$offer        = $this->create_product( 100, 'Inactive offer' );
		$rule         = $this->create_rule( $offer );
		WC()->cart->add_to_cart( $cart_product->get_id(), 1 );

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$this->assertCount( 1, $checkout->get_applicable_offers( WC()->cart ) );

		$result = BumpMint_Rules::set_status( $rule['id'], BumpMint_Rules::STATUS_INACTIVE );
		$this->assertNotWPError( $result );
		$this->assertSame( array(), $checkout->get_applicable_offers( WC()->cart ) );

		$selection = $checkout->update_offer_selection( $rule['id'], $offer->get_id(), true, WC()->cart );
		$this->assertWPError( $selection );
		$this->assertSame( 'bumpmint_offer_unavailable', $selection->get_error_code() );

		BumpMint_Rules::set_status( $rule['id'], BumpMint_Rules::STATUS_ACTIVE );
		$bump_key = $this->add_bump_to_cart( $offer, $rule );
		WC()->cart->calculate_totals();
		$this->assertArrayHasKey( $bump_key, WC()->cart->get_cart() );

		BumpMint_Rules::set_status( $rule['id'], BumpMint_Rules::STATUS_INACTIVE );
		WC()->cart->calculate_totals();
		$this->assertArrayNotHasKey( $bump_key, WC()->cart->get_cart() );
		$this->assertNotEmpty( WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS, array() ) );
	}
}
