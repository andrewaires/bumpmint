<?php
/**
 * Extensible order bump condition registry and evaluators.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the available conditions and evaluates them against the cart.
 */
class BumpMint_Conditions {

	const PRODUCT       = 'product';
	const ALWAYS        = 'always';
	const CART_SUBTOTAL = 'cart_subtotal';
	const CART_QUANTITY = 'cart_quantity';

	/**
	 * Returns all registered condition definitions.
	 *
	 * New condition types can be added with the
	 * `bumpmint_condition_definitions` filter. Each definition must provide
	 * a label and a callable evaluator.
	 *
	 * @return array
	 */
	public static function get_definitions() {
		$definitions = array(
			self::PRODUCT       => array(
				'label'     => __( 'Specific products in cart', 'bumpmint-order-bump-for-woocommerce' ),
				'evaluator' => array( __CLASS__, 'matches_product' ),
			),
			self::ALWAYS        => array(
				'label'     => __( 'Always show', 'bumpmint-order-bump-for-woocommerce' ),
				'evaluator' => array( __CLASS__, 'matches_always' ),
			),
			self::CART_SUBTOTAL => array(
				'label'     => __( 'Cart subtotal', 'bumpmint-order-bump-for-woocommerce' ),
				'evaluator' => array( __CLASS__, 'matches_cart_subtotal' ),
			),
			self::CART_QUANTITY => array(
				'label'     => __( 'Cart item quantity', 'bumpmint-order-bump-for-woocommerce' ),
				'evaluator' => array( __CLASS__, 'matches_cart_quantity' ),
			),
		);

		/**
		 * Filters the available order bump condition definitions.
		 *
		 * @param array $definitions Condition definitions.
		 */
		return apply_filters( 'bumpmint_condition_definitions', $definitions );
	}

	/**
	 * Returns one condition definition.
	 *
	 * @param string $type Condition type.
	 * @return array|null
	 */
	public static function get_definition( $type ) {
		$definitions = self::get_definitions();
		return isset( $definitions[ $type ] ) ? $definitions[ $type ] : null;
	}

	/**
	 * Returns the human-readable label for a condition.
	 *
	 * @param string $type Condition type.
	 * @return string
	 */
	public static function get_label( $type ) {
		$definition = self::get_definition( $type );
		return $definition ? $definition['label'] : __( 'Unknown condition', 'bumpmint-order-bump-for-woocommerce' );
	}

	/**
	 * Evaluates a rule against the current cart.
	 *
	 * @param array        $rule Rule data.
	 * @param WC_Cart|null $cart Cart instance.
	 * @return bool
	 */
	public static function matches( array $rule, $cart = null ) {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart ) {
			return false;
		}

		$type       = isset( $rule['condition_type'] ) ? $rule['condition_type'] : self::PRODUCT;
		$definition = self::get_definition( $type );

		if ( ! $definition || empty( $definition['evaluator'] ) || ! is_callable( $definition['evaluator'] ) ) {
			return false;
		}

		return (bool) call_user_func( $definition['evaluator'], $rule, $cart );
	}

	/**
	 * Matches when any or all configured products or variations are in the cart.
	 *
	 * @param array   $rule Rule data.
	 * @param WC_Cart $cart Cart instance.
	 * @return bool
	 */
	public static function matches_product( array $rule, $cart ) {
		$target_ids = isset( $rule['condition_product_ids'] ) ? (array) $rule['condition_product_ids'] : array();
		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );
		if ( empty( $target_ids ) ) {
			return false;
		}

		$cart_product_ids = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			$variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

			if ( $product_id ) {
				$cart_product_ids[ $product_id ] = true;
			}
			if ( $variation_id ) {
				$cart_product_ids[ $variation_id ] = true;
			}
		}

		if ( isset( $rule['condition_match'] ) && 'all' === $rule['condition_match'] ) {
			foreach ( $target_ids as $target_id ) {
				if ( ! isset( $cart_product_ids[ $target_id ] ) ) {
					return false;
				}
			}

			return true;
		}

		foreach ( $target_ids as $target_id ) {
			if ( isset( $cart_product_ids[ $target_id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Always matches.
	 *
	 * @return bool
	 */
	public static function matches_always() {
		return true;
	}

	/**
	 * Matches the configured cart subtotal comparison.
	 *
	 * BumpMint items are excluded so adding an offer cannot make its own
	 * condition switch on or off.
	 *
	 * @param array   $rule Rule data.
	 * @param WC_Cart $cart Cart instance.
	 * @return bool
	 */
	public static function matches_cart_subtotal( array $rule, $cart ) {
		return self::compare(
			self::get_cart_subtotal_without_bumps( $cart ),
			isset( $rule['condition_operator'] ) ? $rule['condition_operator'] : 'greater_than',
			isset( $rule['condition_value'] ) ? (float) $rule['condition_value'] : 0.0
		);
	}

	/**
	 * Matches the configured cart item quantity comparison.
	 *
	 * @param array   $rule Rule data.
	 * @param WC_Cart $cart Cart instance.
	 * @return bool
	 */
	public static function matches_cart_quantity( array $rule, $cart ) {
		return self::compare(
			self::get_cart_quantity_without_bumps( $cart ),
			isset( $rule['condition_operator'] ) ? $rule['condition_operator'] : 'greater_than',
			isset( $rule['condition_value'] ) ? (float) $rule['condition_value'] : 0.0
		);
	}

	/**
	 * Returns the cart subtotal excluding items added by BumpMint.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return float
	 */
	public static function get_cart_subtotal_without_bumps( $cart ) {
		$subtotal = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['bumpmint_rule_id'] ) ) {
				continue;
			}

			if ( isset( $cart_item['line_subtotal'] ) && is_numeric( $cart_item['line_subtotal'] ) ) {
				$subtotal += (float) $cart_item['line_subtotal'];
				continue;
			}

			$quantity = isset( $cart_item['quantity'] ) ? max( 0, (int) $cart_item['quantity'] ) : 0;
			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( $product && is_callable( array( $product, 'get_price' ) ) ) {
				$subtotal += (float) $product->get_price() * $quantity;
			}
		}

		return $subtotal;
	}

	/**
	 * Returns the total quantity excluding items added by BumpMint.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return int
	 */
	public static function get_cart_quantity_without_bumps( $cart ) {
		$quantity = 0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['bumpmint_rule_id'] ) ) {
				continue;
			}
			$quantity += isset( $cart_item['quantity'] ) ? max( 0, (int) $cart_item['quantity'] ) : 0;
		}

		return $quantity;
	}

	/**
	 * Performs a strict greater-than or less-than comparison.
	 *
	 * @param float  $actual   Actual cart value.
	 * @param string $operator Comparison operator.
	 * @param float  $expected Configured value.
	 * @return bool
	 */
	private static function compare( $actual, $operator, $expected ) {
		if ( 'less_than' === $operator ) {
			return $actual < $expected;
		}

		if ( 'greater_than' === $operator ) {
			return $actual > $expected;
		}

		return false;
	}
}
