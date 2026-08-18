<?php
/**
 * Checkout display, secure cart pricing, and AJAX handling.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the public-facing order bump flow.
 */
class BumpMint_Checkout {

	const AJAX_ACTION              = 'bumpmint_toggle_bump';
	const NONCE_ACTION             = 'bumpmint_toggle_nonce';
	const NATIVE_NONCE_ACTION      = 'bumpmint_native_offer';
	const NATIVE_RULE_PARAM        = 'bumpmint_offer';
	const NATIVE_PRODUCT_PARAM     = 'bumpmint_offer_product';
	const NATIVE_NONCE_PARAM       = 'bumpmint_offer_nonce';
	const SESSION_PENDING_REMOVALS = 'bumpmint_pending_offer_removals';

	/**
	 * Anonymous Analytics service, when tracking is available.
	 *
	 * @var BumpMint_Analytics|null
	 */
	private $analytics = null;

	/**
	 * Prevents recursive price calculations.
	 *
	 * @var bool
	 */
	private $applying_prices = false;

	/**
	 * Trusted offer prices keyed by the exact cart product object.
	 *
	 * @var array<int,string>
	 */
	private $enforced_cart_prices = array();

	/**
	 * Effective base prices keyed by cart item key for synchronized rendering.
	 *
	 * @var array<string,float>
	 */
	private $effective_cart_base_prices = array();

	/**
	 * Whether the narrow cart-price filters were registered in this request.
	 *
	 * @var bool
	 */
	private $price_filters_registered = false;

	/**
	 * Native offer context validated during the current add-to-cart request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $validated_native_offer = null;

	/**
	 * Whether a native product form added a trusted BumpMint line.
	 *
	 * @var bool
	 */
	private $native_offer_added = false;

	/**
	 * Prevents duplicate native context fields in customized product templates.
	 *
	 * @var bool
	 */
	private $native_fields_rendered = false;

	/**
	 * Prevents duplicate native offer notices in customized product templates.
	 *
	 * @var bool
	 */
	private $native_notice_rendered = false;

	/**
	 * Whether native request data has already been parsed in this request.
	 *
	 * @var bool
	 */
	private $native_request_context_loaded = false;

	/**
	 * Cached native request context.
	 *
	 * @var array<string,mixed>|null
	 */
	private $native_request_context = null;

