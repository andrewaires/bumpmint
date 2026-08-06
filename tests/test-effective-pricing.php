<?php
/**
 * Effective price integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies compatibility with legitimate WooCommerce price adjustments.
 */
class Test_BumpMint_Effective_Pricing extends BumpMint_Test_Case {

	/**
	 * Product whose price is adjusted by the test callback.
	 *
	 * @var int
	 */
	private $priced_product_id = 0;

	/**
	 * Price applied by the cart-pricing callback.
	 *
	 * @var string
	 */
	private $cart_price = '80';

	/**
	 * Simulates a plugin filtering the effective product price.
	 *
	 * @param mixed      $price   Current price.
	 * @param WC_Product $product Product instance.
	 * @return mixed
	 */
	public function filter_product_price( $price, $product ) {
		return $product->get_id() === $this->priced_product_id ? '80' : $price;
	}

	/**
	 * Simulates a plugin changing cart item prices during totals calculation.
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function adjust_cart_price( $cart ) {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( $cart_item['data']->get_id() === $this->priced_product_id ) {
				$cart_item['data']->set_price( $this->cart_price );
			}
		}
	}

	/**
	 * A product getter filter becomes the base for the BumpMint discount.
	 */
	public function test_product_price_filter_is_discounted_once() {
		$product                 = $this->create_product( 100, 'Filtered product' );
		$this->priced_product_id = $product->get_id();
		$this->add_bumpmint_test_hook( 'woocommerce_product_get_price', array( $this, 'filter_product_price' ), 20, 2 );

		$rule     = $this->create_rule( $product );
		$bump_key = $this->add_bump_to_cart( $product, $rule );

		WC()->cart->calculate_totals();
		WC()->cart->calculate_totals();

		$bump_item = WC()->cart->get_cart_item( $bump_key );
		$this->assertEqualsWithDelta( 72.0, (float) $bump_item['data']->get_price( 'edit' ), 0.001 );
		$this->assertEqualsWithDelta( 72.0, (float) $bump_item['line_total'], 0.001 );
	}

	/**
	 * Cart pricing hooks affect the bump but not independent product lines.
	 */
	public function test_cart_price_adjustment_is_synchronized_without_compounding() {
		$product                 = $this->create_product( 100, 'Cart-priced product' );
		$this->priced_product_id = $product->get_id();
		$this->add_bumpmint_test_hook( 'woocommerce_before_calculate_totals', array( $this, 'adjust_cart_price' ), 20 );

		$rule       = $this->create_rule(
			$product,
			array(
				'description' => 'Selected price: {price}',
			)
		);
		$normal_key = WC()->cart->add_to_cart( $product->get_id(), 1 );
		$bump_key   = $this->add_bump_to_cart( $product, $rule );

		$this->assertNotFalse( $normal_key );
		$this->assertNotSame( $normal_key, $bump_key );

		WC()->cart->calculate_totals();
		WC()->cart->calculate_totals();

		$normal_item = WC()->cart->get_cart_item( $normal_key );
		$bump_item   = WC()->cart->get_cart_item( $bump_key );
		$this->assertEqualsWithDelta( 80.0, (float) $normal_item['data']->get_price( 'edit' ), 0.001 );
		$this->assertEqualsWithDelta( 80.0, (float) $normal_item['line_total'], 0.001 );
		$this->assertEqualsWithDelta( 72.0, (float) $bump_item['data']->get_price( 'edit' ), 0.001 );
		$this->assertEqualsWithDelta( 72.0, (float) $bump_item['line_total'], 0.001 );

		ob_start();
		do_action( 'woocommerce_review_order_before_payment' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( "checked='checked'", $html );
		$this->assertStringContainsString( '80.00', $html );
		$this->assertStringContainsString( '72.00', $html );
		$this->assertStringContainsString( 'Selected price:', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A fixed badge is capped by the effective base instead of the catalog price.
	 */
	public function test_fixed_discount_badge_uses_effective_cart_base() {
		$product                 = $this->create_product( 100, 'Fixed discount product' );
		$this->priced_product_id = $product->get_id();
		$this->cart_price        = '8';
		$this->add_bumpmint_test_hook( 'woocommerce_before_calculate_totals', array( $this, 'adjust_cart_price' ), 20 );

		$rule = $this->create_rule(
			$product,
			array(
				'discount_type'  => 'fixed',
				'discount_value' => '10',
			)
		);
		$this->add_bump_to_cart( $product, $rule );
		WC()->cart->calculate_totals();

		ob_start();
		do_action( 'woocommerce_review_order_before_payment' );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<div class="bumpmint-badge">[^<]*8\.00[^<]*OFF<\/div>/', $html );
		$this->assertStringContainsString( '0.00', $html );
	}
}
