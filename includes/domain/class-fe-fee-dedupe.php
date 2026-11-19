<?php
/**
 * Combined-delivery credit when two packages deliver on the same date.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Computes how much delivery cost to credit back for same-date deliveries.
 */
class Fe_Fee_Dedupe {

	/**
	 * For each delivery date chosen by more than one package, credits every
	 * delivery cost except the most expensive one.
	 *
	 * @param array $chosen List of {group, type, date, cost_cents}.
	 * @return int Credit in cents, never negative.
	 */
	public static function credit( array $chosen ) {
		$by_date = array();
		foreach ( $chosen as $line ) {
			if ( ! isset( $line['type'] ) || 'delivery' !== $line['type'] || empty( $line['date'] ) ) {
				continue;
			}
			$by_date[ (string) $line['date'] ][] = max( 0, (int) ( isset( $line['cost_cents'] ) ? $line['cost_cents'] : 0 ) );
		}
		$credit = 0;
		foreach ( $by_date as $costs ) {
			if ( count( $costs ) > 1 ) {
				$credit += array_sum( $costs ) - max( $costs );
			}
		}
		return $credit;
	}
}
