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
}
