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

	const AUDIENCE_EVERYONE   = 'everyone';
	const AUDIENCE_LOGGED_IN  = 'logged_in';
	const AUDIENCE_LOGGED_OUT = 'logged_out';
	const AUDIENCE_ROLES      = 'roles';

	const LOCATION_ALL      = 'all';
	const LOCATION_SPECIFIC = 'specific';

	const LOCATION_INCLUDE = 'include';
	const LOCATION_EXCLUDE = 'exclude';

	const LOCATION_UNKNOWN_HIDE = 'hide';
	const LOCATION_UNKNOWN_SHOW = 'show';

	const COUPON_NONE     = 'none';
	const COUPON_ANY      = 'any';
	const COUPON_SPECIFIC = 'specific';

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
	 * Returns the supported customer audience labels.
	 *
	 * @return array<string,string>
	 */
	public static function get_customer_audiences() {
		return array(
			self::AUDIENCE_EVERYONE   => __( 'Everyone', 'bumpmint-order-bump-for-woocommerce' ),
			self::AUDIENCE_LOGGED_IN  => __( 'Logged-in users', 'bumpmint-order-bump-for-woocommerce' ),
			self::AUDIENCE_LOGGED_OUT => __( 'Logged-out visitors', 'bumpmint-order-bump-for-woocommerce' ),
			self::AUDIENCE_ROLES      => __( 'Specific user roles', 'bumpmint-order-bump-for-woocommerce' ),
		);
	}

	/**
	 * Returns the supported location targeting modes.
	 *
	 * @return array<string,string>
	 */
	public static function get_location_modes() {
		return array(
			self::LOCATION_ALL      => __( 'All locations', 'bumpmint-order-bump-for-woocommerce' ),
			self::LOCATION_SPECIFIC => __( 'Specific locations', 'bumpmint-order-bump-for-woocommerce' ),
		);
	}

	/**
	 * Returns the supported location matching operators.
	 *
	 * @return array<string,string>
	 */
	public static function get_location_operators() {
		return array(
			self::LOCATION_INCLUDE => __( 'Available in selected locations', 'bumpmint-order-bump-for-woocommerce' ),
			self::LOCATION_EXCLUDE => __( 'Unavailable in selected locations', 'bumpmint-order-bump-for-woocommerce' ),
		);
	}

	/**
	 * Returns the supported behaviors for an unresolved customer location.
	 *
	 * @return array<string,string>
	 */
	public static function get_location_unknown_behaviors() {
		return array(
			self::LOCATION_UNKNOWN_HIDE => __( 'Hide the offer until location is known', 'bumpmint-order-bump-for-woocommerce' ),
			self::LOCATION_UNKNOWN_SHOW => __( 'Show the offer', 'bumpmint-order-bump-for-woocommerce' ),
		);
	}

	/**
	 * Returns the supported coupon targeting modes.
	 *
	 * @return array<string,string>
	 */
	public static function get_coupon_modes() {
		return array(
			self::COUPON_NONE     => __( 'No coupon required', 'bumpmint-order-bump-for-woocommerce' ),
			self::COUPON_ANY      => __( 'Any applied coupon', 'bumpmint-order-bump-for-woocommerce' ),
			self::COUPON_SPECIFIC => __( 'Specific coupons', 'bumpmint-order-bump-for-woocommerce' ),
		);
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

		if ( ! self::matches_customer( $rule ) ) {
			return false;
		}

		if ( ! self::matches_location( $rule ) ) {
			return false;
		}

		if ( ! self::matches_coupon( $rule, $cart ) ) {
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
	 * Evaluates login state and WordPress role targeting.
	 *
	 * A user with any selected role matches a role-targeted rule. The current
	 * WordPress user object is request-cached, so this adds no order-history or
	 * catalog query to checkout evaluation.
	 *
	 * @param array        $rule Rule data.
	 * @param WP_User|null $user Optional user for deterministic tests.
	 * @return bool
	 */
	public static function matches_customer( array $rule, $user = null ) {
		$audience = isset( $rule['customer_audience'] ) && is_scalar( $rule['customer_audience'] )
			? sanitize_key( (string) $rule['customer_audience'] )
			: self::AUDIENCE_EVERYONE;

		if ( self::AUDIENCE_EVERYONE === $audience ) {
			return true;
		}

		$user         = $user instanceof WP_User ? $user : wp_get_current_user();
		$is_logged_in = $user instanceof WP_User && $user->exists();

		if ( self::AUDIENCE_LOGGED_IN === $audience ) {
			return $is_logged_in;
		}

		if ( self::AUDIENCE_LOGGED_OUT === $audience ) {
			return ! $is_logged_in;
		}

		if ( self::AUDIENCE_ROLES !== $audience || ! $is_logged_in ) {
			return false;
		}

		$selected_roles = isset( $rule['customer_role_slugs'] )
			? self::sanitize_role_slugs( $rule['customer_role_slugs'] )
			: array();
		$user_roles     = self::sanitize_role_slugs( $user->roles );

		return ! empty( $selected_roles ) && ! empty( array_intersect( $selected_roles, $user_roles ) );
	}

	/**
	 * Evaluates country and optional state/region targeting.
	 *
	 * WooCommerce's current shipping location is preferred. Billing is used
	 * when shipping is unavailable, and can supply a missing state when both
	 * addresses use the same country. No address or IP data is persisted here.
	 *
	 * @param array            $rule     Rule data.
	 * @param WC_Customer|null $customer Optional customer for deterministic tests.
	 * @return bool
	 */
	public static function matches_location( array $rule, $customer = null ) {
		$mode = isset( $rule['location_mode'] ) && is_scalar( $rule['location_mode'] )
			? sanitize_key( (string) $rule['location_mode'] )
			: self::LOCATION_ALL;

		if ( self::LOCATION_ALL === $mode ) {
			return true;
		}

		if ( self::LOCATION_SPECIFIC !== $mode ) {
			return false;
		}

		$operator = isset( $rule['location_operator'] ) && is_scalar( $rule['location_operator'] )
			? sanitize_key( (string) $rule['location_operator'] )
			: self::LOCATION_INCLUDE;
		if ( ! in_array( $operator, array( self::LOCATION_INCLUDE, self::LOCATION_EXCLUDE ), true ) ) {
			return false;
		}

		$unknown_behavior = isset( $rule['location_unknown_behavior'] ) && is_scalar( $rule['location_unknown_behavior'] )
			? sanitize_key( (string) $rule['location_unknown_behavior'] )
			: self::LOCATION_UNKNOWN_HIDE;
		if ( ! in_array( $unknown_behavior, array( self::LOCATION_UNKNOWN_HIDE, self::LOCATION_UNKNOWN_SHOW ), true ) ) {
			$unknown_behavior = self::LOCATION_UNKNOWN_HIDE;
		}

		$country_codes = isset( $rule['location_country_codes'] )
			? self::sanitize_location_country_codes( $rule['location_country_codes'] )
			: array();
		$state_codes   = isset( $rule['location_state_codes'] )
			? self::sanitize_location_state_codes( $rule['location_state_codes'], $country_codes )
			: array();

		if ( empty( $country_codes ) ) {
			return false;
		}

		$location = self::get_customer_location( $customer );
		if ( '' === $location['country'] ) {
			return self::LOCATION_UNKNOWN_SHOW === $unknown_behavior;
		}

		$matches_selected = in_array( $location['country'], $country_codes, true );
		if ( $matches_selected ) {
			$country_states = array();
			$prefix         = $location['country'] . ':';
			foreach ( $state_codes as $state_code ) {
				if ( 0 === strpos( $state_code, $prefix ) ) {
					$country_states[] = $state_code;
				}
			}

			if ( ! empty( $country_states ) ) {
				if ( '' === $location['state'] ) {
					return self::LOCATION_UNKNOWN_SHOW === $unknown_behavior;
				}

				$matches_selected = in_array( $prefix . $location['state'], $country_states, true );
			}
		}

		return self::LOCATION_INCLUDE === $operator ? $matches_selected : ! $matches_selected;
	}

	/**
	 * Evaluates applied WooCommerce coupons for a rule.
	 *
	 * Specific coupon rules store stable coupon IDs. Applied coupon codes are
	 * resolved through WooCommerce's cached data-store helper so renamed coupons
	 * keep matching and browser-provided coupon data is never trusted directly.
	 *
	 * @param array        $rule Rule data.
	 * @param WC_Cart|null $cart Optional cart instance.
	 * @return bool
	 */
	public static function matches_coupon( array $rule, $cart = null ) {
		$mode = isset( $rule['coupon_mode'] ) && is_scalar( $rule['coupon_mode'] )
			? sanitize_key( (string) $rule['coupon_mode'] )
			: self::COUPON_NONE;

		if ( self::COUPON_NONE === $mode ) {
			return true;
		}

		if ( ! in_array( $mode, array( self::COUPON_ANY, self::COUPON_SPECIFIC ), true ) ) {
			return false;
		}

		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart instanceof WC_Cart ) {
			return false;
		}

		$applied_codes = array();
		foreach ( (array) $cart->get_applied_coupons() as $coupon_code ) {
			if ( ! is_scalar( $coupon_code ) ) {
				continue;
			}

			$coupon_code = wc_sanitize_coupon_code( (string) $coupon_code );
			if ( '' !== $coupon_code ) {
				$applied_codes[] = $coupon_code;
			}
		}
		if ( self::COUPON_ANY === $mode ) {
			return ! empty( $applied_codes );
		}

		$selected_ids = isset( $rule['coupon_ids'] )
			? self::sanitize_coupon_ids( $rule['coupon_ids'] )
			: array();
		if ( empty( $selected_ids ) || empty( $applied_codes ) ) {
			return false;
		}

		foreach ( $applied_codes as $coupon_code ) {
			$coupon_id = wc_get_coupon_id_by_code( (string) $coupon_code );
			if ( $coupon_id && in_array( $coupon_id, $selected_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves the current WooCommerce customer location without persisting it.
	 *
	 * @param WC_Customer|null $customer Optional customer object.
	 * @return array{country:string,state:string}
	 */
	private static function get_customer_location( $customer = null ) {
		if ( ! $customer instanceof WC_Customer ) {
			$customer = function_exists( 'WC' ) && WC()->customer instanceof WC_Customer
				? WC()->customer
				: null;
		}

		if ( ! $customer ) {
			return array(
				'country' => '',
				'state'   => '',
			);
		}

		$shipping_country = self::sanitize_location_country_code( $customer->get_shipping_country() );
		$shipping_state   = self::sanitize_location_state_code( $customer->get_shipping_state() );
		$billing_country  = self::sanitize_location_country_code( $customer->get_billing_country() );
		$billing_state    = self::sanitize_location_state_code( $customer->get_billing_state() );

		if ( '' !== $shipping_country ) {
			if ( '' === $shipping_state && $shipping_country === $billing_country ) {
				$shipping_state = $billing_state;
			}

			return array(
				'country' => $shipping_country,
				'state'   => $shipping_state,
			);
		}

		return array(
			'country' => $billing_country,
			'state'   => $billing_state,
		);
	}

	/**
	 * Sanitizes ISO-style country codes.
	 *
	 * @param mixed $country_codes Country code or list of country codes.
	 * @return array
	 */
	private static function sanitize_location_country_codes( $country_codes ) {
		$country_codes = is_array( $country_codes ) ? $country_codes : array( $country_codes );
		$sanitized     = array();

		foreach ( $country_codes as $country_code ) {
			$country_code = self::sanitize_location_country_code( $country_code );
			if ( '' !== $country_code ) {
				$sanitized[] = $country_code;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitizes composite country:state targeting codes.
	 *
	 * @param mixed $state_codes   State code or list of state codes.
	 * @param array $country_codes Allowed country codes for this rule.
	 * @return array
	 */
	private static function sanitize_location_state_codes( $state_codes, array $country_codes ) {
		$state_codes = is_array( $state_codes ) ? $state_codes : array( $state_codes );
		$sanitized   = array();

		foreach ( $state_codes as $state_code ) {
			if ( ! is_scalar( $state_code ) ) {
				continue;
			}

			$parts   = explode( ':', (string) $state_code, 2 );
			$country = self::sanitize_location_country_code( isset( $parts[0] ) ? $parts[0] : '' );
			$state   = self::sanitize_location_state_code( isset( $parts[1] ) ? $parts[1] : '' );
			if ( '' !== $country && '' !== $state && in_array( $country, $country_codes, true ) ) {
				$sanitized[] = $country . ':' . $state;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitizes one country code.
	 *
	 * @param mixed $country_code Country code.
	 * @return string
	 */
	private static function sanitize_location_country_code( $country_code ) {
		if ( ! is_scalar( $country_code ) ) {
			return '';
		}

		$country_code = strtoupper( trim( (string) $country_code ) );
		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}

	/**
	 * Sanitizes one state or region code.
	 *
	 * @param mixed $state_code State code.
	 * @return string
	 */
	private static function sanitize_location_state_code( $state_code ) {
		if ( ! is_scalar( $state_code ) ) {
			return '';
		}

		return substr( sanitize_key( (string) $state_code ), 0, 40 );
	}

	/**
	 * Sanitizes persisted coupon IDs for runtime comparison.
	 *
	 * @param mixed $coupon_ids Coupon ID or list of IDs.
	 * @return array
	 */
	private static function sanitize_coupon_ids( $coupon_ids ) {
		$coupon_ids = is_array( $coupon_ids ) ? $coupon_ids : array( $coupon_ids );
		$sanitized  = array();

		foreach ( $coupon_ids as $coupon_id ) {
			if ( ! is_scalar( $coupon_id ) ) {
				continue;
			}

			$coupon_id = absint( $coupon_id );
			if ( $coupon_id ) {
				$sanitized[] = $coupon_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitizes one role slug list for runtime comparison.
	 *
	 * @param mixed $role_slugs Role slug or list of role slugs.
	 * @return array
	 */
	private static function sanitize_role_slugs( $role_slugs ) {
		$role_slugs = is_array( $role_slugs ) ? $role_slugs : array( $role_slugs );
		$sanitized  = array();

		foreach ( $role_slugs as $role_slug ) {
			if ( ! is_scalar( $role_slug ) ) {
				continue;
			}

			$role_slug = sanitize_key( (string) $role_slug );
			if ( '' !== $role_slug ) {
				$sanitized[] = $role_slug;
			}
		}

		return array_values( array_unique( $sanitized ) );
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
