<?php
/**
 * Extensible checkout position registry.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines safe positions inside the classic WooCommerce checkout fragments.
 */
class BumpMint_Positions {

	const BEFORE_ORDER_ITEMS = 'before_order_items';
	const AFTER_ORDER_ITEMS  = 'after_order_items';
	const BEFORE_PAYMENT     = 'before_payment';
	const BEFORE_PLACE_ORDER = 'before_place_order';

	/**
	 * Returns all available checkout positions.
	 *
	 * New positions can be registered with the `bumpmint_checkout_positions`
	 * filter. A position requires a label, hook, priority, and context.
	 *
	 * @return array
	 */
	public static function get_positions() {
		$positions = array(
			self::BEFORE_ORDER_ITEMS => array(
				'label'    => __( 'Before order items', 'bumpmint-order-bump-for-woocommerce' ),
				'hook'     => 'woocommerce_review_order_before_cart_contents',
				'priority' => 20,
				'context'  => 'table',
			),
			self::AFTER_ORDER_ITEMS  => array(
				'label'    => __( 'After order items', 'bumpmint-order-bump-for-woocommerce' ),
				'hook'     => 'woocommerce_review_order_after_cart_contents',
				'priority' => 20,
				'context'  => 'table',
			),
			self::BEFORE_PAYMENT     => array(
				'label'    => __( 'Before payment methods', 'bumpmint-order-bump-for-woocommerce' ),
				'hook'     => 'woocommerce_review_order_before_payment',
				'priority' => 20,
				'context'  => 'block',
			),
			self::BEFORE_PLACE_ORDER => array(
				'label'    => __( 'Before the Place order button', 'bumpmint-order-bump-for-woocommerce' ),
				'hook'     => 'woocommerce_review_order_before_submit',
				'priority' => 20,
				'context'  => 'block',
			),
		);

		/**
		 * Filters available order bump checkout positions.
		 *
		 * @param array $positions Position definitions.
		 */
		return apply_filters( 'bumpmint_checkout_positions', $positions );
	}

	/**
	 * Returns one position definition.
	 *
	 * @param string $key Position key.
	 * @return array|null
	 */
	public static function get_position( $key ) {
		$positions = self::get_positions();
		return isset( $positions[ $key ] ) ? $positions[ $key ] : null;
	}

	/**
	 * Returns the human-readable position label.
	 *
	 * @param string $key Position key.
	 * @return string
	 */
	public static function get_label( $key ) {
		$position = self::get_position( $key );
		return $position ? $position['label'] : __( 'Unknown position', 'bumpmint-order-bump-for-woocommerce' );
	}
}
