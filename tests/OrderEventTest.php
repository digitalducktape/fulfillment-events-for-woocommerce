<?php
use PHPUnit\Framework\TestCase;

/**
 * Staff put an order in an event from its shipping line. They may pick any
 * configured event date, whatever customers are offered.
 */
final class OrderEventTest extends TestCase {

	private function events() {
		return array(
			array(
				'id'           => 7,
				'title'        => 'Holiday Pre-order',
				'status'       => 'draft',
				'sales_open'   => '2030-01-01 00:00:00',
				'sales_close'  => null,
				'closed_dates' => array( '2026-12-10' ),
				'date_limits'  => array(),
				'dates'        => array(
					array( 'date' => '2026-11-01', 'delivery' => true, 'delivery_window' => '', 'pickup' => false, 'pickup_window' => '', 'courier' => false, 'location_id' => null ),
					array( 'date' => '2026-12-10', 'delivery' => true, 'delivery_window' => '9am–1pm', 'pickup' => true, 'pickup_window' => '', 'courier' => true, 'location_id' => 2 ),
				),
			),
		);
	}

	private function locations() {
		return array(
			1 => array( 'id' => 1, 'name' => 'Shop', 'address' => '1 Main St' ),
			2 => array( 'id' => 2, 'name' => 'Holiday Fair', 'address' => '' ),
		);
	}

	private function catalog() {
		$zones = array(
			array( 'zone_id' => 3, 'name' => 'SF', 'type' => 'delivery', 'cost' => 9.0, 'window' => '10am–2pm' ),
			array( 'zone_id' => 4, 'name' => 'Marin', 'type' => 'delivery', 'cost' => 20.0, 'window' => '' ),
			array( 'zone_id' => 3, 'name' => 'SF', 'type' => 'pickup', 'cost' => 0.0, 'window' => '11am–3pm' ),
		);
		// Marin is off for delivery on Dec 10; SF charges $12 that day.
		$rules = array(
			7 => array(
				array( 'zone_id' => 4, 'type' => 'delivery', 'date' => '2026-12-10', 'mode' => 'off', 'cost' => null ),
				array( 'zone_id' => 3, 'type' => 'delivery', 'date' => '2026-12-10', 'mode' => 'on', 'cost' => 12.0 ),
			),
		);
		return Fe_Order_Event::catalog( $this->events(), $zones, $rules, $this->locations(), 1, '2026-11-24' );
	}

	private function current( array $over = array() ) {
		return array_merge(
			array(
				'event_id'   => 7,
				'event_name' => 'Holiday Pre-order',
				'date'       => '2026-12-10',
				'type'       => 'delivery',
				'window'     => '9am–1pm',
				'location'   => '',
				'addon_of'   => null,
				'parent_id'  => null,
			),
			$over
		);
	}

	private function posted( array $over = array() ) {
		return array_merge( array( 'event' => '7', 'date' => '2026-12-10', 'type' => 'delivery', 'window' => '9am–1pm', 'location' => '' ), $over );
	}

	private function resolve( array $posted, $current = null ) {
		return Fe_Order_Event::resolve( $posted, $current, $this->catalog(), Fe_Order_Event::location_names( $this->locations() ) );
	}

	public function test_staff_see_every_configured_date_even_draft_closed_or_past() {
		$c = $this->catalog();
		$this->assertSame( 'Holiday Pre-order', $c[7]['title'] );
		$this->assertTrue( $c[7]['draft'] );
		$this->assertSame( array( '2026-11-01', '2026-12-10' ), array_keys( $c[7]['dates'] ) );
		$this->assertTrue( $c[7]['dates']['2026-11-01']['past'] );
		$this->assertTrue( $c[7]['dates']['2026-12-10']['closed'] );
		$this->assertSame( array( 'delivery', 'pickup', 'courier' ), array_keys( $c[7]['dates']['2026-12-10']['types'] ) );
		$this->assertSame( array( 'delivery' ), array_keys( $c[7]['dates']['2026-11-01']['types'] ) );
	}

