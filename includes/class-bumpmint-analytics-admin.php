<?php
/**
 * BumpMint Analytics administration screen.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders lightweight reports and handles destructive analytics reset requests.
 */
class BumpMint_Analytics_Admin {

	const PAGE_SLUG = 'bumpmint-analytics';
	const CAPABILITY = 'manage_woocommerce';
	const RESET_ACTION = 'bumpmint_reset_analytics';

	/**
	 * Analytics collection service.
	 *
	 * @var BumpMint_Analytics
	 */
	private $analytics;

	/**
	 * Read-only report service.
	 *
	 * @var BumpMint_Analytics_Reports
	 */
	private $reports;

	/**
	 * @param BumpMint_Analytics $analytics Analytics collection service.
	 */
	public function __construct( $analytics ) {
		$this->analytics = $analytics;
		$this->reports   = new BumpMint_Analytics_Reports();

		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
	}

	/**
	 * Adds Analytics beneath the existing Order Bumps top-level menu.
	 */
	public function register_menu() {
		add_submenu_page(
			BumpMint_Admin::PAGE_SLUG,
			__( 'Analytics', 'bumpmint-order-bump-for-woocommerce' ),
			__( 'Analytics', 'bumpmint-order-bump-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads report-only CSS and JavaScript on the Analytics screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		unset( $hook );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$admin_style_path = BUMPMINT_PLUGIN_DIR . 'assets/css/admin.css';
		$style_path       = BUMPMINT_PLUGIN_DIR . 'assets/css/analytics.css';
		$script_path      = BUMPMINT_PLUGIN_DIR . 'assets/js/analytics.js';

		wp_enqueue_style(
			'bumpmint-admin',
			BUMPMINT_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			file_exists( $admin_style_path ) ? (string) filemtime( $admin_style_path ) : BUMPMINT_VERSION
		);
		wp_enqueue_style(
			'bumpmint-analytics',
			BUMPMINT_PLUGIN_URL . 'assets/css/analytics.css',
			array( 'bumpmint-admin' ),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : BUMPMINT_VERSION
		);
		wp_enqueue_script(
			'bumpmint-analytics',
			BUMPMINT_PLUGIN_URL . 'assets/js/analytics.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : BUMPMINT_VERSION,
			true
		);
		wp_localize_script(
			'bumpmint-analytics',
			'bumpmintAnalyticsAdmin',
			array(
				'confirmReset' => __( 'Permanently delete all BumpMint Analytics data? Order bumps and WooCommerce orders will not be deleted.', 'bumpmint-order-bump-for-woocommerce' ),
			)
		);
	}

	/**
	 * Renders the complete Analytics dashboard.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view BumpMint Analytics.', 'bumpmint-order-bump-for-woocommerce' ) );
		}

		if ( ! $this->analytics->is_ready() ) {
			?>
			<div class="wrap bumpmint-admin-wrap bumpmint-analytics-wrap">
				<?php $this->render_page_header(); ?>
				<hr class="wp-header-end" />
				<div class="notice notice-error"><p><?php esc_html_e( 'Analytics storage is unavailable. Check the WordPress database permissions and reload this page to try again.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			</div>
			<?php
			return;
		}

		$currencies = $this->reports->get_available_currencies();
		$rules      = $this->reports->get_available_rules();
		$filters    = $this->get_filters( $currencies, $rules );
		$report     = $this->reports->get_report( $filters );
		?>
		<div class="wrap bumpmint-admin-wrap bumpmint-analytics-wrap">
			<?php $this->render_page_header(); ?>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['bumpmint_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Analytics data was reset. New activity is being tracked from now on.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['bumpmint_reset_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag. ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Analytics data could not be reset. Please try again or review the database logs.', 'bumpmint-order-bump-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_filters( $filters, $currencies, $rules ); ?>

			<div class="bumpmint-metric-grid">
				<?php
				$this->render_metric(
					__( 'Views', 'bumpmint-order-bump-for-woocommerce' ),
					number_format_i18n( $report['views'] ),
					__( 'One view per order bump in each checkout journey. AJAX refreshes are not counted again.', 'bumpmint-order-bump-for-woocommerce' ),
					'dashicons-visibility'
				);
				$this->render_metric(
					__( 'Conversions', 'bumpmint-order-bump-for-woocommerce' ),
					number_format_i18n( $report['conversions'] ),
					__( 'Orders currently recognized as paid by WooCommerce, counted once per order bump.', 'bumpmint-order-bump-for-woocommerce' ),
					'dashicons-yes-alt'
				);
				$this->render_metric(
					__( 'Conversion rate', 'bumpmint-order-bump-for-woocommerce' ),
					number_format_i18n( $report['conversion_rate'], 1 ) . '%',
					__( 'Percentage of bump views in this period that led to a currently valid conversion.', 'bumpmint-order-bump-for-woocommerce' ),
					'dashicons-chart-line'
				);
				$this->render_metric(
					__( 'Bump revenue', 'bumpmint-order-bump-for-woocommerce' ),
					$this->format_plain_price( $report['revenue'], $filters['currency'] ),
					__( 'Net product revenue after applicable refunds, excluding taxes and shipping.', 'bumpmint-order-bump-for-woocommerce' ),
					'dashicons-money-alt'
				);
				$this->render_top_bump_metric( $report['top_bump'], $filters['currency'] );
				?>
			</div>

			<div class="bumpmint-analytics-main-grid">
				<section class="bumpmint-report-card bumpmint-trend-card">
					<div class="bumpmint-report-heading">
						<div>
							<h2><?php esc_html_e( 'Performance over time', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
							<p><?php esc_html_e( 'Bump views and the checkout journeys that became valid conversions.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
						</div>
						<div class="bumpmint-chart-legend" aria-hidden="true">
							<span><i class="is-views"></i><?php esc_html_e( 'Views', 'bumpmint-order-bump-for-woocommerce' ); ?></span>
							<span><i class="is-converted"></i><?php esc_html_e( 'Converted views', 'bumpmint-order-bump-for-woocommerce' ); ?></span>
						</div>
					</div>
					<?php $this->render_trend_chart( $report['daily'] ); ?>
				</section>

				<section class="bumpmint-report-card bumpmint-donut-card">
					<div class="bumpmint-report-heading">
						<div>
							<h2><?php esc_html_e( 'View conversion', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
							<p><?php esc_html_e( 'Converted and non-converted bump views in the selected period.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
						</div>
					</div>
					<?php $this->render_donut( $report['views'], $report['converted_views'], $report['conversion_rate'] ); ?>
				</section>
			</div>

			<div class="bumpmint-analytics-breakdown-grid">
				<section class="bumpmint-report-card">
					<div class="bumpmint-report-heading">
						<div>
							<h2><?php esc_html_e( 'Conversion by weekday', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
							<p><?php esc_html_e( 'Conversion rate based on the local weekday when each offer was viewed.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
						</div>
					</div>
					<?php $this->render_rate_bars( $report['weekdays'], 'weekday' ); ?>
				</section>

				<section class="bumpmint-report-card">
					<div class="bumpmint-report-heading">
						<div>
							<h2><?php esc_html_e( 'Conversion by hour', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
							<p><?php esc_html_e( 'Hourly view conversion in the WordPress site timezone.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
						</div>
					</div>
					<?php $this->render_rate_bars( $report['hours'], 'hour' ); ?>
				</section>
			</div>

			<section class="bumpmint-report-card bumpmint-ranking-card">
				<div class="bumpmint-report-heading">
					<div>
						<h2><?php esc_html_e( 'Order bump performance', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
						<p><?php esc_html_e( 'Compare views, valid paid conversions, conversion rate, quantity, and net revenue.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
					</div>
				</div>
				<?php $this->render_ranking_table( $report['ranking'], $filters ); ?>
			</section>

			<?php $this->render_data_management(); ?>
		</div>
		<?php
	}

	/**
	 * Handles the nonce- and capability-protected full reset.
	 */
	public function handle_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to reset BumpMint Analytics.', 'bumpmint-order-bump-for-woocommerce' ) );
		}

		check_admin_referer( self::RESET_ACTION );
		$confirmed = isset( $_POST['bumpmint_confirm_reset'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['bumpmint_confirm_reset'] ) );
		if ( ! $confirmed ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&bumpmint_reset_error=1' ) );
			exit;
		}

		$result = $this->analytics->reset_all();
		$url    = admin_url(
			'admin.php?page=' . self::PAGE_SLUG . ( $result ? '&bumpmint_reset=1' : '&bumpmint_reset_error=1' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Renders the page title and resource links.
	 */
	private function render_page_header() {
		?>
		<div class="bumpmint-page-header">
			<div class="bumpmint-page-title">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Analytics', 'bumpmint-order-bump-for-woocommerce' ); ?></h1>
			</div>
			<nav class="bumpmint-resource-links" aria-label="<?php esc_attr_e( 'BumpMint resources', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<a href="<?php echo esc_url( BUMPMINT_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'bumpmint-order-bump-for-woocommerce' ); ?></a>
				<span aria-hidden="true">·</span>
				<a href="<?php echo esc_url( BUMPMINT_REVIEW_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Leave a review', 'bumpmint-order-bump-for-woocommerce' ); ?></a>
			</nav>
		</div>
		<?php
	}

	/**
	 * Reads and normalizes read-only dashboard filters.
	 *
	 * @param string[]             $currencies Available currencies.
	 * @param array<string,string> $rules      Available rules.
	 * @return array{range:string,start_date:string,end_date:string,rule_id:string,currency:string}
	 */
	private function get_filters( array $currencies, array $rules ) {
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
		if ( ! in_array( $range, array( '7', '30', '90', 'all', 'custom' ), true ) ) {
			$range = '30';
		}

		$today          = current_datetime()->setTime( 0, 0 );
		$tracking_start = $this->get_local_tracking_start();
		$end            = $today;
		$start          = $today->modify( '-29 days' );

		if ( '7' === $range ) {
			$start = $today->modify( '-6 days' );
		} elseif ( '90' === $range ) {
			$start = $today->modify( '-89 days' );
		} elseif ( 'all' === $range ) {
			$start = $tracking_start;
		} elseif ( 'custom' === $range ) {
			$custom_start = isset( $_GET['start_date'] ) ? $this->parse_date( sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
			$custom_end   = isset( $_GET['end_date'] ) ? $this->parse_date( sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
			if ( $custom_start && $custom_end ) {
				$start = $custom_start;
				$end   = $custom_end > $today ? $today : $custom_end;
			}
		}

		if ( $start < $tracking_start ) {
			$start = $tracking_start;
		}
		if ( $start > $end ) {
			$start = $end;
		}

		$rule_id = isset( $_GET['rule_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
		if ( '' !== $rule_id && ! array_key_exists( $rule_id, $rules ) ) {
			$rule_id = '';
		}

		$currency = isset( $_GET['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['currency'] ) ) ) : get_woocommerce_currency(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
		if ( ! in_array( $currency, $currencies, true ) ) {
			$currency = reset( $currencies );
		}

		return array(
			'range'      => $range,
			'start_date' => $start->format( 'Y-m-d' ),
			'end_date'   => $end->format( 'Y-m-d' ),
			'rule_id'    => $rule_id,
			'currency'   => (string) $currency,
		);
	}

	/**
	 * Renders date, bump, and currency filters.
	 *
	 * @param array                $filters    Current filters.
	 * @param string[]             $currencies Available currencies.
	 * @param array<string,string> $rules      Available rules.
	 */
	private function render_filters( array $filters, array $currencies, array $rules ) {
		?>
		<form class="bumpmint-analytics-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<div class="bumpmint-filter-field">
				<label for="bumpmint-analytics-range"><?php esc_html_e( 'Period', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
				<select id="bumpmint-analytics-range" name="range">
					<option value="7" <?php selected( $filters['range'], '7' ); ?>><?php esc_html_e( 'Last 7 days', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
					<option value="30" <?php selected( $filters['range'], '30' ); ?>><?php esc_html_e( 'Last 30 days', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
					<option value="90" <?php selected( $filters['range'], '90' ); ?>><?php esc_html_e( 'Last 90 days', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
					<option value="all" <?php selected( $filters['range'], 'all' ); ?>><?php esc_html_e( 'All tracked data', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
					<option value="custom" <?php selected( $filters['range'], 'custom' ); ?>><?php esc_html_e( 'Custom dates', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
				</select>
			</div>
			<div class="bumpmint-filter-field bumpmint-custom-date">
				<label for="bumpmint-analytics-start"><?php esc_html_e( 'From', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
				<input id="bumpmint-analytics-start" type="date" name="start_date" value="<?php echo esc_attr( $filters['start_date'] ); ?>" />
			</div>
			<div class="bumpmint-filter-field bumpmint-custom-date">
				<label for="bumpmint-analytics-end"><?php esc_html_e( 'To', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
				<input id="bumpmint-analytics-end" type="date" name="end_date" value="<?php echo esc_attr( $filters['end_date'] ); ?>" />
			</div>
			<div class="bumpmint-filter-field">
				<label for="bumpmint-analytics-rule"><?php esc_html_e( 'Order bump', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
				<select id="bumpmint-analytics-rule" name="rule_id">
					<option value=""><?php esc_html_e( 'All order bumps', 'bumpmint-order-bump-for-woocommerce' ); ?></option>
					<?php foreach ( $rules as $rule_id => $rule_name ) : ?>
						<option value="<?php echo esc_attr( $rule_id ); ?>" <?php selected( $filters['rule_id'], $rule_id ); ?>><?php echo esc_html( $rule_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php if ( count( $currencies ) > 1 ) : ?>
				<div class="bumpmint-filter-field">
					<label for="bumpmint-analytics-currency"><?php esc_html_e( 'Currency', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
					<select id="bumpmint-analytics-currency" name="currency">
						<?php foreach ( $currencies as $currency ) : ?>
							<option value="<?php echo esc_attr( $currency ); ?>" <?php selected( $filters['currency'], $currency ); ?>><?php echo esc_html( $currency ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php else : ?>
				<input type="hidden" name="currency" value="<?php echo esc_attr( $filters['currency'] ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'bumpmint-order-bump-for-woocommerce' ); ?></button>
		</form>
		<p class="bumpmint-tracking-note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: local analytics tracking start date and time. */
					__( 'Tracking started on %s. Orders created before tracking began are not included.', 'bumpmint-order-bump-for-woocommerce' ),
					$this->get_tracking_start_display()
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders one KPI card.
	 *
	 * @param string $label Metric label.
	 * @param string $value Formatted value.
	 * @param string $help  Accessible explanation.
	 * @param string $icon  Dashicon class.
	 */
	private function render_metric( $label, $value, $help, $icon ) {
		?>
		<div class="bumpmint-metric-card">
			<div class="bumpmint-metric-icon"><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span></div>
			<div>
				<div class="bumpmint-metric-label">
					<?php echo esc_html( $label ); ?>
					<span class="dashicons dashicons-editor-help" title="<?php echo esc_attr( $help ); ?>" aria-label="<?php echo esc_attr( $help ); ?>"></span>
				</div>
				<strong class="bumpmint-metric-value"><?php echo esc_html( $value ); ?></strong>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the best-performing bump KPI, ranked by net revenue.
	 *
	 * @param array|null $top_bump Top ranking row.
	 * @param string     $currency Selected currency.
	 */
	private function render_top_bump_metric( $top_bump, $currency ) {
		$name    = is_array( $top_bump ) && ! empty( $top_bump['conversions'] ) ? $top_bump['rule_name'] : '—';
		$revenue = is_array( $top_bump ) && ! empty( $top_bump['conversions'] )
			? $this->format_plain_price( $top_bump['revenue'], $currency )
			: __( 'No conversions yet', 'bumpmint-order-bump-for-woocommerce' );
		?>
		<div class="bumpmint-metric-card bumpmint-top-bump-card">
			<div class="bumpmint-metric-icon"><span class="dashicons dashicons-awards" aria-hidden="true"></span></div>
			<div>
				<div class="bumpmint-metric-label">
					<?php esc_html_e( 'Top-performing bump', 'bumpmint-order-bump-for-woocommerce' ); ?>
					<span class="dashicons dashicons-editor-help" title="<?php esc_attr_e( 'Ranked by net bump revenue in the selected period.', 'bumpmint-order-bump-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Ranked by net bump revenue in the selected period.', 'bumpmint-order-bump-for-woocommerce' ); ?>"></span>
				</div>
				<strong class="bumpmint-metric-value bumpmint-metric-name" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></strong>
				<span class="bumpmint-metric-detail"><?php echo esc_html( $revenue ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a dependency-free SVG line chart.
	 *
	 * @param array $series Date/month view cohorts.
	 */
	private function render_trend_chart( array $series ) {
		if ( empty( $series ) ) {
			$this->render_no_data();
			return;
		}

		$width       = 920;
		$height      = 270;
		$left        = 52;
		$right       = 20;
		$top         = 20;
		$bottom      = 46;
		$plot_width  = $width - $left - $right;
		$plot_height = $height - $top - $bottom;
		$count       = count( $series );
		$maximum     = 1;
		foreach ( $series as $point ) {
			$maximum = max( $maximum, (int) $point['views'], (int) $point['converted'] );
		}

		$view_points      = array();
		$converted_points = array();
		foreach ( $series as $index => $point ) {
			$x = 1 === $count ? $left + ( $plot_width / 2 ) : $left + ( $plot_width * $index / ( $count - 1 ) );
			$view_y = $top + $plot_height - ( $plot_height * (int) $point['views'] / $maximum );
			$converted_y = $top + $plot_height - ( $plot_height * (int) $point['converted'] / $maximum );
			$view_points[]      = number_format( $x, 2, '.', '' ) . ',' . number_format( $view_y, 2, '.', '' );
			$converted_points[] = number_format( $x, 2, '.', '' ) . ',' . number_format( $converted_y, 2, '.', '' );
		}
		?>
		<div class="bumpmint-line-chart">
			<svg viewBox="0 0 <?php echo esc_attr( $width ); ?> <?php echo esc_attr( $height ); ?>" role="img" aria-label="<?php esc_attr_e( 'Views and converted views over time', 'bumpmint-order-bump-for-woocommerce' ); ?>">
				<?php for ( $grid = 0; $grid <= 4; ++$grid ) : ?>
					<?php
					$grid_y     = $top + ( $plot_height * $grid / 4 );
					$grid_value = (int) round( $maximum * ( 1 - $grid / 4 ) );
					?>
					<line class="bumpmint-chart-grid-line" x1="<?php echo esc_attr( $left ); ?>" y1="<?php echo esc_attr( $grid_y ); ?>" x2="<?php echo esc_attr( $width - $right ); ?>" y2="<?php echo esc_attr( $grid_y ); ?>"></line>
					<text class="bumpmint-chart-axis-text" x="<?php echo esc_attr( $left - 10 ); ?>" y="<?php echo esc_attr( $grid_y + 4 ); ?>" text-anchor="end"><?php echo esc_html( number_format_i18n( $grid_value ) ); ?></text>
				<?php endfor; ?>
				<polyline class="bumpmint-chart-line is-views" points="<?php echo esc_attr( implode( ' ', $view_points ) ); ?>"></polyline>
				<polyline class="bumpmint-chart-line is-converted" points="<?php echo esc_attr( implode( ' ', $converted_points ) ); ?>"></polyline>
				<?php foreach ( $this->get_chart_label_indexes( $count ) as $index ) : ?>
					<?php $x = 1 === $count ? $left + ( $plot_width / 2 ) : $left + ( $plot_width * $index / ( $count - 1 ) ); ?>
					<text class="bumpmint-chart-axis-text" x="<?php echo esc_attr( $x ); ?>" y="<?php echo esc_attr( $height - 14 ); ?>" text-anchor="middle"><?php echo esc_html( $series[ $index ]['label'] ); ?></text>
				<?php endforeach; ?>
			</svg>
			<table class="screen-reader-text">
				<caption><?php esc_html_e( 'Views and converted views over time', 'bumpmint-order-bump-for-woocommerce' ); ?></caption>
				<thead><tr><th><?php esc_html_e( 'Date', 'bumpmint-order-bump-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Views', 'bumpmint-order-bump-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Converted views', 'bumpmint-order-bump-for-woocommerce' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $series as $point ) : ?>
						<tr><td><?php echo esc_html( $point['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( $point['views'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $point['converted'] ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the selected report's converted/non-converted donut.
	 *
	 * @param int   $views           Total views.
	 * @param int   $converted_views Converted views.
	 * @param float $rate            Conversion rate.
	 */
	private function render_donut( $views, $converted_views, $rate ) {
		$rate          = max( 0.0, min( 100.0, (float) $rate ) );
		$not_converted = max( 0, (int) $views - (int) $converted_views );
		$aria_label    = sprintf(
			/* translators: 1: conversion rate percentage, 2: number of bump views. */
			__( '%1$s conversion rate from %2$s bump views.', 'bumpmint-order-bump-for-woocommerce' ),
			number_format_i18n( $rate, 1 ) . '%',
			number_format_i18n( $views )
		);
		?>
		<div class="bumpmint-donut-layout">
			<div class="bumpmint-donut" role="img" aria-label="<?php echo esc_attr( $aria_label ); ?>">
				<svg viewBox="0 0 42 42" aria-hidden="true">
					<circle class="bumpmint-donut-track" cx="21" cy="21" r="15.9155"></circle>
					<circle class="bumpmint-donut-value" cx="21" cy="21" r="15.9155" pathLength="100" stroke-dasharray="<?php echo esc_attr( number_format( $rate, 2, '.', '' ) . ' 100' ); ?>"></circle>
				</svg>
				<div><strong><?php echo esc_html( number_format_i18n( $rate, 1 ) . '%' ); ?></strong><span><?php esc_html_e( 'conversion', 'bumpmint-order-bump-for-woocommerce' ); ?></span></div>
			</div>
			<ul class="bumpmint-donut-legend">
				<li><i class="is-converted"></i><span><?php esc_html_e( 'Converted views', 'bumpmint-order-bump-for-woocommerce' ); ?></span><strong><?php echo esc_html( number_format_i18n( $converted_views ) ); ?></strong></li>
				<li><i class="is-other"></i><span><?php esc_html_e( 'Other views', 'bumpmint-order-bump-for-woocommerce' ); ?></span><strong><?php echo esc_html( number_format_i18n( $not_converted ) ); ?></strong></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Renders compact conversion-rate bars for weekdays or hours.
	 *
	 * @param array  $rows Rows with bucket, views, converted, and rate.
	 * @param string $type Either weekday or hour.
	 */
	private function render_rate_bars( array $rows, $type ) {
		?>
		<div class="bumpmint-rate-bars <?php echo 'hour' === $type ? 'is-hours' : ''; ?>">
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$label = 'hour' === $type
					? sprintf( '%02d:00', (int) $row['bucket'] )
					: $this->get_weekday_label( (int) $row['bucket'] );
				$width = number_format( max( 0.0, min( 100.0, (float) $row['rate'] ) ), 2, '.', '' );
				?>
				<div class="bumpmint-rate-row">
					<span class="bumpmint-rate-label"><?php echo esc_html( $label ); ?></span>
					<span class="bumpmint-rate-track"><i style="width: <?php echo esc_attr( $width ); ?>%;"></i></span>
					<strong><?php echo esc_html( number_format_i18n( $row['rate'], 1 ) . '%' ); ?></strong>
					<small><?php echo esc_html( sprintf( '%1$s/%2$s', number_format_i18n( $row['converted'] ), number_format_i18n( $row['views'] ) ) ); ?></small>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders the per-rule comparison table.
	 *
	 * @param array $ranking Ranking rows.
	 * @param array $filters Current report filters.
	 */
	private function render_ranking_table( array $ranking, array $filters ) {
		if ( empty( $ranking ) ) {
			$this->render_no_data();
			return;
		}
		?>
		<div class="bumpmint-ranking-table-wrap">
			<table class="widefat striped bumpmint-ranking-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order bump', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Views', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Conversions', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Rate', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Quantity', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Net revenue', 'bumpmint-order-bump-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ranking as $row ) : ?>
						<?php
						$detail_url = add_query_arg(
							array(
								'page'       => self::PAGE_SLUG,
								'range'      => $filters['range'],
								'start_date' => $filters['start_date'],
								'end_date'   => $filters['end_date'],
								'rule_id'    => $row['rule_id'],
								'currency'   => $filters['currency'],
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td><a href="<?php echo esc_url( $detail_url ); ?>"><strong><?php echo esc_html( $row['rule_name'] ); ?></strong></a></td>
							<td><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['conversions'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['rate'], 1 ) . '%' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['quantity'], 0 ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $row['revenue'], array( 'currency' => $filters['currency'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the destructive reset control with explicit confirmation.
	 */
	private function render_data_management() {
		?>
		<section class="bumpmint-report-card bumpmint-data-management">
			<div class="bumpmint-report-heading">
				<div>
					<h2><?php esc_html_e( 'Data management', 'bumpmint-order-bump-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'Reset reports without changing order bumps, products, or WooCommerce orders.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
				</div>
			</div>
			<div class="bumpmint-reset-panel">
				<div>
					<strong><?php esc_html_e( 'Reset all Analytics data', 'bumpmint-order-bump-for-woocommerce' ); ?></strong>
					<p><?php esc_html_e( 'This permanently deletes views and report conversions. Tracking restarts immediately, and orders created before the reset will remain excluded.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
				</div>
				<form class="bumpmint-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>" />
					<?php wp_nonce_field( self::RESET_ACTION ); ?>
					<label><input type="checkbox" name="bumpmint_confirm_reset" value="1" required /> <?php esc_html_e( 'I understand that Analytics data cannot be recovered.', 'bumpmint-order-bump-for-woocommerce' ); ?></label>
					<button type="submit" class="button button-secondary bumpmint-reset-button"><?php esc_html_e( 'Reset Analytics', 'bumpmint-order-bump-for-woocommerce' ); ?></button>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders a consistent no-data state inside report cards.
	 */
	private function render_no_data() {
		?>
		<div class="bumpmint-report-empty">
			<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No Analytics data is available for the selected filters yet.', 'bumpmint-order-bump-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Returns at most five evenly distributed chart label positions.
	 *
	 * @param int $count Number of data points.
	 * @return int[]
	 */
	private function get_chart_label_indexes( $count ) {
		if ( $count <= 1 ) {
			return array( 0 );
		}

		$indexes = array( 0, $count - 1 );
		for ( $step = 1; $step < 4; ++$step ) {
			$indexes[] = (int) round( ( $count - 1 ) * $step / 4 );
		}
		$indexes = array_values( array_unique( $indexes ) );
		sort( $indexes, SORT_NUMERIC );
		return $indexes;
	}

	/**
	 * Returns a localized weekday label from an ISO weekday number.
	 *
	 * @param int $weekday ISO weekday from 1 (Monday) through 7 (Sunday).
	 * @return string
	 */
	private function get_weekday_label( $weekday ) {
		$monday = new DateTimeImmutable( '2024-01-01 12:00:00', wp_timezone() );
		$date   = $monday->modify( '+' . max( 0, min( 6, $weekday - 1 ) ) . ' days' );
		return wp_date( 'D', $date->getTimestamp(), wp_timezone() );
	}

	/**
	 * Parses an exact YYYY-MM-DD date in the WordPress timezone.
	 *
	 * @param mixed $value Raw date.
	 * @return DateTimeImmutable|null
	 */
	private function parse_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();

		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		return $date;
	}

	/**
	 * Returns the tracking start converted to the current WordPress timezone.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_local_tracking_start() {
		$started_at = BumpMint_Analytics::get_tracking_started_at();
		try {
			$utc = new DateTimeImmutable( $started_at, new DateTimeZone( 'UTC' ) );
			return $utc->setTimezone( wp_timezone() )->setTime( 0, 0 );
		} catch ( Exception $exception ) {
			return current_datetime()->setTime( 0, 0 );
		}
	}

	/**
	 * Formats tracking start date and time for the dashboard notice.
	 *
	 * @return string
	 */
	private function get_tracking_start_display() {
		$started_at = BumpMint_Analytics::get_tracking_started_at();
		try {
			$utc = new DateTimeImmutable( $started_at, new DateTimeZone( 'UTC' ) );
			return wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				$utc->getTimestamp(),
				wp_timezone()
			);
		} catch ( Exception $exception ) {
			return __( 'now', 'bumpmint-order-bump-for-woocommerce' );
		}
	}

	/**
	 * Formats a WooCommerce amount as escaped plain text without exposing HTML entities.
	 *
	 * @param float  $amount   Amount to format.
	 * @param string $currency WooCommerce currency code.
	 * @return string
	 */
	private function format_plain_price( $amount, $currency ) {
		$formatted = wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) );
		return wp_specialchars_decode( $formatted, ENT_QUOTES );
	}
}
