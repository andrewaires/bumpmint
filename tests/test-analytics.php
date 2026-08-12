<?php
/**
 * Analytics storage, attribution, and order reconciliation tests.
 *
 * @package BumpMint
 */

/**
 * Verifies prospective tracking and current WooCommerce order state.
 */
class Test_BumpMint_Analytics extends BumpMint_Test_Case {

	/**
	 * Analytics service under test.
	 *
	 * @var BumpMint_Analytics
	 */
	private $analytics;

	/**
	 * Creates a fresh tracking generation and empty tables.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( BumpMint_Analytics::TRACKING_STARTED_OPTION );
		delete_option( BumpMint_Analytics::TRACKING_ID_OPTION );
		bumpmint_maybe_upgrade_storage();

		$reflection      = new ReflectionClass( BumpMint_Analytics::class );
		$this->analytics = $reflection->newInstanceWithoutConstructor();
		$this->clear_analytics_tables();
	}

	/**
	 * Removes analytics state created by each test.
	 */
	public function tear_down() {
		$this->clear_analytics_tables();
		delete_option( BumpMint_Analytics::TRACKING_STARTED_OPTION );
		delete_option( BumpMint_Analytics::TRACKING_ID_OPTION );

		parent::tear_down();
	}

	/**
	 * One checkout journey sees one rule at most once per local day and currency.
	 */
	public function test_offer_views_are_deduplicated_and_tracking_starts_on_upgrade() {
		$product = $this->create_product( 100, 'Analytics offer' );
		$rule    = $this->create_rule( $product );

		$first  = $this->analytics->record_offer_view( $rule, WC()->cart );
		$second = $this->analytics->record_offer_view( $rule, WC()->cart );

		$this->assertNotEmpty( BumpMint_Analytics::get_tracking_started_at() );
		$this->assertNotEmpty( BumpMint_Analytics::get_tracking_id() );
		$this->assertSame( $first['view_id'], $second['view_id'] );
		$this->assertSame( 1, $this->count_current_views() );
	}

	/**
	 * The same bump counts again in a later checkout within the same WooCommerce session.
	 */
	public function test_views_are_deduplicated_per_checkout_journey_not_per_customer_session() {
		$product_a = $this->create_product( 100, 'First journey bump' );
		$product_b = $this->create_product( 50, 'Second journey bump' );
		$rule_a    = $this->create_rule( $product_a );
		$rule_b    = $this->create_rule( $product_b );

		$first_a           = $this->analytics->record_offer_view( $rule_a, WC()->cart );
		$first_a_duplicate = $this->analytics->record_offer_view( $rule_a, WC()->cart );
		$this->assertSame( $first_a['view_id'], $first_a_duplicate['view_id'] );

		$order_one = $this->create_bump_order( $product_a, $rule_a, $first_a, 1, 90 );
		$order_one->update_status( 'processing' );
		$order_one = wc_get_order( $order_one->get_id() );
		$this->analytics->handle_classic_order_processed( $order_one->get_id(), array(), $order_one );

		$second_a = $this->analytics->record_offer_view( $rule_a, WC()->cart );
		$second_b = $this->analytics->record_offer_view( $rule_b, WC()->cart );
		$this->assertNotSame( $first_a['view_id'], $second_a['view_id'] );
		$this->assertSame( 3, $this->count_current_views() );

		$order_two = $this->create_bump_order( $product_a, $rule_a, $second_a, 1, 90 );
		$item_b    = new WC_Order_Item_Product();
		$item_b->set_product( $product_b );
		$item_b->set_quantity( 1 );
		$item_b->set_subtotal( 45 );
		$item_b->set_total( 45 );
		$item_b->add_meta_data( '_bumpmint_rule_id', $rule_b['id'], true );
		$item_b->add_meta_data( '_bumpmint_rule_name', $rule_b['name'], true );
		$item_b->add_meta_data( '_bumpmint_analytics_tracking_id', $second_b['tracking_id'], true );
		$item_b->add_meta_data( '_bumpmint_analytics_session_hash', $second_b['session_hash'], true );
		$item_b->add_meta_data( '_bumpmint_analytics_view_id', $second_b['view_id'], true );
		$order_two->add_item( $item_b );
		$order_two->calculate_totals( false );
		$order_two->save();
		$order_two->update_status( 'processing' );
		$this->analytics->reconcile_order( wc_get_order( $order_two->get_id() ) );

		$today  = current_datetime()->format( 'Y-m-d' );
		$report = ( new BumpMint_Analytics_Reports() )->get_report(
			array(
				'start_date' => $today,
				'end_date'   => $today,
				'rule_id'    => '',
				'currency'   => 'USD',
			)
		);
		$this->assertSame( 3, $report['views'] );
		$this->assertSame( 3, $report['conversions'] );
		$this->assertEqualsWithDelta( 100.0, $report['conversion_rate'], 0.001 );
	}

