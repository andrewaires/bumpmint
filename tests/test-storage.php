<?php
/**
 * Storage migration integration tests.
 *
 * @package BumpMint
 */

/**
 * Verifies the one-time WordPress option migration.
 */
class Test_BumpMint_Storage extends BumpMint_Test_Case {

	/**
	 * Existing rules are removed from the autoloaded options collection.
	 */
	public function test_storage_migration_disables_rules_autoload() {
		delete_option( BUMPMINT_OPTION_KEY );
		delete_option( BUMPMINT_STORAGE_VERSION_KEY );
		add_option( BUMPMINT_OPTION_KEY, array( array( 'id' => 'legacy-rule' ) ), '', true );
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayHasKey( BUMPMINT_OPTION_KEY, wp_load_alloptions( true ) );

		bumpmint_maybe_upgrade_storage();
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayNotHasKey( BUMPMINT_OPTION_KEY, wp_load_alloptions( true ) );
		$this->assertSame( BUMPMINT_STORAGE_VERSION, (int) get_option( BUMPMINT_STORAGE_VERSION_KEY ) );
	}
}
