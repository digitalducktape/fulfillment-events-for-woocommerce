<?php
/**
 * Integer-cents proration for order splits.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Splits an amount across weights so the parts always sum to the amount.
 */
class Fe_Split_Math {

	/**
	 * Converts a money amount to integer cents.
	 *
	 * @param float|int|string $amount Amount in currency units.
	 * @return int
	 */
	public static function to_cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Prorates an amount by weight. Parts are floored proportionally and the
	 * remainder goes to the first key, so the parts sum exactly to the amount.
	 * Negative weights count as zero; when every weight is zero the whole
	 * amount goes to the first key. A negative amount is split by magnitude.
	 *
	 * @param int               $amount_cents  Amount to split.
	 * @param array<string,int> $weights_cents Weights keyed by part.
	 * @return array<string,int> Same keys, in the same order.
	 */
	public static function prorate( $amount_cents, array $weights_cents ) {
		if ( empty( $weights_cents ) ) {
			return array();
		}
		$amount = (int) $amount_cents;
		$sign   = $amount < 0 ? -1 : 1;
		$abs    = abs( $amount );

		$weights = array();
		$total   = 0;
		foreach ( $weights_cents as $key => $weight ) {
			$weights[ $key ] = max( 0, (int) $weight );
			$total          += $weights[ $key ];
		}

		$parts = array();
		$sum   = 0;
		foreach ( $weights as $key => $weight ) {
			$part          = $total > 0 ? intdiv( $abs * $weight, $total ) : 0;
			$parts[ $key ] = $part;
			$sum          += $part;
		}

		reset( $parts );
		$first           = key( $parts );
		$parts[ $first ] += $abs - $sum;

		foreach ( $parts as $key => $part ) {
			$parts[ $key ] = $sign * $part;
		}
		return $parts;
	}
}
