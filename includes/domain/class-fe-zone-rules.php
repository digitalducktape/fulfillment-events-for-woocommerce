<?php
/**
 * Pure zone-rule resolution.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Resolves whether a fulfillment type is offered to a zone on a date, and
 * at what cost, from an event's zone rules.
 */
class Fe_Zone_Rules {

	/**
	 * Precedence: a rule for zone + type + date, then a rule for zone + type
	 * with no date (event-wide), then the shipping method default. A rule in
	 * "inherit" mode defers to the next level.
	 *
	 * @param array      $rules        Rows of {zone_id, type, date, mode, cost}.
	 * @param int        $zone_id      Shipping zone id.
	 * @param string     $type         delivery|pickup|courier.
	 * @param string     $date         Y-m-d.
	 * @param float|null $default_cost Cost from the shipping method settings.
	 * @return array{enabled:bool, cost:float}
	 */
	public static function resolve( array $rules, $zone_id, $type, $date, $default_cost ) {
		$specific   = null;
		$event_wide = null;
		foreach ( $rules as $rule ) {
			if ( (int) $rule['zone_id'] !== (int) $zone_id || (string) $rule['type'] !== (string) $type ) {
				continue;
			}
			if ( isset( $rule['date'] ) && '' !== $rule['date'] ) {
				if ( (string) $rule['date'] === (string) $date ) {
					$specific = $rule;
				}
			} else {
				$event_wide = $rule;
			}
		}

		foreach ( array( $specific, $event_wide ) as $rule ) {
			if ( null === $rule || 'inherit' === $rule['mode'] ) {
				continue;
			}
			if ( 'off' === $rule['mode'] ) {
				return array(
					'enabled' => false,
					'cost'    => 0.0,
				);
			}
			if ( 'on' === $rule['mode'] ) {
				$cost = isset( $rule['cost'] ) ? (float) $rule['cost'] : (float) $default_cost;
				return array(
					'enabled' => true,
					'cost'    => $cost,
				);
			}
		}

		return array(
			'enabled' => true,
			'cost'    => (float) $default_cost,
		);
	}
}
