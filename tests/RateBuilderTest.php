<?php
use PHPUnit\Framework\TestCase;

final class RateBuilderTest extends TestCase {

	private function event( array $dates ) {
		return array(
			'id'          => 7,
			'title'       => 'Holiday event',
			'status'      => 'publish',
			'sales_open'  => null,
			'sales_close' => null,
			'dates'       => $dates,
		);
	}

	private function date( $date, array $over = array() ) {
		return array_merge(
			array(
				'date'            => $date,
				'delivery'        => false,
				'delivery_window' => '',
				'pickup'          => false,
				'pickup_window'   => '',
				'location_id'     => null,
			),
			$over
		);
	}

	private function locations() {
		return array(
			1 => array(
				'id'           => 1,
				'name'         => 'Main store',
				'address'      => '1 Main St',
				'instructions' => '',
				'is_default'   => true,
			),
			2 => array(
				'id'           => 2,
				'name'         => 'Winter market',
				'address'      => '',
				'instructions' => '',
				'is_default'   => false,
			),
		);
	}

	private function rule( $zone, $type, $date, $mode, $cost ) {
		return array(
			'zone_id' => $zone,
			'type'    => $type,
			'date'    => $date,
			'mode'    => $mode,
			'cost'    => $cost,
		);
	}

	public function test_delivery_rates_one_per_delivery_date_with_zone_cost() {
		$event = $this->event(
			array(
				$this->date( '2026-12-10', array( 'delivery' => true, 'delivery_window' => '9-12' ) ),
				$this->date( '2026-12-13', array( 'pickup' => true ) ),
				$this->date( '2026-12-18', array( 'delivery' => true ) ),
			)
		);
		$rates = Fe_Rate_Builder::build( $event, 'delivery', 1, array(), 9.0, 'Anytime', $this->locations(), 1, '2026-12-01' );
		$this->assertCount( 2, $rates );
		$this->assertSame( 9.0, $rates[0]['cost'] );
		// The time goes in the option name so customers see what they pick.
		$this->assertSame( 'Delivery · Thu, Dec 10 · 9-12', $rates[0]['label'] );
		$this->assertSame( '9-12', $rates[0]['window'] );
		$this->assertSame( 'Delivery · Fri, Dec 18 · Anytime', $rates[1]['label'] );
		$this->assertSame( '9-12', $rates[0]['meta']['fe_window'] );
		$this->assertSame( 'Anytime', $rates[1]['meta']['fe_window'] );
		$this->assertSame( 7, $rates[0]['meta']['fe_event_id'] );
		$this->assertSame( 'Holiday event', $rates[0]['meta']['fe_event_name'] );
		$this->assertSame( '2026-12-10', $rates[0]['meta']['fe_date'] );
		$this->assertSame( 'delivery', $rates[0]['meta']['fe_type'] );
		$this->assertSame( '', $rates[0]['meta']['fe_location'] );
		$this->assertSame( '2026-12-10', $rates[0]['date'] );
	}

	public function test_zone_override_only_on_one_date() {
		$event = $this->event(
			array(
				$this->date( '2026-12-10', array( 'delivery' => true ) ),
				$this->date( '2026-12-18', array( 'delivery' => true ) ),
			)
		);
		$rules = array(
			$this->rule( 2, 'delivery', null, 'on', 20.0 ),
			$this->rule( 2, 'delivery', '2026-12-18', 'on', 25.0 ),
		);
		$rates = Fe_Rate_Builder::build( $event, 'delivery', 2, $rules, 9.0, '', $this->locations(), 1, '2026-12-01' );
		$this->assertSame( 20.0, $rates[0]['cost'] );
		$this->assertSame( 25.0, $rates[1]['cost'] );
	}

	public function test_pickup_uses_date_location_else_default() {
		$event = $this->event(
			array(
				$this->date( '2026-12-10', array( 'pickup' => true ) ),
				$this->date( '2026-12-13', array( 'pickup' => true, 'location_id' => 2 ) ),
			)
		);
		$rates = Fe_Rate_Builder::build( $event, 'pickup', 1, array(), 0.0, '', $this->locations(), 1, '2026-12-01' );
		$this->assertSame( 'Pickup at Main store · Thu, Dec 10', $rates[0]['label'] );
		$this->assertSame( 'Main store, 1 Main St', $rates[0]['meta']['fe_location'] );
		$this->assertSame( 'Pickup at Winter market · Sun, Dec 13', $rates[1]['label'] );
		$this->assertSame( 'Winter market', $rates[1]['meta']['fe_location'] );
	}

