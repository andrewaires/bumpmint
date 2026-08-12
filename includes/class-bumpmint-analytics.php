<?php
/**
 * Lightweight analytics collection and WooCommerce order reconciliation.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects anonymous offer views and reconciles paid order bump conversions.
 */
class BumpMint_Analytics {

	const VIEWS_TABLE_SUFFIX       = 'bumpmint_analytics_views';
	const CONVERSIONS_TABLE_SUFFIX = 'bumpmint_analytics_conversions';
	const TRACKING_STARTED_OPTION  = 'bumpmint_analytics_started_at';
	const TRACKING_ID_OPTION       = 'bumpmint_analytics_tracking_id';
	const CHECKOUT_TOKEN_KEY       = 'bumpmint_analytics_token';
	const AJAX_ACTION              = 'bumpmint_record_views';
	const NONCE_ACTION             = 'bumpmint_record_views';
	const MAX_RULES_PER_REQUEST    = 20;

	/**
	 * Registers public tracking and WooCommerce reconciliation hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_record_views' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_record_views' ) );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_classic_order_processed' ), 100, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'handle_store_api_order_processed' ), 100 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 100, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refunded' ), 100, 2 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'handle_refund_deleted' ), 100, 2 );
		add_action( 'woocommerce_trash_order', array( $this, 'handle_order_trashed' ), 100 );
		add_action( 'woocommerce_delete_order', array( $this, 'handle_order_deleted' ), 100 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'reset_checkout_view_context' ), 10, 0 );
	}

	/**
	 * Creates or updates the dedicated analytics tables.
	 *
	 * @return bool Whether both tables are available after the operation.
	 */
	public static function install_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$views_table       = esc_sql( self::get_views_table_name() );
		$conversions_table = esc_sql( self::get_conversions_table_name() );
		$charset_collate   = $wpdb->get_charset_collate();

