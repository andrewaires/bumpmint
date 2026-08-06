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
	const SESSION_PENDING_REMOVALS = 'bumpmint_pending_offer_removals';

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
	 * Registers checkout, AJAX, and pricing hooks.
	 */
	public function __construct() {
		$this->register_position_hooks();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
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
		$position = BumpMint_Positions::get_position( $position_key );
		if ( ! $position ) {
			return;
		}

		$cart                 = WC()->cart;
		$is_table_position    = isset( $position['context'] ) && 'table' === $position['context'];
		$is_persistent_anchor = BumpMint_Positions::BEFORE_PAYMENT === $position_key && ! $is_table_position;
		$applicable_rules     = array();
		if ( $cart && ! $cart->is_empty() ) {
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
		}

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
		$rule_id              = $rule['id'];
		$product_id           = $product->get_id();
		$is_checked           = is_string( $cart_item_key ) && '' !== $cart_item_key;
		$effective_base_price = $is_checked && array_key_exists( $cart_item_key, $this->effective_cart_base_prices )
			? $this->effective_cart_base_prices[ $cart_item_key ]
			: null;
		$input_id             = 'bumpmint-bump-' . sanitize_html_class( $rule_id . '-' . $product_id );
		$prices               = BumpMint_Rules::calculate_prices( $rule, $product, $effective_base_price );

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

		$badge_text = BumpMint_Rules::get_badge_text( $rule, $product, $effective_base_price );
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
						<span class="bumpmint-bump-price"><?php echo wp_kses_post( BumpMint_Rules::get_price_html( $rule, $product, $effective_base_price ) ); ?></span>
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

			$effective_price = $canonical_product->get_price();
			if ( ! is_numeric( $effective_price ) ) {
				$effective_price = $canonical_product->get_price( 'edit' );
			}

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
						&& $canonical_product;

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
					&& ! empty( $rule_eligibility[ $rule_id ] );

				if ( ! $is_eligible ) {
					if ( $canonical_product ) {
						$effective_price = $canonical_product->get_price();
						if ( ! is_numeric( $effective_price ) ) {
							$effective_price = $canonical_product->get_price( 'edit' );
						}
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

		if ( ! WC()->session || ! is_wp_error( $errors ) ) {
			return;
		}

		$pending_removals = WC()->session->get( self::SESSION_PENDING_REMOVALS, array() );
		if ( ! is_array( $pending_removals ) || empty( $pending_removals ) ) {
			return;
		}

		$messages = array();
		foreach ( $pending_removals as $message ) {
			if ( is_string( $message ) && '' !== trim( $message ) ) {
				$messages[] = trim( wp_strip_all_tags( $message ) );
			}
		}

		foreach ( array_unique( $messages ) as $message ) {
			$errors->add( 'bumpmint_offer_no_longer_eligible', $message );
		}

		if ( ! empty( $messages ) ) {
			WC()->session->set( 'refresh_totals', true );
		}
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

			if ( empty( $product_names ) ) {
				return '';
			}

			$product_list = implode( ', ', $product_names );
			if ( isset( $rule['condition_match'] ) && 'all' === $rule['condition_match'] ) {
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
