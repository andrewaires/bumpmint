<?php
/**
 * Checkout Block integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies the Store API data and mutation layer used by Checkout Blocks.
 */
class Test_BumpMint_Checkout_Block extends BumpMint_Test_Case {

	/**
	 * The Block endpoint exposes eligible cards and updates their selection.
	 */
	public function test_store_api_offer_data_and_selection_share_checkout_logic() {
		$trigger = $this->create_product( 100, 'Trigger product' );
		$offer   = $this->create_product( 50, 'Block offer product' );
		$rule    = $this->create_rule(
			$offer,
			array(
				'offer_title' => 'Add {product}',
				'description' => 'Block price: {price}',
			)
		);
		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		WC()->cart->calculate_totals();

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$block      = new BumpMint_Checkout_Block( $checkout );
		$data       = $block->get_cart_data();

		$this->assertCount( 1, $data['offers'] );
		$this->assertSame( $rule['id'], $data['offers'][0]['rule_id'] );
		$this->assertSame( $offer->get_id(), $data['offers'][0]['product_id'] );
		$this->assertFalse( $data['offers'][0]['selected'] );
		$this->assertSame( 'classic', $data['offers'][0]['design']['preset'] );
		$this->assertInstanceOf( stdClass::class, $data['offers'][0]['design']['css_variables'] );
		$this->assertSame( 'Add Block offer product', $data['offers'][0]['title'] );
		$this->assertStringContainsString( '45.00', $data['offers'][0]['price_html'] );

		$block->update_cart(
			array(
				'rule_id'    => $rule['id'],
				'product_id' => $offer->get_id(),
				'add'        => true,
			)
		);

		$data = $block->get_cart_data();
		$this->assertCount( 1, $data['offers'] );
		$this->assertTrue( $data['offers'][0]['selected'] );

		$block->update_cart(
			array(
				'rule_id'    => $rule['id'],
				'product_id' => $offer->get_id(),
				'add'        => false,
			)
		);

		$data = $block->get_cart_data();
		$this->assertCount( 1, $data['offers'] );
		$this->assertFalse( $data['offers'][0]['selected'] );
	}

	/**
	 * Checkout Blocks receive only the saved preset and validated CSS overrides.
	 */
	public function test_store_api_offer_exposes_design_as_read_only_presentation_data() {
		$trigger = $this->create_product( 100, 'Trigger product' );
		$offer = $this->create_product( 50, 'Styled Block offer' );
		$rule  = $this->create_rule( $offer );
		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		WC()->cart->calculate_totals();

		$design = BumpMint_Design::validate_submission(
			array(
				'preset' => 'minimal',
				'overrides' => array_merge( BumpMint_Design::get_preset_defaults( 'minimal' ), array( 'accent_color' => '#123456' ) ),
			)
		);
		$this->assertNotWPError( $design );
		$submission = $this->rule_submission_from_saved_rule( $rule );
		$submission['design_touched'] = '1';
		$submission['design'] = array( 'preset' => 'minimal', 'overrides' => array_merge( BumpMint_Design::get_preset_defaults( 'minimal' ), array( 'accent_color' => '#123456' ) ) );
		$saved = BumpMint_Rules::save_rule( $rule['id'], $submission );
		$this->assertNotWPError( $saved );

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$block      = new BumpMint_Checkout_Block( $checkout );
		$data       = $block->get_cart_data();

		$this->assertCount( 1, $data['offers'] );
		$this->assertSame( 'minimal', $data['offers'][0]['design']['preset'] );
		$this->assertSame( '#123456', $data['offers'][0]['design']['css_variables']['--bumpmint-accent'] );

		$schema = $block->get_cart_schema();
		$this->assertTrue( $schema['offers']['items']['properties']['design']['readonly'] );
		$this->assertTrue( $schema['offers']['items']['properties']['design']['properties']['css_variables']['readonly'] );
	}

	/**
	 * The Store API rejects a product that is not saved in the selected rule.
	 */
	public function test_store_api_rejects_tampered_offer_product() {
		$trigger   = $this->create_product( 100, 'Trigger product' );
		$offer     = $this->create_product( 50, 'Saved offer product' );
		$unrelated = $this->create_product( 25, 'Unrelated product' );
		$rule      = $this->create_rule( $offer );
		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		WC()->cart->calculate_totals();

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$block      = new BumpMint_Checkout_Block( $checkout );

		$this->expectException( WC_REST_Exception::class );
		$this->expectExceptionCode( 404 );
		$block->update_cart(
			array(
				'rule_id'    => $rule['id'],
				'product_id' => $unrelated->get_id(),
				'add'        => true,
			)
		);
	}

	/**
	 * Creates the full older-form payload needed to update a saved rule.
	 *
	 * @param array $rule Saved rule.
	 * @return array
	 */
	private function rule_submission_from_saved_rule( array $rule ) {
		unset( $rule['id'], $rule['created_at'], $rule['design'] );
		return $rule;
	}

	/**
	 * A stale Block checkout is stopped once and can retry with refreshed data.
	 */
	public function test_store_api_checkout_stops_once_for_pending_offer_removal() {
		$message = 'The stale order bump was removed.';
		WC()->session->set( BumpMint_Checkout::SESSION_PENDING_REMOVALS, array( $message ) );

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$block      = new BumpMint_Checkout_Block( $checkout );

		try {
			$block->validate_before_payment( new WC_Order(), new WP_REST_Request() );
			$this->fail( 'Expected stale Checkout Block validation to fail.' );
		} catch ( Automattic\WooCommerce\StoreApi\Exceptions\RouteException $exception ) {
			$this->assertSame( 400, $exception->getCode() );
			$this->assertStringContainsString( $message, $exception->getMessage() );
		}

		$this->assertNull( WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS ) );

		$block->validate_before_payment( new WC_Order(), new WP_REST_Request() );
	}
}
