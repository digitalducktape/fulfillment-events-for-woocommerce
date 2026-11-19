<?php
use PHPUnit\Framework\TestCase;

final class EventEditorInputTest extends TestCase {

	private function base( array $over = array() ) {
		return array_merge(
			array(
				'fe_sales_open'  => '2026-12-01T09:00',
				'fe_sales_close' => '2026-12-15T18:30',
				'fe_dates'       => array(
					array( 'date' => '2026-12-18', 'delivery' => '1', 'delivery_window' => '10-12' ),
				),
			),
			$over
		);
	}

	public function test_parse_post_converts_sales_window() {
		$out = Fe_Event_Editor::parse_post( $this->base() );
		$this->assertSame( '2026-12-01 09:00:00', $out['event']['sales_open'] );
		$this->assertSame( '2026-12-15 18:30:00', $out['event']['sales_close'] );
		$this->assertSame( '', $out['event']['error'] );
		$this->assertSame( array( '2026-12-18' ), array_column( $out['dates'], 'date' ) );
	}

	public function test_parse_post_rejects_close_before_open() {
		$out = Fe_Event_Editor::parse_post( $this->base( array( 'fe_sales_close' => '2026-11-30T09:00' ) ) );
		$this->assertSame( '', $out['event']['sales_close'] );
		$this->assertSame( 'close_before_open', $out['event']['error'] );
		$this->assertSame( '2026-12-01 09:00:00', $out['event']['sales_open'] );
	}

	public function test_parse_post_drops_negative_cost() {
		$out = Fe_Event_Editor::parse_post(
			$this->base(
				array(
					'fe_rules' => array(
						2 => array(
							'delivery' => array(
								array( 'mode' => 'on', 'cost' => '-5', 'date' => '' ),
							),
							'pickup'   => array(
								array( 'mode' => 'on', 'cost' => 'abc', 'date' => '' ),
								array( 'mode' => 'off', 'cost' => '', 'date' => '' ),
							),
						),
					),
				)
			)
		);
		$this->assertCount( 1, $out['rules'] );
		$this->assertSame( 'pickup', $out['rules'][0]['type'] );
		$this->assertSame( 'off', $out['rules'][0]['mode'] );
		$this->assertNull( $out['rules'][0]['cost'] );
	}

	public function test_parse_post_maps_grid_cells_to_rules() {
		$out = Fe_Event_Editor::parse_post(
			$this->base(
				array(
					'fe_rules' => array(
						2 => array(
							'delivery' => array(
								array( 'mode' => 'on', 'cost' => '25', 'date' => '2026-12-18' ),
								array( 'mode' => 'inherit', 'cost' => '', 'date' => '' ),
							),
						),
						0 => array(
							'courier' => array(
								array( 'mode' => 'on', 'cost' => '', 'date' => 'not-a-date' ),
							),
						),
					),
				)
			)
		);
		$this->assertSame(
			array(
				array(
					'zone_id' => 2,
					'type'    => 'delivery',
					'date'    => '2026-12-18',
					'mode'    => 'on',
					'cost'    => 25.0,
				),
			),
			$out['rules']
		);
	}

	public function test_parse_post_collects_unique_positive_ids() {
		$out = Fe_Event_Editor::parse_post( $this->base( array( 'fe_category_ids' => array( '4', '4', '0', 'x' ), 'fe_product_ids' => array( '9', '12' ) ) ) );
		$this->assertSame( array( 4 ), $out['category_ids'] );
		$this->assertSame( array( 9, 12 ), $out['product_ids'] );
	}

	public function test_shift_dates_one_year_moves_dates_and_drops_leap_day() {
		$rows = array(
			array( 'date' => '2027-12-10', 'delivery' => true ),
			array( 'date' => '2028-02-29', 'delivery' => true ),
			array( 'date' => null, 'mode' => 'on' ),
		);
		$out = Fe_Event_Editor::shift_dates_one_year( $rows );
		$this->assertCount( 2, $out );
		$this->assertSame( '2028-12-10', $out[0]['date'] );
		$this->assertTrue( $out[0]['delivery'] );
		$this->assertNull( $out[1]['date'] );
	}

	public function test_shift_dates_one_year_keeps_ordinary_dates() {
		$out = Fe_Event_Editor::shift_dates_one_year( array( array( 'date' => '2026-12-10' ), array( 'date' => '2026-12-18' ) ) );
		$this->assertSame( array( '2027-12-10', '2027-12-18' ), array_column( $out, 'date' ) );
	}
}
