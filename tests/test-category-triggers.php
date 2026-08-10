<?php
/**
 * Product category trigger integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies product category display rules.
 */
class Test_BumpMint_Category_Triggers extends BumpMint_Test_Case {

	/**
	 * The WooCommerce category search must submit numeric term IDs.
	 */
	public function test_admin_category_selector_returns_term_ids() {
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

		$this->assertStringContainsString( 'class="wc-category-search"', $html );
		$this->assertStringContainsString( 'data-return_id="true"', $html );
	}

	/**
	 * Category-only rules match products assigned to a selected category.
	 */
	public function test_category_only_rule_matches_selected_category() {
		$category = $this->create_category( 'Sofas' );
		$trigger  = $this->create_product( 100, 'Sofa' );
		$offer    = $this->create_product( 40, 'Table' );
		$this->assign_category( $trigger, $category );

		$rule = $this->create_rule(
			$offer,
			array(
				'condition_type'            => BumpMint_Conditions::PRODUCT,
				'condition_category_enabled' => '1',
				'condition_category_ids'    => array( $category ),
			)
		);

		$this->assertSame( array(), $rule['condition_product_ids'] );
		$this->assertTrue( $rule['condition_category_enabled'] );
		$this->assertSame( array( $category ), $rule['condition_category_ids'] );
		$this->assertFalse( BumpMint_Conditions::matches( $rule, WC()->cart ) );

		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		$this->assertTrue( BumpMint_Conditions::matches( $rule, WC()->cart ) );
	}

	/**
	 * Selecting a parent category includes products assigned to its children.
	 */
	public function test_parent_category_matches_child_category_product() {
		$parent   = $this->create_category( 'Furniture' );
		$child    = $this->create_category( 'Sofas', $parent );
		$trigger  = $this->create_product( 100, 'Sofa' );
		$offer    = $this->create_product( 40, 'Table' );
		$this->assign_category( $trigger, $child );

		$rule = $this->create_rule(
			$offer,
			array(
				'condition_type'             => BumpMint_Conditions::PRODUCT,
				'condition_category_enabled' => '1',
				'condition_category_ids'     => array( $parent ),
			)
		);

		$this->assertFalse( BumpMint_Conditions::matches( $rule, WC()->cart ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $trigger->get_id(), 1 ) );
		$this->assertTrue( BumpMint_Conditions::matches( $rule, WC()->cart ) );
	}

	/**
	 * Product and category triggers are combined with OR logic.
	 */
	public function test_product_and_category_triggers_use_or_logic() {
		$category      = $this->create_category( 'Sofas' );
		$product_one   = $this->create_product( 100, 'Chair' );
		$product_two   = $this->create_product( 100, 'Desk' );
		$category_item = $this->create_product( 100, 'Sofa' );
		$offer         = $this->create_product( 40, 'Table' );
		$this->assign_category( $category_item, $category );

		$rule = $this->create_rule(
			$offer,
			array(
				'condition_type'             => BumpMint_Conditions::PRODUCT,
				'condition_product_ids'      => array( $product_one->get_id(), $product_two->get_id() ),
				'condition_category_enabled' => '1',
				'condition_category_ids'     => array( $category ),
				'condition_match'            => 'all',
			)
		);

		$this->assertNotFalse( WC()->cart->add_to_cart( $product_one->get_id(), 1 ) );
		$this->assertFalse( BumpMint_Conditions::matches( $rule, WC()->cart ) );

		$this->assertNotFalse( WC()->cart->add_to_cart( $category_item->get_id(), 1 ) );
		$this->assertTrue( BumpMint_Conditions::matches( $rule, WC()->cart ) );

		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $product_one->get_id(), 1 ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $product_two->get_id(), 1 ) );
		$this->assertTrue( BumpMint_Conditions::matches( $rule, WC()->cart ) );
	}

	/**
	 * A rule's own bump line cannot keep a category-only rule eligible.
	 */
	public function test_own_bump_line_does_not_sustain_category_trigger() {
		$category = $this->create_category( 'Tables' );
		$offer    = $this->create_product( 40, 'Table' );
		$this->assign_category( $offer, $category );

		$rule = $this->create_rule(
			$offer,
			array(
				'condition_type'             => BumpMint_Conditions::PRODUCT,
				'condition_category_enabled' => '1',
				'condition_category_ids'     => array( $category ),
			)
		);

		$this->add_bump_to_cart( $offer, $rule );
		$this->assertFalse( BumpMint_Conditions::matches( $rule, WC()->cart ) );
	}

	/**
	 * Category trigger validation rejects empty and invalid category selections.
	 */
	public function test_category_trigger_validation_is_server_side() {
		$offer = $this->create_product( 40, 'Table' );

		$missing_category = BumpMint_Rules::save_rule(
			'',
			array(
				'name'                       => 'Missing category',
				'condition_type'             => BumpMint_Conditions::PRODUCT,
				'condition_category_enabled' => '1',
				'bump_product_ids'            => array( $offer->get_id() ),
			)
		);
		$this->assertWPError( $missing_category );
		$this->assertSame( 'bumpmint_trigger_category_required', $missing_category->get_error_code() );

		$invalid_category = BumpMint_Rules::save_rule(
			'',
			array(
				'name'                       => 'Invalid category',
				'condition_type'             => BumpMint_Conditions::PRODUCT,
				'condition_category_enabled' => '1',
				'condition_category_ids'     => array( 999999999 ),
				'bump_product_ids'            => array( $offer->get_id() ),
			)
		);
		$this->assertWPError( $invalid_category );
		$this->assertSame( 'bumpmint_invalid_trigger_category', $invalid_category->get_error_code() );
	}

	/**
	 * Category IDs are part of duplicate detection regardless of selection order.
	 */
	public function test_category_ids_are_part_of_duplicate_fingerprint() {
		$category_one = $this->create_category( 'Sofas' );
		$category_two = $this->create_category( 'Chairs' );
		$offer        = $this->create_product( 40, 'Table' );
		$base         = array(
			'condition_type'             => BumpMint_Conditions::PRODUCT,
			'condition_category_enabled' => '1',
			'condition_category_ids'     => array( $category_one, $category_two ),
			'bump_product_ids'            => array( $offer->get_id() ),
		);

		$first = BumpMint_Rules::save_rule( '', wp_parse_args( array( 'name' => 'First category rule' ), $base ) );
		$this->assertNotWPError( $first );

		$duplicate = BumpMint_Rules::save_rule( '', wp_parse_args( array( 'name' => 'Second category rule', 'condition_category_ids' => array( $category_two, $category_one ) ), $base ) );
		$this->assertWPError( $duplicate );
		$this->assertSame( 'bumpmint_duplicate_rule', $duplicate->get_error_code() );
	}

	/**
	 * Creates a product category term.
	 *
	 * @param string $name   Category name.
	 * @param int    $parent Parent term ID.
	 * @return int
	 */
	private function create_category( $name, $parent = 0 ) {
		$term = wp_insert_term(
			$name,
			'product_cat',
			array( 'parent' => absint( $parent ) )
		);
		$this->assertNotWPError( $term );

		return (int) $term['term_id'];
	}

	/**
	 * Assigns a product to a category.
	 *
	 * @param WC_Product $product  Product.
	 * @param int        $category Category term ID.
	 */
	private function assign_category( $product, $category ) {
		$result = wp_set_object_terms( $product->get_id(), array( absint( $category ) ), 'product_cat' );
		$this->assertNotWPError( $result );
	}
}
