<?php
/**
 * Native configurable offer integration tests.
 *
 * @package BumpMint
 */

/**
 * Minimal bookable product used without loading the premium Bookings extension.
 */
class BumpMint_Test_Product_Booking extends WC_Product_Simple {

	/**
	 * @return string
	 */
	public function get_type() {
		return 'booking';
	}

	/**
	 * Mirrors the WooCommerce Bookings confirmation setting used by BumpMint.
	 *
	 * @return bool
	 */
	public function get_requires_confirmation() {
		return 'yes' === get_post_meta( $this->get_id(), '_bumpmint_test_requires_confirmation', true );
	}
}

/**
 * Verifies the product-page flow for offers with required native fields.
 */
class Test_BumpMint_Native_Offers extends BumpMint_Test_Case {

	/**
	 * Registers the fake booking product class.
	 */
	public function set_up() {
		parent::set_up();
		$this->add_bumpmint_test_hook( 'woocommerce_product_class', array( $this, 'map_booking_product_class' ), 10, 4 );
	}

	/**
	 * Clears request data used by native form tests.
	 */
	public function tear_down() {
		$_GET  = array();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Maps the test product type to its lightweight implementation.
	 *
	 * @param string $classname    Product class name.
	 * @param string $product_type Product type.
	 * @param string $post_type    Post type.
	 * @param int    $product_id   Product ID.
	 * @return string
	 */
	public function map_booking_product_class( $classname, $product_type, $post_type = 'product', $product_id = 0 ) {
		unset( $post_type, $product_id );

		return 'booking' === $product_type ? BumpMint_Test_Product_Booking::class : $classname;
	}

	/**
	 * Booking offers expose a signed native URL and reject direct selection.
	 */
	public function test_booking_offer_uses_native_configuration_instead_of_direct_add() {
		$trigger = $this->create_product( 200, 'Trigger product' );
		$booking = $this->create_booking_product( 100, 'Bookable offer' );
		$rule    = $this->create_rule( $booking );
		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		WC()->cart->calculate_totals();

		$checkout = $this->create_checkout_service();
		$offers   = $checkout->get_applicable_offers( WC()->cart );
		$this->assertCount( 1, $offers );

		$view_data = $checkout->get_offer_view_data( $rule, $booking, null );
		$this->assertTrue( $view_data['requires_configuration'] );
		$this->assertSame( 'Choose date', $view_data['configuration_label'] );
		$this->assertStringContainsString( BumpMint_Checkout::NATIVE_RULE_PARAM . '=', $view_data['configuration_url'] );
		$this->assertStringContainsString( BumpMint_Checkout::NATIVE_NONCE_PARAM . '=', $view_data['configuration_url'] );

		ob_start();
		do_action( 'woocommerce_review_order_before_payment' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'bumpmint-configure-button', $html );
		$this->assertStringContainsString( 'Choose date', $html );
		$this->assertStringNotContainsString( 'data-product-id="' . $booking->get_id() . '"', $html );

		$result = $checkout->update_offer_selection( $rule['id'], $booking->get_id(), true, WC()->cart );
		$this->assertWPError( $result );
		$this->assertSame( 'bumpmint_configuration_required', $result->get_error_code() );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	/**
	 * Native booking data remains authoritative and receives the bump discount.
	 */
	public function test_native_booking_cost_is_discounted_and_redirects_to_checkout() {
		$trigger = $this->create_product( 200, 'Trigger product' );
		$booking = $this->create_booking_product( 100, 'Dynamic booking' );
		$rule    = $this->create_rule( $booking );
		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		WC()->cart->calculate_totals();

		$checkout = $this->create_checkout_service();
		$context  = $this->get_native_url_context( $checkout->get_native_configuration_url( $rule, $booking ) );
		$_POST    = $context;

		$this->assertTrue( $checkout->validate_native_offer_add_to_cart( true, $booking->get_id(), 1, 0, array(), array() ) );

		$cart_item_data = $checkout->add_native_offer_cart_item_data(
			array(
				'booking' => array(
					'_cost' => '150',
				),
			),
			$booking->get_id(),
			0,
			1
		);
		$this->assertSame( $rule['id'], $cart_item_data['bumpmint_rule_id'] );
		$this->assertSame( 'order_bump', $cart_item_data['bumpmint_source'] );
		$this->assertEqualsWithDelta( 150.0, (float) $cart_item_data['bumpmint_native_base_price'], 0.001 );

		$_POST = array();
		$key   = WC()->cart->add_to_cart( $booking->get_id(), 1, 0, array(), $cart_item_data );
		$this->assertNotFalse( $key );
		WC()->cart->calculate_totals();

		$item = WC()->cart->get_cart_item( $key );
		$this->assertEqualsWithDelta( 135.0, (float) $item['data']->get_price( 'edit' ), 0.001 );
		$this->assertEqualsWithDelta( 135.0, (float) $item['line_total'], 0.001 );
		$this->assertSame( wc_get_checkout_url(), $checkout->redirect_native_offer_to_checkout( $booking->get_permalink(), $booking ) );
	}

	/**
	 * Altered native context never receives trusted BumpMint cart metadata.
	 */
	public function test_tampered_native_offer_context_is_rejected() {
		$trigger = $this->create_product( 200, 'Trigger product' );
		$booking = $this->create_booking_product( 100, 'Bookable offer' );
		$rule    = $this->create_rule( $booking );
		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		WC()->cart->calculate_totals();

		$checkout = $this->create_checkout_service();
		$_POST    = $this->get_native_url_context( $checkout->get_native_configuration_url( $rule, $booking ) );
		$_POST[ BumpMint_Checkout::NATIVE_NONCE_PARAM ] = 'tampered';

		$this->assertFalse( $checkout->validate_native_offer_add_to_cart( true, $booking->get_id(), 1, 0, array(), array() ) );
		$cart_item_data = $checkout->add_native_offer_cart_item_data( array( 'booking' => array( '_cost' => '150' ) ), $booking->get_id(), 0, 1 );
		$this->assertArrayNotHasKey( 'bumpmint_rule_id', $cart_item_data );
		$this->assertArrayNotHasKey( 'bumpmint_native_base_price', $cart_item_data );
	}

	/**
	 * Confirmation-only bookings cannot be saved or newly displayed as bumps.
	 */
	public function test_booking_requiring_confirmation_is_not_supported() {
		$trigger = $this->create_product( 200, 'Trigger product' );
		$booking = $this->create_booking_product( 100, 'Confirmation booking' );
		$rule    = $this->create_rule( $booking );
		update_post_meta( $booking->get_id(), '_bumpmint_test_requires_confirmation', 'yes' );
		wc_delete_product_transients( $booking->get_id() );

		$result = BumpMint_Rules::save_rule( $rule['id'], $rule );
		$this->assertWPError( $result );
		$this->assertSame( 'bumpmint_booking_requires_confirmation', $result->get_error_code() );

		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		WC()->cart->calculate_totals();
		$checkout = $this->create_checkout_service();
		$this->assertCount( 0, $checkout->get_applicable_offers( WC()->cart ) );
	}

	/**
	 * Creates a published fake bookable product.
	 *
	 * @param float  $price Product price.
	 * @param string $name  Product name.
	 * @return BumpMint_Test_Product_Booking
	 */
	private function create_booking_product( $price, $name ) {
		$product = new BumpMint_Test_Product_Booking();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( (string) $price );
		$product->set_price( (string) $price );
		$product->set_virtual( true );
		$product->save();
		wp_set_object_terms( $product->get_id(), 'booking', 'product_type' );
		wc_delete_product_transients( $product->get_id() );

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Returns an isolated checkout service without registering duplicate hooks.
	 *
	 * @return BumpMint_Checkout
	 */
	private function create_checkout_service() {
		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Converts a generated native URL into the posted hidden fields.
	 *
	 * @param string $url Native configuration URL.
	 * @return array<string,string>
	 */
	private function get_native_url_context( $url ) {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$data  = array();
		parse_str( is_string( $query ) ? $query : '', $data );

		return array(
			BumpMint_Checkout::NATIVE_RULE_PARAM    => isset( $data[ BumpMint_Checkout::NATIVE_RULE_PARAM ] ) ? (string) $data[ BumpMint_Checkout::NATIVE_RULE_PARAM ] : '',
			BumpMint_Checkout::NATIVE_PRODUCT_PARAM => isset( $data[ BumpMint_Checkout::NATIVE_PRODUCT_PARAM ] ) ? (string) $data[ BumpMint_Checkout::NATIVE_PRODUCT_PARAM ] : '',
			BumpMint_Checkout::NATIVE_NONCE_PARAM   => isset( $data[ BumpMint_Checkout::NATIVE_NONCE_PARAM ] ) ? (string) $data[ BumpMint_Checkout::NATIVE_NONCE_PARAM ] : '',
		);
	}
}
