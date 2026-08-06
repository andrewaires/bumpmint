<?php
/**
 * Shared integration test helpers.
 *
 * @package BumpMint
 */

/**
 * Base test case with an isolated WooCommerce cart.
 */
abstract class BumpMint_Test_Case extends WP_UnitTestCase {

	/**
	 * Hooks registered by an individual test.
	 *
	 * @var array
	 */
	private $bumpmint_test_hooks = array();

	/**
	 * Creates a clean cart and deterministic price formatting.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( BUMPMINT_OPTION_KEY );
		delete_option( BUMPMINT_STORAGE_VERSION_KEY );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_decimal_sep', '.' );
		update_option( 'woocommerce_price_thousand_sep', ',' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );

		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->customer = new WC_Customer( 0, true );
		WC()->cart     = new WC_Cart();
		WC()->cart->calculate_totals();
		wc_clear_notices();
	}

	/**
	 * Removes test hooks and cart state.
	 */
	public function tear_down() {
		foreach ( array_reverse( $this->bumpmint_test_hooks ) as $hook ) {
			remove_filter( $hook['name'], $hook['callback'], $hook['priority'] );
		}
		$this->bumpmint_test_hooks = array();

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wc_clear_notices();
		delete_option( BUMPMINT_OPTION_KEY );
		delete_option( BUMPMINT_STORAGE_VERSION_KEY );

		parent::tear_down();
	}

	/**
	 * Registers a hook that is removed automatically after the test.
	 *
	 * @param string   $name          Hook name.
	 * @param callable $callback      Hook callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	protected function add_bumpmint_test_hook( $name, $callback, $priority = 10, $accepted_args = 1 ) {
		add_filter( $name, $callback, $priority, $accepted_args );
		$this->bumpmint_test_hooks[] = array(
			'name'     => $name,
			'callback' => $callback,
			'priority' => $priority,
		);
	}

	/**
	 * Creates a purchasable simple product.
	 *
	 * @param float  $price Product price.
	 * @param string $name  Product name.
	 * @return WC_Product_Simple
	 */
	protected function create_product( $price, $name = 'Test product' ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( (string) $price );
		$product->set_price( (string) $price );
		$product->set_virtual( true );
		$product->save();

		return $product;
	}

	/**
	 * Saves a complete valid rule and returns its normalized representation.
	 *
	 * @param WC_Product $offer_product Offered product.
	 * @param array      $overrides     Rule field overrides.
	 * @return array
	 */
	protected function create_rule( $offer_product, array $overrides = array() ) {
		$data = wp_parse_args(
			$overrides,
			array(
				'name'                  => 'Integration test offer',
				'condition_type'        => BumpMint_Conditions::ALWAYS,
				'condition_product_ids' => array(),
				'condition_match'       => 'any',
				'condition_operator'    => 'greater_than',
				'condition_value'       => '0',
				'bump_product_ids'      => array( $offer_product->get_id() ),
				'hide_if_in_cart'       => '0',
				'position'              => BumpMint_Positions::BEFORE_PAYMENT,
				'discount_enabled'      => '1',
				'discount_type'         => 'percentage',
				'discount_value'        => '10',
				'max_quantity'          => '1',
				'badge_text'             => '',
				'offer_title'            => '',
				'description'            => '',
				'image_id'               => '0',
			)
		);

		$rule = BumpMint_Rules::save_rule( '', $data );
		$this->assertNotWPError( $rule );

		return $rule;
	}

	/**
	 * Adds a cart line marked as coming from a saved order bump.
	 *
	 * @param WC_Product $product  Offered product.
	 * @param array      $rule     Saved rule.
	 * @param int        $quantity Quantity to add.
	 * @return string
	 */
	protected function add_bump_to_cart( $product, array $rule, $quantity = 1 ) {
		$key = WC()->cart->add_to_cart(
			$product->get_id(),
			$quantity,
			0,
			array(),
			array(
				'bumpmint_rule_id' => $rule['id'],
				'bumpmint_source'  => 'order_bump',
			)
		);

		$this->assertNotFalse( $key );
		return $key;
	}
}