	public function test_time_and_place_come_from_the_event_then_the_method() {
		$types = $this->catalog()[7]['dates']['2026-12-10']['types'];
		$this->assertSame( '9am–1pm', $types['delivery']['window'] );
		$this->assertSame( '11am–3pm', $types['pickup']['window'] );
		$this->assertSame( 'Holiday Fair', $types['pickup']['location'] );
		$this->assertSame( '10am–2pm', $this->catalog()[7]['dates']['2026-11-01']['types']['delivery']['window'] );
	}

	public function test_usual_fees_are_listed_per_zone_that_offers_it() {
		$dates = $this->catalog()[7]['dates'];
		$this->assertSame( array( array( 'SF', 12.0 ) ), $dates['2026-12-10']['types']['delivery']['fees'] );
		$this->assertSame( array( array( 'SF', 9.0 ), array( 'Marin', 20.0 ) ), $dates['2026-11-01']['types']['delivery']['fees'] );
	}

	public function test_setting_an_event_builds_the_fulfillment() {
		$r = $this->resolve( $this->posted( array( 'type' => 'pickup', 'window' => ' noon ', 'location' => 'Shop, 1 Main St' ) ) );
		$this->assertSame( 'set', $r['action'] );
		$this->assertSame( 7, $r['fulfillment']['event_id'] );
		$this->assertSame( 'Holiday Pre-order', $r['fulfillment']['event_name'] );
		$this->assertSame( 'pickup', $r['fulfillment']['type'] );
		$this->assertSame( 'noon', $r['fulfillment']['window'] );
		$this->assertSame( 'Shop, 1 Main St', $r['fulfillment']['location'] );
	}

	public function test_unknown_location_falls_back_and_only_pickup_has_one() {
		$this->assertSame( 'Holiday Fair', $this->resolve( $this->posted( array( 'type' => 'pickup', 'location' => 'Nowhere' ) ) )['fulfillment']['location'] );
		$this->assertSame( '', $this->resolve( $this->posted( array( 'location' => 'Shop, 1 Main St' ) ) )['fulfillment']['location'] );
	}

	public function test_saving_an_order_without_touching_the_event_changes_nothing() {
		$this->assertSame( 'same', $this->resolve( $this->posted(), $this->current() )['action'] );
		$this->assertSame( 'same', $this->resolve( $this->posted( array( 'event' => '' ) ) )['action'] );
		// The event is gone: the order keeps what it had.
		$gone = $this->current( array( 'event_id' => 99, 'date' => '2025-12-01' ) );
		$this->assertSame( 'same', $this->resolve( $this->posted( array( 'event' => '99', 'date' => '2025-12-01' ) ), $gone )['action'] );
		// Nothing posted for the line (another screen, the REST API).
		$this->assertSame( 'same', $this->resolve( array(), $this->current() )['action'] );
	}

	public function test_a_combination_the_event_does_not_have_is_refused() {
		$this->assertSame( 'invalid', $this->resolve( $this->posted( array( 'date' => '2026-12-11' ) ) )['action'] );
		$this->assertSame( 'invalid', $this->resolve( $this->posted( array( 'date' => '2026-11-01', 'type' => 'pickup' ) ) )['action'] );
		$this->assertSame( 'invalid', $this->resolve( $this->posted( array( 'event' => '8' ) ) )['action'] );
		$this->assertSame( 'invalid', $this->resolve( $this->posted( array( 'date' => '' ) ), $this->current() )['action'] );
	}

	public function test_choosing_no_event_takes_the_order_out() {
		$this->assertSame( 'clear', $this->resolve( $this->posted( array( 'event' => '' ) ), $this->current() )['action'] );
	}

