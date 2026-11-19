<?php
/**
 * Minimal WordPress/WooCommerce stubs for the unit suite. Extended by later
 * tasks as more classes need them.
 */

if ( ! defined( 'FE_TESTING' ) ) {
	exit;
}

if ( ! function_exists( 'get_option' ) ) {
	/** In-memory options store for tests. */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['fe_test_options'] ) ? $GLOBALS['fe_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['fe_test_options'][ $name ] = $value;
		return true;
	}
}
$GLOBALS['fe_test_options'] = array();
