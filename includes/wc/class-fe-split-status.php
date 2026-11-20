<?php
/**
 * The "Split" order status held by a parent order after it is split.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Registers wc-fe-split and keeps it out of payment flows and Analytics.
 */
class Fe_Split_Status {

	const STATUS = 'fe-split';

	/**
	 * Analytics excludes these by default (WooCommerce's own default list).
	 *
	 * @var string[]
	 */
	const DEFAULT_EXCLUDED = array( 'pending', 'cancelled', 'failed' );

	/**
	 * Registers the status hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( __CLASS__, 'without_split' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'without_split' ) );
		add_filter( 'woocommerce_analytics_excluded_order_statuses', array( __CLASS__, 'with_split' ) );
	}

	/**
	 * Registers the post status (used by the posts order store; HPOS reads
	 * wc_order_statuses).
	 */
	public static function register() {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => _x( 'Split', 'Order status', 'fulfillment-events-for-woocommerce' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders. */
				'label_count'               => _n_noop( 'Split <span class="count">(%s)</span>', 'Split <span class="count">(%s)</span>', 'fulfillment-events-for-woocommerce' ),
			)
		);
	}

	/**
	 * Adds the status right after Processing (or at the end).
	 *
	 * @param array $statuses Status key => label.
	 * @return array
	 */
	public static function add_to_statuses( $statuses ) {
		$statuses = (array) $statuses;
		$key      = 'wc-' . self::STATUS;
		$label    = _x( 'Split', 'Order status', 'fulfillment-events-for-woocommerce' );
		if ( isset( $statuses[ $key ] ) ) {
			return $statuses;
		}
		$out      = array();
		$inserted = false;
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-processing' === $k ) {
				$out[ $key ] = $label;
				$inserted    = true;
			}
		}
		if ( ! $inserted ) {
			$out[ $key ] = $label;
		}
		return $out;
	}

	/**
	 * Removes the split status from a list of statuses.
	 *
	 * @param array $statuses Statuses (without the wc- prefix).
	 * @return array
	 */
	public static function without_split( $statuses ) {
		return array_values(
			array_filter(
				(array) $statuses,
				function ( $status ) {
					return self::STATUS !== $status && 'wc-' . self::STATUS !== $status;
				}
			)
		);
	}

	/**
	 * Adds the split status to a list of statuses once.
	 *
	 * @param array $statuses Statuses (without the wc- prefix).
	 * @return array
	 */
	public static function with_split( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		if ( ! in_array( self::STATUS, $statuses, true ) ) {
			$statuses[] = self::STATUS;
		}
		return $statuses;
	}

	/**
	 * Activation fallback: also store the status in the saved Analytics
	 * exclusion setting. When the setting was never saved, WooCommerce's
	 * defaults are kept alongside it.
	 */
	public static function add_to_excluded_report_option() {
		$current = get_option( 'woocommerce_excluded_report_order_statuses', false );
		if ( ! is_array( $current ) ) {
			$current = self::DEFAULT_EXCLUDED;
		}
		if ( ! in_array( self::STATUS, $current, true ) ) {
			$current[] = self::STATUS;
			update_option( 'woocommerce_excluded_report_order_statuses', $current );
		}
	}
}