	public function test_add_on_link_survives_a_new_time_but_not_a_new_date() {
		$addon = $this->current( array( 'addon_of' => 55 ) );
		$this->assertSame( 55, $this->resolve( $this->posted( array( 'window' => '2pm' ) ), $addon )['fulfillment']['addon_of'] );
		$this->assertNull( $this->resolve( $this->posted( array( 'date' => '2026-11-01' ) ), $addon )['fulfillment']['addon_of'] );
	}

	public function test_order_meta_follows_its_first_event_line_and_keeps_the_split_parent() {
		$plain   = array();
		$line    = Fe_Order_Event::line_meta( $this->current() );
		$desired = Fe_Order_Event::order_meta( array( $plain, $line ), 40 );
		$this->assertSame( '7', $desired[ Fe_Meta::EVENT_ID ] );
		$this->assertSame( '2026-12-10', $desired[ Fe_Meta::DATE ] );
		$this->assertSame( '40', $desired[ Fe_Meta::PARENT_ID ] );
		$f = Fe_Meta::from_meta( $desired );
		$this->assertSame( 'delivery', $f['type'] );

		$this->assertSame( array( Fe_Meta::PARENT_ID => '40' ), Fe_Order_Event::order_meta( array( $plain ), 40 ) );
		$this->assertSame( array(), Fe_Order_Event::order_meta( array(), 0 ) );
	}

	public function test_line_title_is_filled_in_unless_staff_typed_one() {
		$this->assertSame( 'New label', Fe_Order_Event::title( '', '', 'New label' ) );
		$this->assertSame( 'New label', Fe_Order_Event::title( 'Old label', 'Old label', 'New label' ) );
		$this->assertSame( 'New label', Fe_Order_Event::title( '', 'Shipping', 'New label' ) );
		$this->assertSame( 'Mom drops off', Fe_Order_Event::title( 'Old label', 'Mom drops off', 'New label' ) );
	}

	public function test_labels_match_checkout() {
		$this->assertSame( 'Delivery · Thu, Dec 10 · 9am–1pm', Fe_Order_Event::label( 'delivery', 'Thu, Dec 10', '', '9am–1pm' ) );
		$this->assertSame( 'Pickup at Shop · Thu, Dec 10', Fe_Order_Event::label( 'pickup', 'Thu, Dec 10', 'Shop', '' ) );
		$this->assertSame( 'Courier pickup · Thu, Dec 10', Fe_Order_Event::label( 'courier', 'Thu, Dec 10', '', '' ) );
	}

	public function test_staff_list_starts_with_the_next_event() {
		$event  = function ( $title, ...$dates ) {
			return array( 'title' => $title, 'dates' => array_map( function ( $d ) {
				return array( 'date' => $d );
			}, $dates ) );
		};
		$sorted = Fe_Event_Repo::sort_for_staff(
			array( $event( 'Old', '2025-12-01' ), $event( 'Later', '2026-12-18' ), $event( 'Recent', '2026-10-01' ), $event( 'Next', '2026-11-01', '2026-12-03' ) ),
			'2026-11-24'
		);
		$this->assertSame( array( 'Next', 'Later', 'Recent', 'Old' ), array_column( $sorted, 'title' ) );
	}

	public function test_address_spacing_does_not_count_as_a_change() {
		$current = $this->current( array( 'type' => 'pickup', 'window' => '11am', 'location' => "Barn,  2 Oak St\nSuite 4" ) );
		$posted  = $this->posted( array( 'type' => 'pickup', 'window' => '11am', 'location' => 'Barn, 2 Oak St Suite 4' ) );
		$this->assertSame( 'same', $this->resolve( $posted, $current )['action'] );
		$names = array( "Barn,  2 Oak St\nSuite 4" => 'Barn' );
		$r     = Fe_Order_Event::resolve( $posted, null, $this->catalog(), $names );
		$this->assertSame( "Barn,  2 Oak St\nSuite 4", $r['fulfillment']['location'] );
	}
}
