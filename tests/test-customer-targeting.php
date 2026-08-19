<?php
/**
 * Customer targeting integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies login-state and WordPress-role eligibility.
 */
class Test_BumpMint_Customer_Targeting extends BumpMint_Test_Case {

	/**
	 * Previous current user ID.
	 *
	 * @var int
	 */
	private $previous_user_id;

	/**
	 * Starts each test as a logged-out visitor.
	 */
	public function set_up() {
		parent::set_up();

		$this->previous_user_id = get_current_user_id();
		wp_set_current_user( 0 );
	}

	/**
	 * Restores the user context.
	 */
	public function tear_down() {
		wp_set_current_user( $this->previous_user_id );

		parent::tear_down();
	}

	/**
	 * All four audiences are evaluated from the request-cached WordPress user.
	 */
	public function test_everyone_login_state_and_any_selected_role_match() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_role( 'author' );
		$guest = new WP_User( 0 );

		$this->assertTrue( BumpMint_Conditions::matches_customer( array( 'customer_audience' => BumpMint_Conditions::AUDIENCE_EVERYONE ), $guest ) );
		$this->assertTrue( BumpMint_Conditions::matches_customer( array( 'customer_audience' => BumpMint_Conditions::AUDIENCE_EVERYONE ), $user ) );
		$this->assertFalse( BumpMint_Conditions::matches_customer( array( 'customer_audience' => BumpMint_Conditions::AUDIENCE_LOGGED_IN ), $guest ) );
		$this->assertTrue( BumpMint_Conditions::matches_customer( array( 'customer_audience' => BumpMint_Conditions::AUDIENCE_LOGGED_IN ), $user ) );
		$this->assertTrue( BumpMint_Conditions::matches_customer( array( 'customer_audience' => BumpMint_Conditions::AUDIENCE_LOGGED_OUT ), $guest ) );
		$this->assertFalse( BumpMint_Conditions::matches_customer( array( 'customer_audience' => BumpMint_Conditions::AUDIENCE_LOGGED_OUT ), $user ) );
		$this->assertFalse( BumpMint_Conditions::matches_customer( array(
			'customer_audience'   => BumpMint_Conditions::AUDIENCE_ROLES,
			'customer_role_slugs' => array( 'subscriber' ),
		), $guest ) );
		$this->assertTrue( BumpMint_Conditions::matches_customer( array(
			'customer_audience'   => BumpMint_Conditions::AUDIENCE_ROLES,
			'customer_role_slugs' => array( 'editor', 'author' ),
		), $user ) );
		$this->assertFalse( BumpMint_Conditions::matches_customer( array(
			'customer_audience'   => BumpMint_Conditions::AUDIENCE_ROLES,
			'customer_role_slugs' => array( 'editor' ),
		), $user ) );
	}

	/**
	 * Legacy rules remain public and corrupt explicit audience values fail closed.
	 */
	public function test_customer_targeting_normalizes_legacy_and_invalid_values_safely() {
		$offer = $this->create_product( 100, 'Legacy audience offer' );
		$rule  = $this->create_rule( $offer );

		unset( $rule['customer_audience'], $rule['customer_role_slugs'] );
		update_option( BUMPMINT_OPTION_KEY, array( $rule ), false );

		$legacy = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( BumpMint_Conditions::AUDIENCE_EVERYONE, $legacy['customer_audience'] );
		$this->assertSame( array(), $legacy['customer_role_slugs'] );
		$this->assertTrue( BumpMint_Conditions::matches_customer( $legacy ) );

		$legacy['customer_audience']   = 'unsupported';
		$legacy['customer_role_slugs'] = array( 'subscriber' );
		update_option( BUMPMINT_OPTION_KEY, array( $legacy ), false );

		$invalid = BumpMint_Rules::get_rule( $rule['id'] );
		$this->assertSame( BumpMint_Conditions::AUDIENCE_ROLES, $invalid['customer_audience'] );
		$this->assertSame( array(), $invalid['customer_role_slugs'] );
		$this->assertFalse( BumpMint_Conditions::matches_customer( $invalid ) );
	}

	/**
	 * Server validation requires a supported audience and at least one role.
	 */
	public function test_customer_targeting_is_validated_before_persistence() {
		$offer = $this->create_product( 100, 'Validated audience offer' );
		$base  = array(
			'condition_type'   => BumpMint_Conditions::ALWAYS,
			'bump_product_ids' => array( $offer->get_id() ),
		);

		$invalid_audience = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'              => 'Invalid audience',
			'customer_audience' => 'unsupported',
		), $base ) );
		$this->assertWPError( $invalid_audience );
		$this->assertSame( 'bumpmint_invalid_customer_audience', $invalid_audience->get_error_code() );

		$missing_role = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'              => 'Missing customer role',
			'customer_audience' => BumpMint_Conditions::AUDIENCE_ROLES,
		), $base ) );
		$this->assertWPError( $missing_role );
		$this->assertSame( 'bumpmint_customer_role_required', $missing_role->get_error_code() );

		$roles = BumpMint_Rules::save_rule( '', wp_parse_args( array(
			'name'                => 'Selected customer roles',
			'customer_audience'   => BumpMint_Conditions::AUDIENCE_ROLES,
			'customer_role_slugs' => array( 'subscriber', 'author', 'subscriber' ),
		), $base ) );
		$this->assertNotWPError( $roles );
		$this->assertSame( array( 'author', 'subscriber' ), $roles['customer_role_slugs'] );
	}

	/**
	 * Customer targeting differentiates otherwise identical rule fingerprints.
	 */
	public function test_customer_audience_is_part_of_duplicate_detection() {
		$offer = $this->create_product( 100, 'Audience fingerprint offer' );
		$this->create_rule( $offer, array( 'name' => 'Everyone offer' ) );

		$logged_in = $this->create_rule(
			$offer,
			array(
				'name'              => 'Logged-in offer',
				'customer_audience' => BumpMint_Conditions::AUDIENCE_LOGGED_IN,
			)
		);
		$this->assertSame( BumpMint_Conditions::AUDIENCE_LOGGED_IN, $logged_in['customer_audience'] );

		$duplicate_data = $logged_in;
		$duplicate_data['name'] = 'Duplicate logged-in offer';
		unset( $duplicate_data['id'], $duplicate_data['created_at'] );
		$duplicate = BumpMint_Rules::save_rule( '', $duplicate_data );

		$this->assertWPError( $duplicate );
		$this->assertSame( 'bumpmint_duplicate_rule', $duplicate->get_error_code() );
	}

	/**
	 * Central checkout eligibility hides, enables, and removes a role offer.
	 */
	public function test_role_targeting_is_enforced_throughout_checkout() {
		$cart_product = $this->create_product( 25, 'Audience cart product' );
		$offer        = $this->create_product( 100, 'Subscriber offer' );
		$rule         = $this->create_rule(
			$offer,
			array(
				'customer_audience'   => BumpMint_Conditions::AUDIENCE_ROLES,
				'customer_role_slugs' => array( 'subscriber' ),
			)
		);
		WC()->cart->add_to_cart( $cart_product->get_id(), 1 );

		$reflection = new ReflectionClass( BumpMint_Checkout::class );
		$checkout   = $reflection->newInstanceWithoutConstructor();
		$this->assertSame( array(), $checkout->get_applicable_offers( WC()->cart ) );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->assertCount( 1, $checkout->get_applicable_offers( WC()->cart ) );

		$bump_key = $this->add_bump_to_cart( $offer, $rule );
		WC()->cart->calculate_totals();
		$this->assertArrayHasKey( $bump_key, WC()->cart->get_cart() );

		wp_set_current_user( 0 );
		WC()->cart->calculate_totals();
		$this->assertArrayNotHasKey( $bump_key, WC()->cart->get_cart() );

		$pending = WC()->session->get( BumpMint_Checkout::SESSION_PENDING_REMOVALS, array() );
		$this->assertNotEmpty( $pending );
		$this->assertStringContainsString( 'required user role', implode( ' ', $pending ) );
	}

	/**
	 * The admin form lists built-in and integration-provided roles.
	 */
	public function test_admin_customer_targeting_controls_render_registered_roles() {
		$previous_get = $_GET;
		add_role( 'bumpmint_vip', 'VIP Buyer' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin    = new WP_User( $admin_id );
		$admin->add_cap( BumpMint_Admin::CAPABILITY );
		wp_set_current_user( $admin_id );
		$_GET['page']   = BumpMint_Admin::PAGE_SLUG;
		$_GET['action'] = 'new';

		$reflection = new ReflectionClass( BumpMint_Admin::class );
		$controller = $reflection->newInstanceWithoutConstructor();

		ob_start();
		$controller->render_page();
		$html = (string) ob_get_clean();

		$_GET = $previous_get;
		remove_role( 'bumpmint_vip' );

		$this->assertStringContainsString( 'id="bumpmint-customer-audience"', $html );
		$this->assertStringContainsString( 'value="everyone"', $html );
		$this->assertStringContainsString( 'value="logged_in"', $html );
		$this->assertStringContainsString( 'value="logged_out"', $html );
		$this->assertStringContainsString( 'value="roles"', $html );
		$this->assertStringContainsString( 'id="bumpmint-customer-roles"', $html );
		$this->assertStringContainsString( 'value="bumpmint_vip"', $html );
		$this->assertStringContainsString( 'VIP Buyer', $html );
	}
}
