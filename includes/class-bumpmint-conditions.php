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

	const SCHEDULE_ALWAYS   = 'always';
	const SCHEDULE_DATES    = 'dates';
	const SCHEDULE_WEEKDAYS = 'weekdays';

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
				'label'     => __( 'Specific products or categories in cart', 'bumpmint-order-bump-for-woocommerce' ),
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

		if ( ! self::matches_schedule( $rule ) ) {
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
	 * Evaluates a rule's availability schedule in the WordPress site timezone.
	 *
	 * An overnight range belongs to the day on which it starts. For example,
	 * Wednesday 22:00–02:00 remains active until Thursday at 02:00.
	 *
	 * @param array                  $rule Rule data.
	 * @param DateTimeInterface|null $now  Optional current time for deterministic tests.
	 * @return bool
	 */
	public static function matches_schedule( array $rule, $now = null ) {
		$schedule_type = isset( $rule['schedule_type'] ) ? sanitize_key( $rule['schedule_type'] ) : self::SCHEDULE_ALWAYS;
		if ( ! in_array( $schedule_type, array( self::SCHEDULE_ALWAYS, self::SCHEDULE_DATES, self::SCHEDULE_WEEKDAYS ), true ) ) {
			return false;
		}

		if ( $now instanceof DateTimeInterface ) {
			$current = new DateTimeImmutable( '@' . $now->getTimestamp() );
			$current = $current->setTimezone( wp_timezone() );
		} else {
			$current = current_datetime();
		}

		$schedule_day = $current;
		if ( ! empty( $rule['schedule_time_enabled'] ) ) {
			$start_minutes = self::schedule_time_to_minutes( isset( $rule['schedule_start_time'] ) ? $rule['schedule_start_time'] : '' );
			$end_minutes   = self::schedule_time_to_minutes( isset( $rule['schedule_end_time'] ) ? $rule['schedule_end_time'] : '' );

			if ( null === $start_minutes || null === $end_minutes || $start_minutes === $end_minutes ) {
				return false;
			}

			$current_minutes = ( (int) $current->format( 'G' ) * 60 ) + (int) $current->format( 'i' );
			if ( $start_minutes < $end_minutes ) {
				if ( $current_minutes < $start_minutes || $current_minutes >= $end_minutes ) {
					return false;
				}
			} elseif ( $current_minutes < $end_minutes ) {
				$schedule_day = $current->modify( '-1 day' );
			} elseif ( $current_minutes < $start_minutes ) {
				return false;
			}
		}

		if ( self::SCHEDULE_ALWAYS === $schedule_type ) {
			return true;
		}

		if ( self::SCHEDULE_DATES === $schedule_type ) {
			$dates = isset( $rule['schedule_dates'] ) ? (array) $rule['schedule_dates'] : array();
			return in_array( $schedule_day->format( 'Y-m-d' ), $dates, true );
		}

		$weekdays = isset( $rule['schedule_weekdays'] )
			? array_values( array_unique( array_map( 'absint', (array) $rule['schedule_weekdays'] ) ) )
			: array();

		return in_array( (int) $schedule_day->format( 'N' ), $weekdays, true );
	}

	/**
	 * Matches when any or all configured products or variations are in the cart,
	 * or when a cart product belongs to one of the selected categories.
	 *
	 * @param array   $rule Rule data.
	 * @param WC_Cart $cart Cart instance.
	 * @return bool
	 */
	public static function matches_product( array $rule, $cart ) {
		$target_ids = isset( $rule['condition_product_ids'] ) ? (array) $rule['condition_product_ids'] : array();
		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );
		$category_ids = ! empty( $rule['condition_category_enabled'] ) && isset( $rule['condition_category_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', (array) $rule['condition_category_ids'] ) ) ) )
			: array();

		$cart_product_ids = array();
		$category_matches  = false;
		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			$variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

			if ( $product_id ) {
				$cart_product_ids[ $product_id ] = true;
			}
			if ( $variation_id ) {
				$cart_product_ids[ $variation_id ] = true;
			}

			if ( ! empty( $category_ids ) && ! self::is_own_bump_line( $rule, $cart_item ) ) {
				$category_product_id = $product_id;
				if ( ! $category_product_id && $variation_id ) {
					$category_product_id = $variation_id;
				}

				if ( $category_product_id ) {
					$cart_product         = isset( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' )
						? $cart_item['data']
						: null;
					$product_category_ids = self::get_product_category_ids( $category_product_id, $cart_product );
					if ( array_intersect( $category_ids, $product_category_ids ) ) {
						$category_matches = true;
						break;
					}
				}
			}
		}

		$product_matches = false;
		if ( ! empty( $target_ids ) ) {
			if ( isset( $rule['condition_match'] ) && 'all' === $rule['condition_match'] ) {
				$product_matches = true;
				foreach ( $target_ids as $target_id ) {
					if ( ! isset( $cart_product_ids[ $target_id ] ) ) {
						$product_matches = false;
						break;
					}
				}
			} else {
				foreach ( $target_ids as $target_id ) {
					if ( isset( $cart_product_ids[ $target_id ] ) ) {
						$product_matches = true;
						break;
					}
				}
			}
		}

		return $product_matches || $category_matches;
	}

	/**
	 * Determines whether a cart line was created by this same order bump.
	 *
	 * @param array $rule      Current rule.
	 * @param array $cart_item Cart line.
	 * @return bool
	 */
	private static function is_own_bump_line( array $rule, array $cart_item ) {
		return ! empty( $rule['id'] )
			&& ! empty( $cart_item['bumpmint_rule_id'] )
			&& hash_equals( (string) $rule['id'], (string) $cart_item['bumpmint_rule_id'] );
	}

	/**
	 * Returns product category IDs, including ancestor categories.
	 *
	 * Variations inherit categories from their parent product.
	 *
	 * @param int             $product_id Product or variation ID.
	 * @param WC_Product|null $product    Product object already present in the cart line.
	 * @return array
	 */
	private static function get_product_category_ids( $product_id, $product = null ) {
		$product = is_a( $product, 'WC_Product' ) ? $product : wc_get_product( absint( $product_id ) );
		if ( ! $product ) {
			return array();
		}

		if ( $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}

		$terms = get_the_terms( absint( $product_id ), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$category_ids = array();
		foreach ( $terms as $term ) {
			$category_ids[] = (int) $term->term_id;
			$ancestors     = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
			foreach ( $ancestors as $ancestor_id ) {
				$category_ids[] = (int) $ancestor_id;
			}
		}

		return array_values( array_unique( array_filter( $category_ids ) ) );
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

			$quantity = isset( $cart_item['quantity'] ) ? max( 0, (int) $cart_item['quantity'] ) : 0;
			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

			// line_subtotal can still represent the previous totals calculation.
			if ( $quantity && is_a( $product, 'WC_Product' ) ) {
				$current_price = $product->get_price();
				if ( is_numeric( $current_price ) ) {
					$subtotal += (float) wc_get_price_excluding_tax(
						$product,
						array(
							'qty'   => $quantity,
							'price' => (float) $current_price,
						)
					);
					continue;
				}
			}

			if ( isset( $cart_item['line_subtotal'] ) && is_numeric( $cart_item['line_subtotal'] ) ) {
				$subtotal += (float) $cart_item['line_subtotal'];
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

	/**
	 * Converts a strict 24-hour time string into minutes after midnight.
	 *
	 * @param mixed $time Time in H:i format.
	 * @return int|null
	 */
	private static function schedule_time_to_minutes( $time ) {
		$time = is_string( $time ) ? trim( $time ) : '';
		if ( ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ) {
			return null;
		}

		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) );
		return ( $hours * 60 ) + $minutes;
	}
}
