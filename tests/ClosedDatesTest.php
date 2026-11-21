<?php
use PHPUnit\Framework\TestCase;

/**
 * A date can stop taking orders: it keeps its settings but leaves checkout,
 * product notes and "Add to my order" until it's reopened.
 */
final class ClosedDatesTest extends TestCase {

	private function event() {
		return array(
			'id'           => 7,
			'title'        => 'Holiday Pre-order 2026',
			'dates'        => array(
				array( 'date' => '2026-12-10', 'delivery' => true, 'pickup' => false, 'courier' => true ),
				array( 'date' => '2026-12-18', 'delivery' => true, 'pickup' => true, 'courier' => true ),
			),
			'closed_dates' => array( '2026-12-10' ),
			'date_limits'  => array(),
		);
	}

	public function test_closed_dates_are_dropped_from_what_customers_see() {
		$open = Fe_Event_Repo::without_closed( $this->event() );
		$this->assertSame( array( '2026-12-18' ), array_column( $open['dates'], 'date' ) );
		// No closed dates: unchanged.
		$event                 = $this->event();
		$event['closed_dates'] = array();
		$this->assertSame( $event, Fe_Event_Repo::without_closed( $event ) );
	}

	public function test_closed_list_is_cleaned_against_the_event_dates() {
		$this->assertSame(
			array( '2026-12-10' ),
			Fe_Event_Repo::clean_closed( array( '2026-12-10', '2026-12-10', '2026-12-11', 'junk' ), array( '2026-12-10', '2026-12-18' ) )
		);
	}

	public function test_a_product_only_on_a_closed_date_has_nothing_to_offer() {
		$event                = Fe_Event_Repo::without_closed( $this->event() );
		$event['date_limits'] = array( 55 => array( '2026-12-10' ), 56 => array( '2026-12-18' ) );
		$this->assertSame( array(), Fe_Event_Resolver::open_offers( array( 7 ), array( $event ), '2026-11-21', 55 ) );
		$this->assertSame( array( array( 'event_id' => 7, 'dates' => null ) ), Fe_Event_Resolver::open_offers( array( 7 ), array( $event ), '2026-11-21', 56 ) );
		$this->assertSame( array( array( 'event_id' => 7, 'dates' => null ) ), Fe_Event_Resolver::open_offers( array( 7 ), array( $event ), '2026-11-21', 0 ) );
	}

	public function test_add_ons_follow_their_own_switch_not_closing() {
		$target = array(
			'event_id'    => 7,
			'status'      => 'processing',
			'is_addon'    => false,
			'customer_id' => 3,
			'date'        => '2026-12-10',
		);
		$this->assertTrue( Fe_Addon_Rules::eligible( $target, 7, '', 3, '2026-11-21' ) );
		// A full date can still take add-ons to its existing orders...
		$this->assertTrue( Fe_Addon_Rules::eligible( $target + array( 'closed' => true ), 7, '', 3, '2026-11-21' ) );
		// ...unless add-ons are turned off for it.
		$this->assertFalse( Fe_Addon_Rules::eligible( $target + array( 'addons_off' => true ), 7, '', 3, '2026-11-21' ) );
	}

	public function test_editor_reads_which_dates_take_add_ons() {
		$out = Fe_Event_Editor::parse_post(
			array(
				'fe_dates' => array(
					array( 'date' => '2026-12-10', 'delivery' => '1', 'zones_posted' => '1' ),
					array( 'date' => '2026-12-18', 'delivery' => '1', 'zones_posted' => '1', 'addons' => '1' ),
					// A row whose fields never rendered keeps add-ons on.
					array( 'date' => '2026-12-20', 'delivery' => '1' ),
				),
			)
		);
		$this->assertSame( array( '2026-12-10' ), $out['addons_off'] );
	}

	public function test_events_list_says_what_a_date_still_takes() {
		$row = array( 'date' => '2026-12-10', 'delivery' => true, 'pickup' => false, 'courier' => true );
		$this->assertSame( 'delivery and courier', Fe_Event_Editor::date_list_text( $row, false, false ) );
		$this->assertSame( 'delivery and courier · no add-ons', Fe_Event_Editor::date_list_text( $row, false, true ) );
		$this->assertSame( 'Not taking new orders · add-ons open', Fe_Event_Editor::date_list_text( $row, true, false ) );
		$this->assertSame( 'Not taking orders', Fe_Event_Editor::date_list_text( $row, true, true ) );
	}

	public function test_editor_reads_which_dates_stopped_taking_orders() {
		$out = Fe_Event_Editor::parse_post(
			array(
				'fe_dates' => array(
					array( 'date' => '2026-12-10', 'delivery' => '1', 'closed' => '1' ),
					array( 'date' => '2026-12-18', 'delivery' => '1' ),
					// A row with nothing offered is dropped, so its "closed" goes too.
					array( 'date' => '2026-12-20', 'closed' => '1' ),
				),
			)
		);
		$this->assertSame( array( '2026-12-10' ), $out['closed'] );
		$this->assertSame( array( '2026-12-10', '2026-12-18' ), array_column( $out['dates'], 'date' ) );
	}

	public function test_checkout_stops_when_the_picked_date_is_no_longer_offered() {
		$rate     = new class() {
			public function get_meta_data() {
				return array( 'fe_date' => '2026-12-18', 'fe_event_name' => 'Holiday Pre-order 2026' );
			}
		};
		$packages = array(
			array( 'fe_group' => 'event:7', 'fe_event_id' => 7, 'fe_chosen' => '2026-12-10', 'rates' => array( 'fe_delivery:3' => $rate ) ),
		);
		$errors   = Fe_Cart::changed_date_errors( $packages, array( 'fe_delivery:3' ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Holiday Pre-order 2026', $errors[0] );
		// What the section moved to, so the pick can be updated and the
		// customer isn't stopped again once they've seen the new date.
		$this->assertSame( array( 'event:7' => '2026-12-18' ), Fe_Cart::changed_dates( $packages, array( 'fe_delivery:3' ) ) );
		// Picked date still offered, or nothing picked: no error.
		$packages[0]['fe_chosen'] = '2026-12-18';
		$this->assertSame( array(), Fe_Cart::changed_date_errors( $packages, array( 'fe_delivery:3' ) ) );
		$packages[0]['fe_chosen'] = '';
		$this->assertSame( array(), Fe_Cart::changed_date_errors( $packages, array( 'fe_delivery:3' ) ) );
	}
}
