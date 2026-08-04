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

	const NAME_MAX_LENGTH  = 120;
	const BADGE_MAX_LENGTH = 60;

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
			update_option( BUMPMINT_OPTION_KEY, $rules );
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
		$condition_match       = isset( $data['condition_match'] ) ? sanitize_key( $data['condition_match'] ) : 'any';
		$condition_operator    = isset( $data['condition_operator'] ) ? sanitize_key( $data['condition_operator'] ) : 'greater_than';
		$condition_value       = isset( $data['condition_value'] ) ? wc_format_decimal( $data['condition_value'] ) : '0';

		if ( BumpMint_Conditions::PRODUCT === $condition_type ) {
			if ( empty( $condition_product_ids ) ) {
				return new WP_Error(
					'bumpmint_trigger_required',
					__( 'Select at least one valid trigger product.', 'bumpmint-order-bump-for-woocommerce' )
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

			if ( ! in_array( $condition_match, array( 'any', 'all' ), true ) ) {
				return new WP_Error(
					'bumpmint_invalid_condition_match',
					__( 'Select a valid trigger matching option.', 'bumpmint-order-bump-for-woocommerce' )
				);
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
			'condition_match'       => $condition_match,
			'condition_operator'    => $condition_operator,
			'condition_value'       => $condition_value,
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

		update_option( BUMPMINT_OPTION_KEY, $rules );
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

		update_option( BUMPMINT_OPTION_KEY, $rules );
	}

	/**
	 * Calculates the canonical base and offer prices on the server.
	 *
	 * @param array           $rule    Rule data.
	 * @param WC_Product|null $product Product instance.
	 * @return array
	 */
	public static function calculate_prices( array $rule, $product = null ) {
		$product_ids = isset( $rule['bump_product_ids'] ) ? self::sanitize_product_ids( $rule['bump_product_ids'] ) : array();
		$product     = $product ? $product : ( ! empty( $product_ids ) ? wc_get_product( $product_ids[0] ) : null );
		$base        = $product ? max( 0.0, (float) $product->get_price( 'edit' ) ) : 0.0;
		$offer       = $base;

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
	 * @param array           $rule    Rule data.
	 * @param WC_Product|null $product Product instance.
	 * @return string
	 */
	public static function get_price_html( array $rule, $product = null ) {
		$product_ids = isset( $rule['bump_product_ids'] ) ? self::sanitize_product_ids( $rule['bump_product_ids'] ) : array();
		$product     = $product ? $product : ( ! empty( $product_ids ) ? wc_get_product( $product_ids[0] ) : null );
		if ( ! $product ) {
			return '—';
		}

		$prices        = self::calculate_prices( $rule, $product );
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
	 * @param array           $rule    Rule data.
	 * @param WC_Product|null $product Product instance.
	 * @return string
	 */
	public static function get_badge_text( array $rule, $product = null ) {
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

		$prices   = self::calculate_prices( $rule, $product );
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
			'condition_match'       => 'any',
			'condition_operator'    => 'greater_than',
			'condition_value'       => '0',
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
		$normalized['bump_product_ids']      = $bump_product_ids;
		$normalized['hide_if_in_cart']       = ! empty( $normalized['hide_if_in_cart'] );
		$normalized['max_quantity']          = max( 1, absint( $normalized['max_quantity'] ) );
		$normalized['condition_match']       = in_array( $normalized['condition_match'], array( 'any', 'all' ), true )
			? $normalized['condition_match']
			: 'any';

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
		$bump_product_ids      = $rule['bump_product_ids'];
		sort( $condition_product_ids, SORT_NUMERIC );
		sort( $bump_product_ids, SORT_NUMERIC );

		return md5(
			wp_json_encode(
				array(
					$rule['condition_type'],
					$condition_product_ids,
					$rule['condition_match'],
					$rule['condition_operator'],
					$rule['condition_value'],
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
