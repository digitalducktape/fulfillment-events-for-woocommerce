<?php
use PHPUnit\Framework\TestCase;

class AvailabilityNoteTest extends TestCase {

	private function event( array $dates ) {
		$rows = array();
		foreach ( $dates as $d ) {
			$rows[] = array(
				'date'     => $d[0],
				'delivery' => $d[1],
				'pickup'   => $d[2],
			);
		}
		return array( 'id' => 1, 'title' => 'Winter market', 'dates' => $rows );
	}

	public function test_note_lists_types_and_dates() {
		$event = $this->event( array( array( '2026-12-10', true, true ), array( '2026-12-18', true, false ) ) );
		$this->assertSame( 'Available for delivery or pickup on Dec 10 or Dec 18', Fe_Cart::availability_note( $event, '2026-11-20' ) );
	}

	public function test_three_dates_use_commas_and_or() {
		$event = $this->event( array( array( '2026-12-03', true, false ), array( '2026-12-10', true, false ), array( '2026-12-18', true, false ) ) );
		$this->assertSame( 'Available for delivery on Dec 3, Dec 10 or Dec 18', Fe_Cart::availability_note( $event, '2026-11-20' ) );
	}

	public function test_pickup_only_and_past_dates_skipped() {
		$event = $this->event( array( array( '2026-11-01', true, false ), array( '2026-12-10', false, true ) ) );
		$this->assertSame( 'Available for pickup on Dec 10', Fe_Cart::availability_note( $event, '2026-11-20' ) );
	}

	public function test_today_counts_and_no_future_dates_is_empty() {
		$event = $this->event( array( array( '2026-11-20', true, false ) ) );
		$this->assertSame( 'Available for delivery on Nov 20', Fe_Cart::availability_note( $event, '2026-11-20' ) );
		$this->assertSame( '', Fe_Cart::availability_note( $event, '2026-11-21' ) );
		$this->assertSame( '', Fe_Cart::availability_note( array( 'dates' => array() ), '2026-11-21' ) );
	}

	public function test_price_block_decision() {
		$this->assertTrue( Fe_Cart::should_append_to_price_block( array( 'postId' => 7 ), array(), 7 ) );
		$this->assertFalse( Fe_Cart::should_append_to_price_block( array( 'postId' => 9 ), array(), 7 ) );
		$this->assertFalse( Fe_Cart::should_append_to_price_block( array( 'postId' => 7, 'queryId' => 3 ), array(), 7 ) );
		$this->assertTrue( Fe_Cart::should_append_to_price_block( array(), array( 'productId' => 7 ), 7 ) );
		$this->assertFalse( Fe_Cart::should_append_to_price_block( array(), array(), 7 ) );
	}

	public function test_non_shipping_product_is_treated_as_in_no_event() {
		$active = array( 'status' => 'active', 'event_id' => 4 );
		$closed = array( 'status' => 'closed', 'event_id' => null );
		$none   = array( 'status' => 'none', 'event_id' => null, 'event_ids' => array() );
		$this->assertSame( $none, Fe_Cart::status_for_shipping( false, $active ) );
		$this->assertSame( $none, Fe_Cart::status_for_shipping( false, $closed ) );
	}

	public function test_shipping_product_keeps_its_event_status() {
		$active = array( 'status' => 'active', 'event_id' => 4 );
		$closed = array( 'status' => 'closed', 'event_id' => null );
		$this->assertSame( $active, Fe_Cart::status_for_shipping( true, $active ) );
		$this->assertSame( $closed, Fe_Cart::status_for_shipping( true, $closed ) );
	}

	public function test_note_lists_dates_from_every_event() {
		$merged = Fe_Cart::merged_dates(
			array(
				array( 'dates' => array( array( 'date' => '2026-12-18', 'delivery' => true, 'pickup' => false ) ) ),
				array(
					'dates' => array(
						array( 'date' => '2026-12-10', 'delivery' => true, 'pickup' => false ),
						array( 'date' => '2026-12-18', 'delivery' => false, 'pickup' => true ),
					),
				),
			)
		);
		$this->assertSame( 'Available for delivery or pickup on Dec 10 or Dec 18', Fe_Cart::availability_note( $merged, '2026-12-01' ) );
		$this->assertSame( 'Available for delivery or pickup on Dec 18', Fe_Cart::availability_note( $merged, '2026-12-11' ) );
	}

	public function test_courier_only_note() {
		$this->assertSame( 'Available for courier on Dec 18', Fe_Cart::availability_note( array( 'dates' => array( array( 'date' => '2026-12-18', 'courier' => true ) ) ), '2026-12-01' ) );
		$this->assertSame( 'Available for delivery on Dec 18', Fe_Cart::availability_note( array( 'dates' => array( array( 'date' => '2026-12-18', 'delivery' => true, 'courier' => true ) ) ), '2026-12-01' ) );
		$merged = Fe_Cart::merged_dates( array( array( 'dates' => array( array( 'date' => '2026-12-18', 'courier' => true ) ) ) ) );
		$this->assertTrue( $merged['dates'][0]['courier'] );
	}
}
