<?php
/**
 * Order bump rules data layer.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, validates, migrates, and persists order bump rules.
 */
class BumpMint_Rules {

	const NAME_MAX_LENGTH    = 120;
	const BADGE_MAX_LENGTH   = 60;
	const MAX_SCHEDULE_DATES = 366;

	/**
	 * Returns all saved rules and migrates legacy data when necessary.
	 *
	 * @return array
	 */
	public static function get_rules() {
		$stored_rules = get_option( BUMPMINT_OPTION_KEY, array() );
		if ( ! is_array( $stored_rules ) ) {
			return array();
		}

		$rules           = array();
		$needs_migration = false;

		foreach ( $stored_rules as $stored_rule ) {
			if ( ! is_array( $stored_rule ) ) {
				$needs_migration = true;
				continue;
			}

			$normalized = self::normalize_rule( $stored_rule );
			$rules[]    = $normalized;

			if ( $normalized !== $stored_rule ) {
				$needs_migration = true;
			}
		}

		if ( $needs_migration ) {
			update_option( BUMPMINT_OPTION_KEY, $rules, false );
		}

		return $rules;
	}

	/**
	 * Finds one rule by ID.
	 *
	 * @param string $id Rule ID.
	 * @return array|null
	 */
	public static function get_rule( $id ) {
		foreach ( self::get_rules() as $rule ) {
			if ( isset( $rule['id'] ) && hash_equals( (string) $rule['id'], (string) $id ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Creates or updates a rule after strict server-side validation.
	 *
	 * @param string $id   Existing rule ID, or an empty string for a new rule.
	 * @param array  $data Unslashed form data.
	 * @return array|WP_Error
	 */
	public static function save_rule( $id, array $data ) {
		$existing_rule = $id ? self::get_rule( $id ) : null;
		if ( $id && ! $existing_rule ) {
			return new WP_Error(
				'bumpmint_rule_not_found',
				__( 'The order bump you tried to edit no longer exists.', 'bumpmint-order-bump-for-woocommerce' )
			);
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$name = self::limit_text( $name, self::NAME_MAX_LENGTH );
		if ( '' === $name ) {
			return new WP_Error(
				'bumpmint_name_required',
				__( 'Enter an internal name for this order bump.', 'bumpmint-order-bump-for-woocommerce' )
			);
		}

		$condition_type = isset( $data['condition_type'] ) ? sanitize_key( $data['condition_type'] ) : '';
		if ( ! BumpMint_Conditions::get_definition( $condition_type ) ) {
			return new WP_Error(
				'bumpmint_invalid_condition',
				__( 'Select a valid display rule.', 'bumpmint-order-bump-for-woocommerce' )
			);
		}

		$condition_product_ids = isset( $data['condition_product_ids'] )
			? self::sanitize_product_ids( $data['condition_product_ids'] )
			: array();
		$condition_category_enabled = isset( $data['condition_category_enabled'] ) && '1' === (string) $data['condition_category_enabled'];
		$condition_category_ids     = isset( $data['condition_category_ids'] )
			? self::sanitize_category_ids( $data['condition_category_ids'] )
			: array();
		$condition_match       = isset( $data['condition_match'] ) ? sanitize_key( $data['condition_match'] ) : 'any';
		$condition_operator    = isset( $data['condition_operator'] ) ? sanitize_key( $data['condition_operator'] ) : 'greater_than';
		$condition_value       = isset( $data['condition_value'] ) ? wc_format_decimal( $data['condition_value'] ) : '0';

		if ( BumpMint_Conditions::PRODUCT === $condition_type ) {
			if ( ! in_array( $condition_match, array( 'any', 'all' ), true ) ) {
				return new WP_Error(
					'bumpmint_invalid_condition_match',
					__( 'Select a valid trigger matching option.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( $condition_category_enabled && empty( $condition_category_ids ) ) {
				return new WP_Error(
					'bumpmint_trigger_category_required',
					__( 'Select at least one trigger category or disable category triggers.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( ! $condition_category_enabled ) {
				$condition_category_ids = array();
			}

			if ( empty( $condition_product_ids ) && empty( $condition_category_ids ) ) {
				return new WP_Error(
					'bumpmint_trigger_required',
					__( 'Select at least one valid trigger product or category.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			foreach ( $condition_product_ids as $condition_product_id ) {
				if ( ! wc_get_product( $condition_product_id ) ) {
					return new WP_Error(
						'bumpmint_invalid_trigger_product',
						__( 'One or more selected trigger products are invalid.', 'bumpmint-order-bump-for-woocommerce' )
					);
				}
			}

			foreach ( $condition_category_ids as $condition_category_id ) {
				$category = get_term( $condition_category_id, 'product_cat' );
				if ( ! $category || is_wp_error( $category ) ) {
					return new WP_Error(
						'bumpmint_invalid_trigger_category',
						__( 'One or more selected trigger categories are invalid.', 'bumpmint-order-bump-for-woocommerce' )
					);
				}
			}

			if ( empty( $condition_product_ids ) ) {
				$condition_match = 'any';
			}
		} elseif ( in_array( $condition_type, array( BumpMint_Conditions::CART_SUBTOTAL, BumpMint_Conditions::CART_QUANTITY ), true ) ) {
			if ( ! in_array( $condition_operator, array( 'greater_than', 'less_than' ), true ) ) {
				return new WP_Error(
					'bumpmint_invalid_operator',
					__( 'Select a valid comparison operator.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( '' === $condition_value || ! is_numeric( $condition_value ) || (float) $condition_value < 0 ) {
				return new WP_Error(
					'bumpmint_invalid_condition_value',
					__( 'Enter a valid non-negative comparison value.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( BumpMint_Conditions::CART_QUANTITY === $condition_type ) {
				$condition_value = (string) absint( $condition_value );
			}
			$condition_product_ids      = array();
			$condition_category_enabled = false;
			$condition_category_ids     = array();
			$condition_match            = 'any';
		} else {
			$condition_product_ids      = array();
			$condition_category_enabled = false;
			$condition_category_ids     = array();
			$condition_match            = 'any';
		}

		$schedule_type = isset( $data['schedule_type'] )
			? sanitize_key( $data['schedule_type'] )
			: BumpMint_Conditions::SCHEDULE_ALWAYS;
		if ( ! in_array( $schedule_type, array( BumpMint_Conditions::SCHEDULE_ALWAYS, BumpMint_Conditions::SCHEDULE_DATES, BumpMint_Conditions::SCHEDULE_WEEKDAYS ), true ) ) {
			return new WP_Error(
				'bumpmint_invalid_schedule_type',
				__( 'Select a valid availability schedule.', 'bumpmint-order-bump-for-woocommerce' )
			);
		}

		$schedule_dates_raw = isset( $data['schedule_dates'] ) ? (array) $data['schedule_dates'] : array();
		if ( count( $schedule_dates_raw ) > self::MAX_SCHEDULE_DATES ) {
			return new WP_Error(
				'bumpmint_too_many_schedule_dates',
				sprintf(
					/* translators: %d: maximum number of specific dates. */
					__( 'Select no more than %d specific dates.', 'bumpmint-order-bump-for-woocommerce' ),
					self::MAX_SCHEDULE_DATES
				)
			);
		}

		$schedule_dates = array();
		foreach ( $schedule_dates_raw as $schedule_date_raw ) {
			if ( ! is_scalar( $schedule_date_raw ) ) {
				return new WP_Error(
					'bumpmint_invalid_schedule_date',
					__( 'Select valid specific dates.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			$schedule_date_raw = trim( sanitize_text_field( (string) $schedule_date_raw ) );
			if ( '' === $schedule_date_raw ) {
				continue;
			}

			$schedule_date = self::sanitize_schedule_date( $schedule_date_raw );
			if ( '' === $schedule_date ) {
				return new WP_Error(
					'bumpmint_invalid_schedule_date',
					__( 'Select valid specific dates.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
			$schedule_dates[] = $schedule_date;
		}
		$schedule_dates = array_values( array_unique( $schedule_dates ) );
		sort( $schedule_dates, SORT_STRING );

		$schedule_weekdays_raw = isset( $data['schedule_weekdays'] ) ? (array) $data['schedule_weekdays'] : array();
		$schedule_weekdays     = array();
		foreach ( $schedule_weekdays_raw as $schedule_weekday_raw ) {
			if ( ! is_scalar( $schedule_weekday_raw ) ) {
				return new WP_Error(
					'bumpmint_invalid_schedule_weekday',
					__( 'Select valid days of the week.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			$schedule_weekday_raw = trim( (string) $schedule_weekday_raw );
			if ( '' === $schedule_weekday_raw ) {
				continue;
			}

			if ( ! preg_match( '/^[1-7]$/', $schedule_weekday_raw ) ) {
				return new WP_Error(
					'bumpmint_invalid_schedule_weekday',
					__( 'Select valid days of the week.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
			$schedule_weekdays[] = (int) $schedule_weekday_raw;
		}
		$schedule_weekdays = array_values( array_unique( $schedule_weekdays ) );
		sort( $schedule_weekdays, SORT_NUMERIC );

		if ( BumpMint_Conditions::SCHEDULE_DATES === $schedule_type ) {
			if ( empty( $schedule_dates ) ) {
				return new WP_Error(
					'bumpmint_schedule_date_required',
					__( 'Select at least one specific date.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
			$schedule_weekdays = array();
		} elseif ( BumpMint_Conditions::SCHEDULE_WEEKDAYS === $schedule_type ) {
			if ( empty( $schedule_weekdays ) ) {
				return new WP_Error(
					'bumpmint_schedule_weekday_required',
					__( 'Select at least one day of the week.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
			$schedule_dates = array();
		} else {
			$schedule_dates    = array();
			$schedule_weekdays = array();
		}

		$schedule_time_enabled = isset( $data['schedule_time_enabled'] )
			&& is_scalar( $data['schedule_time_enabled'] )
			&& '1' === (string) $data['schedule_time_enabled'];
		$schedule_start_time    = isset( $data['schedule_start_time'] ) && is_scalar( $data['schedule_start_time'] ) ? sanitize_text_field( (string) $data['schedule_start_time'] ) : '';
		$schedule_end_time      = isset( $data['schedule_end_time'] ) && is_scalar( $data['schedule_end_time'] ) ? sanitize_text_field( (string) $data['schedule_end_time'] ) : '';
		if ( $schedule_time_enabled ) {
			if ( ! self::is_valid_schedule_time( $schedule_start_time ) || ! self::is_valid_schedule_time( $schedule_end_time ) ) {
				return new WP_Error(
					'bumpmint_invalid_schedule_time',
					__( 'Enter valid start and end times.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( $schedule_start_time === $schedule_end_time ) {
				return new WP_Error(
					'bumpmint_equal_schedule_times',
					__( 'Start and end times must be different.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
		} else {
			$schedule_start_time = '';
			$schedule_end_time   = '';
		}

		$bump_product_ids = isset( $data['bump_product_ids'] )
			? self::sanitize_product_ids( $data['bump_product_ids'] )
			: array();
		$hide_if_in_cart = isset( $data['hide_if_in_cart'] ) && '1' === (string) $data['hide_if_in_cart'];
		if ( empty( $bump_product_ids ) ) {
			return new WP_Error(
				'bumpmint_bump_required',
				__( 'Select at least one valid product to offer.', 'bumpmint-order-bump-for-woocommerce' )
			);
		}

		foreach ( $bump_product_ids as $bump_product_id ) {
			$bump_product = wc_get_product( $bump_product_id );
			if ( ! $bump_product ) {
				return new WP_Error(
					'bumpmint_invalid_bump_product',
					__( 'One or more selected offer products are invalid.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( $bump_product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
				return new WP_Error(
					'bumpmint_unsupported_bump_product',
					__( 'Choose directly purchasable products or specific variations.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
		}

		if ( BumpMint_Conditions::PRODUCT === $condition_type ) {
			foreach ( $bump_product_ids as $bump_product_id ) {
				$bump_product   = wc_get_product( $bump_product_id );
				$bump_parent_id = $bump_product->is_type( 'variation' ) ? $bump_product->get_parent_id() : 0;

				foreach ( $condition_product_ids as $condition_product_id ) {
					if ( $condition_product_id === $bump_product_id || ( $bump_parent_id && $condition_product_id === $bump_parent_id ) ) {
						return new WP_Error(
							'bumpmint_same_product',
							__( 'Trigger products and offered products cannot contain the same product.', 'bumpmint-order-bump-for-woocommerce' )
						);
					}
				}
			}
		}

		$position = isset( $data['position'] ) ? sanitize_key( $data['position'] ) : BumpMint_Positions::BEFORE_PAYMENT;
		if ( ! BumpMint_Positions::get_position( $position ) ) {
			return new WP_Error(
				'bumpmint_invalid_position',
				__( 'Select a valid checkout position.', 'bumpmint-order-bump-for-woocommerce' )
			);
		}

		$discount_enabled = isset( $data['discount_enabled'] ) && '1' === (string) $data['discount_enabled'];
		$discount_type    = isset( $data['discount_type'] ) ? sanitize_key( $data['discount_type'] ) : 'percentage';
		$discount_value   = isset( $data['discount_value'] ) ? wc_format_decimal( $data['discount_value'] ) : '0';
		$max_quantity_raw = isset( $data['max_quantity'] ) ? trim( (string) $data['max_quantity'] ) : '1';

		if ( ! preg_match( '/^[1-9][0-9]*$/', $max_quantity_raw ) ) {
			return new WP_Error(
				'bumpmint_invalid_max_quantity',
				__( 'Enter a maximum discounted quantity of 1 or more.', 'bumpmint-order-bump-for-woocommerce' )
			);
		}

		$max_quantity = absint( $max_quantity_raw );

		if ( $discount_enabled ) {
			if ( ! in_array( $discount_type, array( 'fixed', 'percentage' ), true ) ) {
				return new WP_Error(
					'bumpmint_invalid_discount_type',
					__( 'Select a valid discount type.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( '' === $discount_value || ! is_numeric( $discount_value ) || (float) $discount_value <= 0 ) {
				return new WP_Error(
					'bumpmint_invalid_discount',
					__( 'Enter a discount greater than zero.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}

			if ( 'percentage' === $discount_type && (float) $discount_value > 100 ) {
				return new WP_Error(
					'bumpmint_invalid_percentage',
					__( 'Percentage discounts cannot exceed 100%.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
		} else {
			$discount_value = '0';
		}

		$badge_text = isset( $data['badge_text'] ) ? sanitize_text_field( $data['badge_text'] ) : '';
		$badge_text = self::limit_text( $badge_text, self::BADGE_MAX_LENGTH );

		$sanitized = array(
			'id'                    => $existing_rule ? $existing_rule['id'] : wp_generate_uuid4(),
			'name'                  => $name,
			'condition_type'        => $condition_type,
			'condition_product_ids' => $condition_product_ids,
			'condition_category_enabled' => $condition_category_enabled,
			'condition_category_ids' => $condition_category_ids,
			'condition_match'       => $condition_match,
			'condition_operator'    => $condition_operator,
			'condition_value'       => $condition_value,
			'schedule_type'         => $schedule_type,
			'schedule_dates'        => $schedule_dates,
			'schedule_weekdays'     => $schedule_weekdays,
			'schedule_time_enabled' => $schedule_time_enabled,
			'schedule_start_time'   => $schedule_start_time,
			'schedule_end_time'     => $schedule_end_time,
			'bump_product_ids'      => $bump_product_ids,
			'hide_if_in_cart'       => $hide_if_in_cart,
			'position'              => $position,
			'discount_enabled'      => $discount_enabled,
			'discount_type'         => $discount_type,
			'discount_value'        => $discount_value,
			'max_quantity'          => $max_quantity,
			'badge_text'            => $badge_text,
			'offer_title'           => isset( $data['offer_title'] ) ? sanitize_text_field( $data['offer_title'] ) : '',
			'description'           => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'image_id'              => isset( $data['image_id'] ) ? absint( $data['image_id'] ) : 0,
			'status'                => 'active',
			'created_at'            => $existing_rule && ! empty( $existing_rule['created_at'] )
				? $existing_rule['created_at']
				: current_time( 'mysql', true ),
		);

		foreach ( self::get_rules() as $other_rule ) {
			if ( $existing_rule && hash_equals( (string) $other_rule['id'], (string) $existing_rule['id'] ) ) {
				continue;
			}

			if ( self::get_fingerprint( $other_rule ) === self::get_fingerprint( $sanitized ) ) {
				return new WP_Error(
					'bumpmint_duplicate_rule',
					__( 'An identical order bump rule already exists.', 'bumpmint-order-bump-for-woocommerce' )
				);
			}
		}

		$rules = self::get_rules();
		$found = false;

		foreach ( $rules as $index => $rule ) {
			if ( hash_equals( (string) $rule['id'], (string) $sanitized['id'] ) ) {
				$rules[ $index ] = $sanitized;
				$found           = true;
				break;
			}
		}

		if ( ! $found ) {
			$rules[] = $sanitized;
		}

		update_option( BUMPMINT_OPTION_KEY, $rules, false );
		return $sanitized;
	}

	/**
	 * Deletes a rule by ID.
	 *
	 * @param string $id Rule ID.
	 */
	public static function delete_rule( $id ) {
		$rules = array_values(
			array_filter(
				self::get_rules(),
				function ( $rule ) use ( $id ) {
					return ! isset( $rule['id'] ) || ! hash_equals( (string) $rule['id'], (string) $id );
				}
			)
		);

		update_option( BUMPMINT_OPTION_KEY, $rules, false );
	}

	/**
	 * Calculates the effective base and offer prices on the server.
	 *
	 * @param array           $rule       Rule data.
	 * @param WC_Product|null $product    Product instance.
	 * @param float|null      $base_price Effective cart price override.
	 * @return array
	 */
	public static function calculate_prices( array $rule, $product = null, $base_price = null ) {
		$product_ids = isset( $rule['bump_product_ids'] ) ? self::sanitize_product_ids( $rule['bump_product_ids'] ) : array();
		$product     = $product ? $product : ( ! empty( $product_ids ) ? wc_get_product( $product_ids[0] ) : null );
		$base_price  = null !== $base_price ? $base_price : ( $product ? $product->get_price() : null );

		// Invalid third-party filter output must never turn a paid product free.
		if ( ! is_numeric( $base_price ) && $product ) {
			$base_price = $product->get_price( 'edit' );
		}

		$base  = is_numeric( $base_price ) ? max( 0.0, (float) $base_price ) : 0.0;
		$offer = $base;

		if ( ! empty( $rule['discount_enabled'] ) ) {
			$value = max( 0.0, (float) $rule['discount_value'] );
			if ( 'percentage' === $rule['discount_type'] ) {
				$offer = $base * ( 1 - min( 100.0, $value ) / 100 );
			} elseif ( 'fixed' === $rule['discount_type'] ) {
				$offer = max( 0.0, $base - $value );
			}
		}

		return array(
			'base'  => (float) wc_format_decimal( $base, wc_get_price_decimals() ),
			'offer' => (float) wc_format_decimal( max( 0.0, $offer ), wc_get_price_decimals() ),
		);
	}

	/**
	 * Returns formatted price HTML for a rule.
	 *
	 * @param array           $rule       Rule data.
	 * @param WC_Product|null $product    Product instance.
	 * @param float|null      $base_price Effective cart price override.
	 * @return string
	 */
	public static function get_price_html( array $rule, $product = null, $base_price = null ) {
		$product_ids = isset( $rule['bump_product_ids'] ) ? self::sanitize_product_ids( $rule['bump_product_ids'] ) : array();
		$product     = $product ? $product : ( ! empty( $product_ids ) ? wc_get_product( $product_ids[0] ) : null );
		if ( ! $product ) {
			return '—';
		}

		$prices        = self::calculate_prices( $rule, $product, $base_price );
		$base_display  = wc_get_price_to_display( $product, array( 'price' => $prices['base'] ) );
		$offer_display = wc_get_price_to_display( $product, array( 'price' => $prices['offer'] ) );

		if ( $prices['offer'] < $prices['base'] ) {
			return '<del>' . wc_price( $base_display ) . '</del> <ins>' . wc_price( $offer_display ) . '</ins>';
		}

		return wc_price( $base_display );
	}

	/**
	 * Returns the configured or generated badge text.
	 *
	 * @param array           $rule       Rule data.
	 * @param WC_Product|null $product    Product instance.
	 * @param float|null      $base_price Effective cart price override.
	 * @return string
	 */
	public static function get_badge_text( array $rule, $product = null, $base_price = null ) {
		if ( ! empty( $rule['badge_text'] ) ) {
			return $rule['badge_text'];
		}

		if ( empty( $rule['discount_enabled'] ) ) {
			return __( 'Sale', 'bumpmint-order-bump-for-woocommerce' );
		}

		if ( 'percentage' === $rule['discount_type'] ) {
			/* translators: %s: discount percentage. */
			return sprintf( __( '%s%% OFF', 'bumpmint-order-bump-for-woocommerce' ), wc_format_decimal( $rule['discount_value'] ) );
		}

		$prices   = self::calculate_prices( $rule, $product, $base_price );
		$discount = max( 0.0, $prices['base'] - $prices['offer'] );

		/* translators: %s: formatted fixed discount amount. */
		return sprintf( __( '%s OFF', 'bumpmint-order-bump-for-woocommerce' ), wp_strip_all_tags( wc_price( $discount ) ) );
	}

	/**
	 * Normalizes current and legacy rule structures.
	 *
	 * @param array $rule Stored rule.
	 * @return array
	 */
	private static function normalize_rule( array $rule ) {
		$condition_product_ids = isset( $rule['condition_product_ids'] )
			? self::sanitize_product_ids( $rule['condition_product_ids'] )
			: self::sanitize_product_ids(
				isset( $rule['condition_product_id'] )
					? $rule['condition_product_id']
					: ( isset( $rule['gatilho'] ) ? $rule['gatilho'] : array() )
			);
		$bump_product_ids      = isset( $rule['bump_product_ids'] )
			? self::sanitize_product_ids( $rule['bump_product_ids'] )
			: self::sanitize_product_ids(
				isset( $rule['bump_product_id'] )
					? $rule['bump_product_id']
					: ( isset( $rule['bump'] ) ? $rule['bump'] : array() )
			);
		$condition_category_enabled = ! empty( $rule['condition_category_enabled'] );
		$condition_category_ids     = isset( $rule['condition_category_ids'] )
			? self::sanitize_category_ids( $rule['condition_category_ids'] )
			: array();
		$schedule_type = isset( $rule['schedule_type'] ) ? sanitize_key( $rule['schedule_type'] ) : BumpMint_Conditions::SCHEDULE_ALWAYS;
		if ( ! in_array( $schedule_type, array( BumpMint_Conditions::SCHEDULE_ALWAYS, BumpMint_Conditions::SCHEDULE_DATES, BumpMint_Conditions::SCHEDULE_WEEKDAYS ), true ) ) {
			$schedule_type = BumpMint_Conditions::SCHEDULE_ALWAYS;
		}
		$schedule_dates    = isset( $rule['schedule_dates'] ) ? self::sanitize_schedule_dates( $rule['schedule_dates'] ) : array();
		$schedule_weekdays = isset( $rule['schedule_weekdays'] ) ? self::sanitize_schedule_weekdays( $rule['schedule_weekdays'] ) : array();
		if ( BumpMint_Conditions::SCHEDULE_DATES === $schedule_type ) {
			$schedule_weekdays = array();
		} elseif ( BumpMint_Conditions::SCHEDULE_WEEKDAYS === $schedule_type ) {
			$schedule_dates = array();
		} else {
			$schedule_dates    = array();
			$schedule_weekdays = array();
		}

		$schedule_start_time = isset( $rule['schedule_start_time'] ) && is_scalar( $rule['schedule_start_time'] ) ? sanitize_text_field( (string) $rule['schedule_start_time'] ) : '';
		$schedule_end_time   = isset( $rule['schedule_end_time'] ) && is_scalar( $rule['schedule_end_time'] ) ? sanitize_text_field( (string) $rule['schedule_end_time'] ) : '';
		$schedule_time_enabled = ! empty( $rule['schedule_time_enabled'] )
			&& self::is_valid_schedule_time( $schedule_start_time )
			&& self::is_valid_schedule_time( $schedule_end_time )
			&& $schedule_start_time !== $schedule_end_time;
		if ( ! $schedule_time_enabled ) {
			$schedule_start_time = '';
			$schedule_end_time   = '';
		}

		$fallback_name = '';
		if ( ! empty( $rule['name'] ) ) {
			$fallback_name = $rule['name'];
		} elseif ( ! empty( $rule['titulo'] ) ) {
			$fallback_name = $rule['titulo'];
		} elseif ( ! empty( $bump_product_ids ) ) {
			$product       = wc_get_product( $bump_product_ids[0] );
			$fallback_name = $product
				? sprintf(
					/* translators: %s: offered product name. */
					__( 'Order Bump — %s', 'bumpmint-order-bump-for-woocommerce' ),
					$product->get_name()
				)
				: __( 'Order Bump', 'bumpmint-order-bump-for-woocommerce' );
		}

		$defaults = array(
			'id'                    => wp_generate_uuid4(),
			'name'                  => self::limit_text( sanitize_text_field( $fallback_name ), self::NAME_MAX_LENGTH ),
			'condition_type'        => isset( $rule['gatilho'] ) ? BumpMint_Conditions::PRODUCT : BumpMint_Conditions::ALWAYS,
			'condition_product_ids' => $condition_product_ids,
			'condition_category_enabled' => $condition_category_enabled,
			'condition_category_ids' => $condition_category_ids,
			'condition_match'       => 'any',
			'condition_operator'    => 'greater_than',
			'condition_value'       => '0',
			'schedule_type'         => $schedule_type,
			'schedule_dates'        => $schedule_dates,
			'schedule_weekdays'     => $schedule_weekdays,
			'schedule_time_enabled' => $schedule_time_enabled,
			'schedule_start_time'   => $schedule_start_time,
			'schedule_end_time'     => $schedule_end_time,
			'bump_product_ids'      => $bump_product_ids,
			'hide_if_in_cart'       => false,
			'position'              => BumpMint_Positions::BEFORE_PAYMENT,
			'discount_enabled'      => false,
			'discount_type'         => 'percentage',
			'discount_value'        => '0',
			'max_quantity'          => 1,
			'badge_text'            => '',
			'offer_title'           => isset( $rule['titulo'] ) ? sanitize_text_field( $rule['titulo'] ) : '',
			'description'           => isset( $rule['descricao'] ) ? sanitize_textarea_field( $rule['descricao'] ) : '',
			'image_id'              => isset( $rule['imagem_id'] ) ? absint( $rule['imagem_id'] ) : 0,
			'status'                => 'active',
			'created_at'            => current_time( 'mysql', true ),
		);

		$normalized = wp_parse_args( $rule, $defaults );
		$normalized['condition_product_ids'] = $condition_product_ids;
		$normalized['condition_category_enabled'] = BumpMint_Conditions::PRODUCT === $normalized['condition_type']
			&& $condition_category_enabled
			&& ! empty( $condition_category_ids );
		$normalized['condition_category_ids'] = $normalized['condition_category_enabled'] ? $condition_category_ids : array();
		$normalized['schedule_type']         = $schedule_type;
		$normalized['schedule_dates']        = $schedule_dates;
		$normalized['schedule_weekdays']     = $schedule_weekdays;
		$normalized['schedule_time_enabled'] = $schedule_time_enabled;
		$normalized['schedule_start_time']   = $schedule_start_time;
		$normalized['schedule_end_time']     = $schedule_end_time;
		$normalized['bump_product_ids']      = $bump_product_ids;
		$normalized['hide_if_in_cart']       = ! empty( $normalized['hide_if_in_cart'] );
		$normalized['max_quantity']          = max( 1, absint( $normalized['max_quantity'] ) );
		$normalized['condition_match']       = in_array( $normalized['condition_match'], array( 'any', 'all' ), true )
			? $normalized['condition_match']
			: 'any';
		if ( empty( $condition_product_ids ) ) {
			$normalized['condition_match'] = 'any';
		}
		if ( BumpMint_Conditions::PRODUCT !== $normalized['condition_type'] ) {
			$normalized['condition_product_ids'] = array();
			$normalized['condition_category_enabled'] = false;
			$normalized['condition_category_ids'] = array();
		}

		unset(
			$normalized['gatilho'],
			$normalized['bump'],
			$normalized['condition_product_id'],
			$normalized['bump_product_id'],
			$normalized['titulo'],
			$normalized['descricao'],
			$normalized['imagem_id']
		);

		return $normalized;
	}

	/**
	 * Builds a stable duplicate-detection fingerprint.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private static function get_fingerprint( array $rule ) {
		$condition_product_ids = $rule['condition_product_ids'];
		$condition_category_ids = ! empty( $rule['condition_category_enabled'] ) && isset( $rule['condition_category_ids'] )
			? self::sanitize_category_ids( $rule['condition_category_ids'] )
			: array();
		$bump_product_ids      = $rule['bump_product_ids'];
		$schedule_dates        = isset( $rule['schedule_dates'] ) ? self::sanitize_schedule_dates( $rule['schedule_dates'] ) : array();
		$schedule_weekdays     = isset( $rule['schedule_weekdays'] ) ? self::sanitize_schedule_weekdays( $rule['schedule_weekdays'] ) : array();
		sort( $condition_product_ids, SORT_NUMERIC );
		sort( $condition_category_ids, SORT_NUMERIC );
		sort( $bump_product_ids, SORT_NUMERIC );
		sort( $schedule_dates, SORT_STRING );
		sort( $schedule_weekdays, SORT_NUMERIC );

		return md5(
			wp_json_encode(
				array(
					$rule['condition_type'],
					$condition_product_ids,
					! empty( $condition_category_ids ),
					$condition_category_ids,
					$rule['condition_match'],
					$rule['condition_operator'],
					$rule['condition_value'],
					isset( $rule['schedule_type'] ) ? $rule['schedule_type'] : BumpMint_Conditions::SCHEDULE_ALWAYS,
					$schedule_dates,
					$schedule_weekdays,
					! empty( $rule['schedule_time_enabled'] ),
					isset( $rule['schedule_start_time'] ) ? $rule['schedule_start_time'] : '',
					isset( $rule['schedule_end_time'] ) ? $rule['schedule_end_time'] : '',
					$bump_product_ids,
					$rule['position'],
				)
			)
		);
	}

	/**
	 * Sanitizes a product ID or list of product IDs.
	 *
	 * @param mixed $product_ids Product ID or list of IDs.
	 * @return array
	 */
	private static function sanitize_product_ids( $product_ids ) {
		$product_ids = is_array( $product_ids ) ? $product_ids : array( $product_ids );
		$product_ids = array_map( 'absint', $product_ids );
		$product_ids = array_filter( $product_ids );

		return array_values( array_unique( $product_ids ) );
	}

	/**
	 * Sanitizes a product category ID or list of category IDs.
	 *
	 * @param mixed $category_ids Product category ID or list of IDs.
	 * @return array
	 */
	private static function sanitize_category_ids( $category_ids ) {
		$category_ids = is_array( $category_ids ) ? $category_ids : array( $category_ids );
		$category_ids = array_map( 'absint', $category_ids );
		$category_ids = array_filter( $category_ids );

		return array_values( array_unique( $category_ids ) );
	}

	/**
	 * Sanitizes and sorts a list of specific local calendar dates.
	 *
	 * @param mixed $dates Dates in Y-m-d format.
	 * @return array
	 */
	private static function sanitize_schedule_dates( $dates ) {
		$dates     = is_array( $dates ) ? $dates : array( $dates );
		$sanitized = array();

		foreach ( array_slice( $dates, 0, self::MAX_SCHEDULE_DATES ) as $date ) {
			if ( ! is_scalar( $date ) ) {
				continue;
			}
			$date = self::sanitize_schedule_date( $date );
			if ( '' !== $date ) {
				$sanitized[] = $date;
			}
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		sort( $sanitized, SORT_STRING );
		return $sanitized;
	}

	/**
	 * Validates one local calendar date without applying a timezone offset.
	 *
	 * @param mixed $date Date in Y-m-d format.
	 * @return string
	 */
	private static function sanitize_schedule_date( $date ) {
		$date = is_scalar( $date ) ? trim( sanitize_text_field( (string) $date ) ) : '';
		if ( ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date ) ) {
			return '';
		}

		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$errors   = DateTimeImmutable::getLastErrors();
		if (
			! $datetime
			|| ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) )
			|| $datetime->format( 'Y-m-d' ) !== $date
		) {
			return '';
		}

		return $date;
	}

	/**
	 * Sanitizes and sorts ISO-8601 weekday numbers (Monday 1 through Sunday 7).
	 *
	 * @param mixed $weekdays Weekday number or list.
	 * @return array
	 */
	private static function sanitize_schedule_weekdays( $weekdays ) {
		$weekdays = is_array( $weekdays ) ? $weekdays : array( $weekdays );
		$weekdays = array_map( 'absint', $weekdays );
		$weekdays = array_values( array_unique( array_filter( $weekdays, function ( $weekday ) {
			return $weekday >= 1 && $weekday <= 7;
		} ) ) );
		sort( $weekdays, SORT_NUMERIC );

		return $weekdays;
	}

	/**
	 * Validates a strict 24-hour time string.
	 *
	 * @param mixed $time Time in H:i format.
	 * @return bool
	 */
	private static function is_valid_schedule_time( $time ) {
		return is_string( $time ) && (bool) preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time );
	}

	/**
	 * Limits plain text without breaking multibyte characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function limit_text( $text, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length );
		}

		return substr( $text, 0, $length );
	}
}
