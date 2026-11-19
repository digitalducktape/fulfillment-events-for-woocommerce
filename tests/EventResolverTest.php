<?php
use PHPUnit\Framework\TestCase;

class EventResolverTest extends TestCase {

	private function event( $id, array $dates ) {
		$rows = array();
		foreach ( $dates as $d ) {
			$rows[] = array(
				'date'            => $d,
				'delivery'        => 1,
				'delivery_window' => '',
				'pickup'          => 0,
				'pickup_window'   => '',
				'location_id'     => 0,
			);
		}
		return array(
			'id'          => $id,
			'title'       => 'Event ' . $id,
			'status'      => 'publish',
			'sales_open'  => '',
			'sales_close' => '',
			'dates'       => $rows,
		);
	}

	public function test_earliest_next_date_wins_among_open_events() {
		$open = array(
			$this->event( 1, array( '2026-12-20' ) ),
			$this->event( 2, array( '2026-12-05', '2026-12-30' ) ),
		);
		$this->assertSame( 2, Fe_Event_Resolver::resolve( array( 1, 2 ), $open, '2026-12-01' ) );
	}

	public function test_event_with_only_past_dates_is_skipped() {
		$open = array(
			$this->event( 1, array( '2026-11-01' ) ),
			$this->event( 2, array( '2026-12-20' ) ),
		);
		$this->assertSame( 2, Fe_Event_Resolver::resolve( array( 1, 2 ), $open, '2026-12-01' ) );
		$this->assertNull( Fe_Event_Resolver::resolve( array( 1 ), $open, '2026-12-01' ) );
	}

	public function test_tie_breaks_on_lower_id() {
		$open = array(
			$this->event( 9, array( '2026-12-10' ) ),
			$this->event( 4, array( '2026-12-10' ) ),
		);
		$this->assertSame( 4, Fe_Event_Resolver::resolve( array( 9, 4 ), $open, '2026-12-01' ) );
	}

	public function test_today_counts_as_upcoming() {
		$open = array( $this->event( 1, array( '2026-12-01' ) ) );
		$this->assertSame( 1, Fe_Event_Resolver::resolve( array( 1 ), $open, '2026-12-01' ) );
	}

	public function test_product_without_events_is_status_none() {
		$this->assertSame( 'none', Fe_Event_Resolver::status( array(), null ) );
	}

	public function test_product_with_only_closed_events_is_not_purchasable() {
		$open = array( $this->event( 2, array( '2026-12-20' ) ) );
		$resolved = Fe_Event_Resolver::resolve( array( 1 ), $open, '2026-12-01' );
		$this->assertNull( $resolved );
		$this->assertSame( 'closed', Fe_Event_Resolver::status( array( 1 ), $resolved ) );
		$this->assertSame( 'active', Fe_Event_Resolver::status( array( 2 ), 2 ) );
	}
}
