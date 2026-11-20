<?php
use PHPUnit\Framework\TestCase;

final class EventRepoNormalizeTest extends TestCase {

	public function test_normalize_dates_sorts_dedupes_and_drops_empty() {
		$raw = array(
			array( 'date' => '2026-12-18', 'delivery' => 1, 'delivery_window' => '10-12' ),
			array( 'date' => '2026-12-10', 'pickup' => 1, 'pickup_window' => '9-11', 'location_id' => '3' ),
			array( 'date' => '2026-12-10', 'delivery' => 1 ),
			array( 'date' => '2026-02-30', 'delivery' => 1 ),
			array( 'date' => '2026-12-20' ),
		);
		$out = Fe_Event_Repo::normalize_dates( $raw );
		$this->assertSame( array( '2026-12-10', '2026-12-18' ), array_column( $out, 'date' ) );
		// Duplicate dates: last row wins.
		$this->assertTrue( $out[0]['delivery'] );
		$this->assertFalse( $out[0]['pickup'] );
		$this->assertNull( $out[0]['location_id'] );
		$this->assertSame( '10-12', $out[1]['delivery_window'] );
	}

	public function test_is_open_window_bounds() {
		$now = '2026-12-01 12:00:00';
		$this->assertTrue( Fe_Event_Repo::is_open( '', '', $now ) );
		$this->assertTrue( Fe_Event_Repo::is_open( '2026-12-01 12:00:00', '2026-12-02 00:00:00', $now ) );
		$this->assertFalse( Fe_Event_Repo::is_open( '2026-12-02 00:00:00', '', $now ) );
		$this->assertFalse( Fe_Event_Repo::is_open( '', '2026-12-01 12:00:00', $now ) );
	}

	public function test_normalize_rules_dedupes_null_date_last_wins() {
		$out = Fe_Zone_Rule_Repo::normalize_rules(
			array(
				array( 'zone_id' => 1, 'type' => 'delivery', 'date' => null, 'mode' => 'on', 'cost' => 10 ),
				array( 'zone_id' => 1, 'type' => 'delivery', 'date' => '', 'mode' => 'on', 'cost' => 12 ),
				array( 'zone_id' => 1, 'type' => 'delivery', 'date' => '2026-12-10', 'mode' => 'off' ),
				array( 'zone_id' => 1, 'type' => 'bogus', 'mode' => 'on' ),
				array( 'zone_id' => 1, 'type' => 'pickup', 'mode' => 'weird' ),
				array( 'zone_id' => 1, 'type' => 'pickup', 'mode' => 'on', 'cost' => -1 ),
			)
		);
		$this->assertCount( 2, $out );
		$this->assertSame( 12.0, $out[0]['cost'] );
		$this->assertNull( $out[0]['date'] );
		$this->assertSame( '2026-12-10', $out[1]['date'] );
		$this->assertNull( $out[1]['cost'] );
	}

	public function test_clean_window_strips_markup() {
		$out = Fe_Event_Repo::clean_window( '<b>9am</b> – 6pm<script>x</script>' );
		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsString( '>', $out );
		$this->assertStringStartsWith( '9am – 6pm', $out );
	}

	public function test_clean_window_collapses_whitespace() {
		$this->assertSame( '10 to 2 pm', Fe_Event_Repo::clean_window( "  10\n\tto \r\n 2   pm " ) );
	}

	public function test_clean_window_caps_length_without_breaking_multibyte() {
		$this->assertSame( 100, strlen( Fe_Event_Repo::clean_window( str_repeat( 'a', 150 ) ) ) );
		$out = Fe_Event_Repo::clean_window( str_repeat( '–', 150 ) );
		$this->assertSame( 100, mb_strlen( $out, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
		$this->assertSame( '9am – 6pm', Fe_Event_Repo::clean_window( '9am – 6pm' ) );
	}

	public function test_normalize_dates_cleans_windows() {
		$out = Fe_Event_Repo::normalize_dates( array( array( 'date' => '2026-12-10', 'delivery' => 1, 'delivery_window' => '<i>9</i>-12' ) ) );
		$this->assertSame( '9-12', $out[0]['delivery_window'] );
	}

	public function test_courier_only_row_is_kept_and_neither_is_dropped() {
		$out = Fe_Event_Repo::normalize_dates(
			array(
				array( 'date' => '2026-12-18', 'courier' => 1 ),
				array( 'date' => '2026-12-19' ),
			)
		);
		$this->assertCount( 1, $out );
		$this->assertTrue( $out[0]['courier'] );
		$this->assertFalse( $out[0]['delivery'] );
		$this->assertFalse( $out[0]['pickup'] );
	}
}
