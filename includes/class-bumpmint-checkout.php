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

	const AJAX_ACTION = 'bumpmint_toggle_bump';
	const NONCE_ACTION = 'bumpmint_toggle_nonce';

	/**
	 * Prevents recursive price calculations.
	 *
	 * @var bool
	 */
	private $applying_prices = false;

	/**
	 * Registers checkout, AJAX, and pricing hooks.
	 */
	public function __construct() {
		$this->register_position_hooks();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_item_quantity' ), 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_item_prices' ), 20 );
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

		wp_localize_script(
			'bumpmint-frontend',
			'bumpmintFrontend',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'genericError' => __( 'The offer could not be updated. Please try again.', 'bumpmint-order-bump-for-woocommerce' ),
			)
		);
	}

	/**
	 * Renders all applicable rules assigned to one checkout position.
	 *
	 * @param string $position_key Position key.
	 */
	public function render_position( $position_key ) {
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$position = BumpMint_Positions::get_position( $position_key );
		if ( ! $position ) {
			return;
		}

		$applicable_rules = array();
		foreach ( BumpMint_Rules::get_rules() as $rule ) {
			if ( 'active' !== $rule['status'] || $position_key !== $rule['position'] ) {
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
				if ( ! $cart_item_key && ! empty( $rule['hide_if_in_cart'] ) && $this->cart_contains_product( $product->get_id(), $cart ) ) {
					continue;
				}

				if ( ! $cart_item_key && ! $this->is_product_available( $product, $cart ) ) {
					continue;
				}

				$applicable_rules[] = array(
					'rule'          => $rule,
					'product'       => $product,
					'cart_item_key' => $cart_item_key,
				);
			}
		}

		if ( empty( $applicable_rules ) ) {
			return;
		}

		$is_table_position = isset( $position['context'] ) && 'table' === $position['context'];
		if ( $is_table_position ) {
			echo '<tr class="bumpmint-checkout-table-row"><td colspan="2">';
		}

		echo '<div class="bumpmint-position bumpmint-position-' . esc_attr( $position_key ) . '">';
		foreach ( $applicable_rules as $offer ) {
			$this->render_card(
				$offer['rule'],
				$offer['product'],
				(bool) $offer['cart_item_key']
			);
		}
		echo '</div>';

		if ( $is_table_position ) {
			echo '</td></tr>';
		}
	}

	/**
	 * Renders one order bump card.
	 *
	 * @param array      $rule       Rule data.
	 * @param WC_Product $product    Offered product.
	 * @param bool       $is_checked Whether this rule's cart item exists.
	 */
	private function render_card( array $rule, $product, $is_checked ) {
		$rule_id    = $rule['id'];
		$product_id = $product->get_id();
		$input_id   = 'bumpmint-bump-' . sanitize_html_class( $rule_id . '-' . $product_id );
		$prices     = BumpMint_Rules::calculate_prices( $rule, $product );

		$offer_display_price = wc_get_price_to_display( $product, array( 'price' => $prices['offer'] ) );
		$formatted_price     = wp_strip_all_tags( wc_price( $offer_display_price ) );

		$title = ! empty( $rule['offer_title'] )
			? $rule['offer_title']
			/* translators: %s: product name. */
			: sprintf( __( 'Add %s to your order', 'bumpmint-order-bump-for-woocommerce' ), $product->get_name() );
		$title = $this->replace_placeholders( $title, $product->get_name(), $formatted_price );

		$description = ! empty( $rule['description'] )
			? $rule['description']
			/* translators: 1: product name, 2: formatted price. */
			: sprintf( __( 'Add %1$s for only %2$s.', 'bumpmint-order-bump-for-woocommerce' ), $product->get_name(), $formatted_price );
		$description = $this->replace_placeholders( $description, $product->get_name(), $formatted_price );

		$badge_text = BumpMint_Rules::get_badge_text( $rule, $product );
		$image_id   = ! empty( $rule['image_id'] ) ? absint( $rule['image_id'] ) : 0;
		?>
		<div id="<?php echo esc_attr( $input_id ); ?>-card" class="bumpmint-bump-box">
			<div class="bumpmint-badge"><?php echo esc_html( $badge_text ); ?></div>
			<div class="bumpmint-bump-content">
				<label for="<?php echo esc_attr( $input_id ); ?>" class="bumpmint-bump-label">
					<input
						type="checkbox"
						class="bumpmint-checkbox"
						id="<?php echo esc_attr( $input_id ); ?>"
						data-rule-id="<?php echo esc_attr( $rule_id ); ?>"
						data-product-id="<?php echo esc_attr( $product_id ); ?>"
						<?php checked( $is_checked, true ); ?>
					/>
					<?php
					if ( $image_id ) {
						echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'class' => 'bumpmint-bump-image' ) );
					} else {
						echo $product->get_image( 'thumbnail', array( 'class' => 'bumpmint-bump-image' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes product image HTML.
					}
					?>
					<span class="bumpmint-bump-copy">
						<span class="bumpmint-bump-title"><?php echo esc_html( $title ); ?></span>
						<span class="bumpmint-bump-price"><?php echo wp_kses_post( BumpMint_Rules::get_price_html( $rule, $product ) ); ?></span>
					</span>
				</label>

				<p class="bumpmint-bump-description"><?php echo esc_html( $description ); ?></p>
				<p class="bumpmint-bump-feedback" role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
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

		$cart = WC()->cart;
		if ( ! $cart ) {
			wp_send_json_error( array( 'message' => __( 'The cart is unavailable.', 'bumpmint-order-bump-for-woocommerce' ) ), 400 );
		}

		$rule_id    = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$add        = isset( $_POST['add'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['add'] ) );
		$rule       = $rule_id ? BumpMint_Rules::get_rule( $rule_id ) : null;

		if ( ! $rule || 'active' !== $rule['status'] ) {
			wp_send_json_error( array( 'message' => __( 'This offer is no longer available.', 'bumpmint-order-bump-for-woocommerce' ) ), 404 );
		}

		if ( ! $product_id || ! in_array( $product_id, $rule['bump_product_ids'], true ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is not part of the selected offer.', 'bumpmint-order-bump-for-woocommerce' ) ), 404 );
		}

		$existing_key = $this->find_cart_item_key_for_rule( $rule_id, $product_id, $cart );

		if ( ! $add ) {
			if ( $existing_key ) {
				$cart->remove_cart_item( $existing_key );
			}
			$cart->calculate_totals();
			wp_send_json_success( array( 'cart_hash' => $cart->get_cart_hash() ) );
		}

		if ( ! empty( $rule['hide_if_in_cart'] ) && ! $existing_key && $this->cart_contains_product( $product_id, $cart ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is already in the cart.', 'bumpmint-order-bump-for-woocommerce' ) ), 409 );
		}

		if ( ! BumpMint_Conditions::matches( $rule, $cart ) ) {
			wp_send_json_error( array( 'message' => __( 'The cart no longer meets this offer’s display rule.', 'bumpmint-order-bump-for-woocommerce' ) ), 409 );
		}

		if ( $existing_key ) {
			wp_send_json_success( array( 'cart_hash' => $cart->get_cart_hash() ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $this->is_product_available( $product, $cart ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is unavailable or out of stock.', 'bumpmint-order-bump-for-woocommerce' ) ), 409 );
		}

		$cart_item_data = array(
			'bumpmint_rule_id' => $rule['id'],
			'bumpmint_source'  => 'order_bump',
		);
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
			wp_send_json_error( array( 'message' => $message ), 409 );
		}

		$cart->calculate_totals();
		wp_send_json_success( array( 'cart_hash' => $cart->get_cart_hash() ) );
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
	 * Recalculates every BumpMint price from canonical server-side data.
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

		try {
			$rules_by_id      = array();
			$rule_eligibility = array();
			foreach ( BumpMint_Rules::get_rules() as $saved_rule ) {
				$rules_by_id[ (string) $saved_rule['id'] ] = $saved_rule;
			}

			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( empty( $cart_item['bumpmint_rule_id'] ) || empty( $cart_item['data'] ) ) {
					continue;
				}

				$cart_product_id   = $this->get_cart_item_product_id( $cart_item );
				$canonical_product = wc_get_product( $cart_product_id );
				if ( ! $canonical_product ) {
					continue;
				}

				$rule_id       = (string) $cart_item['bumpmint_rule_id'];
				$rule          = isset( $rules_by_id[ $rule_id ] ) ? $rules_by_id[ $rule_id ] : null;
				if ( $this->has_discount_quantity_limit( $rule, $cart_product_id ) ) {
					$max_quantity = max( 1, absint( $rule['max_quantity'] ) );
					if ( isset( $cart_item['quantity'] ) && (float) $cart_item['quantity'] > $max_quantity ) {
						$cart->set_quantity( $cart_item_key, $max_quantity, false );
					}
				}

				if ( $rule && ! array_key_exists( $rule_id, $rule_eligibility ) ) {
					$rule_eligibility[ $rule_id ] = 'active' === $rule['status'] && BumpMint_Conditions::matches( $rule, $cart );
				}

				$prices        = $rule
					? BumpMint_Rules::calculate_prices( $rule, $canonical_product )
					: array(
						'base'  => (float) wc_format_decimal( max( 0.0, (float) $canonical_product->get_price( 'edit' ) ), wc_get_price_decimals() ),
						'offer' => 0.0,
					);
				$is_eligible   = $rule
					&& in_array( $cart_product_id, $rule['bump_product_ids'], true )
					&& $rule_eligibility[ $rule_id ];
				$trusted_price = $is_eligible ? $prices['offer'] : $prices['base'];

				$cart_item['data']->set_price( wc_format_decimal( $trusted_price ) );
			}
		} finally {
			$this->applying_prices = false;
		}
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
