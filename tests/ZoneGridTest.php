<?php
use PHPUnit\Framework\TestCase;

final class ZoneGridTest extends TestCase {

	private function rule( $date, $mode, $cost = null ) {
		return array( 'zone_id' => 1, 'type' => 'delivery', 'date' => $date, 'mode' => $mode, 'cost' => $cost );
	}

	public function test_rows_are_fixed_so_a_saved_rule_does_not_add_a_row() {
		$dates = array( '2026-12-10' );
		$empty = Fe_Zone_Grid::cell_rows( array(), $dates );
		$off   = Fe_Zone_Grid::cell_rows( array( $this->rule( null, 'off' ) ), $dates );
		$this->assertCount( 2, $empty );
		$this->assertCount( 2, $off );
		$this->assertSame( array( 'date' => '', 'mode' => 'off', 'cost' => '' ), $off[0] );
		$this->assertSame( 'inherit', $off[1]['mode'] );
	}

	public function test_date_rules_fill_their_date_row_and_orphans_are_kept() {
		$rows = Fe_Zone_Grid::cell_rows(
			array( $this->rule( '2026-12-18', 'on', 25.0 ), $this->rule( '2026-12-01', 'off' ) ),
			array( '2026-12-18', '2026-12-10' )
		);
		$this->assertSame( array( '', '2026-12-01', '2026-12-10', '2026-12-18' ), array_column( $rows, 'date' ) );
		$this->assertSame( '25', $rows[3]['cost'] );
		$this->assertSame( 'off', $rows[1]['mode'] );
	}

	public function test_cell_state() {
		$this->assertSame( 'default', Fe_Zone_Grid::cell_state( Fe_Zone_Grid::cell_rows( array(), array( '2026-12-10' ) ) ) );
		$this->assertSame( 'off', Fe_Zone_Grid::cell_state( Fe_Zone_Grid::cell_rows( array( $this->rule( null, 'off' ) ), array() ) ) );
		$this->assertSame( 'override', Fe_Zone_Grid::cell_state( Fe_Zone_Grid::cell_rows( array( $this->rule( '2026-12-10', 'off' ) ), array( '2026-12-10' ) ) ) );
		$this->assertSame( 'override', Fe_Zone_Grid::cell_state( Fe_Zone_Grid::cell_rows( array( $this->rule( null, 'on', 5.0 ) ), array() ) ) );
	}

	public function test_offer_text() {
		$this->assertSame( 'delivery and pickup', Fe_Event_Editor::offer_text( array( 'delivery' => true, 'pickup' => true ) ) );
		$this->assertSame( 'pickup', Fe_Event_Editor::offer_text( array( 'pickup' => true ) ) );
	}
}
