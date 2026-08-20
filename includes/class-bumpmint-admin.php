<?php
/**
 * Order bump administration screens.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles rule listing, creation, editing, status changes, and deletion.
 */
class BumpMint_Admin {

	const CAPABILITY = 'manage_woocommerce';
	const PAGE_SLUG  = 'bumpmint';

	/**
	 * Submitted data retained when validation fails.
	 *
	 * @var array
	 */
	private $submitted_rule_data = array();

	/**
	 * Registers admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'wp_ajax_bumpmint_get_location_states', array( $this, 'ajax_get_location_states' ) );
		add_action( 'wp_ajax_bumpmint_search_coupons', array( $this, 'ajax_search_coupons' ) );
	}

	/**
	 * Registers the Order Bumps menu page.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Order Bumps', 'bumpmint-order-bump-for-woocommerce' ),
			__( 'Order Bumps', 'bumpmint-order-bump-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cart',
			56
		);
	}

	/**
	 * Loads lightweight assets only on the plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$is_form_page       = in_array( $this->get_current_action(), array( 'new', 'edit' ), true );
		$style_path          = BUMPMINT_PLUGIN_DIR . 'assets/css/admin.css';
		$style_version       = file_exists( $style_path ) ? (string) filemtime( $style_path ) : BUMPMINT_VERSION;
		$script_dependencies = array( 'jquery' );
		$script_path         = BUMPMINT_PLUGIN_DIR . 'assets/js/admin.js';
		$script_version      = file_exists( $script_path ) ? (string) filemtime( $script_path ) : BUMPMINT_VERSION;

		wp_enqueue_style(
			'bumpmint-admin',
			BUMPMINT_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$style_version
		);

		if ( $is_form_page ) {
			wp_enqueue_media();
			wp_enqueue_style( 'woocommerce_admin_styles' );
			$script_dependencies[] = 'jquery-tiptip';
			$script_dependencies[] = 'wc-enhanced-select';
		}

		wp_enqueue_script(
			'bumpmint-admin',
			BUMPMINT_PLUGIN_URL . 'assets/js/admin.js',
			$script_dependencies,
			$script_version,
			true
		);

		wp_localize_script(
			'bumpmint-admin',
			'bumpmintAdmin',
			array(
				'selectImageTitle' => __( 'Select image', 'bumpmint-order-bump-for-woocommerce' ),
				'useImageText'     => __( 'Use this image', 'bumpmint-order-bump-for-woocommerce' ),
				'confirmDelete'    => __( 'Are you sure you want to delete this order bump?', 'bumpmint-order-bump-for-woocommerce' ),
				'maxScheduleDates' => BumpMint_Rules::MAX_SCHEDULE_DATES,
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'locationNonce'    => wp_create_nonce( 'bumpmint_location_states' ),
				'locationLoadError' => __( 'States and regions could not be loaded. Try selecting the countries again.', 'bumpmint-order-bump-for-woocommerce' ),
				'couponSearchNonce' => wp_create_nonce( 'bumpmint_search_coupons' ),
			)
		);
	}

	/**
	 * Returns states and regions for selected countries on the rule form.
	 */
	public function ajax_get_location_states() {
		check_ajax_referer( 'bumpmint_location_states', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage order bumps.', 'bumpmint-order-bump-for-woocommerce' ) ), 403 );
		}

		$country_codes = isset( $_POST['country_codes'] ) && is_array( $_POST['country_codes'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['country_codes'] ) )
			: array();
		$country_codes = $this->sanitize_location_country_codes( $country_codes );
		$options       = $this->get_location_state_options( $country_codes );
		$response      = array();

		foreach ( $options as $state_code => $state_label ) {
			$response[] = array(
				'value' => $state_code,
				'label' => $state_label,
			);
		}

		wp_send_json_success( array( 'states' => $response ) );
	}

