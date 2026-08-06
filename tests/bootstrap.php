<?php
/**
 * PHPUnit bootstrap for the wp-env integration suite.
 *
 * @package BumpMint
 */

$bumpmint_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $bumpmint_tests_dir ) {
	$bumpmint_tests_dir = '/wordpress-phpunit';
}

$bumpmint_polyfills_dir = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( ! file_exists( $bumpmint_polyfills_dir . '/phpunitpolyfills-autoload.php' ) ) {
	fwrite( STDERR, "PHPUnit Polyfills were not found. Run npm test so wp-env can install the test dependencies.\n" );
	exit( 1 );
}
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $bumpmint_polyfills_dir );

if ( ! file_exists( $bumpmint_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress PHPUnit files were not found. Run the suite through wp-env.\n" );
	exit( 1 );
}

require_once $bumpmint_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce before BumpMint during the WordPress test bootstrap.
 */
function bumpmint_tests_load_plugins() {
	require dirname( dirname( __DIR__ ) ) . '/woocommerce/woocommerce.php';
	require dirname( __DIR__ ) . '/bumpmint.php';
}
tests_add_filter( 'muplugins_loaded', 'bumpmint_tests_load_plugins' );

require $bumpmint_tests_dir . '/includes/bootstrap.php';

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'BumpMint_Rules' ) ) {
	fwrite( STDERR, "WooCommerce or BumpMint did not initialize during the test bootstrap.\n" );
	exit( 1 );
}

require_once __DIR__ . '/class-bumpmint-test-case.php';
