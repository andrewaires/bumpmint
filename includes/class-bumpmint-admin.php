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
 * Handles rule listing, creation, editing, and deletion.
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
		$script_dependencies = array( 'jquery' );
		$script_path         = BUMPMINT_PLUGIN_DIR . 'assets/js/admin.js';
		$script_version      = file_exists( $script_path ) ? (string) filemtime( $script_path ) : BUMPMINT_VERSION;

		wp_enqueue_style(
			'bumpmint-admin',
			BUMPMINT_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			BUMPMINT_VERSION
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
			)
		);
	}

	/**
	 * Handles save and delete requests before output begins.
	 */
	public function handle_actions() {
		if ( ! $this->is_plugin_page() || ! current_user_can( self::CAPABILITY ) ) {
			return;
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
		$rules = BumpMint_Rules::get_rules();
		?>
		<div class="wrap bumpmint-admin-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Order Bumps', 'bumpmint-order-bump-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $this->get_new_url() ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'bumpmint-order-bump-for-woocommerce' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['bumpmint_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order bump saved successfully.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['bumpmint_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order bump deleted.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
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
		$products   = $this->get_products( $rule['bump_product_ids'] );
		$edit_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&rule=' . rawurlencode( $rule['id'] ) );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=delete&rule=' . rawurlencode( $rule['id'] ) ),
			'bumpmint_delete_rule_' . $rule['id']
		);

		$full_name  = $rule['name'];
		$short_name = wp_html_excerpt( $full_name, 52, '…' );
		$date       = ! empty( $rule['created_at'] )
			? get_date_from_gmt( $rule['created_at'], get_option( 'date_format' ) )
			: '—';
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
			</td>
			<td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<span class="bumpmint-status-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Active', 'bumpmint-order-bump-for-woocommerce' ); ?>
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
			'condition_match'       => 'any',
			'condition_operator'    => 'greater_than',
			'condition_value'       => '',
			'bump_product_ids'      => array(),
			'hide_if_in_cart'       => false,
			'position'              => BumpMint_Positions::BEFORE_PAYMENT,
			'discount_enabled'      => false,
			'discount_type'         => 'percentage',
			'discount_value'        => '',
			'badge_text'            => '',
			'offer_title'           => '',
			'description'           => '',
			'image_id'              => 0,
			'status'                => 'active',
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

		$errors = get_settings_errors( 'bumpmint' );
		?>
		<div class="wrap bumpmint-admin-wrap">
			<h1><?php echo $rule['id'] ? esc_html__( 'Edit order bump', 'bumpmint-order-bump-for-woocommerce' ) : esc_html__( 'New order bump', 'bumpmint-order-bump-for-woocommerce' ); ?></h1>

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
							<td><span class="bumpmint-status-dot" aria-hidden="true"></span><?php esc_html_e( 'Active', 'bumpmint-order-bump-for-woocommerce' ); ?></td>
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
								<p class="description"><?php esc_html_e( 'Select one or more products or variations that can trigger the offer.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
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
					<h2><?php esc_html_e( 'Offer settings', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bumpmint-bump-product"><?php esc_html_e( 'Products to offer', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<?php $this->render_product_select( 'bumpmint_rule[bump_product_ids][]', 'bumpmint-bump-product', $rule['bump_product_ids'] ); ?>
								<p class="description"><?php esc_html_e( 'Choose one or more directly purchasable products or specific variations. Each product is displayed as a separate offer.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bumpmint-position"><?php esc_html_e( 'Checkout position', 'bumpmint-order-bump-for-woocommerce' ); ?></label></th>
							<td>
								<select id="bumpmint-position" name="bumpmint_rule[position]">
									<?php foreach ( BumpMint_Positions::get_positions() as $position_key => $position ) : ?>
										<option value="<?php echo esc_attr( $position_key ); ?>" <?php selected( $rule['position'], $position_key ); ?>>
											<?php echo esc_html( $position['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
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
	 * Returns a concise condition summary for the rules table.
	 *
	 * @param array $rule Rule data.
	 * @return string
	 */
	private function get_condition_summary( array $rule ) {
		if ( BumpMint_Conditions::PRODUCT === $rule['condition_type'] ) {
			$products = $this->get_products( $rule['condition_product_ids'] );
			if ( empty( $products ) ) {
				return __( 'Products not found', 'bumpmint-order-bump-for-woocommerce' );
			}

			$matching = 'all' === $rule['condition_match']
				? __( 'All:', 'bumpmint-order-bump-for-woocommerce' )
				: __( 'Any:', 'bumpmint-order-bump-for-woocommerce' );

			return $matching . ' ' . wp_html_excerpt( $this->get_product_names( $products ), 60, '…' );
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
