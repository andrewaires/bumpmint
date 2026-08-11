<?php
/**
 * Availability scheduling integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies date, weekday, time, persistence, and checkout behavior.
 */
class Test_BumpMint_Scheduling extends BumpMint_Test_Case {

	/**
	 * Previous WordPress timezone setting.
	 *
	 * @var string
	 */
	private $previous_timezone_string;

	/**
	 * Previous manual UTC offset.
	 *
	 * @var mixed
	 */
	private $previous_gmt_offset;

	/**
	 * Uses a timezone with a negative UTC offset for deterministic checks.
	 */
	public function set_up() {
		parent::set_up();

		$this->previous_timezone_string = get_option( 'timezone_string', '' );
		$this->previous_gmt_offset      = get_option( 'gmt_offset', 0 );
		update_option( 'timezone_string', 'America/Sao_Paulo' );
		update_option( 'gmt_offset', -3 );
	}

	/**
	 * Restores the WordPress timezone setting.
	 */
	public function tear_down() {
		update_option( 'timezone_string', $this->previous_timezone_string );
		update_option( 'gmt_offset', $this->previous_gmt_offset );

		parent::tear_down();
	}

	/**
	 * Existing rules without schedule fields remain available every day.
	 */
	public function test_legacy_rules_default_to_always_available() {
		$offer = $this->create_product( 40, 'Legacy offer' );
		$rule  = $this->create_rule( $offer );

		unset(
			$rule['schedule_type'],
			$rule['schedule_dates'],
			$rule['schedule_weekdays'],
			$rule['schedule_time_enabled'],
			$rule['schedule_start_time'],
			$rule['schedule_end_time']
		);
		update_option( BUMPMINT_OPTION_KEY, array( $rule ), false );

		$migrated = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( BumpMint_Conditions::SCHEDULE_ALWAYS, $migrated['schedule_type'] );
		$this->assertSame( array(), $migrated['schedule_dates'] );
		$this->assertSame( array(), $migrated['schedule_weekdays'] );
		$this->assertFalse( $migrated['schedule_time_enabled'] );
		$this->assertTrue( BumpMint_Conditions::matches_schedule( $migrated, new DateTimeImmutable( '2026-08-12 12:00:00', wp_timezone() ) ) );
	}

	/**
	 * Specific dates and boundaries are evaluated in the site timezone.
	 */
	public function test_specific_dates_and_time_boundaries_use_site_timezone() {
		$rule = array(
			'schedule_type'         => BumpMint_Conditions::SCHEDULE_DATES,
			'schedule_dates'        => array( '2026-08-12' ),
			'schedule_weekdays'     => array(),
			'schedule_time_enabled' => true,
			'schedule_start_time'   => '21:00',
			'schedule_end_time'     => '23:00',
		);

		$this->assertTrue( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-12 21:00:00', wp_timezone() ) ) );
		$this->assertTrue( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-12 22:59:59', wp_timezone() ) ) );
		$this->assertFalse( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-12 23:00:00', wp_timezone() ) ) );
		$this->assertFalse( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-13 22:00:00', wp_timezone() ) ) );

		$utc_time = new DateTimeImmutable( '2026-08-13 01:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertTrue( BumpMint_Conditions::matches_schedule( $rule, $utc_time ) );
	}

	/**
	 * An overnight range remains associated with the weekday on which it began.
	 */
	public function test_weekday_overnight_range_uses_its_start_day() {
		$rule = array(
			'schedule_type'         => BumpMint_Conditions::SCHEDULE_WEEKDAYS,
			'schedule_dates'        => array(),
			'schedule_weekdays'     => array( 3 ),
			'schedule_time_enabled' => true,
			'schedule_start_time'   => '22:00',
			'schedule_end_time'     => '02:00',
		);

		$this->assertTrue( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-12 22:00:00', wp_timezone() ) ) );
		$this->assertTrue( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-13 01:59:59', wp_timezone() ) ) );
		$this->assertFalse( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-13 02:00:00', wp_timezone() ) ) );
		$this->assertFalse( BumpMint_Conditions::matches_schedule( $rule, new DateTimeImmutable( '2026-08-13 23:00:00', wp_timezone() ) ) );
	}

	/**
	 * Schedule values are strictly validated before a rule is persisted.
	 */
	public function test_schedule_validation_is_server_side() {
		$offer = $this->create_product( 40, 'Scheduled offer' );
		$base  = array(
			'condition_type'   => BumpMint_Conditions::ALWAYS,
			'bump_product_ids' => array( $offer->get_id() ),
		);

		$missing_date = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'          => 'Missing date',
			'schedule_type' => BumpMint_Conditions::SCHEDULE_DATES,
		), $base ) );
		$this->assertWPError( $missing_date );
		$this->assertSame( 'bumpmint_schedule_date_required', $missing_date->get_error_code() );

		$invalid_date = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'           => 'Invalid date',
			'schedule_type'  => BumpMint_Conditions::SCHEDULE_DATES,
			'schedule_dates' => array( '2026-02-30' ),
		), $base ) );
		$this->assertWPError( $invalid_date );
		$this->assertSame( 'bumpmint_invalid_schedule_date', $invalid_date->get_error_code() );

		$missing_weekday = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'          => 'Missing weekday',
			'schedule_type' => BumpMint_Conditions::SCHEDULE_WEEKDAYS,
		), $base ) );
		$this->assertWPError( $missing_weekday );
		$this->assertSame( 'bumpmint_schedule_weekday_required', $missing_weekday->get_error_code() );

