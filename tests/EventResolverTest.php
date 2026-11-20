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

	public function test_exclusion_removes_a_category_event() {
		$this->assertSame( array( 1, 3 ), Fe_Event_Resolver::effective_ids( array( 1, 2, 3, 2 ), array( 2 ) ) );
	}

	public function test_exclusion_wins_over_a_direct_link() {
		$this->assertSame( array(), Fe_Event_Resolver::effective_ids( array( 5 ), array( 5 ) ) );
	}

	public function test_exclusion_of_an_unlinked_event_changes_nothing() {
		$this->assertSame( array( 1 ), Fe_Event_Resolver::effective_ids( array( 1 ), array( 9 ) ) );
	}

	public function test_excluded_product_resolves_to_its_other_event() {
		$open = array(
			$this->event( 1, array( '2026-12-03' ) ),
			$this->event( 2, array( '2026-12-10' ) ),
		);
		$ids  = Fe_Event_Resolver::effective_ids( array( 1, 2 ), array( 1 ) );
		$this->assertSame( 2, Fe_Event_Resolver::resolve( $ids, $open, '2026-12-01' ) );
		$this->assertSame( 'closed', Fe_Event_Resolver::status( array( 9 ), Fe_Event_Resolver::resolve( array(), $open, '2026-12-01' ) ) );
	}

	public function test_bulk_add_links_and_clears_exclusion() {
		$this->assertSame( array( array( 1, 7 ), array( 3 ) ), Fe_Event_Resolver::apply_bulk( array( 1 ), array( 3, 7 ), 'add', 7 ) );
	}

	public function test_bulk_add_does_not_duplicate() {
		$this->assertSame( array( array( 1, 7 ), array() ), Fe_Event_Resolver::apply_bulk( array( 7, 1 ), array(), 'add', 7 ) );
	}

	public function test_bulk_exclude_drops_link_and_excludes() {
		$this->assertSame( array( array( 1 ), array( 7 ) ), Fe_Event_Resolver::apply_bulk( array( 1, 7 ), array(), 'exclude', 7 ) );
	}

	public function test_bulk_remove_clears_both() {
		$this->assertSame( array( array( 1 ), array( 3 ) ), Fe_Event_Resolver::apply_bulk( array( 1, 7 ), array( 3, 7 ), 'remove', 7 ) );
	}

	public function test_bulk_unknown_action_or_event_changes_nothing() {
		$this->assertSame( array( array( 1 ), array( 3 ) ), Fe_Event_Resolver::apply_bulk( array( 1 ), array( 3 ), 'add', 0 ) );
	}

	public function test_product_in_two_events_moves_to_the_later_one_when_the_first_closes() {
		$dec10 = $this->event( 10, array( '2026-12-10' ) );
		$dec18 = $this->event( 18, array( '2026-12-18' ) );

		// Both open: the Dec 10 event applies.
		$this->assertSame( 10, Fe_Event_Resolver::resolve( array( 10, 18 ), array( $dec10, $dec18 ), '2026-12-01' ) );

		// Dec 10 sales window closed (not in the open list): Dec 18 applies, still purchasable.
		$resolved = Fe_Event_Resolver::resolve( array( 10, 18 ), array( $dec18 ), '2026-12-08' );
		$this->assertSame( 18, $resolved );
		$this->assertSame( 'active', Fe_Event_Resolver::status( array( 10, 18 ), $resolved ) );

		// Dec 10 date has passed while its sales window is still open: Dec 18 applies.
		$this->assertSame( 18, Fe_Event_Resolver::resolve( array( 10, 18 ), array( $dec10, $dec18 ), '2026-12-11' ) );
	}

	public function test_open_ids_lists_every_open_event_soonest_first() {
		$open = array(
			array( 'id' => 18, 'dates' => array( array( 'date' => '2026-12-18' ) ) ),
			array( 'id' => 10, 'dates' => array( array( 'date' => '2026-12-10' ) ) ),
			array( 'id' => 3, 'dates' => array( array( 'date' => '2026-12-03' ) ) ),
		);
		$this->assertSame( array( 10, 18 ), Fe_Event_Resolver::open_ids( array( 18, 10 ), $open, '2026-12-01' ) );
		$this->assertSame( array( 18 ), Fe_Event_Resolver::open_ids( array( 18, 10 ), $open, '2026-12-11' ) );
		$this->assertSame( array(), Fe_Event_Resolver::open_ids( array( 3 ), $open, '2026-12-04' ) );
		$this->assertSame( 10, Fe_Event_Resolver::resolve( array( 18, 10 ), $open, '2026-12-01' ) );
	}

	private function limited_events( array $limits ) {
		return array(
			array(
				'id'          => 7,
				'dates'       => array( array( 'date' => '2026-12-10' ), array( 'date' => '2026-12-18' ) ),
				'date_limits' => $limits,
			),
		);
	}

	public function test_open_offers_apply_the_products_limit() {
		$events = $this->limited_events( array( 42 => array( '2026-12-18' ) ) );
		$this->assertSame( array( array( 'event_id' => 7, 'dates' => array( '2026-12-18' ) ) ), Fe_Event_Resolver::open_offers( array( 7 ), $events, '2026-12-01', 42 ) );
		$this->assertSame( array( array( 'event_id' => 7, 'dates' => null ) ), Fe_Event_Resolver::open_offers( array( 7 ), $events, '2026-12-01', 41 ) );
		$this->assertSame( array(), Fe_Event_Resolver::open_offers( array( 7 ), $events, '2026-12-19', 42 ) );
		$this->assertSame( array(), Fe_Event_Resolver::open_offers( array( 7 ), $this->limited_events( array( 42 => array() ) ), '2026-12-01', 42 ) );
	}

	public function test_limited_offer_sorts_by_its_own_next_date() {
		$events   = $this->limited_events( array( 42 => array( '2026-12-18' ) ) );
		$events[] = array( 'id' => 9, 'dates' => array( array( 'date' => '2026-12-12' ) ) );
		$this->assertSame( array( 9, 7 ), array_column( Fe_Event_Resolver::open_offers( array( 7, 9 ), $events, '2026-12-01', 42 ), 'event_id' ) );
		$this->assertSame( array( 7, 9 ), Fe_Event_Resolver::open_ids( array( 7, 9 ), $events, '2026-12-01' ) );
	}

	public function test_limit_matching_every_remaining_date_counts_as_no_limit() {
		$events = $this->limited_events( array( 42 => array( '2026-12-18' ), 43 => array( '2026-12-10', '2026-12-18' ) ) );
		// Day after Dec 10: the cookie box and the rest both ship only Dec 18, so they share a shipping choice.
		$this->assertSame( array( array( 'event_id' => 7, 'dates' => null ) ), Fe_Event_Resolver::open_offers( array( 7 ), $events, '2026-12-11', 42 ) );
		$this->assertSame( array( array( 'event_id' => 7, 'dates' => null ) ), Fe_Event_Resolver::open_offers( array( 7 ), $events, '2026-12-01', 43 ) );
	}
}