	/**
	 * Searches published WooCommerce coupons for the admin rule selector.
	 */
	public function ajax_search_coupons() {
		check_ajax_referer( 'bumpmint_search_coupons', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage order bumps.', 'bumpmint-order-bump-for-woocommerce' ) ), 403 );
		}

		$term = isset( $_POST['term'] ) && is_scalar( $_POST['term'] )
			? sanitize_text_field( wp_unslash( $_POST['term'] ) )
			: '';
		$page = isset( $_POST['page'] ) && is_scalar( $_POST['page'] )
			? max( 1, absint( wp_unslash( $_POST['page'] ) ) )
			: 1;
		$args = array(
			'post_type'              => 'shop_coupon',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => 21,
			'offset'                 => ( $page - 1 ) * 20,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( '' !== $term ) {
			$args['s'] = $term;
		}

		$query    = new WP_Query( $args );
		$post_ids = array_map( 'absint', $query->posts );
		$has_more = count( $post_ids ) > 20;
		$results  = array();

		foreach ( array_slice( $post_ids, 0, 20 ) as $coupon_id ) {
			$coupon_code = get_the_title( $coupon_id );
			if ( '' === $coupon_code ) {
				/* translators: %d: coupon post ID. */
				$coupon_code = sprintf( __( 'Coupon #%d', 'bumpmint-order-bump-for-woocommerce' ), $coupon_id );
			}

			$results[] = array(
				'id'   => $coupon_id,
				'text' => wp_strip_all_tags( $coupon_code ),
			);
		}

		wp_send_json_success(
			array(
				'results'    => $results,
				'pagination' => array( 'more' => $has_more ),
			)
		);
	}

	/**
	 * Handles save, status, and delete requests before output begins.
	 */
	public function handle_actions() {
		if ( ! $this->is_plugin_page() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_POST['bumpmint_toggle_status'] ) ) {
			$rule_id = isset( $_POST['bumpmint_rule_id'] ) && is_scalar( $_POST['bumpmint_rule_id'] )
				? sanitize_text_field( wp_unslash( $_POST['bumpmint_rule_id'] ) )
				: '';
			$status  = isset( $_POST['bumpmint_rule_status'] ) && is_scalar( $_POST['bumpmint_rule_status'] )
				? sanitize_key( wp_unslash( $_POST['bumpmint_rule_status'] ) )
				: '';

			check_admin_referer( 'bumpmint_toggle_rule_' . $rule_id, 'bumpmint_nonce_status' );
			$result = BumpMint_Rules::set_status( $rule_id, $status );

			wp_safe_redirect(
				add_query_arg(
					'bumpmint_status',
					is_wp_error( $result ) ? 'error' : $result['status'],
					admin_url( 'admin.php?page=' . self::PAGE_SLUG )
				)
			);
			exit;
		}

		if (
			isset( $_POST['bumpmint_nonce_save'] ) &&
			wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['bumpmint_nonce_save'] ) ),
				'bumpmint_save_rule'
			)
		) {
			$rule_id = isset( $_POST['bumpmint_rule_id'] )
				? sanitize_text_field( wp_unslash( $_POST['bumpmint_rule_id'] ) )
				: '';
			$data    = isset( $_POST['bumpmint_rule'] )
				? map_deep( wp_unslash( $_POST['bumpmint_rule'] ), 'sanitize_text_field' )
				: array();
			$data    = is_array( $data ) ? $data : array();

			if ( isset( $_POST['bumpmint_rule']['description'] ) ) {
				$data['description'] = sanitize_textarea_field(
					wp_unslash( $_POST['bumpmint_rule']['description'] )
				);
			}

			if ( ! isset( $data['discount_enabled'] ) ) {
				$data['discount_enabled'] = '0';
			}
			if ( ! isset( $data['condition_category_enabled'] ) ) {
				$data['condition_category_enabled'] = '0';
			}
			if ( ! isset( $data['condition_category_ids'] ) ) {
				$data['condition_category_ids'] = array();
			}
			if ( ! isset( $data['customer_role_slugs'] ) ) {
				$data['customer_role_slugs'] = array();
			}
			if ( ! isset( $data['location_country_codes'] ) ) {
				$data['location_country_codes'] = array();
			}
			if ( ! isset( $data['location_state_codes'] ) ) {
				$data['location_state_codes'] = array();
			}
			if ( ! isset( $data['coupon_ids'] ) ) {
				$data['coupon_ids'] = array();
			}
			if ( ! isset( $data['schedule_dates'] ) ) {
				$data['schedule_dates'] = array();
			}
			if ( ! isset( $data['schedule_weekdays'] ) ) {
				$data['schedule_weekdays'] = array();
			}
			if ( ! isset( $data['schedule_time_enabled'] ) ) {
				$data['schedule_time_enabled'] = '0';
			}

			$result = BumpMint_Rules::save_rule( $rule_id, $data );
			if ( is_wp_error( $result ) ) {
				$this->submitted_rule_data = $data;
				add_settings_error( 'bumpmint', $result->get_error_code(), $result->get_error_message(), 'error' );
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&bumpmint_updated=1' ) );
			exit;
		}

		if ( 'delete' === $this->get_current_action() && isset( $_GET['rule'] ) ) {
			$rule_id = sanitize_text_field( wp_unslash( $_GET['rule'] ) );
			check_admin_referer( 'bumpmint_delete_rule_' . $rule_id );

			BumpMint_Rules::delete_rule( $rule_id );

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&bumpmint_deleted=1' ) );
			exit;
		}
	}

	/**
	 * Routes to the list or form screen.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage order bumps.', 'bumpmint-order-bump-for-woocommerce' ) );
		}

		$action = $this->get_current_action();
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$this->render_form_page( $action );
			return;
		}

		$this->render_list_page();
	}

	/**
	 * Displays the modern, WordPress-native rules table.
	 */
	private function render_list_page() {
		$rules         = BumpMint_Rules::get_rules();
		$status_notice = isset( $_GET['bumpmint_status'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice.
			? sanitize_key( wp_unslash( $_GET['bumpmint_status'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice.
			: '';
		?>
		<div class="wrap bumpmint-admin-wrap">
			<?php $this->render_page_header( __( 'Order Bumps', 'bumpmint-order-bump-for-woocommerce' ), true ); ?>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['bumpmint_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order bump saved successfully.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['bumpmint_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order bump deleted.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( BumpMint_Rules::STATUS_ACTIVE === $status_notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order bump activated.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php elseif ( BumpMint_Rules::STATUS_INACTIVE === $status_notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order bump deactivated.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php elseif ( 'error' === $status_notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The order bump status could not be updated.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $rules ) ) : ?>
				<div class="bumpmint-empty-state">
					<span class="dashicons dashicons-cart" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'Create your first order bump', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'Add a targeted offer to the WooCommerce checkout without writing code.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
					<a href="<?php echo esc_url( $this->get_new_url() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Add order bump', 'bumpmint-order-bump-for-woocommerce' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="bumpmint-table-card">
					<table class="wp-list-table widefat fixed striped table-view-list bumpmint-rules-table">
						<thead>
							<tr>
								<th class="column-actions"><?php esc_html_e( 'Actions', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
								<th class="column-title column-primary"><?php esc_html_e( 'Title', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
								<th class="column-rule"><?php esc_html_e( 'Rule type', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
								<th class="column-status"><?php esc_html_e( 'Status', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
								<th class="column-price"><?php esc_html_e( 'Price', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
								<th class="column-position"><?php esc_html_e( 'Position', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
								<th class="column-date"><?php esc_html_e( 'Date', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rules as $rule ) : ?>
								<?php $this->render_rule_row( $rule ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Displays one rules table row.
	 *
	 * @param array $rule Rule data.
	 */
	private function render_rule_row( array $rule ) {
		$products         = $this->get_products( $rule['bump_product_ids'] );
		$customer_summary = $this->get_customer_summary( $rule );
		$location_summary = $this->get_location_summary( $rule );
		$coupon_summary   = $this->get_coupon_summary( $rule );
		$schedule_summary = $this->get_schedule_summary( $rule );
		$is_active         = BumpMint_Rules::STATUS_ACTIVE === $rule['status'];
		$next_status       = $is_active ? BumpMint_Rules::STATUS_INACTIVE : BumpMint_Rules::STATUS_ACTIVE;
		$edit_url         = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&rule=' . rawurlencode( $rule['id'] ) );
		$delete_url       = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=delete&rule=' . rawurlencode( $rule['id'] ) ),
			'bumpmint_delete_rule_' . $rule['id']
		);

		$full_name  = $rule['name'];
		$short_name = wp_html_excerpt( $full_name, 52, '…' );
		$date       = ! empty( $rule['created_at'] )
			? get_date_from_gmt( $rule['created_at'], get_option( 'date_format' ) )
			: '—';
		/* translators: %s: internal order bump name. */
		$status_aria_label = sprintf( __( 'Status for %s', 'bumpmint-order-bump-for-woocommerce' ), $full_name );
		?>
		<tr>
			<td class="column-actions" data-colname="<?php esc_attr_e( 'Actions', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'bumpmint-order-bump-for-woocommerce' ); ?></a>
				<span aria-hidden="true"> | </span>
				<a href="<?php echo esc_url( $delete_url ); ?>" class="bumpmint-delete-link"><?php esc_html_e( 'Delete', 'bumpmint-order-bump-for-woocommerce' ); ?></a>
			</td>
			<td class="column-title column-primary" data-colname="<?php esc_attr_e( 'Title', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<strong>
					<a href="<?php echo esc_url( $edit_url ); ?>" title="<?php echo esc_attr( $full_name ); ?>">
						<?php echo esc_html( $short_name ); ?>
					</a>
				</strong>
				<?php if ( ! empty( $products ) ) : ?>
					<span class="bumpmint-row-detail"><?php echo esc_html( wp_html_excerpt( $this->get_product_names( $products ), 80, '…' ) ); ?></span>
				<?php endif; ?>
			</td>
			<td class="column-rule" data-colname="<?php esc_attr_e( 'Rule type', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<?php echo esc_html( BumpMint_Conditions::get_label( $rule['condition_type'] ) ); ?>
				<span class="bumpmint-row-detail"><?php echo esc_html( $this->get_condition_summary( $rule ) ); ?></span>
				<?php if ( $customer_summary ) : ?>
					<span class="bumpmint-row-detail"><?php echo esc_html( $customer_summary ); ?></span>
				<?php endif; ?>
				<?php if ( $location_summary ) : ?>
					<span class="bumpmint-row-detail"><?php echo esc_html( $location_summary ); ?></span>
				<?php endif; ?>
				<?php if ( $coupon_summary ) : ?>
					<span class="bumpmint-row-detail"><?php echo esc_html( $coupon_summary ); ?></span>
				<?php endif; ?>
				<?php if ( $schedule_summary ) : ?>
					<span class="bumpmint-row-detail"><?php echo esc_html( $schedule_summary ); ?></span>
				<?php endif; ?>
			</td>
			<td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="bumpmint-status-form">
					<?php wp_nonce_field( 'bumpmint_toggle_rule_' . $rule['id'], 'bumpmint_nonce_status', false ); ?>
					<input type="hidden" name="bumpmint_rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>" />
					<input type="hidden" name="bumpmint_rule_status" value="<?php echo esc_attr( $next_status ); ?>" />
					<button
						type="submit"
						name="bumpmint_toggle_status"
						value="1"
						class="bumpmint-switch-button"
						role="switch"
						aria-checked="<?php echo esc_attr( $is_active ? 'true' : 'false' ); ?>"
						aria-label="<?php echo esc_attr( $status_aria_label ); ?>"
						title="<?php echo esc_attr( $is_active ? __( 'Deactivate order bump', 'bumpmint-order-bump-for-woocommerce' ) : __( 'Activate order bump', 'bumpmint-order-bump-for-woocommerce' ) ); ?>"
					>
						<span class="bumpmint-switch-control" aria-hidden="true"></span>
						<span><?php echo esc_html( $is_active ? __( 'Active', 'bumpmint-order-bump-for-woocommerce' ) : __( 'Inactive', 'bumpmint-order-bump-for-woocommerce' ) ); ?></span>
					</button>
				</form>
			</td>
			<td class="column-price" data-colname="<?php esc_attr_e( 'Price', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<?php echo wp_kses_post( $this->get_products_price_html( $rule, $products ) ); ?>
			</td>
			<td class="column-position" data-colname="<?php esc_attr_e( 'Position', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<?php echo esc_html( BumpMint_Positions::get_label( $rule['position'] ) ); ?>
			</td>
			<td class="column-date" data-colname="<?php esc_attr_e( 'Date', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<?php echo esc_html( $date ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Displays the creation or editing form.
	 *
	 * @param string $action Current action.
	 */
	private function render_form_page( $action ) {
		$defaults = array(
			'id'                    => '',
			'name'                  => '',
			'condition_type'        => BumpMint_Conditions::PRODUCT,
			'condition_product_ids' => array(),
			'condition_category_enabled' => false,
			'condition_category_ids' => array(),
			'condition_match'       => 'any',
			'condition_operator'    => 'greater_than',
			'condition_value'       => '',
			'customer_audience'     => BumpMint_Conditions::AUDIENCE_EVERYONE,
			'customer_role_slugs'   => array(),
			'location_mode'         => BumpMint_Conditions::LOCATION_ALL,
			'location_operator'     => BumpMint_Conditions::LOCATION_INCLUDE,
			'location_unknown_behavior' => BumpMint_Conditions::LOCATION_UNKNOWN_HIDE,
			'location_country_codes' => array(),
			'location_state_codes'   => array(),
			'coupon_mode'            => BumpMint_Conditions::COUPON_NONE,
			'coupon_ids'             => array(),
			'schedule_type'         => BumpMint_Conditions::SCHEDULE_ALWAYS,
			'schedule_dates'        => array(),
			'schedule_weekdays'     => array(),
			'schedule_time_enabled' => false,
			'schedule_start_time'   => '',
			'schedule_end_time'     => '',
			'bump_product_ids'      => array(),
			'hide_if_in_cart'       => false,
			'position'              => BumpMint_Positions::BEFORE_PAYMENT,
			'discount_enabled'      => false,
			'discount_type'         => 'percentage',
			'discount_value'        => '',
			'max_quantity'          => 1,
			'badge_text'            => '',
			'offer_title'           => '',
			'description'           => '',
			'image_id'              => 0,
			'status'                => BumpMint_Rules::STATUS_ACTIVE,
		);

		$rule = array();
		if ( 'edit' === $action && isset( $_GET['rule'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route read only.
			$rule_id = sanitize_text_field( wp_unslash( $_GET['rule'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route read only.
			$rule    = BumpMint_Rules::get_rule( $rule_id );
		}

		$rule = wp_parse_args( $rule ? $rule : array(), $defaults );
		if ( ! empty( $this->submitted_rule_data ) ) {
			$rule = wp_parse_args( $this->submitted_rule_data, $rule );
		}

		$schedule_dates = array_values( array_filter( (array) $rule['schedule_dates'], 'is_scalar' ) );
		if ( empty( $schedule_dates ) ) {
			$schedule_dates = array( '' );
		}
		$timezone_name = wp_timezone()->getName();
		$is_active     = BumpMint_Rules::STATUS_ACTIVE === $rule['status'];
		$role_options  = $this->get_user_role_options( (array) $rule['customer_role_slugs'] );
		$country_options = $this->get_location_country_options( (array) $rule['location_country_codes'] );
		$state_options   = $this->get_location_state_options( (array) $rule['location_country_codes'], (array) $rule['location_state_codes'] );

		$errors = get_settings_errors( 'bumpmint' );
		?>
		<div class="wrap bumpmint-admin-wrap">
			<?php
			$this->render_page_header(
				$rule['id']
					? __( 'Edit order bump', 'bumpmint-order-bump-for-woocommerce' )
					: __( 'New order bump', 'bumpmint-order-bump-for-woocommerce' )
			);
			?>

			<?php foreach ( $errors as $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error['message'] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" class="bumpmint-rule-form">
				<?php wp_nonce_field( 'bumpmint_save_rule', 'bumpmint_nonce_save' ); ?>
				<input type="hidden" name="bumpmint_rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>" />

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Basic details', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-name"><?php esc_html_e( 'Internal name', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<input
									type="text"
									id="bumpmint-name"
									name="bumpmint_rule[name]"
									value="<?php echo esc_attr( $rule['name'] ); ?>"
									class="regular-text"
									maxlength="<?php echo esc_attr( BumpMint_Rules::NAME_MAX_LENGTH ); ?>"
									required
								/>
								<p class="description"><?php esc_html_e( 'Used only in the admin to help you identify this order bump.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							<td>
								<input type="hidden" name="bumpmint_rule[status]" value="<?php echo esc_attr( BumpMint_Rules::STATUS_INACTIVE ); ?>" />
								<label class="bumpmint-switch" for="bumpmint-status">
									<input
										type="checkbox"
										id="bumpmint-status"
										name="bumpmint_rule[status]"
										value="<?php echo esc_attr( BumpMint_Rules::STATUS_ACTIVE ); ?>"
										class="bumpmint-switch-input"
										role="switch"
										aria-describedby="bumpmint-status-description"
										<?php checked( $is_active ); ?>
									/>
									<span class="bumpmint-switch-control" aria-hidden="true"></span>
									<span class="bumpmint-switch-label bumpmint-switch-label-active"><?php esc_html_e( 'Active', 'bumpmint-order-bump-for-woocommerce' ); ?></span>
									<span class="bumpmint-switch-label bumpmint-switch-label-inactive"><?php esc_html_e( 'Inactive', 'bumpmint-order-bump-for-woocommerce' ); ?></span>
								</label>
								<p id="bumpmint-status-description" class="description"><?php esc_html_e( 'Inactive order bumps are not displayed at checkout, but their settings and Analytics history are preserved.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Display rule', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-condition-type"><?php esc_html_e( 'Show this offer when', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-condition-type" name="bumpmint_rule[condition_type]">
									<?php foreach ( BumpMint_Conditions::get_definitions() as $type => $definition ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $rule['condition_type'], $type ); ?>>
											<?php echo esc_html( $definition['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="bumpmint-condition-fields" data-condition-types="product">
							<th scope="row"><label for="bumpmint-condition-product"><?php esc_html_e( 'Trigger products', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<?php $this->render_product_select( 'bumpmint_rule[condition_product_ids][]', 'bumpmint-condition-product', $rule['condition_product_ids'] ); ?>
								<p class="description"><?php esc_html_e( 'Optional when category triggers are enabled. Select one or more products or variations that can trigger the offer.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="bumpmint-condition-fields" data-condition-types="product">
							<th scope="row">
								<label for="bumpmint-condition-category-enabled"><?php esc_html_e( 'Category triggers', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
								<?php
								echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
									__( 'When enabled, a product in any selected category can trigger this offer. Products and categories are combined with OR logic. Child categories also match when a parent category is selected.', 'bumpmint-order-bump-for-woocommerce' )
								);
								?>
							</th>
							<td>
								<label>
									<input
										type="checkbox"
										id="bumpmint-condition-category-enabled"
										name="bumpmint_rule[condition_category_enabled]"
										value="1"
										<?php checked( ! empty( $rule['condition_category_enabled'] ) ); ?>
									/>
									<?php esc_html_e( 'Also trigger this offer by product category', 'bumpmint-order-bump-for-woocommerce' ); ?>
								</label>
							</td>
						</tr>
						<tr class="bumpmint-condition-fields bumpmint-category-select-row" data-condition-types="product">
							<th scope="row"><label for="bumpmint-condition-category"><?php esc_html_e( 'Trigger categories', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<?php $this->render_category_select( 'bumpmint_rule[condition_category_ids][]', 'bumpmint-condition-category', $rule['condition_category_ids'] ); ?>
								<p class="description"><?php esc_html_e( 'Select one or more categories. Any product in a selected category can trigger the offer.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="bumpmint-condition-fields" data-condition-types="product">
							<th scope="row">
								<label for="bumpmint-condition-match"><?php esc_html_e( 'Trigger matching', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
								<?php
								echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
									__( 'Any selected product: show the offer when at least one selected product is in the cart. All selected products: show the offer only when every selected product is in the cart.', 'bumpmint-order-bump-for-woocommerce' )
								);
								?>
							</th>
							<td>
								<select id="bumpmint-condition-match" name="bumpmint_rule[condition_match]">
									<option value="any" <?php selected( $rule['condition_match'], 'any' ); ?>><?php esc_html_e( 'Any selected product', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
									<option value="all" <?php selected( $rule['condition_match'], 'all' ); ?>><?php esc_html_e( 'All selected products', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
						<tr class="bumpmint-condition-fields" data-condition-types="cart_subtotal cart_quantity">
							<th scope="row"><?php esc_html_e( 'Comparison', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							<td class="bumpmint-inline-fields">
								<select name="bumpmint_rule[condition_operator]">
									<option value="greater_than" <?php selected( $rule['condition_operator'], 'greater_than' ); ?>><?php esc_html_e( 'Greater than', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
									<option value="less_than" <?php selected( $rule['condition_operator'], 'less_than' ); ?>><?php esc_html_e( 'Less than', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
								</select>
								<input
									type="number"
									name="bumpmint_rule[condition_value]"
									value="<?php echo esc_attr( $rule['condition_value'] ); ?>"
									min="0"
									step="any"
									class="small-text"
								/>
								<p class="description"><?php esc_html_e( 'Cart subtotal excludes shipping, taxes, and products added by BumpMint. Quantity means total product units.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Customer targeting', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-customer-audience"><?php esc_html_e( 'Who can see this offer?', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-customer-audience" name="bumpmint_rule[customer_audience]" aria-describedby="bumpmint-customer-audience-description">
									<?php foreach ( BumpMint_Conditions::get_customer_audiences() as $audience_key => $audience_label ) : ?>
										<option value="<?php echo esc_attr( $audience_key ); ?>" <?php selected( $rule['customer_audience'], $audience_key ); ?>>
											<?php echo esc_html( $audience_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p id="bumpmint-customer-audience-description" class="description"><?php esc_html_e( 'Customer targeting is combined with the display rule and availability schedule.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="bumpmint-customer-roles-row">
							<th scope="row">
								<label for="bumpmint-customer-roles"><?php esc_html_e( 'User roles', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
								<?php
								echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
									__( 'A logged-in user can see the offer when they have at least one selected role. Roles registered by other plugins are included.', 'bumpmint-order-bump-for-woocommerce' )
								);
								?>
							</th>
							<td>
								<select
									id="bumpmint-customer-roles"
									name="bumpmint_rule[customer_role_slugs][]"
									class="wc-enhanced-select"
									multiple="multiple"
									data-placeholder="<?php esc_attr_e( 'Select user roles', 'bumpmint-order-bump-for-woocommerce' ); ?>"
									style="width: 360px; max-width: 100%;"
								>
									<?php foreach ( $role_options as $role_slug => $role_label ) : ?>
										<option value="<?php echo esc_attr( $role_slug ); ?>" <?php selected( in_array( $role_slug, (array) $rule['customer_role_slugs'], true ) ); ?>>
											<?php echo esc_html( $role_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Select one or more roles. Matching any selected role makes the customer eligible.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Location targeting', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-location-mode"><?php esc_html_e( 'Customer location', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-location-mode" name="bumpmint_rule[location_mode]" aria-describedby="bumpmint-location-description">
									<?php foreach ( BumpMint_Conditions::get_location_modes() as $mode_key => $mode_label ) : ?>
										<option value="<?php echo esc_attr( $mode_key ); ?>" <?php selected( $rule['location_mode'], $mode_key ); ?>><?php echo esc_html( $mode_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p id="bumpmint-location-description" class="description"><?php esc_html_e( 'Uses the location currently known by WooCommerce. Shipping is preferred, with billing as a fallback. Before an address is entered, WooCommerce may use the store default or configured geolocation.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="bumpmint-location-fields">
							<th scope="row"><label for="bumpmint-location-operator"><?php esc_html_e( 'Matching', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-location-operator" name="bumpmint_rule[location_operator]">
									<?php foreach ( BumpMint_Conditions::get_location_operators() as $operator_key => $operator_label ) : ?>
										<option value="<?php echo esc_attr( $operator_key ); ?>" <?php selected( $rule['location_operator'], $operator_key ); ?>><?php echo esc_html( $operator_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="bumpmint-location-fields">
							<th scope="row"><label for="bumpmint-location-countries"><?php esc_html_e( 'Countries', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select
									id="bumpmint-location-countries"
									name="bumpmint_rule[location_country_codes][]"
									class="wc-enhanced-select"
									multiple="multiple"
									data-placeholder="<?php esc_attr_e( 'Select countries', 'bumpmint-order-bump-for-woocommerce' ); ?>"
									style="width: 420px; max-width: 100%;"
								>
									<?php foreach ( $country_options as $country_code => $country_label ) : ?>
										<option value="<?php echo esc_attr( $country_code ); ?>" <?php selected( in_array( $country_code, (array) $rule['location_country_codes'], true ) ); ?>><?php echo esc_html( $country_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Countries come from the locations where the WooCommerce store currently sells.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
								<p class="bumpmint-location-state-feedback" role="status" aria-live="polite"></p>
							</td>
						</tr>
						<tr class="bumpmint-location-fields bumpmint-location-states-row">
							<th scope="row">
								<label for="bumpmint-location-states"><?php esc_html_e( 'States / regions', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
								<?php
								echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
									__( 'Optional. If regions are selected for a country, only those regions match. A selected country with no regions matches the entire country.', 'bumpmint-order-bump-for-woocommerce' )
								);
								?>
							</th>
							<td>
								<select
									id="bumpmint-location-states"
									name="bumpmint_rule[location_state_codes][]"
									class="wc-enhanced-select"
									multiple="multiple"
									data-placeholder="<?php esc_attr_e( 'Select states or regions', 'bumpmint-order-bump-for-woocommerce' ); ?>"
									style="width: 420px; max-width: 100%;"
								>
									<?php foreach ( $state_options as $state_code => $state_label ) : ?>
										<option value="<?php echo esc_attr( $state_code ); ?>" <?php selected( in_array( $state_code, (array) $rule['location_state_codes'], true ) ); ?>><?php echo esc_html( $state_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Only regions registered by WooCommerce or another active plugin are listed.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="bumpmint-location-fields">
							<th scope="row">
								<label for="bumpmint-location-unknown"><?php esc_html_e( 'Unknown location', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
								<?php
								echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
									__( 'A location is unknown when WooCommerce has no customer country, or when a state-specific rule has no customer state. This can happen in virtual-product checkouts without billing location fields.', 'bumpmint-order-bump-for-woocommerce' )
								);
								?>
							</th>
							<td>
								<select id="bumpmint-location-unknown" name="bumpmint_rule[location_unknown_behavior]">
									<?php foreach ( BumpMint_Conditions::get_location_unknown_behaviors() as $behavior_key => $behavior_label ) : ?>
										<option value="<?php echo esc_attr( $behavior_key ); ?>" <?php selected( $rule['location_unknown_behavior'], $behavior_key ); ?>><?php echo esc_html( $behavior_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Coupon targeting', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-coupon-mode"><?php esc_html_e( 'Coupon requirement', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-coupon-mode" name="bumpmint_rule[coupon_mode]" aria-describedby="bumpmint-coupon-description">
									<?php foreach ( BumpMint_Conditions::get_coupon_modes() as $coupon_mode_key => $coupon_mode_label ) : ?>
										<option value="<?php echo esc_attr( $coupon_mode_key ); ?>" <?php selected( $rule['coupon_mode'], $coupon_mode_key ); ?>><?php echo esc_html( $coupon_mode_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p id="bumpmint-coupon-description" class="description"><?php esc_html_e( 'The coupon must be valid and currently applied to the WooCommerce cart. Coupon targeting is combined with the other eligibility settings.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="bumpmint-coupon-fields">
							<th scope="row">
								<label for="bumpmint-coupon-ids"><?php esc_html_e( 'Coupons', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
								<?php
								echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
									__( 'The offer appears when any selected coupon is applied. Search results include published coupons registered in WooCommerce.', 'bumpmint-order-bump-for-woocommerce' )
								);
								?>
							</th>
							<td>
								<?php $this->render_coupon_select( 'bumpmint_rule[coupon_ids][]', 'bumpmint-coupon-ids', $rule['coupon_ids'] ); ?>
								<p class="description"><?php esc_html_e( 'Coupon targeting controls offer eligibility only. Coupon discounts continue to follow WooCommerce rules and may combine with the order bump price.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Availability schedule', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-schedule-type"><?php esc_html_e( 'Available on', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-schedule-type" name="bumpmint_rule[schedule_type]">
									<option value="always" <?php selected( $rule['schedule_type'], BumpMint_Conditions::SCHEDULE_ALWAYS ); ?>><?php esc_html_e( 'Every day', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
									<option value="dates" <?php selected( $rule['schedule_type'], BumpMint_Conditions::SCHEDULE_DATES ); ?>><?php esc_html_e( 'Specific dates', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
									<option value="weekdays" <?php selected( $rule['schedule_type'], BumpMint_Conditions::SCHEDULE_WEEKDAYS ); ?>><?php esc_html_e( 'Days of the week', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'The display rule above must also match before the offer appears.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="bumpmint-schedule-dates-row">
							<th scope="row"><?php esc_html_e( 'Specific dates', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							<td>
								<div class="bumpmint-schedule-dates">
									<?php foreach ( $schedule_dates as $schedule_date ) : ?>
										<div class="bumpmint-schedule-date-row">
											<input type="date" name="bumpmint_rule[schedule_dates][]" value="<?php echo esc_attr( $schedule_date ); ?>" aria-label="<?php esc_attr_e( 'Specific date', 'bumpmint-order-bump-for-woocommerce' ); ?>" />
											<button type="button" class="button-link-delete bumpmint-remove-schedule-date"><?php esc_html_e( 'Remove', 'bumpmint-order-bump-for-woocommerce' ); ?></button>
										</div>
									<?php endforeach; ?>
								</div>
								<button type="button" class="button bumpmint-add-schedule-date"><?php esc_html_e( 'Add date', 'bumpmint-order-bump-for-woocommerce' ); ?></button>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: maximum number of specific dates. */
											__( 'Choose one or more calendar dates. You can add up to %d dates.', 'bumpmint-order-bump-for-woocommerce' ),
											BumpMint_Rules::MAX_SCHEDULE_DATES
										)
									);
									?>
								</p>
							</td>
						</tr>
						<tr class="bumpmint-schedule-weekdays-row">
							<th scope="row"><?php esc_html_e( 'Days of the week', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							<td>
								<fieldset class="bumpmint-weekday-options">
									<?php foreach ( $this->get_weekday_labels() as $weekday_number => $weekday_label ) : ?>
										<label>
											<input
												type="checkbox"
												name="bumpmint_rule[schedule_weekdays][]"
												value="<?php echo esc_attr( $weekday_number ); ?>"
												<?php checked( in_array( $weekday_number, array_map( 'absint', (array) $rule['schedule_weekdays'] ), true ) ); ?>
											/>
											<?php echo esc_html( $weekday_label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'The offer repeats every week on the selected days.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bumpmint-schedule-time-enabled"><?php esc_html_e( 'Hours', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<label>
									<input
										type="checkbox"
										id="bumpmint-schedule-time-enabled"
										name="bumpmint_rule[schedule_time_enabled]"
										value="1"
										<?php checked( ! empty( $rule['schedule_time_enabled'] ) ); ?>
									/>
									<?php esc_html_e( 'Limit availability to a time range', 'bumpmint-order-bump-for-woocommerce' ); ?>
								</label>
								<p class="description">
									<?php
									/* translators: %s: WordPress site timezone. */
									echo esc_html( sprintf( __( 'Times use the site timezone: %s.', 'bumpmint-order-bump-for-woocommerce' ), $timezone_name ) );
									?>
								</p>
							</td>
						</tr>
						<tr class="bumpmint-schedule-time-row">
							<th scope="row"><?php esc_html_e( 'Time range', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							<td>
								<div class="bumpmint-inline-fields">
									<label for="bumpmint-schedule-start-time"><?php esc_html_e( 'From', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
									<input
										type="time"
										id="bumpmint-schedule-start-time"
										name="bumpmint_rule[schedule_start_time]"
										value="<?php echo esc_attr( $rule['schedule_start_time'] ); ?>"
										step="60"
									/>
									<label for="bumpmint-schedule-end-time"><?php esc_html_e( 'Until', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
									<input
										type="time"
										id="bumpmint-schedule-end-time"
										name="bumpmint_rule[schedule_end_time]"
										value="<?php echo esc_attr( $rule['schedule_end_time'] ); ?>"
										step="60"
									/>
								</div>
								<p class="description"><?php esc_html_e( 'The start time is included and the end time is excluded. If the end is earlier than the start, availability continues into the next day.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Offer settings', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-bump-product"><?php esc_html_e( 'Products to offer', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<?php $this->render_product_select( 'bumpmint_rule[bump_product_ids][]', 'bumpmint-bump-product', $rule['bump_product_ids'] ); ?>
								<p class="description"><?php esc_html_e( 'Choose one or more directly purchasable products, specific variations, or supported bookable products. Each product is displayed as a separate offer.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bumpmint-position"><?php esc_html_e( 'Classic checkout position', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-position" name="bumpmint_rule[position]" aria-describedby="bumpmint-position-description">
									<optgroup label="<?php esc_attr_e( 'Classic checkout only', 'bumpmint-order-bump-for-woocommerce' ); ?>">
										<?php foreach ( BumpMint_Positions::get_positions() as $position_key => $position ) : ?>
											<option value="<?php echo esc_attr( $position_key ); ?>" <?php selected( $rule['position'], $position_key ); ?>>
												<?php echo esc_html( $position['label'] ); ?>
											</option>
										<?php endforeach; ?>
									</optgroup>
								</select>
								<p id="bumpmint-position-description" class="description"><?php esc_html_e( 'Checkout Blocks always display offers below the order summary. The positions in this select apply only to classic checkout.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Products already in cart', 'bumpmint-order-bump-for-woocommerce' ); ?>
								<?php
								echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
									__( 'When enabled, an offered product is hidden if that exact product or variation is already in the cart. Other offered products remain visible.', 'bumpmint-order-bump-for-woocommerce' )
								);
								?>
							</th>
							<td>
								<label>
									<input
										type="checkbox"
										name="bumpmint_rule[hide_if_in_cart]"
										value="1"
										<?php checked( ! empty( $rule['hide_if_in_cart'] ) ); ?>
									/>
									<?php esc_html_e( 'Hide offered products that are already in the cart', 'bumpmint-order-bump-for-woocommerce' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Order bump discount', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										id="bumpmint-discount-enabled"
										name="bumpmint_rule[discount_enabled]"
										value="1"
										<?php checked( ! empty( $rule['discount_enabled'] ) ); ?>
									/>
									<?php esc_html_e( 'Enable a special price for this offer', 'bumpmint-order-bump-for-woocommerce' ); ?>
								</label>
								<div class="bumpmint-discount-fields bumpmint-inline-fields">
									<select name="bumpmint_rule[discount_type]">
										<option value="percentage" <?php selected( $rule['discount_type'], 'percentage' ); ?>><?php esc_html_e( 'Percentage discount', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
										<option value="fixed" <?php selected( $rule['discount_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed discount', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
									</select>
									<input
										type="number"
										name="bumpmint_rule[discount_value]"
										value="<?php echo esc_attr( $rule['discount_value'] ); ?>"
										min="0"
										step="any"
										class="small-text"
									/>
								</div>
								<div class="bumpmint-discount-fields bumpmint-discount-quantity">
									<label for="bumpmint-max-quantity"><?php esc_html_e( 'Maximum discounted quantity', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
									<?php
									echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes help tip output.
										__( 'Maximum quantity of each offered product that can receive the order bump discount. For example, a limit of 2 allows up to two discounted units of each offered product.', 'bumpmint-order-bump-for-woocommerce' )
									);
									?>
									<input
										type="number"
										id="bumpmint-max-quantity"
										name="bumpmint_rule[max_quantity]"
										value="<?php echo esc_attr( $rule['max_quantity'] ); ?>"
										min="1"
										step="1"
										class="small-text"
										required
									/>
								</div>
								<p class="description"><?php esc_html_e( 'The final price is calculated and enforced on the server. Percentage discounts are limited to 100%.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-card">
					<h2><?php esc_html_e( 'Offer content', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-badge-text"><?php esc_html_e( 'Top banner text (optional)', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<input
									type="text"
									id="bumpmint-badge-text"
									name="bumpmint_rule[badge_text]"
									value="<?php echo esc_attr( $rule['badge_text'] ); ?>"
									class="regular-text"
									maxlength="<?php echo esc_attr( BumpMint_Rules::BADGE_MAX_LENGTH ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'Default: “Sale” without a discount, or the discount value when enabled.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bumpmint-offer-title"><?php esc_html_e( 'Offer title (optional)', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<input
									type="text"
									id="bumpmint-offer-title"
									name="bumpmint_rule[offer_title]"
									value="<?php echo esc_attr( $rule['offer_title'] ); ?>"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'Add {product} to your order', 'bumpmint-order-bump-for-woocommerce' ); ?>"
								/>
								<p class="description">
									<?php
									printf(
										/* translators: %s: localized product placeholder. */
										esc_html__( 'Available placeholder: %s', 'bumpmint-order-bump-for-woocommerce' ),
										'<code>' . esc_html__( '{product}', 'bumpmint-order-bump-for-woocommerce' ) . '</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bumpmint-description"><?php esc_html_e( 'Description (optional)', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<textarea
									id="bumpmint-description"
									name="bumpmint_rule[description]"
									class="large-text"
									rows="3"
									placeholder="<?php esc_attr_e( 'Add {product} for only {price}.', 'bumpmint-order-bump-for-woocommerce' ); ?>"
								><?php echo esc_textarea( $rule['description'] ); ?></textarea>
								<p class="description">
									<?php
									printf(
										/* translators: 1: product placeholder, 2: price placeholder. */
										esc_html__( 'Available placeholders: %1$s and %2$s', 'bumpmint-order-bump-for-woocommerce' ),
										'<code>' . esc_html__( '{product}', 'bumpmint-order-bump-for-woocommerce' ) . '</code>',
										'<code>' . esc_html__( '{price}', 'bumpmint-order-bump-for-woocommerce' ) . '</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Image (optional)', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
							<td>
								<?php
								$image_id  = absint( $rule['image_id'] );
								$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
								?>
								<div class="bumpmint-image-control">
									<input type="hidden" class="bumpmint-image-id" name="bumpmint_rule[image_id]" value="<?php echo esc_attr( $image_id ); ?>" />
									<img src="<?php echo esc_url( $image_url ); ?>" class="bumpmint-image-preview" style="<?php echo $image_url ? '' : 'display:none;'; ?>" alt="" />
									<button type="button" class="button bumpmint-select-image"><?php esc_html_e( 'Select image', 'bumpmint-order-bump-for-woocommerce' ); ?></button>
									<button type="button" class="button bumpmint-remove-image" style="<?php echo $image_url ? '' : 'display:none;'; ?>">
										<?php esc_html_e( 'Remove image', 'bumpmint-order-bump-for-woocommerce' ); ?>
									</button>
								</div>
								<p class="description"><?php esc_html_e( 'Each offered product uses its own image when no custom image is selected.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bumpmint-form-actions">
					<?php submit_button( __( 'Save order bump', 'bumpmint-order-bump-for-woocommerce' ), 'primary', 'submit', false ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'bumpmint-order-bump-for-woocommerce' ); ?>
					</a>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders a consistent page title and discreet resource links.
	 *
	 * @param string $title        Page title.
	 * @param bool   $show_add_new Whether to display the Add New action.
	 */
	private function render_page_header( $title, $show_add_new = false ) {
		?>
		<div class="bumpmint-page-header">
			<div class="bumpmint-page-title">
				<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
				<?php if ( $show_add_new ) : ?>
					<a href="<?php echo esc_url( $this->get_new_url() ); ?>" class="page-title-action">
						<?php esc_html_e( 'Add New', 'bumpmint-order-bump-for-woocommerce' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<nav class="bumpmint-resource-links" aria-label="<?php esc_attr_e( 'BumpMint resources', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<a href="<?php echo esc_url( BUMPMINT_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Support', 'bumpmint-order-bump-for-woocommerce' ); ?>
				</a>
				<span aria-hidden="true">·</span>
				<a href="<?php echo esc_url( BUMPMINT_REVIEW_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Leave a review', 'bumpmint-order-bump-for-woocommerce' ); ?>
				</a>
			</nav>
		</div>
		<?php
	}

	/**
	 * Renders a native WooCommerce product search field.
	 *
	 * @param string $name        Field name.
	 * @param string $id_attr     Field ID.
	 * @param array  $selected_ids Selected product or variation IDs.
	 */
	private function render_product_select( $name, $id_attr, $selected_ids ) {
		$selected_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $selected_ids ) ) ) );
		?>
		<select
			id="<?php echo esc_attr( $id_attr ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			class="wc-product-search"
			style="width: min(400px, 100%);"
			data-placeholder="<?php esc_attr_e( 'Type to search for a product…', 'bumpmint-order-bump-for-woocommerce' ); ?>"
			data-action="woocommerce_json_search_products_and_variations"
			data-allow-clear="true"
			multiple="multiple">
			<?php foreach ( $selected_ids as $selected_id ) : ?>
				<?php $product = wc_get_product( $selected_id ); ?>
				<?php if ( $product ) : ?>
					<option value="<?php echo esc_attr( $selected_id ); ?>" selected="selected">
						<?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?>
					</option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders a native WooCommerce product category search field.
	 *
	 * @param string $name        Field name.
	 * @param string $id_attr     Field ID.
	 * @param array  $selected_ids Selected product category IDs.
	 */
	private function render_category_select( $name, $id_attr, $selected_ids ) {
		$selected_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $selected_ids ) ) ) );
		?>
		<select
			id="<?php echo esc_attr( $id_attr ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			class="wc-category-search"
			style="width: min(400px, 100%);"
			data-placeholder="<?php esc_attr_e( 'Type to search for a category…', 'bumpmint-order-bump-for-woocommerce' ); ?>"
			data-action="woocommerce_json_search_categories"
			data-allow_clear="true"
			data-minimum_input_length="3"
			data-return_id="true"
			multiple="multiple">
			<?php foreach ( $selected_ids as $selected_id ) : ?>
				<?php $category = get_term( $selected_id, 'product_cat' ); ?>
				<?php if ( $category && ! is_wp_error( $category ) ) : ?>
					<option value="<?php echo esc_attr( $selected_id ); ?>" selected="selected">
						<?php echo esc_html( $category->name ); ?>
					</option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders an AJAX-backed WooCommerce coupon search field.
	 *
	 * @param string $name         Field name.
	 * @param string $id_attr      Field ID.
	 * @param array  $selected_ids Selected coupon IDs.
	 */
	private function render_coupon_select( $name, $id_attr, $selected_ids ) {
		$selected_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $selected_ids ) ) ) );
		?>
		<select
			id="<?php echo esc_attr( $id_attr ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			class="bumpmint-coupon-search"
			style="width: min(400px, 100%);"
			data-placeholder="<?php esc_attr_e( 'Search for coupons…', 'bumpmint-order-bump-for-woocommerce' ); ?>"
			multiple="multiple">
			<?php foreach ( $selected_ids as $selected_id ) : ?>
				<option value="<?php echo esc_attr( $selected_id ); ?>" selected="selected">
					<?php echo esc_html( $this->get_coupon_label( $selected_id ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Returns a concise condition summary for the rules table.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_condition_summary( array $rule ) {
		if ( BumpMint_Conditions::PRODUCT === $rule['condition_type'] ) {
			$products = $this->get_products( $rule['condition_product_ids'] );
			$categories = $this->get_categories( isset( $rule['condition_category_ids'] ) ? $rule['condition_category_ids'] : array() );
			$parts      = array();

			if ( ! empty( $products ) ) {
				$matching = 'all' === $rule['condition_match']
					? __( 'All:', 'bumpmint-order-bump-for-woocommerce' )
					: __( 'Any:', 'bumpmint-order-bump-for-woocommerce' );
				$parts[] = $matching . ' ' . $this->get_product_names( $products );
			}

			if ( ! empty( $categories ) ) {
				$parts[] = __( 'Categories:', 'bumpmint-order-bump-for-woocommerce' ) . ' ' . $this->get_category_names( $categories );
			}

			if ( empty( $parts ) ) {
				return __( 'Triggers not found', 'bumpmint-order-bump-for-woocommerce' );
			}

			return wp_html_excerpt( implode( ' ' . __( 'OR', 'bumpmint-order-bump-for-woocommerce' ) . ' ', $parts ), 100, '…' );
		}

		if ( BumpMint_Conditions::ALWAYS === $rule['condition_type'] ) {
			return __( 'No conditions', 'bumpmint-order-bump-for-woocommerce' );
		}

		$operator = 'less_than' === $rule['condition_operator']
			? __( 'Less than', 'bumpmint-order-bump-for-woocommerce' )
			: __( 'Greater than', 'bumpmint-order-bump-for-woocommerce' );

		if ( BumpMint_Conditions::CART_SUBTOTAL === $rule['condition_type'] ) {
			return $operator . ' ' . wp_strip_all_tags( wc_price( $rule['condition_value'] ) );
		}

		return $operator . ' ' . absint( $rule['condition_value'] );
	}

	/**
	 * Returns a concise customer audience summary for the rules table.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_customer_summary( array $rule ) {
		$audience  = isset( $rule['customer_audience'] ) ? $rule['customer_audience'] : BumpMint_Conditions::AUDIENCE_EVERYONE;
		$audiences = BumpMint_Conditions::get_customer_audiences();

		if ( BumpMint_Conditions::AUDIENCE_EVERYONE === $audience ) {
			return '';
		}

		if ( BumpMint_Conditions::AUDIENCE_ROLES !== $audience ) {
			$audience_label = isset( $audiences[ $audience ] ) ? $audiences[ $audience ] : __( 'Unknown audience', 'bumpmint-order-bump-for-woocommerce' );

			/* translators: %s: customer audience label. */
			return sprintf( __( 'Customers: %s', 'bumpmint-order-bump-for-woocommerce' ), $audience_label );
		}

		$selected_roles = isset( $rule['customer_role_slugs'] ) ? (array) $rule['customer_role_slugs'] : array();
		$role_options   = $this->get_user_role_options( $selected_roles );
		$role_labels    = array();
		foreach ( $selected_roles as $role_slug ) {
			if ( isset( $role_options[ $role_slug ] ) ) {
				$role_labels[] = $role_options[ $role_slug ];
			}
		}

		if ( empty( $role_labels ) ) {
			return __( 'Roles not found', 'bumpmint-order-bump-for-woocommerce' );
		}

		/* translators: %s: comma-separated user role labels. */
		return wp_html_excerpt( sprintf( __( 'Roles: %s', 'bumpmint-order-bump-for-woocommerce' ), implode( ', ', $role_labels ) ), 100, '…' );
	}

	/**
	 * Returns current WordPress roles plus selected roles that are unavailable.
	 *
	 * Temporarily unavailable custom roles remain visible so editing another
	 * setting cannot silently discard targeting configured by an integration.
	 *
	 * @param array $selected_roles Selected role slugs.
	 * @return array<string,string>
	 */
	private function get_user_role_options( array $selected_roles = array() ) {
		$role_names = wp_roles()->get_names();
		$options    = array();

		foreach ( $role_names as $role_slug => $role_name ) {
			$role_slug = sanitize_key( $role_slug );
			if ( '' !== $role_slug ) {
				$options[ $role_slug ] = translate_user_role( $role_name );
			}
		}

		foreach ( $selected_roles as $role_slug ) {
			$role_slug = is_scalar( $role_slug ) ? sanitize_key( (string) $role_slug ) : '';
			if ( '' === $role_slug || isset( $options[ $role_slug ] ) ) {
				continue;
			}

			$options[ $role_slug ] = sprintf(
				/* translators: %s: unavailable WordPress user role slug. */
				__( 'Unavailable role: %s', 'bumpmint-order-bump-for-woocommerce' ),
				$role_slug
			);
		}

		natcasesort( $options );
		return $options;
	}

	/**
	 * Returns allowed selling countries plus selected unavailable countries.
	 *
	 * @param array $selected_countries Selected country codes.
	 * @return array<string,string>
	 */
	private function get_location_country_options( array $selected_countries = array() ) {
		$selected_countries = $this->sanitize_location_country_codes( $selected_countries );
		$all_countries      = WC()->countries->get_countries();
		$options            = WC()->countries->get_allowed_countries();

		foreach ( $selected_countries as $country_code ) {
			if ( isset( $options[ $country_code ] ) ) {
				continue;
			}

			if ( isset( $all_countries[ $country_code ] ) ) {
				$options[ $country_code ] = sprintf(
					/* translators: %s: country name that is no longer an allowed selling location. */
					__( 'Not currently sold to: %s', 'bumpmint-order-bump-for-woocommerce' ),
					$all_countries[ $country_code ]
				);
			} else {
				$options[ $country_code ] = sprintf(
					/* translators: %s: unavailable country code. */
					__( 'Unavailable country: %s', 'bumpmint-order-bump-for-woocommerce' ),
					$country_code
				);
			}
		}

		natcasesort( $options );
		return $options;
	}

	/**
	 * Returns registered states for selected countries and preserves unavailable selections.
	 *
	 * @param array $country_codes Selected country codes.
	 * @param array $selected_states Selected composite country:state codes.
	 * @return array<string,string>
	 */
	private function get_location_state_options( array $country_codes, array $selected_states = array() ) {
		$country_codes  = $this->sanitize_location_country_codes( $country_codes );
		$selected_states = $this->sanitize_location_state_codes( $selected_states, $country_codes );
		$all_countries   = WC()->countries->get_countries();
		$options         = array();

		foreach ( $country_codes as $country_code ) {
			$country_label = isset( $all_countries[ $country_code ] ) ? $all_countries[ $country_code ] : $country_code;
			$states        = WC()->countries->get_states( $country_code );
			if ( ! is_array( $states ) ) {
				continue;
			}

			foreach ( $states as $state_code => $state_label ) {
				$state_code = substr( sanitize_key( $state_code ), 0, 40 );
				if ( '' !== $state_code ) {
					$options[ $country_code . ':' . $state_code ] = $country_label . ' — ' . $state_label;
				}
			}
		}

		foreach ( $selected_states as $selected_state ) {
			if ( isset( $options[ $selected_state ] ) ) {
				continue;
			}

			$parts         = explode( ':', $selected_state, 2 );
			$country_code  = $parts[0];
			$state_code    = isset( $parts[1] ) ? $parts[1] : '';
			$country_label = isset( $all_countries[ $country_code ] ) ? $all_countries[ $country_code ] : $country_code;
			$options[ $selected_state ] = sprintf(
				/* translators: 1: country name, 2: unavailable state or region code. */
				__( '%1$s — Unavailable region: %2$s', 'bumpmint-order-bump-for-woocommerce' ),
				$country_label,
				$state_code
			);
		}

		natcasesort( $options );
		return $options;
	}

	/**
	 * Returns a concise location targeting summary for the rules table.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_location_summary( array $rule ) {
		$mode = isset( $rule['location_mode'] ) ? $rule['location_mode'] : BumpMint_Conditions::LOCATION_ALL;
		if ( BumpMint_Conditions::LOCATION_ALL === $mode ) {
			return '';
		}

		$country_codes = isset( $rule['location_country_codes'] )
			? $this->sanitize_location_country_codes( (array) $rule['location_country_codes'] )
			: array();
		$state_codes   = isset( $rule['location_state_codes'] )
			? $this->sanitize_location_state_codes( (array) $rule['location_state_codes'], $country_codes )
			: array();
		if ( empty( $country_codes ) ) {
			return __( 'Locations not found', 'bumpmint-order-bump-for-woocommerce' );
		}

		$all_countries = WC()->countries->get_countries();
		$parts         = array();
		foreach ( $country_codes as $country_code ) {
			$country_label = isset( $all_countries[ $country_code ] ) ? $all_countries[ $country_code ] : $country_code;
			$states        = WC()->countries->get_states( $country_code );
			$state_options = array();
			$state_labels  = array();
			if ( is_array( $states ) ) {
				foreach ( $states as $registered_state_code => $registered_state_label ) {
					$state_options[ substr( sanitize_key( $registered_state_code ), 0, 40 ) ] = $registered_state_label;
				}
			}

			foreach ( $state_codes as $state_code ) {
				$state_parts = explode( ':', $state_code, 2 );
				if ( $country_code !== $state_parts[0] ) {
					continue;
				}

				$state_key      = isset( $state_parts[1] ) ? $state_parts[1] : '';
				$state_labels[] = isset( $state_options[ $state_key ] )
					? $state_options[ $state_key ]
					: $state_key;
			}

			$parts[] = empty( $state_labels )
				? $country_label
				: $country_label . ': ' . implode( ', ', $state_labels );
		}

		$locations = implode( '; ', $parts );
		if ( BumpMint_Conditions::LOCATION_EXCLUDE === $rule['location_operator'] ) {
			/* translators: %s: location exclusion summary. */
			return wp_html_excerpt( sprintf( __( 'Excluded locations: %s', 'bumpmint-order-bump-for-woocommerce' ), $locations ), 100, '…' );
		}

		/* translators: %s: location inclusion summary. */
		return wp_html_excerpt( sprintf( __( 'Locations: %s', 'bumpmint-order-bump-for-woocommerce' ), $locations ), 100, '…' );
	}

	/**
	 * Returns a concise coupon targeting summary for the rules table.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_coupon_summary( array $rule ) {
		$mode = isset( $rule['coupon_mode'] ) ? $rule['coupon_mode'] : BumpMint_Conditions::COUPON_NONE;
		if ( BumpMint_Conditions::COUPON_NONE === $mode ) {
			return '';
		}

		if ( BumpMint_Conditions::COUPON_ANY === $mode ) {
			return __( 'Coupons: Any applied coupon', 'bumpmint-order-bump-for-woocommerce' );
		}

		$coupon_ids = isset( $rule['coupon_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', (array) $rule['coupon_ids'] ) ) ) )
			: array();
		if ( empty( $coupon_ids ) ) {
			return __( 'Coupons not found', 'bumpmint-order-bump-for-woocommerce' );
		}

		$labels = array_map( array( $this, 'get_coupon_label' ), $coupon_ids );

		/* translators: %s: comma-separated WooCommerce coupon codes. */
		return wp_html_excerpt( sprintf( __( 'Coupons: %s', 'bumpmint-order-bump-for-woocommerce' ), implode( ', ', $labels ) ), 100, '…' );
	}

	/**
	 * Returns a coupon code or an explicit unavailable-coupon label.
	 *
	 * @param int $coupon_id Coupon post ID.
	 * @return string
	 */
	private function get_coupon_label( $coupon_id ) {
		$coupon_id = absint( $coupon_id );
		$post      = $coupon_id ? get_post( $coupon_id ) : null;
		if ( $post && 'shop_coupon' === $post->post_type && 'publish' === $post->post_status ) {
			$coupon_code = get_the_title( $post );
			if ( '' !== $coupon_code ) {
				return wp_strip_all_tags( $coupon_code );
			}
		}

		/* translators: %d: unavailable WooCommerce coupon post ID. */
		return sprintf( __( 'Unavailable coupon #%d', 'bumpmint-order-bump-for-woocommerce' ), $coupon_id );
	}

	/**
	 * Sanitizes country codes used by the location form.
	 *
	 * @param mixed $country_codes Country code or list of codes.
	 * @return array
	 */
	private function sanitize_location_country_codes( $country_codes ) {
		$country_codes = is_array( $country_codes ) ? $country_codes : array( $country_codes );
		$sanitized     = array();

		foreach ( $country_codes as $country_code ) {
			if ( ! is_scalar( $country_code ) ) {
				continue;
			}

			$country_code = strtoupper( trim( (string) $country_code ) );
			if ( preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
				$sanitized[] = $country_code;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitizes composite country:state codes used by the location form.
	 *
	 * @param mixed $state_codes   State code or list of codes.
	 * @param array $country_codes Selected country codes.
	 * @return array
	 */
	private function sanitize_location_state_codes( $state_codes, array $country_codes ) {
		$state_codes = is_array( $state_codes ) ? $state_codes : array( $state_codes );
		$sanitized   = array();

		foreach ( $state_codes as $state_code ) {
			if ( ! is_scalar( $state_code ) ) {
				continue;
			}

			$parts   = explode( ':', (string) $state_code, 2 );
			$country = isset( $parts[0] ) ? strtoupper( trim( $parts[0] ) ) : '';
			$state   = isset( $parts[1] ) ? substr( sanitize_key( $parts[1] ), 0, 40 ) : '';
			if ( preg_match( '/^[A-Z]{2}$/', $country ) && '' !== $state && in_array( $country, $country_codes, true ) ) {
				$sanitized[] = $country . ':' . $state;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Returns translated weekday labels keyed by ISO-8601 weekday number.
	 *
	 * @return array
	 */
	private function get_weekday_labels() {
		return array(
			1 => __( 'Monday', 'bumpmint-order-bump-for-woocommerce' ),
			2 => __( 'Tuesday', 'bumpmint-order-bump-for-woocommerce' ),
			3 => __( 'Wednesday', 'bumpmint-order-bump-for-woocommerce' ),
			4 => __( 'Thursday', 'bumpmint-order-bump-for-woocommerce' ),
			5 => __( 'Friday', 'bumpmint-order-bump-for-woocommerce' ),
			6 => __( 'Saturday', 'bumpmint-order-bump-for-woocommerce' ),
			7 => __( 'Sunday', 'bumpmint-order-bump-for-woocommerce' ),
		);
	}

	/**
	 * Returns a concise localized availability summary for the rules table.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_schedule_summary( array $rule ) {
		$schedule_type = isset( $rule['schedule_type'] ) ? $rule['schedule_type'] : BumpMint_Conditions::SCHEDULE_ALWAYS;
		$parts         = array();

		if ( BumpMint_Conditions::SCHEDULE_DATES === $schedule_type ) {
			$dates       = array_values( array_filter( (array) $rule['schedule_dates'], 'is_scalar' ) );
			$date_labels = array();
			foreach ( array_slice( $dates, 0, 3 ) as $date ) {
				$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, wp_timezone() );
				if ( $datetime && $datetime->format( 'Y-m-d' ) === $date ) {
					$date_labels[] = wp_date( get_option( 'date_format' ), $datetime->getTimestamp(), wp_timezone() );
				}
			}

			if ( ! empty( $date_labels ) ) {
				$date_summary = implode( ', ', $date_labels );
				if ( count( $dates ) > count( $date_labels ) ) {
					$date_summary .= ' ' . sprintf(
						/* translators: %d: number of additional specific dates. */
						__( '+%d more', 'bumpmint-order-bump-for-woocommerce' ),
						count( $dates ) - count( $date_labels )
					);
				}
				/* translators: %s: localized list of specific dates. */
				$parts[] = sprintf( __( 'Dates: %s', 'bumpmint-order-bump-for-woocommerce' ), $date_summary );
			}
		} elseif ( BumpMint_Conditions::SCHEDULE_WEEKDAYS === $schedule_type ) {
			$weekday_labels   = $this->get_weekday_labels();
			$selected_labels = array();
			foreach ( array_map( 'absint', (array) $rule['schedule_weekdays'] ) as $weekday ) {
				if ( isset( $weekday_labels[ $weekday ] ) ) {
					$selected_labels[] = $weekday_labels[ $weekday ];
				}
			}

			if ( ! empty( $selected_labels ) ) {
				/* translators: %s: localized list of weekdays. */
				$parts[] = sprintf( __( 'Days: %s', 'bumpmint-order-bump-for-woocommerce' ), implode( ', ', $selected_labels ) );
			}
		} elseif ( ! empty( $rule['schedule_time_enabled'] ) ) {
			$parts[] = __( 'Every day', 'bumpmint-order-bump-for-woocommerce' );
		}

		if ( ! empty( $rule['schedule_time_enabled'] ) ) {
			$start_time = $this->format_schedule_time( isset( $rule['schedule_start_time'] ) ? $rule['schedule_start_time'] : '' );
			$end_time   = $this->format_schedule_time( isset( $rule['schedule_end_time'] ) ? $rule['schedule_end_time'] : '' );
			if ( $start_time && $end_time ) {
				/* translators: 1: schedule start time, 2: schedule end time. */
				$parts[] = sprintf( __( 'Time: %1$s–%2$s', 'bumpmint-order-bump-for-woocommerce' ), $start_time, $end_time );
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Formats one stored 24-hour time using the site's display setting.
	 *
	 * @param string $time Time in H:i format.
	 * @return string
	 */
	private function format_schedule_time( $time ) {
		$datetime = DateTimeImmutable::createFromFormat( '!H:i', (string) $time, wp_timezone() );
		if ( ! $datetime || $datetime->format( 'H:i' ) !== $time ) {
			return '';
		}

		return wp_date( get_option( 'time_format' ), $datetime->getTimestamp(), wp_timezone() );
	}

	/**
	 * Resolves a list of product IDs without querying the catalog broadly.
	 *
	 * @param array $product_ids Product or variation IDs.
	 * @return array
	 */
	private function get_products( array $product_ids ) {
		$products = array();
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( absint( $product_id ) );
			if ( $product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Resolves selected product categories without querying the catalog broadly.
	 *
	 * @param array $category_ids Product category IDs.
	 * @return array
	 */
	private function get_categories( array $category_ids ) {
		$categories = array();
		foreach ( $category_ids as $category_id ) {
			$category = get_term( absint( $category_id ), 'product_cat' );
			if ( $category && ! is_wp_error( $category ) ) {
				$categories[] = $category;
			}
		}

		return $categories;
	}

	/**
	 * Returns a comma-separated product name list.
	 *
	 * @param array $products Product objects.
	 * @return string
	 */
	private function get_product_names( array $products ) {
		return implode(
			', ',
			array_map(
				function ( $product ) {
					return $product->get_name();
				},
				$products
			)
		);
	}

	/**
	 * Returns a comma-separated category name list.
	 *
	 * @param array $categories Product category term objects.
	 * @return string
	 */
	private function get_category_names( array $categories ) {
		return implode(
			', ',
			array_map(
				function ( $category ) {
					return $category->name;
				},
				$categories
			)
		);
	}

	/**
	 * Returns one price or an offer-price range for the rules table.
	 *
	 * @param array $rule     Rule data.
	 * @param array $products Product objects.
	 * @return string
	 */
	private function get_products_price_html( array $rule, array $products ) {
		if ( empty( $products ) ) {
			return '—';
		}

		if ( 1 === count( $products ) ) {
			return BumpMint_Rules::get_price_html( $rule, $products[0] );
		}

		$offer_prices = array();
		foreach ( $products as $product ) {
			$prices         = BumpMint_Rules::calculate_prices( $rule, $product );
			$offer_prices[] = wc_get_price_to_display( $product, array( 'price' => $prices['offer'] ) );
		}

		$minimum = min( $offer_prices );
		$maximum = max( $offer_prices );
		if ( $minimum === $maximum ) {
			return wc_price( $minimum );
		}

		return wc_price( $minimum ) . ' – ' . wc_price( $maximum );
	}

	/**
	 * Returns the new rule URL.
	 *
	 * @return string
	 */
	private function get_new_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
	}

	/**
	 * Checks whether the current request targets this plugin page.
	 *
	 * @return bool
	 */
	private function is_plugin_page() {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route read only.
	}

	/**
	 * Returns the current screen action.
	 *
	 * @return string
	 */
	private function get_current_action() {
		if ( ! isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route read only.
			return 'list';
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Route read only.
		return in_array( $action, array( 'new', 'edit', 'delete' ), true ) ? $action : 'list';
	}
}