		$invalid_time = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'                  => 'Invalid time',
			'schedule_time_enabled' => '1',
			'schedule_start_time'   => '25:00',
			'schedule_end_time'     => '18:00',
		), $base ) );
		$this->assertWPError( $invalid_time );
		$this->assertSame( 'bumpmint_invalid_schedule_time', $invalid_time->get_error_code() );

		$equal_times = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'                  => 'Equal times',
			'schedule_time_enabled' => '1',
			'schedule_start_time'   => '09:00',
			'schedule_end_time'     => '09:00',
		), $base ) );
		$this->assertWPError( $equal_times );
		$this->assertSame( 'bumpmint_equal_schedule_times', $equal_times->get_error_code() );
	}

	/**
	 * Different schedules may reuse one commercial rule, but equal schedules may not.
	 */
	public function test_schedule_is_part_of_duplicate_detection() {
		$offer = $this->create_product( 40, 'Duplicate schedule offer' );
		$base  = array(
			'condition_type'   => BumpMint_Conditions::ALWAYS,
			'bump_product_ids' => array( $offer->get_id() ),
			'schedule_type'    => BumpMint_Conditions::SCHEDULE_DATES,
		);

		$first = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'           => 'First date schedule',
			'schedule_dates' => array( '2026-08-12', '2026-08-13' ),
		), $base ) );
		$this->assertNotWPError( $first );

		$different = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'           => 'Different date schedule',
			'schedule_dates' => array( '2026-08-14' ),
		), $base ) );
		$this->assertNotWPError( $different );

		$duplicate = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'           => 'Duplicate date schedule',
			'schedule_dates' => array( '2026-08-13', '2026-08-12' ),
		), $base ) );
		$this->assertWPError( $duplicate );
		$this->assertSame( 'bumpmint_duplicate_rule', $duplicate->get_error_code() );
	}

	/**
	 * An expired schedule removes its selected product through existing safeguards.
	 */
	public function test_expired_schedule_removes_bump_and_queues_schedule_message() {
		$offer    = $this->create_product( 40, 'Expired scheduled offer' );
		$past_day = current_datetime()->modify( '-2 days' )->format( 'Y-m-d' );
		$rule     = $this->create_rule(
			$offer,
			array(
				'schedule_type'  => BumpMint_Conditions::SCHEDULE_DATES,
				'schedule_dates' => array( $past_day ),
			)
		);
		$bump_key = $this->add_bump_to_cart( $offer, $rule );

		WC()->cart->calculate_totals();
		$this->assertArrayNotHasKey( $bump_key, WC()->cart->get_cart() );

		$pending = WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS, array() );
		$this->assertNotEmpty( $pending );
		$this->assertStringContainsString( 'scheduled date or time', implode( ' ', $pending ) );
	}

	/**
	 * The admin form uses native date and time controls and shows the site timezone.
	 */
	public function test_admin_schedule_controls_render() {
		$previous_get     = $_GET;
		$previous_user_id = get_current_user_id();
		$user_id          = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user             = new WP_User( $user_id );
		$user->add_cap( BumpMint_Admin::CAPABILITY );
		wp_set_current_user( $user_id );
		$_GET['page']   = BumpMint_Admin::PAGE_SLUG;
		$_GET['action'] = 'new';

		$reflection = new ReflectionClass( BumpMint_Admin::class );
		$admin      = $reflection->newInstanceWithoutConstructor();

		ob_start();
		$admin->render_page();
		$html = (string) ob_get_clean();

		$_GET = $previous_get;
		wp_set_current_user( $previous_user_id );

		$this->assertStringContainsString( 'id="bumpmint-schedule-type"', $html );
		$this->assertStringContainsString( 'type="date"', $html );
		$this->assertStringContainsString( 'type="time"', $html );
		$this->assertStringContainsString( 'America/Sao_Paulo', $html );
	}
}