		$views_sql = "CREATE TABLE {$views_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tracking_id varchar(36) NOT NULL,
			rule_id varchar(36) NOT NULL,
			rule_name varchar(120) NOT NULL,
			session_hash char(64) NOT NULL,
			currency varchar(10) NOT NULL,
			viewed_at_gmt datetime NOT NULL,
			view_date date NOT NULL,
			view_weekday tinyint(1) unsigned NOT NULL,
			view_hour tinyint(2) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_rule_day (tracking_id,session_hash,rule_id,view_date,currency),
			KEY tracking_rule_date (tracking_id,rule_id,view_date),
			KEY tracking_currency_date (tracking_id,currency,view_date)
		) {$charset_collate};";

		$conversions_sql = "CREATE TABLE {$conversions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tracking_id varchar(36) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			rule_id varchar(36) NOT NULL,
			rule_name varchar(120) NOT NULL,
			session_hash char(64) NOT NULL,
			view_id bigint(20) unsigned NOT NULL DEFAULT 0,
			currency varchar(10) NOT NULL,
			quantity decimal(18,6) NOT NULL DEFAULT 0,
			net_revenue decimal(26,8) NOT NULL DEFAULT 0,
			order_status varchar(40) NOT NULL,
			is_valid tinyint(1) unsigned NOT NULL DEFAULT 0,
			converted_at_gmt datetime NOT NULL,
			conversion_date date NOT NULL,
			conversion_weekday tinyint(1) unsigned NOT NULL,
			conversion_hour tinyint(2) unsigned NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_rule (tracking_id,order_id,rule_id),
			KEY tracking_valid_date (tracking_id,is_valid,conversion_date),
			KEY tracking_rule_date (tracking_id,rule_id,is_valid,conversion_date),
			KEY view_id (view_id),
			KEY tracking_currency_date (tracking_id,currency,conversion_date)
		) {$charset_collate};";

		dbDelta( $views_sql );
		dbDelta( $conversions_sql );

		if ( ! self::table_exists( $views_table ) || ! self::table_exists( $conversions_table ) ) {
			return false;
		}

		self::ensure_tracking_options();
		return true;
	}

	/**
	 * Returns the site-specific views table name.
	 *
	 * @return string
	 */
	public static function get_views_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::VIEWS_TABLE_SUFFIX;
	}

	/**
	 * Returns the site-specific conversions table name.
	 *
	 * @return string
	 */
	public static function get_conversions_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::CONVERSIONS_TABLE_SUFFIX;
	}

	/**
	 * Returns the UTC time at which the current analytics generation began.
	 *
	 * @return string
	 */
	public static function get_tracking_started_at() {
		$value = get_option( self::TRACKING_STARTED_OPTION, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Returns the opaque identifier for the current analytics generation.
	 *
	 * @return string
	 */
	public static function get_tracking_id() {
		$value = get_option( self::TRACKING_ID_OPTION, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Returns public request data used by either checkout client.
	 *
	 * @return array<string,string>
	 */
	public static function get_frontend_config() {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		);
	}

	/**
	 * Records a bounded list of visible, currently eligible rules.
	 */
	public function ajax_record_views() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$raw_rule_ids = isset( $_POST['rule_ids'] )
			? map_deep( wp_unslash( (array) $_POST['rule_ids'] ), 'sanitize_text_field' )
			: array();
		if ( ! is_array( $raw_rule_ids ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_success( array( 'recorded' => 0 ) );
		}

		$rule_ids = array();
		foreach ( array_slice( $raw_rule_ids, 0, self::MAX_RULES_PER_REQUEST ) as $raw_rule_id ) {
			if ( ! is_scalar( $raw_rule_id ) ) {
				continue;
			}

			$rule_id = sanitize_text_field( (string) $raw_rule_id );
			if ( '' !== $rule_id ) {
				$rule_ids[] = $rule_id;
			}
		}
		$rule_ids = array_values( array_unique( $rule_ids ) );

		$recorded = 0;
		foreach ( $rule_ids as $rule_id ) {
			$rule = BumpMint_Rules::get_rule( $rule_id );
			if ( ! $rule || 'active' !== $rule['status'] || ! BumpMint_Conditions::matches( $rule, WC()->cart ) ) {
				continue;
			}

			$attribution = $this->record_offer_view( $rule, WC()->cart );
			if ( ! empty( $attribution['view_id'] ) ) {
				++$recorded;
			}
		}

		wp_send_json_success( array( 'recorded' => $recorded ) );
	}

	/**
	 * Records one rule view for the checkout journey and returns trusted attribution.
	 *
	 * Selection requests call this too, ensuring a selected offer always has an
	 * attributable view even when IntersectionObserver or its request was blocked.
	 *
	 * @param array        $rule Saved rule.
	 * @param WC_Cart|null $cart Current cart.
	 * @return array<string,mixed>
	 */
	public function record_offer_view( array $rule, $cart = null ) {
		global $wpdb;

		if ( ! $this->is_ready() || empty( $rule['id'] ) || ! WC()->session ) {
			return array();
		}

		$session_hash = $this->get_checkout_hash();
		$tracking_id  = self::get_tracking_id();
		if ( '' === $session_hash || '' === $tracking_id ) {
			return array();
		}

		$local_now   = current_datetime();
		$gmt_now     = $local_now->setTimezone( new DateTimeZone( 'UTC' ) );
		$rule_id     = sanitize_text_field( (string) $rule['id'] );
		$rule_name   = isset( $rule['name'] ) ? sanitize_text_field( (string) $rule['name'] ) : '';
		$currency    = $this->get_current_currency();
		$views_table = self::get_views_table_name();

		$query = $wpdb->prepare(
			"INSERT IGNORE INTO %i
			(tracking_id, rule_id, rule_name, session_hash, currency, viewed_at_gmt, view_date, view_weekday, view_hour)
			VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %d)",
			$views_table,
			$tracking_id,
			$rule_id,
			$rule_name,
			$session_hash,
			$currency,
			$gmt_now->format( 'Y-m-d H:i:s' ),
			$local_now->format( 'Y-m-d' ),
			(int) $local_now->format( 'N' ),
			(int) $local_now->format( 'G' )
		);
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Analytics write against a dedicated table; prepared immediately above.

		$view_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed lookup retrieves the ID of the view just inserted or previously deduplicated.
			$wpdb->prepare(
				"SELECT id FROM %i WHERE tracking_id = %s AND session_hash = %s AND rule_id = %s AND view_date = %s AND currency = %s LIMIT 1",
				$views_table,
				$tracking_id,
				$session_hash,
				$rule_id,
				$local_now->format( 'Y-m-d' ),
				$currency
			)
		);

		if ( ! $view_id ) {
			return array();
		}

		$attribution = array(
			'session_hash' => $session_hash,
			'tracking_id'  => $tracking_id,
			'view_id'      => absint( $view_id ),
		);

		if ( $cart ) {
			$this->apply_attribution_to_cart_rule( $rule_id, $attribution, $cart );
		}

		return $attribution;
	}

	/**
	 * Adds current analytics attribution to selected lines from one rule.
	 *
	 * @param string       $rule_id     Rule ID.
	 * @param array        $attribution Trusted attribution.
	 * @param WC_Cart|null $cart        Cart instance.
	 * @return void
	 */
	public function apply_attribution_to_cart_rule( $rule_id, array $attribution, $cart ) {
		if ( ! $cart || empty( $attribution['session_hash'] ) || empty( $attribution['tracking_id'] ) || empty( $attribution['view_id'] ) ) {
			return;
		}

		$changed = false;
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if (
				empty( $cart_item['bumpmint_rule_id'] )
				|| ! hash_equals( (string) $rule_id, (string) $cart_item['bumpmint_rule_id'] )
			) {
				continue;
			}

			$cart->cart_contents[ $cart_item_key ]['bumpmint_analytics_session_hash'] = $attribution['session_hash'];
			$cart->cart_contents[ $cart_item_key ]['bumpmint_analytics_tracking_id']  = $attribution['tracking_id'];
			$cart->cart_contents[ $cart_item_key ]['bumpmint_analytics_view_id']      = absint( $attribution['view_id'] );
			$changed = true;
		}

		if ( $changed ) {
			$cart->set_session();
		}
	}

	/**
	 * Copies trusted cart attribution into private order-item metadata.
	 *
	 * @param WC_Order_Item_Product $item   Order item being created.
	 * @param array                 $values Cart item values.
	 * @return void
	 */
	public function add_order_item_attribution_meta( $item, array $values ) {
		$tracking_id = isset( $values['bumpmint_analytics_tracking_id'] )
			? sanitize_text_field( (string) $values['bumpmint_analytics_tracking_id'] )
			: '';
		$current_id  = self::get_tracking_id();

		if ( '' === $tracking_id || '' === $current_id || ! hash_equals( $current_id, $tracking_id ) ) {
			return;
		}

		$session_hash = isset( $values['bumpmint_analytics_session_hash'] )
			? strtolower( sanitize_text_field( (string) $values['bumpmint_analytics_session_hash'] ) )
			: '';
		$view_id      = isset( $values['bumpmint_analytics_view_id'] ) ? absint( $values['bumpmint_analytics_view_id'] ) : 0;

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $session_hash ) || ! $view_id ) {
			return;
		}

		$item->add_meta_data( '_bumpmint_analytics_tracking_id', $tracking_id, true );
		$item->add_meta_data( '_bumpmint_analytics_session_hash', $session_hash, true );
		$item->add_meta_data( '_bumpmint_analytics_view_id', $view_id, true );
	}

	/**
	 * Reconciles an order after classic checkout creates all line items.
	 *
	 * @param int      $order_id   Order ID.
	 * @param array    $posted_data Checkout data.
	 * @param WC_Order $order      Order object.
	 */
	public function handle_classic_order_processed( $order_id, $posted_data, $order ) {
		unset( $posted_data );
		$this->reconcile_order( $order ? $order : $order_id );
		$this->reset_checkout_view_context();
	}

	/**
	 * Reconciles an order after Checkout Blocks creates all line items.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function handle_store_api_order_processed( $order ) {
		$this->reconcile_order( $order );
		$this->reset_checkout_view_context();
	}

	/**
	 * Starts a fresh view context after a checkout journey is completed or reset.
	 *
	 * Repeated WooCommerce AJAX renders keep the current token, while the next
	 * cart or order journey receives a new token and can count the same bump again.
	 */
	public function reset_checkout_view_context() {
		if ( ! WC()->session ) {
			return;
		}

		WC()->session->set( self::CHECKOUT_TOKEN_KEY, '' );
	}

	/**
	 * Reconciles every order status transition against WooCommerce paid statuses.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     Previous status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order object.
	 */
	public function handle_order_status_changed( $order_id, $from, $to, $order ) {
		unset( $from, $to );
		$this->reconcile_order( $order ? $order : $order_id );
	}

	/**
	 * Reconciles line-level refunds created through WooCommerce.
	 *
	 * @param int $order_id  Parent order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function handle_order_refunded( $order_id, $refund_id ) {
		unset( $refund_id );
		$this->reconcile_order( $order_id );
	}

	/**
	 * Reconciles an order when an admin deletes one of its refunds.
	 *
	 * @param int $refund_id Refund ID.
	 * @param int $order_id  Parent order ID.
	 */
	public function handle_refund_deleted( $refund_id, $order_id ) {
		unset( $refund_id );
		$this->reconcile_order( $order_id );
	}

	/**
	 * Excludes a trashed order without deleting its reversible analytics row.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order_trashed( $order_id ) {
		$this->invalidate_order( $order_id, 'trash' );
	}

	/**
	 * Removes analytics rows whose WooCommerce order was permanently deleted.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order_deleted( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( ! $order_id || ! $this->is_ready() ) {
			return;
		}

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A deleted WooCommerce order must be removed from the dedicated report table immediately.
			self::get_conversions_table_name(),
			array( 'order_id' => $order_id ),
			array( '%d' )
		);
	}

	/**
	 * Synchronizes one order's current paid/refunded state into analytics.
	 *
	 * Only order items carrying the current tracking generation are considered,
	 * which intentionally excludes orders created before Analytics began or before
	 * the latest reset.
	 *
	 * @param int|WC_Order $order Order object or ID.
	 * @return void
	 */
	public function reconcile_order( $order ) {
		global $wpdb;

		if ( ! $this->is_ready() ) {
			return;
		}

		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( absint( $order ) );
		if ( ! $order || ! $order->get_id() ) {
			return;
		}

		$order_id         = $order->get_id();
		$order_status     = sanitize_key( $order->get_status() );
		$current_tracking = self::get_tracking_id();
		$conversions      = self::get_conversions_table_name();
		$updated_at_gmt   = current_time( 'mysql', true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current order state must be written immediately to the dedicated analytics table.
			$conversions,
			array(
				'is_valid'      => 0,
				'order_status'  => $order_status,
				'updated_at_gmt' => $updated_at_gmt,
			),
			array( 'order_id' => $order_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( '' === $current_tracking ) {
			return;
		}

		$groups = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$rule_id     = sanitize_text_field( (string) $item->get_meta( '_bumpmint_rule_id', true ) );
			$tracking_id = sanitize_text_field( (string) $item->get_meta( '_bumpmint_analytics_tracking_id', true ) );
			if ( '' === $rule_id || '' === $tracking_id || ! hash_equals( $current_tracking, $tracking_id ) ) {
				continue;
			}

			$session_hash = strtolower( sanitize_text_field( (string) $item->get_meta( '_bumpmint_analytics_session_hash', true ) ) );
			$view_id      = absint( $item->get_meta( '_bumpmint_analytics_view_id', true ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $session_hash ) || ! $this->is_valid_view_attribution( $view_id, $rule_id, $session_hash ) ) {
				continue;
			}

			if ( ! isset( $groups[ $rule_id ] ) ) {
				$groups[ $rule_id ] = array(
					'rule_name'    => sanitize_text_field( (string) $item->get_meta( '_bumpmint_rule_name', true ) ),
					'session_hash' => $session_hash,
					'view_id'      => $view_id,
					'quantity'     => 0.0,
					'net_revenue'  => 0.0,
					'has_remaining' => false,
				);
			}

			$ordered_quantity = max( 0.0, (float) $item->get_quantity() );
			$refunded_quantity = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
			$remaining_quantity = max( 0.0, $ordered_quantity - $refunded_quantity );
			$line_total          = max( 0.0, (float) $item->get_total() );
			$refunded_total      = max( 0.0, (float) $order->get_total_refunded_for_item( $item_id ) );
			$remaining_revenue   = max( 0.0, $line_total - $refunded_total );
			$has_remaining       = $remaining_quantity > 0
				&& ( $line_total <= 0.0 || $remaining_revenue > 0.0 || $refunded_total <= 0.0 );

			if ( $has_remaining ) {
				$groups[ $rule_id ]['quantity']      += $remaining_quantity;
				$groups[ $rule_id ]['net_revenue']   += $remaining_revenue;
				$groups[ $rule_id ]['has_remaining']  = true;
			}
		}

		if ( empty( $groups ) ) {
			return;
		}

		$conversion_time = $this->get_order_conversion_time( $order );
		$is_paid         = (bool) $order->is_paid();
		$currency        = $this->sanitize_currency( $order->get_currency() );

		foreach ( $groups as $rule_id => $group ) {
			$is_valid = $is_paid && ! empty( $group['has_remaining'] );
			$exists   = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed existence check used once per order bump during reconciliation.
				$wpdb->prepare(
					"SELECT id FROM %i WHERE tracking_id = %s AND order_id = %d AND rule_id = %s LIMIT 1",
					$conversions,
					$current_tracking,
					$order_id,
					$rule_id
				)
			);

			if ( ! $is_valid && ! $exists ) {
				continue;
			}

			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reconciliation intentionally upserts the current order-bump state.
				$conversions,
				array(
					'tracking_id'          => $current_tracking,
					'order_id'              => $order_id,
					'rule_id'               => $rule_id,
					'rule_name'             => $group['rule_name'],
					'session_hash'          => $group['session_hash'],
					'view_id'               => $group['view_id'],
					'currency'              => $currency,
					'quantity'              => wc_format_decimal( $group['quantity'], 6 ),
					'net_revenue'           => wc_format_decimal( $group['net_revenue'], 8 ),
					'order_status'          => $order_status,
					'is_valid'              => $is_valid ? 1 : 0,
					'converted_at_gmt'      => $conversion_time['gmt'],
					'conversion_date'       => $conversion_time['date'],
					'conversion_weekday'    => $conversion_time['weekday'],
					'conversion_hour'       => $conversion_time['hour'],
					'updated_at_gmt'        => $updated_at_gmt,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Permanently clears Analytics and starts a new tracking generation.
	 *
	 * Rules, WooCommerce orders, and private order-item audit metadata remain
	 * untouched. The new generation prevents those old order items from being
	 * reintroduced by a later status transition.
	 *
	 * @return bool
	 */
	public function reset_all() {
		global $wpdb;

		if ( ! $this->is_ready() ) {
			return false;
		}

		$views_table         = self::get_views_table_name();
		$conversions_table   = self::get_conversions_table_name();
		$views_query         = $wpdb->prepare( 'DELETE FROM %i', $views_table );
		$conversions_query   = $wpdb->prepare( 'DELETE FROM %i', $conversions_table );
		$views_deleted       = $wpdb->query( $views_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator reset against the dedicated table; prepared immediately above.
		$conversions_deleted = $wpdb->query( $conversions_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator reset against the dedicated table; prepared immediately above.

		if ( false === $views_deleted || false === $conversions_deleted ) {
			return false;
		}

		update_option( self::TRACKING_STARTED_OPTION, current_time( 'mysql', true ), true );
		update_option( self::TRACKING_ID_OPTION, wp_generate_uuid4(), true );
		return true;
	}

	/**
	 * Returns whether schema and generation data are ready.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return defined( 'BUMPMINT_STORAGE_VERSION' )
			&& (int) get_option( BUMPMINT_STORAGE_VERSION_KEY, 0 ) >= 2
			&& '' !== self::get_tracking_id();
	}

	/**
	 * Generates a pseudonymous hash for the current checkout journey.
	 *
	 * @return string
	 */
	private function get_checkout_hash() {
		if ( ! WC()->session ) {
			return '';
		}

		$token = WC()->session->get( self::CHECKOUT_TOKEN_KEY, '' );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = wp_generate_uuid4();
			WC()->session->set( self::CHECKOUT_TOKEN_KEY, $token );
		}

		return hash_hmac( 'sha256', self::get_tracking_id() . '|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Returns the current effective WooCommerce currency code.
	 *
	 * @return string
	 */
	private function get_current_currency() {
		return $this->sanitize_currency( get_woocommerce_currency() );
	}

	/**
	 * Sanitizes a WooCommerce currency without assuming three-letter codes.
	 *
	 * @param mixed $currency Currency value.
	 * @return string
	 */
	private function sanitize_currency( $currency ) {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		$currency = preg_replace( '/[^A-Z0-9_-]/', '', $currency );
		return substr( (string) $currency, 0, 10 );
	}

	/**
	 * Confirms that item metadata points to a matching plugin-owned view.
	 *
	 * @param int    $view_id      View ID.
	 * @param string $rule_id      Rule ID.
	 * @param string $session_hash Session hash.
	 * @return bool
	 */
	private function is_valid_view_attribution( $view_id, $rule_id, $session_hash ) {
		global $wpdb;

		if ( ! $view_id ) {
			return false;
		}

		$views_table = self::get_views_table_name();
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed trust check against the dedicated attribution table.
			$wpdb->prepare(
				"SELECT id FROM %i WHERE tracking_id = %s AND id = %d AND rule_id = %s AND session_hash = %s LIMIT 1",
				$views_table,
				self::get_tracking_id(),
				$view_id,
				$rule_id,
				$session_hash
			)
		);
	}

	/**
	 * Returns a stable conversion time in UTC and the site's local timezone.
	 *
	 * @param WC_Order $order Order object.
	 * @return array{gmt:string,date:string,weekday:int,hour:int}
	 */
	private function get_order_conversion_time( $order ) {
		$order_date = $order->get_date_paid();
		if ( ! $order_date ) {
			$order_date = $order->get_date_created();
		}

		$timestamp = $order_date ? $order_date->getTimestamp() : time();
		$utc       = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$local     = $utc->setTimezone( wp_timezone() );

		return array(
			'gmt'     => $utc->format( 'Y-m-d H:i:s' ),
			'date'    => $local->format( 'Y-m-d' ),
			'weekday' => (int) $local->format( 'N' ),
			'hour'    => (int) $local->format( 'G' ),
		);
	}

	/**
	 * Marks existing rows invalid when an order is moved to trash.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Current synthetic status.
	 * @return void
	 */
	private function invalidate_order( $order_id, $status ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( ! $order_id || ! $this->is_ready() ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Order state invalidation must be persisted immediately.
			self::get_conversions_table_name(),
			array(
				'is_valid'       => 0,
				'order_status'   => sanitize_key( $status ),
				'updated_at_gmt' => current_time( 'mysql', true ),
			),
			array( 'order_id' => $order_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Adds the tracking start and generation options without overwriting them.
	 */
	private static function ensure_tracking_options() {
		if ( false === get_option( self::TRACKING_STARTED_OPTION, false ) ) {
			add_option( self::TRACKING_STARTED_OPTION, current_time( 'mysql', true ), '', true );
		}

		if ( false === get_option( self::TRACKING_ID_OPTION, false ) ) {
			add_option( self::TRACKING_ID_OPTION, wp_generate_uuid4(), '', true );
		}
	}

	/**
	 * Checks a schema table through the active WordPress database connection.
	 *
	 * @param string $table_name Exact plugin-owned table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;
		return $table_name === $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema upgrade check.
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);
	}
}