	/**
	 * Registers checkout, AJAX, and pricing hooks.
	 */
	public function __construct( $analytics = null ) {
		$this->analytics = is_a( $analytics, 'BumpMint_Analytics' ) ? $analytics : null;
		$this->register_position_hooks();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'render_native_offer_notice' ) );
		add_action( 'woocommerce_before_booking_form', array( $this, 'render_native_offer_notice' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_native_offer_fields' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_native_offer_add_to_cart' ), PHP_INT_MAX, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_native_offer_cart_item_data' ), PHP_INT_MAX, 4 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_native_offer_to_checkout' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_item_quantity' ), 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'restore_effective_cart_item_prices' ), 1 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_item_prices' ), PHP_INT_MAX );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'refresh_before_payment_fragment' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'acknowledge_removed_bump_items' ), 99 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_bump_eligibility_before_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_audit_meta' ), 10, 4 );
	}

	/**
	 * Registers every configured position without hard-coding render calls.
	 */
	private function register_position_hooks() {
		foreach ( BumpMint_Positions::get_positions() as $position_key => $position ) {
			if ( empty( $position['hook'] ) ) {
				continue;
			}

			$priority = isset( $position['priority'] ) ? (int) $position['priority'] : 20;
			add_action(
				$position['hook'],
				function () use ( $position_key ) {
					$this->render_position( $position_key );
				},
				$priority
			);
		}
	}

	/**
	 * Loads frontend assets only on the classic checkout.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		if ( $this->is_checkout_block_page() ) {
			return;
		}

		wp_enqueue_style(
			'bumpmint-frontend',
			BUMPMINT_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			BUMPMINT_VERSION
		);

		wp_enqueue_script(
			'bumpmint-frontend',
			BUMPMINT_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			BUMPMINT_VERSION,
			true
		);

		$analytics_config = BumpMint_Analytics::get_frontend_config();
		wp_localize_script(
			'bumpmint-frontend',
			'bumpmintFrontend',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'genericError'   => __( 'The offer could not be updated. Please try again.', 'bumpmint-order-bump-for-woocommerce' ),
				'analyticsAction' => $analytics_config['action'],
				'analyticsNonce'  => $analytics_config['nonce'],
			)
		);
	}

	/**
	 * Determines whether the assigned checkout page uses Checkout Blocks.
	 *
	 * @return bool
	 */
	private function is_checkout_block_page() {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return false;
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );
		return $checkout_page_id > 0 && has_block( 'woocommerce/checkout', $checkout_page_id );
	}

	/**
	 * Returns whether an offered product must use its native product form.
	 *
	 * WooCommerce Bookings calculates availability, booking data, and the final
	 * price from that form. The filter leaves a small integration point for other
	 * product types that follow WooCommerce's standard add-to-cart hooks.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public function product_requires_native_configuration( $product ) {
		$requires_configuration = is_a( $product, 'WC_Product' ) && $product->is_type( 'booking' );

		return (bool) apply_filters(
			'bumpmint_product_requires_native_configuration',
			$requires_configuration,
			$product
		);
	}

	/**
	 * Returns whether a native configurable product can share the current cart.
	 *
	 * Bookings that require confirmation have a dedicated checkout flow and
	 * cannot safely be offered alongside the products that triggered a bump.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public function product_supports_native_configuration( $product ) {
		if ( ! $this->product_requires_native_configuration( $product ) ) {
			return false;
		}

		$supported = true;
		if ( $product->is_type( 'booking' ) ) {
			$supported = ! BumpMint_Rules::booking_requires_confirmation( $product );
		}

		return (bool) apply_filters(
			'bumpmint_product_supports_native_configuration',
			$supported,
			$product
		);
	}

	/**
	 * Builds a session-bound link to the product's native configuration form.
	 *
	 * @param array      $rule    Saved rule.
	 * @param WC_Product $product Offered product.
	 * @return string
	 */
	public function get_native_configuration_url( array $rule, $product ) {
		if ( ! $this->product_supports_native_configuration( $product ) ) {
			return '';
		}

		$rule_id    = isset( $rule['id'] ) ? sanitize_text_field( (string) $rule['id'] ) : '';
		$product_id = absint( $product->get_id() );
		$permalink  = $product->get_permalink();
		if ( '' === $rule_id || ! $product_id || ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		$url = add_query_arg(
			array(
				self::NATIVE_RULE_PARAM    => $rule_id,
				self::NATIVE_PRODUCT_PARAM => $product_id,
				self::NATIVE_NONCE_PARAM   => wp_create_nonce( $this->get_native_nonce_action( $rule_id, $product_id ) ),
			),
			$permalink
		);

		$url = apply_filters( 'bumpmint_native_configuration_url', $url, $rule, $product );

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Returns the call-to-action used for native product configuration.
	 *
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	private function get_native_configuration_label( $product ) {
		$label = $product->is_type( 'booking' )
			? __( 'Choose date', 'bumpmint-order-bump-for-woocommerce' )
			: __( 'Choose options', 'bumpmint-order-bump-for-woocommerce' );

		return (string) apply_filters( 'bumpmint_native_configuration_label', $label, $product );
	}

	/**
	 * Returns the helper text shown before a configurable offer is selected.
	 *
	 * @return string
	 */
	private function get_native_configuration_hint() {
		return __( 'Complete the required options on the product page and you will be automatically redirected back to this checkout page.', 'bumpmint-order-bump-for-woocommerce' );
	}

	/**
	 * Renders guidance above a native product form reached from a bump card.
	 *
	 * @return void
	 */
	public function render_native_offer_notice() {
		if ( $this->native_notice_rendered ) {
			return;
		}

		$context = $this->get_native_request_context();
		if ( ! $this->native_context_matches_current_product( $context ) ) {
			return;
		}

		$this->native_notice_rendered = true;
		$result                       = $this->validate_native_offer_context( $context, WC()->cart );
		$message                      = is_wp_error( $result )
			? $result->get_error_message()
			: __( 'Complete the required options below to add this order bump. You will return to checkout automatically.', 'bumpmint-order-bump-for-woocommerce' );
		if ( ! is_wp_error( $result ) ) {
			$this->validated_native_offer = $result;
		}

		wc_print_notice( $message, is_wp_error( $result ) ? 'error' : 'notice' );
	}

	/**
	 * Keeps the trusted bump context inside the native add-to-cart form.
	 *
	 * WooCommerce Bookings exposes this standard hook in its product form, so
	 * every booking field and its own validation remain authoritative.
	 *
	 * @return void
	 */
	public function render_native_offer_fields() {
		if ( $this->native_fields_rendered ) {
			return;
		}

		$context = $this->get_native_request_context();
		if ( ! $this->native_context_matches_current_product( $context ) ) {
			return;
		}

		$result = $this->validated_native_offer;
		if ( ! is_array( $result ) ) {
			$result = $this->validate_native_offer_context( $context, WC()->cart );
		}
		if ( is_wp_error( $result ) ) {
			return;
		}

		$this->validated_native_offer = $result;
		$this->native_fields_rendered = true;
		?>
		<input type="hidden" name="<?php echo esc_attr( self::NATIVE_RULE_PARAM ); ?>" value="<?php echo esc_attr( $context['rule_id'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( self::NATIVE_PRODUCT_PARAM ); ?>" value="<?php echo esc_attr( $context['product_id'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( self::NATIVE_NONCE_PARAM ); ?>" value="<?php echo esc_attr( $context['nonce'] ); ?>" />
		<?php
	}

	/**
	 * Revalidates a native order bump before the product creates cart data.
	 *
	 * @param bool  $passed         Current validation state.
	 * @param int   $product_id     Parent product ID.
	 * @param mixed $quantity       Requested quantity.
	 * @param int   $variation_id   Variation ID, when present.
	 * @param array $variations     Variation attributes.
	 * @param array $cart_item_data Existing cart data.
	 * @return bool
	 */
	public function validate_native_offer_add_to_cart( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		unset( $variations, $cart_item_data );

		if ( ! $passed ) {
			return false;
		}

		$context = $this->get_native_request_context();
		if ( null === $context ) {
			return $passed;
		}

		$requested_product_id = $variation_id ? absint( $variation_id ) : absint( $product_id );
		$result               = $this->validate_native_offer_context( $context, WC()->cart, $requested_product_id );
		if ( is_wp_error( $result ) ) {
			$this->validated_native_offer = null;
			wc_add_notice( $result->get_error_message(), 'error' );
			return false;
		}

		if ( $this->has_discount_quantity_limit( $result['rule'], $requested_product_id ) ) {
			$max_quantity = max( 1, absint( $result['rule']['max_quantity'] ) );
			if ( is_numeric( $quantity ) && (float) $quantity > $max_quantity ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: product name, 2: maximum discounted quantity. */
						__( 'The discounted quantity for %1$s is limited to %2$d.', 'bumpmint-order-bump-for-woocommerce' ),
						wp_strip_all_tags( $result['product']->get_name() ),
						$max_quantity
					),
					'error'
				);
				$this->validated_native_offer = null;
				return false;
			}
		}

		$this->validated_native_offer = $result;
		return true;
	}

	/**
	 * Attaches BumpMint metadata after the native product extension builds data.
	 *
	 * Registered at the last priority so WooCommerce Bookings has already
	 * calculated and stored its trusted booking cost.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Parent product ID.
	 * @param int   $variation_id   Variation ID, when present.
	 * @param mixed $quantity       Requested quantity.
	 * @return array
	 */
	public function add_native_offer_cart_item_data( $cart_item_data, $product_id, $variation_id = 0, $quantity = 1 ) {
		unset( $quantity );

		$context = $this->get_native_request_context();
		if ( null === $context ) {
			return $cart_item_data;
		}

		$requested_product_id = $variation_id ? absint( $variation_id ) : absint( $product_id );
		$result               = $this->validated_native_offer;
		if (
			! is_array( $result )
			|| empty( $result['product'] )
			|| (int) $result['product']->get_id() !== $requested_product_id
		) {
			$result = $this->validate_native_offer_context( $context, WC()->cart, $requested_product_id );
		}

		if ( is_wp_error( $result ) ) {
			return $cart_item_data;
		}

		$cart_item_data = array_merge(
			$cart_item_data,
			$this->build_offer_cart_item_data( $result['rule'], WC()->cart )
		);

		if (
			isset( $cart_item_data['booking']['_cost'] )
			&& is_numeric( $cart_item_data['booking']['_cost'] )
		) {
			$cart_item_data['bumpmint_native_base_price'] = wc_format_decimal(
				max( 0.0, (float) $cart_item_data['booking']['_cost'] )
			);
		}

		$this->native_offer_added = true;
		return $cart_item_data;
	}

	/**
	 * Returns a customer to checkout after a native order bump is configured.
	 *
	 * @param string     $url     Existing redirect URL.
	 * @param WC_Product $product Product that was added.
	 * @return string
	 */
	public function redirect_native_offer_to_checkout( $url, $product = null ) {
		unset( $product );

		if ( ! $this->native_offer_added ) {
			return $url;
		}

		$this->native_offer_added = false;
		return wc_get_checkout_url();
	}

	/**
	 * Reads native offer data from the current GET or POST request.
	 *
	 * @return array{rule_id:string,product_id:int,nonce:string}|null
	 */
	private function get_native_request_context() {
		if ( $this->native_request_context_loaded ) {
			return $this->native_request_context;
		}

		$this->native_request_context_loaded = true;
		$post_data = is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce travels inside this data and is verified before the context is trusted.
		$get_data  = is_array( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The session-bound nonce is verified before the context is trusted.
		$source    = array();
		if (
			isset( $post_data[ self::NATIVE_RULE_PARAM ] )
			|| isset( $post_data[ self::NATIVE_PRODUCT_PARAM ] )
			|| isset( $post_data[ self::NATIVE_NONCE_PARAM ] )
		) {
			$source = $post_data;
		} elseif (
			isset( $get_data[ self::NATIVE_RULE_PARAM ] )
			|| isset( $get_data[ self::NATIVE_PRODUCT_PARAM ] )
			|| isset( $get_data[ self::NATIVE_NONCE_PARAM ] )
		) {
			$source = $get_data;
		}

		if ( empty( $source ) ) {
			return null;
		}

		$this->native_request_context = array(
			'rule_id'    => isset( $source[ self::NATIVE_RULE_PARAM ] ) && is_scalar( $source[ self::NATIVE_RULE_PARAM ] )
				? sanitize_text_field( (string) $source[ self::NATIVE_RULE_PARAM ] )
				: '',
			'product_id' => isset( $source[ self::NATIVE_PRODUCT_PARAM ] ) && is_scalar( $source[ self::NATIVE_PRODUCT_PARAM ] )
				? absint( $source[ self::NATIVE_PRODUCT_PARAM ] )
				: 0,
			'nonce'      => isset( $source[ self::NATIVE_NONCE_PARAM ] ) && is_scalar( $source[ self::NATIVE_NONCE_PARAM ] )
				? sanitize_text_field( (string) $source[ self::NATIVE_NONCE_PARAM ] )
				: '',
		);

		return $this->native_request_context;
	}

	/**
	 * Checks a native URL context against the current single-product page.
	 *
	 * @param array<string,mixed>|null $context Native request context.
	 * @return bool
	 */
	private function native_context_matches_current_product( $context ) {
		global $product;

		return is_array( $context )
			&& ! empty( $context['rule_id'] )
			&& ! empty( $context['product_id'] )
			&& ! empty( $context['nonce'] )
			&& is_a( $product, 'WC_Product' )
			&& (int) $product->get_id() === (int) $context['product_id']
			&& (bool) wp_verify_nonce(
				$context['nonce'],
				$this->get_native_nonce_action( $context['rule_id'], $context['product_id'] )
			);
	}

	/**
	 * Validates a session-bound native offer against current trusted state.
	 *
	 * @param array<string,mixed> $context              Native request context.
	 * @param WC_Cart|null        $cart                 Current cart.
	 * @param int                 $requested_product_id Product being added.
	 * @return array{rule:array,product:WC_Product}|WP_Error
	 */
	private function validate_native_offer_context( array $context, $cart, $requested_product_id = 0 ) {
		$rule_id    = isset( $context['rule_id'] ) ? sanitize_text_field( (string) $context['rule_id'] ) : '';
		$product_id = isset( $context['product_id'] ) ? absint( $context['product_id'] ) : 0;
		$nonce      = isset( $context['nonce'] ) ? sanitize_text_field( (string) $context['nonce'] ) : '';

		if (
			'' === $rule_id
			|| ! $product_id
			|| ! wp_verify_nonce( $nonce, $this->get_native_nonce_action( $rule_id, $product_id ) )
		) {
			return new WP_Error(
				'bumpmint_invalid_native_offer',
				__( 'This order bump link is invalid or has expired. Return to checkout and try again.', 'bumpmint-order-bump-for-woocommerce' ),
				403
			);
		}

		if ( $requested_product_id && $requested_product_id !== $product_id ) {
			return new WP_Error(
				'bumpmint_native_product_mismatch',
				__( 'This product does not match the selected order bump.', 'bumpmint-order-bump-for-woocommerce' ),
				403
			);
		}

		$rule    = BumpMint_Rules::get_rule( $rule_id );
		$product = wc_get_product( $product_id );
		if ( ! $rule || 'active' !== $rule['status'] || ! $product ) {
			return new WP_Error(
				'bumpmint_native_offer_unavailable',
				__( 'This order bump is no longer available.', 'bumpmint-order-bump-for-woocommerce' ),
				404
			);
		}

		if ( ! in_array( $product_id, $rule['bump_product_ids'], true ) ) {
			return new WP_Error(
				'bumpmint_native_product_not_in_offer',
				__( 'This product is not part of the selected offer.', 'bumpmint-order-bump-for-woocommerce' ),
				404
			);
		}

		if ( ! $this->product_requires_native_configuration( $product ) ) {
			return new WP_Error(
				'bumpmint_native_configuration_not_required',
				__( 'This product does not require the native configuration flow.', 'bumpmint-order-bump-for-woocommerce' ),
				400
			);
		}

		if ( ! $this->product_supports_native_configuration( $product ) ) {
			return new WP_Error(
				'bumpmint_native_configuration_unsupported',
				__( 'This configurable product cannot be purchased together with the current cart.', 'bumpmint-order-bump-for-woocommerce' ),
				409
			);
		}

		if ( ! $cart ) {
			return new WP_Error(
				'bumpmint_cart_unavailable',
				__( 'The cart is unavailable.', 'bumpmint-order-bump-for-woocommerce' ),
				400
			);
		}

		if ( $this->find_cart_item_key_for_rule( $rule_id, $product_id, $cart ) ) {
			return new WP_Error(
				'bumpmint_native_offer_already_selected',
				__( 'This order bump is already in the cart.', 'bumpmint-order-bump-for-woocommerce' ),
				409
			);
		}

		if ( ! empty( $rule['hide_if_in_cart'] ) && $this->cart_contains_product( $product_id, $cart ) ) {
			return new WP_Error(
				'bumpmint_product_in_cart',
				__( 'This product is already in the cart.', 'bumpmint-order-bump-for-woocommerce' ),
				409
			);
		}

		if ( ! BumpMint_Conditions::matches( $rule, $cart ) ) {
			return new WP_Error(
				'bumpmint_rule_not_matched',
				__( 'The cart no longer meets this offer’s display rule.', 'bumpmint-order-bump-for-woocommerce' ),
				409
			);
		}

		if ( ! $this->is_product_available( $product, $cart ) ) {
			return new WP_Error(
				'bumpmint_product_unavailable',
				__( 'This product is unavailable or out of stock.', 'bumpmint-order-bump-for-woocommerce' ),
				409
			);
		}

		return array(
			'rule'    => $rule,
			'product' => $product,
		);
	}

	/**
	 * Binds native offer nonces to the current WooCommerce customer session.
	 *
	 * @param string $rule_id    Rule ID.
	 * @param int    $product_id Product ID.
	 * @return string
	 */
	private function get_native_nonce_action( $rule_id, $product_id ) {
		$customer_id = '';
		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$customer_id = (string) WC()->session->get_customer_id();
		}

		return self::NATIVE_NONCE_ACTION . '|' . (string) $rule_id . '|' . absint( $product_id ) . '|' . $customer_id;
	}

	/**
	 * Renders all applicable rules assigned to one checkout position.
	 *
	 * @param string $position_key Position key.
	 */
	public function render_position( $position_key ) {
		$position = BumpMint_Positions::get_position( $position_key );
		if ( ! $position ) {
			return;
		}

		$is_table_position    = isset( $position['context'] ) && 'table' === $position['context'];
		$is_persistent_anchor = BumpMint_Positions::BEFORE_PAYMENT === $position_key && ! $is_table_position;
		$applicable_rules     = $this->get_applicable_offers( WC()->cart, $position_key );

		if ( empty( $applicable_rules ) && ! $is_persistent_anchor ) {
			return;
		}

		if ( $is_table_position ) {
			echo '<tr class="bumpmint-checkout-table-row"><td colspan="2">';
		}

		echo '<div class="bumpmint-position bumpmint-position-' . esc_attr( $position_key ) . '"' . ( empty( $applicable_rules ) ? ' hidden' : '' ) . '>';
		foreach ( $applicable_rules as $offer ) {
			$this->render_card(
				$offer['rule'],
				$offer['product'],
				$offer['cart_item_key']
			);
		}
		echo '</div>';

		if ( $is_table_position ) {
			echo '</td></tr>';
		}
	}

	/**
	 * Returns offers that are currently eligible for one checkout surface.
	 *
	 * Passing no position returns offers from every configured classic position.
	 * The Checkout Block uses that mode because its public extension API exposes a
	 * single stable order-summary location rather than classic PHP hook positions.
	 *
	 * @param WC_Cart|null $cart         Cart instance.
	 * @param string|null  $position_key Optional classic checkout position.
	 * @return array<int,array{rule:array,product:WC_Product,cart_item_key:string|null}>
	 */
	public function get_applicable_offers( $cart, $position_key = null ) {
		$applicable_offers = array();
		if ( ! $cart || $cart->is_empty() ) {
			return $applicable_offers;
		}

		foreach ( BumpMint_Rules::get_rules() as $rule ) {
			if ( 'active' !== $rule['status'] || ( null !== $position_key && $position_key !== $rule['position'] ) ) {
				continue;
			}

			if ( ! BumpMint_Conditions::matches( $rule, $cart ) ) {
				continue;
			}

			foreach ( $rule['bump_product_ids'] as $bump_product_id ) {
				$product = wc_get_product( $bump_product_id );
				if ( ! $product ) {
					continue;
				}

				$cart_item_key = $this->find_cart_item_key_for_rule( $rule['id'], $product->get_id(), $cart );
				if (
					! $cart_item_key
					&& $this->product_requires_native_configuration( $product )
					&& ! $this->product_supports_native_configuration( $product )
				) {
					continue;
				}

				if ( ! $cart_item_key && ! empty( $rule['hide_if_in_cart'] ) && $this->cart_contains_product( $product->get_id(), $cart ) ) {
					continue;
				}

				if ( ! $cart_item_key && ! $this->is_product_available( $product, $cart ) ) {
					continue;
				}

				$applicable_offers[] = array(
					'rule'          => $rule,
					'product'       => $product,
					'cart_item_key' => $cart_item_key,
				);
			}
		}

		return $applicable_offers;
	}

	/**
	 * Keeps the before-payment position synchronized during checkout AJAX updates.
	 *
	 * WooCommerce renders that hook outside its payment fragment and skips the
	 * hook during AJAX requests, so BumpMint supplies a small stable fragment.
	 *
	 * @param array $fragments Checkout fragments.
	 * @return array
	 */
	public function refresh_before_payment_fragment( $fragments ) {
		if ( ! is_array( $fragments ) || ! BumpMint_Positions::get_position( BumpMint_Positions::BEFORE_PAYMENT ) ) {
			return $fragments;
		}

		ob_start();
		$this->render_position( BumpMint_Positions::BEFORE_PAYMENT );
		$fragment = (string) ob_get_clean();

		if ( '' !== $fragment ) {
			$fragments['.bumpmint-position-' . BumpMint_Positions::BEFORE_PAYMENT] = $fragment;
		}

		return $fragments;
	}

	/**
	 * Renders one order bump card.
	 *
	 * @param array       $rule          Rule data.
	 * @param WC_Product  $product       Offered product.
	 * @param string|null $cart_item_key Matching cart item key when selected.
	 */
	private function render_card( array $rule, $product, $cart_item_key ) {
		$view_data              = $this->get_offer_view_data( $rule, $product, $cart_item_key );
		$rule_id                = $view_data['rule_id'];
		$product_id             = $view_data['product_id'];
		$is_checked             = $view_data['selected'];
		$requires_configuration = ! $is_checked && ! empty( $view_data['requires_configuration'] );
		$input_id               = 'bumpmint-bump-' . sanitize_html_class( $rule_id . '-' . $product_id );
		$image_id               = ! empty( $rule['image_id'] ) ? absint( $rule['image_id'] ) : 0;
		?>
		<div
			id="<?php echo esc_attr( $input_id ); ?>-card"
			class="bumpmint-bump-box<?php echo $requires_configuration ? ' is-configurable' : ''; ?>"
			data-bumpmint-rule-id="<?php echo esc_attr( $rule_id ); ?>">
			<div class="bumpmint-badge"><?php echo esc_html( $view_data['badge'] ); ?></div>
			<div class="bumpmint-bump-content">
				<?php if ( $requires_configuration ) : ?>
					<div class="bumpmint-bump-label">
				<?php else : ?>
					<label for="<?php echo esc_attr( $input_id ); ?>" class="bumpmint-bump-label">
				<?php endif; ?>
				<?php if ( ! $requires_configuration ) : ?>
					<input
						type="checkbox"
						class="bumpmint-checkbox"
						id="<?php echo esc_attr( $input_id ); ?>"
						data-rule-id="<?php echo esc_attr( $rule_id ); ?>"
						data-product-id="<?php echo esc_attr( $product_id ); ?>"
						<?php checked( $is_checked, true ); ?>
					/>
				<?php endif; ?>
					<?php
					if ( $image_id ) {
						echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'class' => 'bumpmint-bump-image' ) );
					} else {
						echo $product->get_image( 'thumbnail', array( 'class' => 'bumpmint-bump-image' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes product image HTML.
					}
					?>
					<span class="bumpmint-bump-copy">
						<span class="bumpmint-bump-title"><?php echo esc_html( $view_data['title'] ); ?></span>
						<span class="bumpmint-bump-price"><?php echo wp_kses_post( $view_data['price_html'] ); ?></span>
					</span>
				<?php if ( $requires_configuration ) : ?>
					</div>
				<?php else : ?>
					</label>
				<?php endif; ?>

				<p class="bumpmint-bump-description"><?php echo esc_html( $view_data['description'] ); ?></p>
				<?php if ( $requires_configuration && ! empty( $view_data['configuration_url'] ) ) : ?>
					<div class="bumpmint-native-actions">
						<a class="bumpmint-configure-button" href="<?php echo esc_url( $view_data['configuration_url'] ); ?>">
							<?php echo esc_html( $view_data['configuration_label'] ); ?>
						</a>
						<p class="bumpmint-native-hint"><?php echo esc_html( $view_data['configuration_hint'] ); ?></p>
					</div>
				<?php endif; ?>
				<p class="bumpmint-bump-feedback" role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Builds presentation data shared by classic and block checkout cards.
	 *
	 * @param array       $rule          Rule data.
	 * @param WC_Product  $product       Offered product.
	 * @param string|null $cart_item_key Matching cart item key when selected.
	 * @return array<string,mixed>
	 */
	public function get_offer_view_data( array $rule, $product, $cart_item_key ) {
		$is_selected            = is_string( $cart_item_key ) && '' !== $cart_item_key;
		$requires_configuration = $this->product_requires_native_configuration( $product );
		$configuration_url      = ! $is_selected && $requires_configuration
			? $this->get_native_configuration_url( $rule, $product )
			: '';
		$effective_base_price   = $is_selected && array_key_exists( $cart_item_key, $this->effective_cart_base_prices )
			? $this->effective_cart_base_prices[ $cart_item_key ]
			: null;
		$prices               = BumpMint_Rules::calculate_prices( $rule, $product, $effective_base_price );
		$offer_display_price  = wc_get_price_to_display( $product, array( 'price' => $prices['offer'] ) );
		$formatted_price      = wp_strip_all_tags( wc_price( $offer_display_price ) );
		$product_name         = $product->get_name();

		$title = ! empty( $rule['offer_title'] )
			? $rule['offer_title']
			/* translators: %s: product name. */
			: sprintf( __( 'Add %s to your order', 'bumpmint-order-bump-for-woocommerce' ), $product_name );
		$title = $this->replace_placeholders( $title, $product_name, $formatted_price );

		$description = ! empty( $rule['description'] )
			? $rule['description']
			/* translators: 1: product name, 2: formatted price. */
			: sprintf( __( 'Add %1$s for only %2$s.', 'bumpmint-order-bump-for-woocommerce' ), $product_name, $formatted_price );
		$description = $this->replace_placeholders( $description, $product_name, $formatted_price );

		$image_id  = ! empty( $rule['image_id'] ) ? absint( $rule['image_id'] ) : $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		$image_alt = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

		if ( ! $image_url ) {
			$image_url = wc_placeholder_img_src( 'thumbnail' );
		}
		if ( ! is_string( $image_alt ) || '' === trim( $image_alt ) ) {
			$image_alt = $product_name;
		}

		return array(
			'rule_id'                => (string) $rule['id'],
			'product_id'             => (int) $product->get_id(),
			'selected'               => $is_selected,
			'requires_configuration' => $requires_configuration,
			'configuration_url'      => $configuration_url,
			'configuration_label'    => $requires_configuration ? $this->get_native_configuration_label( $product ) : '',
			'configuration_hint'     => $requires_configuration ? $this->get_native_configuration_hint() : '',
			'badge'                  => BumpMint_Rules::get_badge_text( $rule, $product, $effective_base_price ),
			'title'                  => $title,
			'description'            => $description,
			'price_html'             => wp_kses_post( BumpMint_Rules::get_price_html( $rule, $product, $effective_base_price ) ),
			'image_url'              => $image_url,
			'image_alt'              => $image_alt,
		);
	}

	/**
	 * Handles secure add and remove requests.
	 *
	 * The browser sends a rule ID, one selected product ID, and the desired
	 * state. The product must belong to the saved rule; condition, stock, and
	 * price are resolved again from trusted server-side data.
	 */
	public function ajax_toggle() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$rule_id    = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$add        = isset( $_POST['add'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['add'] ) );
		$result     = $this->update_offer_selection( $rule_id, $product_id, $add, WC()->cart );

		if ( is_wp_error( $result ) ) {
			$status = absint( $result->get_error_data() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status ? $status : 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Applies a trusted offer selection to a WooCommerce cart.
	 *
	 * @param string       $rule_id    Saved rule ID.
	 * @param int          $product_id Offered product or variation ID.
	 * @param bool         $add        Whether the offer should be selected.
	 * @param WC_Cart|null $cart       Cart instance.
	 * @return array<string,string>|WP_Error
	 */
	public function update_offer_selection( $rule_id, $product_id, $add, $cart = null ) {
		if ( ! $cart ) {
			return new WP_Error( 'bumpmint_cart_unavailable', __( 'The cart is unavailable.', 'bumpmint-order-bump-for-woocommerce' ), 400 );
		}

		$rule_id    = sanitize_text_field( (string) $rule_id );
		$product_id = absint( $product_id );
		$add        = (bool) $add;
		$rule       = $rule_id ? BumpMint_Rules::get_rule( $rule_id ) : null;

		if ( ! $rule || 'active' !== $rule['status'] ) {
			return new WP_Error( 'bumpmint_offer_unavailable', __( 'This offer is no longer available.', 'bumpmint-order-bump-for-woocommerce' ), 404 );
		}

		if ( ! $product_id || ! in_array( $product_id, $rule['bump_product_ids'], true ) ) {
			return new WP_Error( 'bumpmint_product_not_in_offer', __( 'This product is not part of the selected offer.', 'bumpmint-order-bump-for-woocommerce' ), 404 );
		}

		$existing_key = $this->find_cart_item_key_for_rule( $rule_id, $product_id, $cart );

		if ( ! $add ) {
			if ( $existing_key ) {
				$cart->remove_cart_item( $existing_key );
			}
			$cart->calculate_totals();
			return array( 'cart_hash' => $cart->get_cart_hash() );
		}

		if ( ! empty( $rule['hide_if_in_cart'] ) && ! $existing_key && $this->cart_contains_product( $product_id, $cart ) ) {
			return new WP_Error( 'bumpmint_product_in_cart', __( 'This product is already in the cart.', 'bumpmint-order-bump-for-woocommerce' ), 409 );
		}

		if ( ! BumpMint_Conditions::matches( $rule, $cart ) ) {
			return new WP_Error( 'bumpmint_rule_not_matched', __( 'The cart no longer meets this offer’s display rule.', 'bumpmint-order-bump-for-woocommerce' ), 409 );
		}

		if ( $existing_key ) {
			if ( $this->analytics ) {
				$this->analytics->record_offer_view( $rule, $cart );
			}
			return array( 'cart_hash' => $cart->get_cart_hash() );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $this->is_product_available( $product, $cart ) ) {
			return new WP_Error( 'bumpmint_product_unavailable', __( 'This product is unavailable or out of stock.', 'bumpmint-order-bump-for-woocommerce' ), 409 );
		}

		if ( $this->product_requires_native_configuration( $product ) ) {
			return new WP_Error(
				'bumpmint_configuration_required',
				__( 'Choose the required options on the product page before adding this offer.', 'bumpmint-order-bump-for-woocommerce' ),
				409
			);
		}

		$cart_item_data = $this->build_offer_cart_item_data( $rule, $cart );
		$notices_before = wc_get_notices();

		if ( $product->is_type( 'variation' ) ) {
			$cart_item_key = $cart->add_to_cart(
				$product->get_parent_id(),
				1,
				$product->get_id(),
				$product->get_variation_attributes(),
				$cart_item_data
			);
		} else {
			$cart_item_key = $cart->add_to_cart( $product->get_id(), 1, 0, array(), $cart_item_data );
		}

		if ( ! $cart_item_key ) {
			$message = $this->get_new_cart_error_message( $notices_before );
			wc_set_notices( $notices_before );
			return new WP_Error( 'bumpmint_add_to_cart_failed', $message, 409 );
		}

		$cart->calculate_totals();
		return array( 'cart_hash' => $cart->get_cart_hash() );
	}

	/**
	 * Builds trusted cart metadata shared by direct and native offer additions.
	 *
	 * @param array        $rule Saved rule.
	 * @param WC_Cart|null $cart Current cart.
	 * @return array<string,mixed>
	 */
	private function build_offer_cart_item_data( array $rule, $cart ) {
		$cart_item_data = array(
			'bumpmint_rule_id' => $rule['id'],
			'bumpmint_source'  => 'order_bump',
		);

		if ( $this->analytics ) {
			$attribution = $this->analytics->record_offer_view( $rule, $cart );
			if ( ! empty( $attribution ) ) {
				$cart_item_data['bumpmint_analytics_session_hash'] = $attribution['session_hash'];
				$cart_item_data['bumpmint_analytics_tracking_id']  = $attribution['tracking_id'];
				$cart_item_data['bumpmint_analytics_view_id']      = $attribution['view_id'];
			}
		}

		return $cart_item_data;
	}

	/**
	 * Rejects cart updates above a rule's discounted quantity limit.
	 *
	 * @param bool   $passed        Whether WooCommerce validation has passed.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $cart_item     Cart item data.
	 * @param mixed  $quantity      Requested quantity.
	 * @return bool
	 */
	public function validate_cart_item_quantity( $passed, $cart_item_key, $cart_item, $quantity ) {
		unset( $cart_item_key );

		if ( ! $passed || empty( $cart_item['bumpmint_rule_id'] ) ) {
			return $passed;
		}

		$rule       = BumpMint_Rules::get_rule( $cart_item['bumpmint_rule_id'] );
		$product_id = $this->get_cart_item_product_id( $cart_item );
		if ( ! $this->has_discount_quantity_limit( $rule, $product_id ) ) {
			return $passed;
		}

		$max_quantity = max( 1, absint( $rule['max_quantity'] ) );
		if ( (float) $quantity <= $max_quantity ) {
			return $passed;
		}

		$product_name = ! empty( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' )
			? wp_strip_all_tags( $cart_item['data']->get_name() )
			: __( 'this order bump product', 'bumpmint-order-bump-for-woocommerce' );

		wc_add_notice(
			sprintf(
				/* translators: 1: product name, 2: maximum discounted quantity. */
				__( 'The discounted quantity for %1$s is limited to %2$d.', 'bumpmint-order-bump-for-woocommerce' ),
				$product_name,
				$max_quantity
			),
			'error'
		);

		return false;
	}

	/**
	 * Restores current WooCommerce prices before other cart pricing hooks run.
	 *
	 * This prevents repeated totals calculations from discounting a previous
	 * BumpMint offer price again while allowing legitimate pricing extensions to
	 * adjust the restored cart product before BumpMint runs last.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return void
	 */
	public function restore_effective_cart_item_prices( $cart ) {
		if ( ! $cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		$this->enforced_cart_prices = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['bumpmint_rule_id'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}

			$product_id        = $this->get_cart_item_product_id( $cart_item );
			$canonical_product = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $canonical_product ) {
				continue;
			}

			$effective_price = $this->get_cart_item_restore_price( $cart_item, $canonical_product );

			if ( is_numeric( $effective_price ) ) {
				$cart_item['data']->set_price( wc_format_decimal( max( 0.0, (float) $effective_price ) ) );
			}
		}
	}

	/**
	 * Removes ineligible BumpMint lines and recalculates eligible prices.
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function apply_cart_item_prices( $cart ) {
		if ( $this->applying_prices || ! $cart ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$this->applying_prices = true;
		$this->effective_cart_base_prices = array();

		try {
			$rules_by_id = array();
			foreach ( BumpMint_Rules::get_rules() as $saved_rule ) {
				$rules_by_id[ (string) $saved_rule['id'] ] = $saved_rule;
			}

			// A removed BumpMint line can invalidate another product-based rule.
			$remaining_passes = max( 1, count( $cart->get_cart() ) );
			do {
				$items_to_remove  = array();
				$rule_eligibility = array();

				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
					if ( empty( $cart_item['bumpmint_rule_id'] ) ) {
						continue;
					}

					$rule_id           = (string) $cart_item['bumpmint_rule_id'];
					$rule              = isset( $rules_by_id[ $rule_id ] ) ? $rules_by_id[ $rule_id ] : null;
					$product_id        = $this->get_cart_item_product_id( $cart_item );
					$canonical_product = $product_id ? wc_get_product( $product_id ) : false;

					if ( $rule && ! array_key_exists( $rule_id, $rule_eligibility ) ) {
						$rule_eligibility[ $rule_id ] = 'active' === $rule['status'] && BumpMint_Conditions::matches( $rule, $cart );
					}

					$is_eligible = $rule
						&& in_array( $product_id, $rule['bump_product_ids'], true )
						&& ! empty( $rule_eligibility[ $rule_id ] )
						&& $canonical_product
						&& (
							! $this->product_requires_native_configuration( $canonical_product )
							|| $this->product_supports_native_configuration( $canonical_product )
						);

					if ( ! $is_eligible ) {
						$items_to_remove[ $cart_item_key ] = array(
							'item' => $cart_item,
							'rule' => $rule,
						);
					}
				}

				if ( empty( $items_to_remove ) ) {
					break;
				}

				$removed_any = false;
				foreach ( $items_to_remove as $cart_item_key => $removal ) {
					if ( $cart->remove_cart_item( $cart_item_key ) ) {
						$this->queue_removed_bump_notice( $removal['rule'], $removal['item'] );
						$removed_any = true;
					}
				}

				if ( ! $removed_any ) {
					break;
				}

				--$remaining_passes;
			} while ( $remaining_passes > 0 );

			$rule_eligibility = array();
			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( empty( $cart_item['bumpmint_rule_id'] ) || empty( $cart_item['data'] ) ) {
					continue;
				}

				$rule_id           = (string) $cart_item['bumpmint_rule_id'];
				$rule              = isset( $rules_by_id[ $rule_id ] ) ? $rules_by_id[ $rule_id ] : null;
				$cart_product_id   = $this->get_cart_item_product_id( $cart_item );
				$canonical_product = wc_get_product( $cart_product_id );

				if ( $rule && ! array_key_exists( $rule_id, $rule_eligibility ) ) {
					$rule_eligibility[ $rule_id ] = 'active' === $rule['status'] && BumpMint_Conditions::matches( $rule, $cart );
				}

				$is_eligible = $rule
					&& $canonical_product
					&& in_array( $cart_product_id, $rule['bump_product_ids'], true )
					&& ! empty( $rule_eligibility[ $rule_id ] )
					&& (
						! $this->product_requires_native_configuration( $canonical_product )
						|| $this->product_supports_native_configuration( $canonical_product )
					);

				if ( ! $is_eligible ) {
					if ( $canonical_product ) {
						$effective_price = $this->get_cart_item_restore_price( $cart_item, $canonical_product );
						if ( is_numeric( $effective_price ) ) {
							$cart_item['data']->set_price( wc_format_decimal( max( 0.0, (float) $effective_price ) ) );
						}
					}
					continue;
				}

				if ( $this->has_discount_quantity_limit( $rule, $cart_product_id ) ) {
					$max_quantity = max( 1, absint( $rule['max_quantity'] ) );
					if ( isset( $cart_item['quantity'] ) && (float) $cart_item['quantity'] > $max_quantity ) {
						$cart->set_quantity( $cart_item_key, $max_quantity, false );
					}
				}

				$effective_price = $cart_item['data']->get_price();
				$prices          = BumpMint_Rules::calculate_prices( $rule, $canonical_product, $effective_price );
				$offer_price     = wc_format_decimal( $prices['offer'] );
				$cart_item['data']->set_price( $offer_price );

				$this->effective_cart_base_prices[ $cart_item_key ] = $prices['base'];
				$this->enforced_cart_prices[ spl_object_id( $cart_item['data'] ) ] = $offer_price;
				$this->register_price_enforcement_filters();
			}
		} finally {
			$this->applying_prices = false;
		}
	}

	/**
	 * Returns the effective price to restore before third-party pricing hooks run.
	 *
	 * Native booking forms calculate a trusted cost from the selected date,
	 * duration, resources, and persons. That cost must remain the base for the
	 * BumpMint discount instead of being replaced by the product's catalog price.
	 *
	 * @param array      $cart_item         Cart item data.
	 * @param WC_Product $canonical_product Fresh product object.
	 * @return float|string|null
	 */
	private function get_cart_item_restore_price( array $cart_item, $canonical_product ) {
		if ( isset( $cart_item['booking']['_cost'] ) && is_numeric( $cart_item['booking']['_cost'] ) ) {
			return wc_format_decimal( max( 0.0, (float) $cart_item['booking']['_cost'] ) );
		}

		if ( isset( $cart_item['bumpmint_native_base_price'] ) && is_numeric( $cart_item['bumpmint_native_base_price'] ) ) {
			return wc_format_decimal( max( 0.0, (float) $cart_item['bumpmint_native_base_price'] ) );
		}

		$effective_price = $canonical_product->get_price();
		if ( ! is_numeric( $effective_price ) ) {
			$effective_price = $canonical_product->get_price( 'edit' );
		}

		return is_numeric( $effective_price ) ? $effective_price : null;
	}

	/**
	 * Enforces a trusted offer price only for its exact cart product object.
	 *
	 * Legitimate product-price filters still determine the effective base before
	 * the BumpMint discount. This final filter prevents those same callbacks from
	 * replacing the already calculated offer price in WooCommerce totals.
	 *
	 * @param mixed      $price   Filtered WooCommerce price.
	 * @param WC_Product $product Product instance.
	 * @return mixed
	 */
	public function enforce_cart_item_offer_price( $price, $product ) {
		if ( ! is_object( $product ) ) {
			return $price;
		}

		$object_id = spl_object_id( $product );
		return array_key_exists( $object_id, $this->enforced_cart_prices )
			? $this->enforced_cart_prices[ $object_id ]
			: $price;
	}

	/**
	 * Registers price enforcement only after this request has an eligible bump.
	 *
	 * @return void
	 */
	private function register_price_enforcement_filters() {
		if ( $this->price_filters_registered ) {
			return;
		}

		add_filter( 'woocommerce_product_get_price', array( $this, 'enforce_cart_item_offer_price' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'enforce_cart_item_offer_price' ), PHP_INT_MAX, 2 );
		$this->price_filters_registered = true;
	}

	/**
	 * Clears removal notices after updated checkout totals have been rendered.
	 *
	 * @return void
	 */
	public function acknowledge_removed_bump_items() {
		if ( ! WC()->session || ! WC()->session->get( self::SESSION_PENDING_REMOVALS ) ) {
			return;
		}

		WC()->session->set( self::SESSION_PENDING_REMOVALS, null );
	}

	/**
	 * Stops checkout when an eligibility change has not yet been reviewed.
	 *
	 * WooCommerce refreshes the customer session and recalculates totals before
	 * this hook runs, so this is the final server-side eligibility check before
	 * an order is created.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Checkout validation errors.
	 */
	public function validate_bump_eligibility_before_checkout( $data, $errors ) {
		unset( $data );

		$messages = $this->add_pending_removal_errors( $errors );

		if ( ! empty( $messages ) ) {
			WC()->session->set( 'refresh_totals', true );
		}
	}

	/**
	 * Adds queued stale-offer messages to a checkout error collection.
	 *
	 * @param WP_Error $errors Error collection.
	 * @param bool     $clear  Whether to clear messages after reading them.
	 * @return string[]
	 */
	public function add_pending_removal_errors( $errors, $clear = false ) {
		if ( ! WC()->session || ! is_wp_error( $errors ) ) {
			return array();
		}

		$pending_removals = WC()->session->get( self::SESSION_PENDING_REMOVALS, array() );
		if ( ! is_array( $pending_removals ) || empty( $pending_removals ) ) {
			return array();
		}

		$messages = array();
		foreach ( $pending_removals as $message ) {
			if ( is_string( $message ) && '' !== trim( $message ) ) {
				$messages[] = trim( wp_strip_all_tags( $message ) );
			}
		}
		$messages = array_unique( $messages );

		foreach ( $messages as $message ) {
			$errors->add( 'bumpmint_offer_no_longer_eligible', $message );
		}

		if ( $clear && ! empty( $messages ) ) {
			WC()->session->set( self::SESSION_PENDING_REMOVALS, null );
		}

		return array_values( $messages );
	}

	/**
	 * Queues one checkout notice for an automatically removed BumpMint line.
	 *
	 * @param array|null $rule      Saved rule, when it still exists.
	 * @param array      $cart_item Removed cart item.
	 * @return void
	 */
	private function queue_removed_bump_notice( $rule, array $cart_item ) {
		if ( ! WC()->session ) {
			return;
		}

		$product_id   = $this->get_cart_item_product_id( $cart_item );
		$product_name = ! empty( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' )
			? wp_strip_all_tags( $cart_item['data']->get_name() )
			: __( 'this product', 'bumpmint-order-bump-for-woocommerce' );
		$message      = sprintf(
			/* translators: %s: product name. */
			__( 'The order bump for %s was removed because the offer is no longer eligible. Review the updated cart before placing the order.', 'bumpmint-order-bump-for-woocommerce' ),
			$product_name
		);

		if (
			is_array( $rule )
			&& 'active' === $rule['status']
			&& in_array( $product_id, $rule['bump_product_ids'], true )
		) {
			$requirement = $this->get_condition_requirement_message( $rule );
			if ( $requirement ) {
				$message .= ' ' . $requirement;
			}
		}

		$pending_removals = WC()->session->get( self::SESSION_PENDING_REMOVALS, array() );
		if ( ! is_array( $pending_removals ) ) {
			$pending_removals = array();
		}

		if ( ! in_array( $message, $pending_removals, true ) ) {
			$pending_removals[] = $message;
		}
		WC()->session->set( self::SESSION_PENDING_REMOVALS, $pending_removals );
	}

	/**
	 * Returns an actionable explanation of a rule's current requirement.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_condition_requirement_message( array $rule ) {
		if ( ! BumpMint_Conditions::matches_schedule( $rule ) ) {
			return __( 'This offer is currently outside its scheduled date or time.', 'bumpmint-order-bump-for-woocommerce' );
		}

		$type     = isset( $rule['condition_type'] ) ? $rule['condition_type'] : '';
		$operator = isset( $rule['condition_operator'] ) ? $rule['condition_operator'] : 'greater_than';

		if ( BumpMint_Conditions::CART_SUBTOTAL === $type ) {
			$amount = wp_strip_all_tags( wc_price( (float) $rule['condition_value'] ) );

			if ( 'less_than' === $operator ) {
				/* translators: %s: configured cart subtotal. */
				return sprintf( __( 'To receive the discount, reduce your cart subtotal to below %s.', 'bumpmint-order-bump-for-woocommerce' ), $amount );
			}

			/* translators: %s: configured cart subtotal. */
			return sprintf( __( 'To receive the discount, increase your cart subtotal to more than %s.', 'bumpmint-order-bump-for-woocommerce' ), $amount );
		}

		if ( BumpMint_Conditions::CART_QUANTITY === $type ) {
			$quantity = absint( $rule['condition_value'] );

			if ( 'less_than' === $operator ) {
				/* translators: %d: configured cart item quantity. */
				return sprintf( __( 'To receive the discount, reduce your cart to fewer than %d product units.', 'bumpmint-order-bump-for-woocommerce' ), $quantity );
			}

			/* translators: %d: configured cart item quantity. */
			return sprintf( __( 'To receive the discount, add more than %d product units to your cart.', 'bumpmint-order-bump-for-woocommerce' ), $quantity );
		}

		if ( BumpMint_Conditions::PRODUCT === $type ) {
			$product_names = array();
			foreach ( (array) $rule['condition_product_ids'] as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$product_names[] = wp_strip_all_tags( $product->get_name() );
				}
			}

			$category_names = array();
			if ( ! empty( $rule['condition_category_enabled'] ) ) {
				foreach ( (array) $rule['condition_category_ids'] as $category_id ) {
					$category = get_term( absint( $category_id ), 'product_cat' );
					if ( $category && ! is_wp_error( $category ) ) {
						$category_names[] = wp_strip_all_tags( $category->name );
					}
				}
			}

			if ( empty( $product_names ) && empty( $category_names ) ) {
				return '';
			}

			$product_list  = implode( ', ', $product_names );
			$category_list = implode( ', ', $category_names );
			$match_all     = isset( $rule['condition_match'] ) && 'all' === $rule['condition_match'];

			if ( ! empty( $product_names ) && ! empty( $category_names ) ) {
				if ( $match_all ) {
					/* translators: 1: list of required product names, 2: list of eligible category names. */
					return sprintf( __( 'To receive the discount, add all of these products to your cart: %1$s, or add a product from one of these categories: %2$s.', 'bumpmint-order-bump-for-woocommerce' ), $product_list, $category_list );
				}

				/* translators: 1: list of eligible product names, 2: list of eligible category names. */
				return sprintf( __( 'To receive the discount, add at least one of these products to your cart: %1$s, or add a product from one of these categories: %2$s.', 'bumpmint-order-bump-for-woocommerce' ), $product_list, $category_list );
			}

			if ( ! empty( $category_names ) ) {
				/* translators: %s: list of eligible category names. */
				return sprintf( __( 'To receive the discount, add a product from one of these categories: %s.', 'bumpmint-order-bump-for-woocommerce' ), $category_list );
			}

			if ( $match_all ) {
				/* translators: %s: list of required product names. */
				return sprintf( __( 'To receive the discount, add all of these products to your cart: %s.', 'bumpmint-order-bump-for-woocommerce' ), $product_list );
			}

			/* translators: %s: list of eligible product names. */
			return sprintf( __( 'To receive the discount, add at least one of these products to your cart: %s.', 'bumpmint-order-bump-for-woocommerce' ), $product_list );
		}

		return '';
	}

	/**
	 * Adds private audit metadata to order items created from an order bump.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order.
	 */
	public function add_order_item_audit_meta( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );

		if ( empty( $values['bumpmint_rule_id'] ) ) {
			return;
		}

		$rule = BumpMint_Rules::get_rule( $values['bumpmint_rule_id'] );
		if ( $rule ) {
			$item->add_meta_data( '_bumpmint_rule_id', $rule['id'], true );
			$item->add_meta_data( '_bumpmint_rule_name', $rule['name'], true );
			if ( $this->analytics ) {
				$this->analytics->add_order_item_attribution_meta( $item, $values );
			}
		}
	}

	/**
	 * Returns whether the offered product can be added safely.
	 *
	 * @param WC_Product $product Product.
	 * @param WC_Cart    $cart    Cart.
	 * @return bool
	 */
	private function is_product_available( $product, $cart ) {
		if ( ! $product->exists() || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return false;
		}

		if ( method_exists( $product, 'has_enough_stock' ) && ! $product->has_enough_stock( 1 ) ) {
			return false;
		}

		if ( $product->is_sold_individually() && $this->cart_contains_product( $product->get_id(), $cart ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns errors added by the latest WooCommerce cart operation.
	 *
	 * Links are removed because feedback is rendered as plain text inside the
	 * order bump card. Existing notices are excluded and restored by the caller.
	 *
	 * @param array $notices_before Notices queued before the cart operation.
	 * @return string
	 */
	private function get_new_cart_error_message( array $notices_before ) {
		$errors_before = isset( $notices_before['error'] ) && is_array( $notices_before['error'] )
			? $notices_before['error']
			: array();
		$errors_after  = wc_get_notices( 'error' );
		$new_errors    = array_slice( $errors_after, count( $errors_before ) );
		$messages      = array();

		foreach ( $new_errors as $error ) {
			$raw_message = is_array( $error ) && isset( $error['notice'] )
				? $error['notice']
				: $error;
			if ( ! is_string( $raw_message ) ) {
				continue;
			}

			$plain_message = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', '', $raw_message );
			$plain_message = html_entity_decode(
				wp_strip_all_tags( (string) $plain_message ),
				ENT_QUOTES,
				get_bloginfo( 'charset' )
			);
			$plain_message = trim( wp_strip_all_tags( $plain_message ) );
			if ( '' !== $plain_message ) {
				$messages[] = $plain_message;
			}
		}

		if ( ! empty( $messages ) ) {
			return implode( ' ', array_unique( $messages ) );
		}

		return __( 'WooCommerce could not add this product to the cart.', 'bumpmint-order-bump-for-woocommerce' );
	}

	/**
	 * Finds the cart line created by a specific rule.
	 *
	 * @param string  $rule_id    Rule ID.
	 * @param int     $product_id Exact product or variation ID.
	 * @param WC_Cart $cart       Cart instance.
	 * @return string|null
	 */
	private function find_cart_item_key_for_rule( $rule_id, $product_id, $cart ) {
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if (
				! empty( $cart_item['bumpmint_rule_id'] ) &&
				hash_equals( (string) $cart_item['bumpmint_rule_id'], (string) $rule_id ) &&
				$this->get_cart_item_product_id( $cart_item ) === (int) $product_id
			) {
				return $cart_item_key;
			}
		}

		return null;
	}

	/**
	 * Checks whether a product or variation already exists in the cart.
	 *
	 * @param int     $product_id Product or variation ID.
	 * @param WC_Cart $cart       Cart instance.
	 * @return bool
	 */
	private function cart_contains_product( $product_id, $cart ) {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( $this->get_cart_item_product_id( $cart_item ) === (int) $product_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the exact product or variation ID represented by a cart item.
	 *
	 * @param array $cart_item Cart item.
	 * @return int
	 */
	private function get_cart_item_product_id( array $cart_item ) {
		if ( ! empty( $cart_item['variation_id'] ) ) {
			return absint( $cart_item['variation_id'] );
		}

		return isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
	}

	/**
	 * Checks whether a saved rule limits the discounted quantity for a product.
	 *
	 * @param array|null $rule       Rule data.
	 * @param int        $product_id Exact product or variation ID.
	 * @return bool
	 */
	private function has_discount_quantity_limit( $rule, $product_id ) {
		return is_array( $rule )
			&& 'active' === $rule['status']
			&& ! empty( $rule['discount_enabled'] )
			&& in_array( (int) $product_id, $rule['bump_product_ids'], true );
	}

	/**
	 * Replaces all supported localized placeholders.
	 *
	 * @param string $text         Source text.
	 * @param string $product_name Product name.
	 * @param string $price        Formatted price.
	 * @return string
	 */
	private function replace_placeholders( $text, $product_name, $price ) {
		return str_replace(
			array( '{product}', '{produto}', '{producto}', '{price}', '{preco}', '{precio}' ),
			array( $product_name, $product_name, $product_name, $price, $price, $price ),
			$text
		);
	}
}
