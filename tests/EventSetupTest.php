<?php
use PHPUnit\Framework\TestCase;

final class EventSetupTest extends TestCase {

	const DATES = array( '2026-12-10', '2026-12-18' );

	private function rule( $zone, $type, $date, $mode, $cost = null ) {
		return array( 'zone_id' => $zone, 'type' => $type, 'date' => $date, 'mode' => $mode, 'cost' => $cost );
	}

	private function zones() {
		return array( 'delivery' => array( 1, 2 ), 'courier' => array( 1, 3 ) );
	}

	private function find( array $rules, $zone, $type, $date ) {
		foreach ( $rules as $r ) {
			if ( $r['zone_id'] === $zone && $r['type'] === $type && $r['date'] === $date ) {
				return $r;
			}
		}
		return null;
	}

	public function test_event_wide_off_becomes_unticked_on_every_date_and_survives_save() {
		$existing = array( $this->rule( 3, 'courier', null, 'off' ) );
		$ticks    = Fe_Event_Setup::ticks_from_rules( $existing, self::DATES, $this->zones() );
		$this->assertFalse( $ticks['2026-12-10']['courier'][3] );
		$this->assertFalse( $ticks['2026-12-18']['courier'][3] );
		$this->assertTrue( $ticks['2026-12-10']['courier'][1] );
		$this->assertTrue( $ticks['2026-12-10']['delivery'][2] );

		$out = Fe_Event_Setup::rules_from_setup( $existing, $ticks, array(), self::DATES, $this->zones() );
		$this->assertSame( 'off', $this->find( $out['rules'], 3, 'courier', '2026-12-10' )['mode'] );
		$this->assertSame( 'off', $this->find( $out['rules'], 3, 'courier', '2026-12-18' )['mode'] );
		$this->assertNull( $this->find( $out['rules'], 3, 'courier', null ) );
		$this->assertCount( 2, $out['rules'] );
		$this->assertSame( 0, $out['dropped'] );
	}

	public function test_date_rule_beats_event_wide_rule_in_ticks() {
		$existing = array( $this->rule( 1, 'delivery', null, 'off' ), $this->rule( 1, 'delivery', '2026-12-18', 'on', 25.0 ) );
		$ticks    = Fe_Event_Setup::ticks_from_rules( $existing, self::DATES, $this->zones() );
		$this->assertFalse( $ticks['2026-12-10']['delivery'][1] );
		$this->assertTrue( $ticks['2026-12-18']['delivery'][1] );
	}

	public function test_out_of_scope_rules_are_kept() {
		$existing = array(
			$this->rule( 1, 'pickup', null, 'off' ),
			$this->rule( 9, 'delivery', '2026-12-10', 'off' ),
			$this->rule( 1, 'delivery', '2026-11-01', 'off' ),
			$this->rule( 1, 'delivery', null, 'inherit' ),
		);
		$ticks = Fe_Event_Setup::ticks_from_rules( $existing, self::DATES, $this->zones() );
		$out   = Fe_Event_Setup::rules_from_setup( $existing, $ticks, array(), self::DATES, $this->zones() );
		$this->assertNotNull( $this->find( $out['rules'], 1, 'pickup', null ) );
		$this->assertNotNull( $this->find( $out['rules'], 9, 'delivery', '2026-12-10' ) );
		$this->assertNotNull( $this->find( $out['rules'], 1, 'delivery', '2026-11-01' ) );
		$this->assertSame( 'inherit', $this->find( $out['rules'], 1, 'delivery', null )['mode'] );
		$this->assertCount( 4, $out['rules'] );
	}

	public function test_price_rows_replace_on_rules_and_collide_with_off() {
		$existing = array( $this->rule( 1, 'delivery', null, 'on', 20.0 ) );
		$ticks    = Fe_Event_Setup::ticks_from_rules( array(), self::DATES, $this->zones() );
		$ticks['2026-12-10']['delivery'][1] = false;
		$prices = Fe_Event_Setup::prices_from_post(
			array(
				array( 'zone_id' => '1', 'type' => 'delivery', 'date' => '2026-12-10', 'cost' => '25' ),
				array( 'zone_id' => '1', 'type' => 'delivery', 'date' => '', 'cost' => '22' ),
				array( 'zone_id' => '1', 'type' => 'delivery', 'date' => '', 'cost' => '' ),
				array( 'zone_id' => '1', 'type' => 'boat', 'date' => '', 'cost' => '5' ),
			)
		);
		$this->assertCount( 2, $prices );
		$out = Fe_Event_Setup::rules_from_setup( $existing, $ticks, $prices, self::DATES, $this->zones() );
		$this->assertSame( 22.0, $this->find( $out['rules'], 1, 'delivery', null )['cost'] );
		$this->assertSame( 'off', $this->find( $out['rules'], 1, 'delivery', '2026-12-10' )['mode'] );
		$this->assertSame( 1, $out['dropped'] );
		$this->assertCount( 2, $out['rules'] );
	}

	public function test_unticking_every_zone_posts_all_off_but_missing_marker_means_all_on() {
		$rows  = array(
			array( 'date' => '2026-12-10', 'zones_posted' => '1', 'zones' => array( 'courier' => array( '3' ) ) ),
			array( 'date' => '2026-12-18' ),
		);
		$ticks = Fe_Event_Setup::ticks_from_post( $rows, $this->zones() );
		$this->assertSame( array( 1 => false, 2 => false ), $ticks['2026-12-10']['delivery'] );
		$this->assertSame( array( 1 => false, 3 => true ), $ticks['2026-12-10']['courier'] );
		$this->assertSame( array( 1 => true, 2 => true ), $ticks['2026-12-18']['delivery'] );
	}

	public function test_exceptions_split_into_exclusions_and_limits() {
		$out = Fe_Event_Setup::exceptions_from_post(
			array(
				array( 'product_id' => '12', 'mode' => 'only', 'dates' => array( '2026-12-18' ) ),
				array( 'product_id' => '13', 'mode' => 'exclude' ),
				array( 'product_id' => '14', 'mode' => 'only', 'dates' => array( '2026-12-01' ) ),
				array( 'product_id' => '0', 'mode' => 'exclude' ),
			),
			self::DATES
		);
		$this->assertSame( array( 13 ), $out['excluded'] );
		$this->assertSame( array( 12 => array( '2026-12-18' ), 14 => array() ), $out['limits'] );
		$this->assertSame( array( 14 ), $out['emptied'] );
	}

	public function test_only_products_not_in_event_get_linked() {
		$this->assertSame(
			array( 5, 12 ),
			Fe_Event_Setup::products_to_link( array( 12, 13, 5 ), array( 5 ), array( 12 => array( 40 ), 13 => array( 77 ) ), array( 77 ) )
		);
	}
}
