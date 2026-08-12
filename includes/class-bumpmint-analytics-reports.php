<?php
/**
 * Read-only report queries for the BumpMint Analytics screen.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds indexed, admin-only summaries from BumpMint's dedicated tables.
 */
class BumpMint_Analytics_Reports {

	/**
	 * Returns every currency represented in tracked data.
	 *
	 * @return string[]
	 */
	public function get_available_currencies() {
		global $wpdb;

		$views       = BumpMint_Analytics::get_views_table_name();
		$conversions = BumpMint_Analytics::get_conversions_table_name();
		$tracking_id = BumpMint_Analytics::get_tracking_id();
		$sql         = $wpdb->prepare(
			"SELECT currency FROM %i WHERE tracking_id = %s
			UNION SELECT currency FROM %i WHERE tracking_id = %s
			ORDER BY currency ASC",
			$views,
			$tracking_id,
			$conversions,
			$tracking_id
		);
		$currencies  = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One admin report query against plugin-owned tables; prepared immediately above.

		$currencies = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $currencies ) ) ) );
		if ( empty( $currencies ) ) {
			$currencies[] = get_woocommerce_currency();
		}

		return $currencies;
	}

	/**
	 * Returns current and historical rule labels for the report filter.
	 *
	 * @return array<string,string>
	 */
	public function get_available_rules() {
		global $wpdb;

		$rules = array();
		foreach ( BumpMint_Rules::get_rules() as $rule ) {
			if ( ! empty( $rule['id'] ) ) {
				$rules[ (string) $rule['id'] ] = (string) $rule['name'];
			}
		}

		$views       = BumpMint_Analytics::get_views_table_name();
		$conversions = BumpMint_Analytics::get_conversions_table_name();
		$tracking_id = BumpMint_Analytics::get_tracking_id();
		$sql         = $wpdb->prepare(
			"SELECT rule_id, MAX(rule_name) AS rule_name FROM (
			SELECT rule_id, rule_name FROM %i WHERE tracking_id = %s
			UNION ALL
			SELECT rule_id, rule_name FROM %i WHERE tracking_id = %s
		) AS bumpmint_rules GROUP BY rule_id",
			$views,
			$tracking_id,
			$conversions,
			$tracking_id
		);
		$historical  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One admin report query against plugin-owned tables; prepared immediately above.

		foreach ( (array) $historical as $rule ) {
			$rule_id = isset( $rule['rule_id'] ) ? sanitize_text_field( $rule['rule_id'] ) : '';
			if ( '' !== $rule_id && ! isset( $rules[ $rule_id ] ) ) {
				$rules[ $rule_id ] = isset( $rule['rule_name'] ) ? sanitize_text_field( $rule['rule_name'] ) : $rule_id;
			}
		}

		natcasesort( $rules );
		return $rules;
	}

	/**
	 * Builds all metrics needed by one Analytics page request.
	 *
	 * @param array{start_date:string,end_date:string,rule_id:string,currency:string} $filters Sanitized filters.
	 * @return array<string,mixed>
	 */
	public function get_report( array $filters ) {
		$view_summary       = $this->get_view_summary( $filters );
		$conversion_summary = $this->get_conversion_summary( $filters );
		$ranking            = $this->get_rule_ranking( $filters );
		$conversion_rate    = $view_summary['views'] > 0
			? ( $view_summary['converted_views'] / $view_summary['views'] ) * 100
			: 0.0;

		return array(
			'views'            => $view_summary['views'],
			'converted_views'  => $view_summary['converted_views'],
			'conversions'      => $conversion_summary['conversions'],
			'quantity'         => $conversion_summary['quantity'],
			'revenue'          => $conversion_summary['revenue'],
			'conversion_rate'  => $conversion_rate,
			'top_bump'         => ! empty( $ranking ) ? $ranking[0] : null,
			'daily'            => $this->get_view_rate_series( $filters ),
			'weekdays'         => $this->get_dimension_rates( $filters, 'view_weekday', range( 1, 7 ) ),
			'hours'            => $this->get_dimension_rates( $filters, 'view_hour', range( 0, 23 ) ),
			'ranking'          => $ranking,
		);
	}

	/**
	 * Counts checkout-journey bump views and the views linked to conversions.
	 *
	 * @param array $filters Report filters.
	 * @return array{views:int,converted_views:int}
	 */
	private function get_view_summary( array $filters ) {
		global $wpdb;

		$views       = BumpMint_Analytics::get_views_table_name();
		$conversions = BumpMint_Analytics::get_conversions_table_name();
		$conditions  = $this->build_conditions( 'v', 'view_date', $filters, false );
		$sql         = "SELECT
			COUNT(DISTINCT v.id) AS views,
			COUNT(DISTINCT CASE WHEN c.is_valid = 1 THEN v.id END) AS converted_views
			FROM %i v
			LEFT JOIN %i c ON c.view_id = v.id AND c.is_valid = 1
			{$conditions['sql']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact plugin-owned tables and whitelisted conditions.
		$params      = array_merge( array( $views, $conversions ), $conditions['params'] );
		$row         = $wpdb->get_row( $this->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the internal whitelist-aware helper; report reads are not repeated in the request.

		return array(
			'views'           => isset( $row['views'] ) ? absint( $row['views'] ) : 0,
			'converted_views' => isset( $row['converted_views'] ) ? absint( $row['converted_views'] ) : 0,
		);
	}

	/**
	 * Returns valid paid conversions and net revenue by conversion date.
	 *
	 * @param array $filters Report filters.
	 * @return array{conversions:int,quantity:float,revenue:float}
	 */
	private function get_conversion_summary( array $filters ) {
		global $wpdb;

		$conversions = BumpMint_Analytics::get_conversions_table_name();
		$conditions  = $this->build_conditions( 'c', 'conversion_date', $filters, true );
		$sql         = "SELECT COUNT(*) AS conversions, COALESCE(SUM(c.quantity), 0) AS quantity,
			COALESCE(SUM(c.net_revenue), 0) AS revenue
			FROM %i c {$conditions['sql']}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The condition fragments use strict internal alias/column whitelists.
		$params      = array_merge( array( $conversions ), $conditions['params'] );
		$row         = $wpdb->get_row( $this->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the internal whitelist-aware helper; report reads are not repeated in the request.

		return array(
			'conversions' => isset( $row['conversions'] ) ? absint( $row['conversions'] ) : 0,
			'quantity'    => isset( $row['quantity'] ) ? (float) $row['quantity'] : 0.0,
			'revenue'     => isset( $row['revenue'] ) ? (float) $row['revenue'] : 0.0,
		);
	}

	/**
	 * Returns conversion-rate cohorts by date or month for the trend chart.
	 *
	 * @param array $filters Report filters.
	 * @return array<int,array{key:string,label:string,views:int,converted:int,rate:float}>
	 */
	private function get_view_rate_series( array $filters ) {
		global $wpdb;

		$views       = BumpMint_Analytics::get_views_table_name();
		$conversions = BumpMint_Analytics::get_conversions_table_name();
		$conditions  = $this->build_conditions( 'v', 'view_date', $filters, false );
		$days        = $this->get_range_days( $filters['start_date'], $filters['end_date'] );
		$group_sql   = $days > 120 ? "DATE_FORMAT(v.view_date, '%Y-%m-01')" : 'v.view_date';
		$sql         = "SELECT {$group_sql} AS period_key,
			COUNT(DISTINCT v.id) AS views,
			COUNT(DISTINCT CASE WHEN c.is_valid = 1 THEN v.id END) AS converted_views
			FROM %i v
			LEFT JOIN %i c ON c.view_id = v.id AND c.is_valid = 1
			{$conditions['sql']}
			GROUP BY period_key ORDER BY period_key ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Grouping and condition fragments use strict internal whitelists.
		$params      = array_merge( array( $views, $conversions ), $conditions['params'] );
		$rows        = $wpdb->get_results( $this->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the internal whitelist-aware helper; report reads are not repeated in the request.
		$series      = array();

		foreach ( (array) $rows as $row ) {
			$key       = isset( $row['period_key'] ) ? sanitize_text_field( $row['period_key'] ) : '';
			$views_num = isset( $row['views'] ) ? absint( $row['views'] ) : 0;
			$converted = isset( $row['converted_views'] ) ? absint( $row['converted_views'] ) : 0;
			$timestamp = $key ? strtotime( $key . ' 12:00:00' ) : false;

			$series[] = array(
				'key'       => $key,
				'label'     => $timestamp
					? wp_date( $days > 120 ? 'M Y' : get_option( 'date_format' ), $timestamp, wp_timezone() )
					: $key,
				'views'     => $views_num,
				'converted' => $converted,
				'rate'      => $views_num > 0 ? ( $converted / $views_num ) * 100 : 0.0,
			);
		}

		return $series;
	}

	/**
	 * Returns view-cohort conversion rates for weekday or hour buckets.
	 *
	 * @param array  $filters     Report filters.
	 * @param string $dimension   Whitelisted views-table column.
	 * @param int[]  $all_buckets Buckets to include even when empty.
	 * @return array<int,array{bucket:int,views:int,converted:int,rate:float}>
	 */
	private function get_dimension_rates( array $filters, $dimension, array $all_buckets ) {
		global $wpdb;

		$allowed = array( 'view_weekday', 'view_hour' );
		if ( ! in_array( $dimension, $allowed, true ) ) {
			return array();
		}

		$views       = BumpMint_Analytics::get_views_table_name();
		$conversions = BumpMint_Analytics::get_conversions_table_name();
		$conditions  = $this->build_conditions( 'v', 'view_date', $filters, false );
		$sql         = "SELECT v.{$dimension} AS bucket,
			COUNT(DISTINCT v.id) AS views,
			COUNT(DISTINCT CASE WHEN c.is_valid = 1 THEN v.id END) AS converted_views
			FROM %i v
			LEFT JOIN %i c ON c.view_id = v.id AND c.is_valid = 1
			{$conditions['sql']}
			GROUP BY v.{$dimension} ORDER BY v.{$dimension} ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dimension and condition fragments use strict internal whitelists.
		$params      = array_merge( array( $views, $conversions ), $conditions['params'] );
		$rows        = $wpdb->get_results( $this->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the internal whitelist-aware helper; report reads are not repeated in the request.
		$indexed     = array();

		foreach ( (array) $rows as $row ) {
			$indexed[ (int) $row['bucket'] ] = $row;
		}

		$result = array();
		foreach ( $all_buckets as $bucket ) {
			$row       = isset( $indexed[ $bucket ] ) ? $indexed[ $bucket ] : array();
			$views_num = isset( $row['views'] ) ? absint( $row['views'] ) : 0;
			$converted = isset( $row['converted_views'] ) ? absint( $row['converted_views'] ) : 0;
			$result[]  = array(
				'bucket'    => (int) $bucket,
				'views'     => $views_num,
				'converted' => $converted,
				'rate'      => $views_num > 0 ? ( $converted / $views_num ) * 100 : 0.0,
			);
		}

		return $result;
	}

	/**
	 * Merges per-rule view cohorts with valid paid conversion totals.
	 *
	 * @param array $filters Report filters.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_rule_ranking( array $filters ) {
		global $wpdb;

		$views            = BumpMint_Analytics::get_views_table_name();
		$conversions      = BumpMint_Analytics::get_conversions_table_name();
		$view_conditions  = $this->build_conditions( 'v', 'view_date', $filters, false );
		$order_conditions = $this->build_conditions( 'c', 'conversion_date', $filters, true );
		$view_sql         = "SELECT v.rule_id, MAX(v.rule_name) AS rule_name,
			COUNT(DISTINCT v.id) AS views,
			COUNT(DISTINCT CASE WHEN c.is_valid = 1 THEN v.id END) AS converted_views
			FROM %i v
			LEFT JOIN %i c ON c.view_id = v.id AND c.is_valid = 1
			{$view_conditions['sql']}
			GROUP BY v.rule_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Condition fragments use strict internal whitelists.
		$order_sql        = "SELECT c.rule_id, MAX(c.rule_name) AS rule_name,
			COUNT(*) AS conversions, COALESCE(SUM(c.quantity), 0) AS quantity,
			COALESCE(SUM(c.net_revenue), 0) AS revenue
			FROM %i c {$order_conditions['sql']}
			GROUP BY c.rule_id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Condition fragments use strict internal whitelists.
		$view_params      = array_merge( array( $views, $conversions ), $view_conditions['params'] );
		$order_params     = array_merge( array( $conversions ), $order_conditions['params'] );
		$view_rows        = $wpdb->get_results( $this->prepare( $view_sql, $view_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the internal whitelist-aware helper; report reads are not repeated in the request.
		$order_rows       = $wpdb->get_results( $this->prepare( $order_sql, $order_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the internal whitelist-aware helper; report reads are not repeated in the request.
		$current_names    = array();

		foreach ( BumpMint_Rules::get_rules() as $rule ) {
			$current_names[ (string) $rule['id'] ] = (string) $rule['name'];
		}

		$ranking = array();
		foreach ( (array) $view_rows as $row ) {
			$rule_id   = sanitize_text_field( (string) $row['rule_id'] );
			$views_num = absint( $row['views'] );
			$converted = absint( $row['converted_views'] );
			$ranking[ $rule_id ] = array(
				'rule_id'         => $rule_id,
				'rule_name'       => isset( $current_names[ $rule_id ] ) ? $current_names[ $rule_id ] : sanitize_text_field( $row['rule_name'] ),
				'views'           => $views_num,
				'converted_views' => $converted,
				'conversions'     => 0,
				'quantity'        => 0.0,
				'revenue'         => 0.0,
				'rate'            => $views_num > 0 ? ( $converted / $views_num ) * 100 : 0.0,
			);
		}

		foreach ( (array) $order_rows as $row ) {
			$rule_id = sanitize_text_field( (string) $row['rule_id'] );
			if ( ! isset( $ranking[ $rule_id ] ) ) {
				$ranking[ $rule_id ] = array(
					'rule_id'         => $rule_id,
					'rule_name'       => isset( $current_names[ $rule_id ] ) ? $current_names[ $rule_id ] : sanitize_text_field( $row['rule_name'] ),
					'views'           => 0,
					'converted_views' => 0,
					'conversions'     => 0,
					'quantity'        => 0.0,
					'revenue'         => 0.0,
					'rate'            => 0.0,
				);
			}

			$ranking[ $rule_id ]['conversions'] = absint( $row['conversions'] );
			$ranking[ $rule_id ]['quantity']    = (float) $row['quantity'];
			$ranking[ $rule_id ]['revenue']     = (float) $row['revenue'];
		}

		$ranking = array_values( $ranking );
		usort(
			$ranking,
			static function ( $left, $right ) {
				if ( (float) $left['revenue'] === (float) $right['revenue'] ) {
					if ( (int) $left['conversions'] === (int) $right['conversions'] ) {
						return strcasecmp( $left['rule_name'], $right['rule_name'] );
					}
					return (int) $right['conversions'] <=> (int) $left['conversions'];
				}
				return (float) $right['revenue'] <=> (float) $left['revenue'];
			}
		);

		return $ranking;
	}

	/**
	 * Builds a safe WHERE clause shared by views and conversion queries.
	 *
	 * @param string $alias      Whitelisted table alias.
	 * @param string $date_field Whitelisted date field.
	 * @param array  $filters    Report filters.
	 * @param bool   $valid_only Whether to require current valid conversions.
	 * @return array{sql:string,params:array}
	 */
	private function build_conditions( $alias, $date_field, array $filters, $valid_only ) {
		$allowed_aliases = array( 'v', 'c' );
		$allowed_dates   = array( 'view_date', 'conversion_date' );
		if ( ! in_array( $alias, $allowed_aliases, true ) || ! in_array( $date_field, $allowed_dates, true ) ) {
			return array( 'sql' => 'WHERE 1 = 0', 'params' => array() );
		}

		$parts  = array(
			"{$alias}.tracking_id = %s",
			"{$alias}.{$date_field} BETWEEN %s AND %s",
		);
		$params = array( BumpMint_Analytics::get_tracking_id(), $filters['start_date'], $filters['end_date'] );

		if ( $valid_only ) {
			$parts[] = "{$alias}.is_valid = 1";
		}
		if ( ! empty( $filters['rule_id'] ) ) {
			$parts[]  = "{$alias}.rule_id = %s";
			$params[] = $filters['rule_id'];
		}
		if ( ! empty( $filters['currency'] ) ) {
			$parts[]  = "{$alias}.currency = %s";
			$params[] = $filters['currency'];
		}

		return array(
			'sql'    => 'WHERE ' . implode( ' AND ', $parts ),
			'params' => $params,
		);
	}

	/**
	 * Prepares a query assembled only from trusted table/column identifiers.
	 *
	 * @param string $sql    SQL with placeholders.
	 * @param array  $params Placeholder values.
	 * @return string
	 */
	private function prepare( $sql, array $params ) {
		global $wpdb;
		return empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL identifiers are internal whitelists; all values use placeholders.
	}

	/**
	 * Returns inclusive days in a valid report range.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return int
	 */
	private function get_range_days( $start_date, $end_date ) {
		try {
			$start = new DateTimeImmutable( $start_date, wp_timezone() );
			$end   = new DateTimeImmutable( $end_date, wp_timezone() );
			return max( 1, (int) $start->diff( $end )->format( '%a' ) + 1 );
		} catch ( Exception $exception ) {
			return 1;
		}
	}
}