	public function test_courier_offered_on_pickup_only_dates_too() {
		$event = $this->event(
			array(
				$this->date( '2026-12-10', array( 'delivery' => true ) ),
				$this->date( '2026-12-13', array( 'pickup' => true ) ),
				$this->date( '2026-12-14' ),
			)
		);
		$rates = Fe_Rate_Builder::build( $event, 'courier', 1, array(), 5.0, '', $this->locations(), 1, '2026-12-01' );
		$this->assertCount( 2, $rates );
		// Courier has no set time; its instructions explain how it works.
		$this->assertSame( 'Courier pickup · Sun, Dec 13', $rates[1]['label'] );
		$this->assertSame( '', $rates[1]['window'] );
		$this->assertSame( "We'll coordinate the time with you", $rates[1]['meta']['fe_window'] );
		$this->assertSame( 'courier', $rates[1]['meta']['fe_type'] );
	}

	public function test_past_dates_are_skipped() {
		$event = $this->event(
			array(
				$this->date( '2026-12-10', array( 'delivery' => true ) ),
				$this->date( '2026-12-18', array( 'delivery' => true ) ),
			)
		);
		$rates = Fe_Rate_Builder::build( $event, 'delivery', 1, array(), 0.0, '', $this->locations(), 1, '2026-12-11' );
		$this->assertCount( 1, $rates );
		$this->assertSame( '2026-12-18', $rates[0]['date'] );
		$today = Fe_Rate_Builder::build( $event, 'delivery', 1, array(), 0.0, '', $this->locations(), 1, '2026-12-18' );
		$this->assertCount( 1, $today );
	}

	public function test_zone_off_rule_removes_rates() {
		$event = $this->event( array( $this->date( '2026-12-10', array( 'delivery' => true ) ) ) );
		$rules = array( $this->rule( 3, 'delivery', null, 'off', null ) );
		$this->assertSame( array(), Fe_Rate_Builder::build( $event, 'delivery', 3, $rules, 9.0, '', $this->locations(), 1, '2026-12-01' ) );
	}

	public function test_custom_formatter_and_courier_window_are_used() {
		$event = $this->event( array( $this->date( '2026-12-10', array( 'delivery' => true ) ) ) );
		$fmt   = function ( $ymd ) {
			return 'X ' . $ymd;
		};
		$rates = Fe_Rate_Builder::build( $event, 'courier', 1, array(), 0.0, '', $this->locations(), 1, '2026-12-01', $fmt, 'Texto' );
		$this->assertSame( 'Courier pickup · X 2026-12-10', $rates[0]['label'] );
		$this->assertSame( 'Texto', $rates[0]['meta']['fe_window'] );
	}

	public function test_format_date_is_pure_english() {
		$this->assertSame( 'Thu, Dec 10', Fe_Rate_Builder::format_date( '2026-12-10' ) );
	}

	public function test_pickup_with_unknown_location_falls_back_to_default() {
		$event = $this->event(
			array(
				$this->date( '2026-12-12', array( 'pickup' => true, 'location_id' => 99 ) ),
			)
		);
		$rates = Fe_Rate_Builder::build( $event, 'pickup', 1, array(), 0.0, '', $this->locations(), 1, '2026-12-01' );
		$this->assertSame( 'Main store, 1 Main St', $rates[0]['meta']['fe_location'] );
		$this->assertSame( 'Main store', $rates[0]['where'] );
	}

	public function test_courier_follows_its_own_flag() {
		$event = $this->event(
			array(
				$this->date( '2026-12-10', array( 'delivery' => true, 'courier' => false ) ),
				$this->date( '2026-12-18', array( 'courier' => true ) ),
				$this->date( '2026-12-20', array( 'delivery' => true ) ),
			)
		);
		// The 12-20 row has a courier key from date(); drop it to test the legacy fallback.
		unset( $event['dates'][2]['courier'] );
		$rates = Fe_Rate_Builder::build( $event, 'courier', 1, array(), 5.0, '', $this->locations(), 1, '2026-12-01' );
		$this->assertSame( array( '2026-12-18', '2026-12-20' ), array_column( $rates, 'date' ) );
	}
}