	/**
	 * Paid and cancelled statuses reconcile without creating duplicate rows.
	 */
	public function test_paid_statuses_count_and_later_cancellation_is_excluded() {
		$product     = $this->create_product( 100, 'Paid bump' );
		$rule        = $this->create_rule( $product );
		$attribution = $this->analytics->record_offer_view( $rule, WC()->cart );
		$order       = $this->create_bump_order( $product, $rule, $attribution, 2, 180 );

		$this->analytics->reconcile_order( $order );
		$this->assertSame( 0, $this->count_current_valid_conversions() );

		$order->update_status( 'processing' );
		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );
		$row = $this->get_conversion_row( $order->get_id(), $rule['id'] );
		$this->assertSame( '1', $row['is_valid'] );
		$this->assertEqualsWithDelta( 2.0, (float) $row['quantity'], 0.001 );
		$this->assertEqualsWithDelta( 180.0, (float) $row['net_revenue'], 0.001 );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'cancelled' );
		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );
		$this->assertSame( 0, $this->count_current_valid_conversions() );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'completed' );
		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );
		$this->assertSame( 1, $this->count_current_valid_conversions() );

		$today  = current_datetime()->format( 'Y-m-d' );
		$report = ( new BumpMint_Analytics_Reports() )->get_report(
			array(
				'start_date' => $today,
				'end_date'   => $today,
				'rule_id'    => '',
				'currency'   => 'USD',
			)
		);
		$this->assertSame( 1, $report['views'] );
		$this->assertSame( 1, $report['conversions'] );
		$this->assertEqualsWithDelta( 100.0, $report['conversion_rate'], 0.001 );
		$this->assertEqualsWithDelta( 180.0, $report['revenue'], 0.001 );
		$this->assertCount( 1, $report['ranking'] );
	}

	/**
	 * Line refunds reduce net product revenue and a full bump refund removes its conversion.
	 */
	public function test_partial_and_full_bump_refunds_reconcile_revenue_and_conversion() {
		$product     = $this->create_product( 100, 'Refunded bump' );
		$rule        = $this->create_rule( $product );
		$attribution = $this->analytics->record_offer_view( $rule, WC()->cart );
		$order       = $this->create_bump_order( $product, $rule, $attribution, 2, 180 );
		$order->update_status( 'processing' );
		$order   = wc_get_order( $order->get_id() );
		$item_id = array_key_first( $order->get_items( 'line_item' ) );

		$partial_refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 90,
				'line_items' => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 90,
						'refund_tax'   => array(),
					),
				),
			)
		);
		$this->assertNotWPError( $partial_refund );
		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );

		$row = $this->get_conversion_row( $order->get_id(), $rule['id'] );
		$this->assertSame( '1', $row['is_valid'] );
		$this->assertEqualsWithDelta( 1.0, (float) $row['quantity'], 0.001 );
		$this->assertEqualsWithDelta( 90.0, (float) $row['net_revenue'], 0.001 );

		$full_refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 90,
				'line_items' => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 90,
						'refund_tax'   => array(),
					),
				),
			)
		);
		$this->assertNotWPError( $full_refund );
		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );

		$row = $this->get_conversion_row( $order->get_id(), $rule['id'] );
		$this->assertSame( '0', $row['is_valid'] );
		$this->assertEqualsWithDelta( 0.0, (float) $row['net_revenue'], 0.001 );
	}

	/**
	 * Refunding a different order line does not change the bump conversion.
	 */
	public function test_unrelated_line_refund_preserves_bump_conversion() {
		$bump_product  = $this->create_product( 100, 'Preserved bump' );
		$other_product = $this->create_product( 50, 'Refunded regular item' );
		$rule          = $this->create_rule( $bump_product );
		$attribution   = $this->analytics->record_offer_view( $rule, WC()->cart );
		$order         = $this->create_bump_order( $bump_product, $rule, $attribution, 1, 90 );

		$other_item = new WC_Order_Item_Product();
		$other_item->set_product( $other_product );
		$other_item->set_quantity( 1 );
		$other_item->set_subtotal( 50 );
		$other_item->set_total( 50 );
		$order->add_item( $other_item );
		$order->calculate_totals( false );
		$order->save();
		$order->update_status( 'processing' );
		$order         = wc_get_order( $order->get_id() );
		$other_item_id = 0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( $other_product->get_id() === $item->get_product_id() ) {
				$other_item_id = $item_id;
				break;
			}
		}
		$this->assertGreaterThan( 0, $other_item_id );

		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 50,
				'line_items' => array(
					$other_item_id => array(
						'qty'          => 1,
						'refund_total' => 50,
						'refund_tax'   => array(),
					),
				),
			)
		);
		$this->assertNotWPError( $refund );
		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );

		$row = $this->get_conversion_row( $order->get_id(), $rule['id'] );
		$this->assertSame( '1', $row['is_valid'] );
		$this->assertEqualsWithDelta( 1.0, (float) $row['quantity'], 0.001 );
		$this->assertEqualsWithDelta( 90.0, (float) $row['net_revenue'], 0.001 );
	}

	/**
	 * Orders without current-generation attribution are intentionally historical.
	 */
	public function test_orders_without_current_tracking_are_not_imported() {
		$product = $this->create_product( 100, 'Historical bump' );
		$rule    = $this->create_rule( $product );
		$order   = wc_create_order();
		$item    = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( 90 );
		$item->set_total( 90 );
		$item->add_meta_data( '_bumpmint_rule_id', $rule['id'], true );
		$item->add_meta_data( '_bumpmint_rule_name', $rule['name'], true );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();
		$order->update_status( 'processing' );

		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );
		$this->assertSame( 0, $this->count_current_valid_conversions() );
	}

	/**
	 * Resetting starts a new generation that old order metadata cannot re-enter.
	 */
	public function test_reset_prevents_old_orders_from_reappearing() {
		$product     = $this->create_product( 100, 'Reset bump' );
		$rule        = $this->create_rule( $product );
		$attribution = $this->analytics->record_offer_view( $rule, WC()->cart );
		$order       = $this->create_bump_order( $product, $rule, $attribution, 1, 90 );
		$order->update_status( 'processing' );
		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );

		$old_tracking_id = BumpMint_Analytics::get_tracking_id();
		$this->assertSame( 1, $this->count_current_valid_conversions() );
		$this->assertTrue( $this->analytics->reset_all() );
		$this->assertNotSame( $old_tracking_id, BumpMint_Analytics::get_tracking_id() );
		$this->assertSame( 0, $this->count_current_views() );
		$this->assertSame( 0, $this->count_current_valid_conversions() );

		$this->analytics->reconcile_order( wc_get_order( $order->get_id() ) );
		$this->assertSame( 0, $this->count_current_valid_conversions() );
	}

	/**
	 * Creates an order item carrying the same trusted metadata as checkout.
	 *
	 * @param WC_Product $product     Product.
	 * @param array      $rule        Saved rule.
	 * @param array      $attribution Current tracking attribution.
	 * @param int        $quantity    Ordered quantity.
	 * @param float      $total       Product line total excluding tax.
	 * @return WC_Order
	 */
	private function create_bump_order( $product, array $rule, array $attribution, $quantity, $total ) {
		$order = wc_create_order();
		$order->set_currency( 'USD' );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$item->set_subtotal( $total );
		$item->set_total( $total );
		$item->add_meta_data( '_bumpmint_rule_id', $rule['id'], true );
		$item->add_meta_data( '_bumpmint_rule_name', $rule['name'], true );
		$item->add_meta_data( '_bumpmint_analytics_tracking_id', $attribution['tracking_id'], true );
		$item->add_meta_data( '_bumpmint_analytics_session_hash', $attribution['session_hash'], true );
		$item->add_meta_data( '_bumpmint_analytics_view_id', $attribution['view_id'], true );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();

		return $order;
	}

	/**
	 * Returns the current-generation conversion row for an order and rule.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $rule_id  Rule ID.
	 * @return array
	 */
	private function get_conversion_row( $order_id, $rule_id ) {
		global $wpdb;

		$table = BumpMint_Analytics::get_conversions_table_name();
		return (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE tracking_id = %s AND order_id = %d AND rule_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact test table.
				BumpMint_Analytics::get_tracking_id(),
				$order_id,
				$rule_id
			),
			ARRAY_A
		);
	}

	/**
	 * Counts current-generation views.
	 *
	 * @return int
	 */
	private function count_current_views() {
		global $wpdb;

		$table = BumpMint_Analytics::get_views_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE tracking_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact test table.
				BumpMint_Analytics::get_tracking_id()
			)
		);
	}

	/**
	 * Counts current valid conversion rows.
	 *
	 * @return int
	 */
	private function count_current_valid_conversions() {
		global $wpdb;

		$table = BumpMint_Analytics::get_conversions_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE tracking_id = %s AND is_valid = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact test table.
				BumpMint_Analytics::get_tracking_id()
			)
		);
	}

	/**
	 * Clears rows without changing the current generation.
	 */
	private function clear_analytics_tables() {
		global $wpdb;

		$views       = BumpMint_Analytics::get_views_table_name();
		$conversions = BumpMint_Analytics::get_conversions_table_name();
		$wpdb->query( "DELETE FROM {$views}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact test table.
		$wpdb->query( "DELETE FROM {$conversions}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact test table.
	}
}
