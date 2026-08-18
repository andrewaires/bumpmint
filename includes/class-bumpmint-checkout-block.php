<?php
/**
 * WooCommerce Checkout Block integration.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

/**
 * Exposes BumpMint offers through the Store API and Checkout Block SlotFill.
 */
class BumpMint_Checkout_Block implements IntegrationInterface {

	const NAME          = 'bumpmint';
	const SCRIPT_HANDLE = 'bumpmint-checkout-block';

	/**
	 * Shared checkout service.
	 *
	 * @var BumpMint_Checkout
	 */
	private $checkout;

	/**
	 * Prevents duplicate Store API registrations.
	 *
	 * @var bool
	 */
	private $store_api_registered = false;

	/**
	 * @param BumpMint_Checkout $checkout Shared checkout service.
	 */
	public function __construct( $checkout ) {
		$this->checkout = $checkout;

		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_before_payment' ), PHP_INT_MAX, 2 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		}
	}

	/**
	 * Registers this integration with Checkout Blocks.
	 *
	 * @param object $registry WooCommerce integration registry.
	 * @return void
	 */
	public function register_integration( $registry ) {
		$registry->register( $this );
	}

	/**
	 * Returns the integration namespace.
	 *
	 * @return string
	 */
	public function get_name() {
		return self::NAME;
	}

	/**
	 * Registers the lightweight Checkout Block script and shared styles.
	 *
	 * @return void
	 */
	public function initialize() {
		wp_register_script(
			self::SCRIPT_HANDLE,
			BUMPMINT_PLUGIN_URL . 'assets/js/checkout-block.js',
			array( 'wc-blocks-checkout', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			BUMPMINT_VERSION,
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'bumpmint-order-bump-for-woocommerce' );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'bumpmintBlockAnalytics',
			BumpMint_Analytics::get_frontend_config()
		);

		wp_enqueue_style(
			'bumpmint-frontend',
			BUMPMINT_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			BUMPMINT_VERSION
		);
	}

	/**
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * BumpMint is rendered automatically and has no editor placeholder.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * @return array<string,string>
	 */
	public function get_script_data() {
		return array();
	}

	/**
	 * Registers BumpMint cart data and its mutation callback in the Store API.
	 *
	 * @return void
	 */
	public function register_store_api() {
		if (
			$this->store_api_registered
			|| ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
			|| ! function_exists( 'woocommerce_store_api_register_update_callback' )
		) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::NAME,
				'data_callback'   => array( $this, 'get_cart_data' ),
				'schema_callback' => array( $this, 'get_cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::NAME,
				'callback'  => array( $this, 'update_cart' ),
			)
		);

		$this->store_api_registered = true;
	}

	/**
	 * Returns eligible offer cards for the current Store API cart response.
	 *
	 * @return array{offers:array<int,array<string,mixed>>}
	 */
	public function get_cart_data() {
		$offers = array();
		$cart   = WC()->cart;

		foreach ( $this->checkout->get_applicable_offers( $cart ) as $offer ) {
			$offers[] = $this->checkout->get_offer_view_data(
				$offer['rule'],
				$offer['product'],
				$offer['cart_item_key']
			);
		}

		return array( 'offers' => $offers );
	}

	/**
	 * Defines the public Store API extension schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_cart_schema() {
		return array(
			'offers' => array(
				'type'     => 'array',
				'readonly' => true,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'rule_id'                => array( 'type' => 'string', 'readonly' => true ),
						'product_id'             => array( 'type' => 'integer', 'readonly' => true ),
						'selected'               => array( 'type' => 'boolean', 'readonly' => true ),
						'requires_configuration' => array( 'type' => 'boolean', 'readonly' => true ),
						'configuration_url'      => array( 'type' => 'string', 'readonly' => true ),
						'configuration_label'    => array( 'type' => 'string', 'readonly' => true ),
						'configuration_hint'     => array( 'type' => 'string', 'readonly' => true ),
						'badge'                  => array( 'type' => 'string', 'readonly' => true ),
						'title'                  => array( 'type' => 'string', 'readonly' => true ),
						'description'            => array( 'type' => 'string', 'readonly' => true ),
						'price_html'             => array( 'type' => 'string', 'readonly' => true ),
						'image_url'              => array( 'type' => 'string', 'format' => 'uri', 'readonly' => true ),
						'image_alt'              => array( 'type' => 'string', 'readonly' => true ),
					),
				),
			),
		);
	}

	/**
	 * Handles an authenticated Store API cart extension update.
	 *
	 * @param array<string,mixed> $data Extension request data.
	 * @throws WC_REST_Exception When the offer cannot be updated.
	 * @return void
	 */
	public function update_cart( $data ) {
		$data       = is_array( $data ) ? $data : array();
		$rule_id    = isset( $data['rule_id'] ) ? sanitize_text_field( $data['rule_id'] ) : '';
		$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		$add        = isset( $data['add'] ) ? rest_sanitize_boolean( $data['add'] ) : false;
		$result     = $this->checkout->update_offer_selection( $rule_id, $product_id, $add, WC()->cart );

		if ( is_wp_error( $result ) ) {
			$status = absint( $result->get_error_data() );
			throw new WC_REST_Exception(
				esc_html( $result->get_error_code() ),
				esc_html( $result->get_error_message() ),
				absint( $status ? $status : 400 )
			);
		}
	}

	/**
	 * Stops a Block checkout once when an open tab used stale offer eligibility.
	 *
	 * This Store API hook is available throughout the declared WooCommerce
	 * compatibility range, including WooCommerce 8.0.
	 *
	 * @param WC_Order        $order   Pending order.
	 * @param WP_REST_Request $request Checkout request.
	 * @throws RouteException When stale eligibility must be reviewed.
	 * @return void
	 */
	public function validate_before_payment( $order, $request ) {
		unset( $order );
		unset( $request );

		$errors   = new WP_Error();
		$messages = $this->checkout->add_pending_removal_errors( $errors, true );

		if ( ! empty( $messages ) ) {
			throw new RouteException(
				'bumpmint_offer_no_longer_eligible',
				esc_html( implode( ' ', $messages ) ),
				400
			);
		}
	}
}
