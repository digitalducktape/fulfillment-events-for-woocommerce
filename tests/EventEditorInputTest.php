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

	public function test_parse_post_full_setup_form() {
		$zones = array( 'delivery' => array( 1, 2 ), 'courier' => array( 1, 3 ) );
		$out   = Fe_Event_Editor::parse_post(
			array(
				'fe_sales_open'   => '2026-11-01T09:00',
				'fe_dates'        => array(
					array(
						'date'            => '2026-12-10',
						'zones_posted'    => '1',
						'delivery'        => '1',
						'delivery_window' => '10am to 2pm',
						'courier'         => '1',
						'zones'           => array(
							'delivery' => array( '1', '2' ),
							'courier'  => array( '1' ),
						),
					),
					array(
						'date'          => '2026-12-18',
						'zones_posted'  => '1',
						'pickup'        => '1',
						'pickup_window' => '11am to 4pm',
						'location_id'   => '2',
						'zones'         => array( 'delivery' => array( '1', '2' ), 'courier' => array( '1', '3' ) ),
					),
				),
				'fe_category_ids' => array( '40' ),
				'fe_product_ids'  => array( '7', '13' ),
				'fe_exceptions'   => array(
					array( 'product_id' => '12', 'mode' => 'only', 'dates' => array( '2026-12-18' ) ),
					array( 'product_id' => '13', 'mode' => 'exclude' ),
				),
				'fe_prices'       => array(
					array( 'zone_id' => '2', 'type' => 'delivery', 'date' => '', 'cost' => '25' ),
				),
			),
			$zones,
			array( array( 'zone_id' => 1, 'type' => 'pickup', 'date' => null, 'mode' => 'off', 'cost' => null ) )
		);
		$this->assertSame( array( '2026-12-10', '2026-12-18' ), array_column( $out['dates'], 'date' ) );
		$this->assertTrue( $out['dates'][0]['courier'] );
		$this->assertFalse( $out['dates'][1]['courier'] );
		$modes = array();
		foreach ( $out['rules'] as $r ) {
			$modes[ $r['zone_id'] . '|' . $r['type'] . '|' . ( null === $r['date'] ? '' : $r['date'] ) ] = $r['mode'];
		}
		$this->assertSame(
			array(
				'1|pickup|'              => 'off',
				'2|delivery|'            => 'on',
				'3|courier|2026-12-10'   => 'off',
			),
			$modes
		);
		$this->assertSame( array( 13 ), $out['excluded_ids'] );
		$this->assertSame( array( 7 ), $out['product_ids'] );
		$this->assertSame( array( 12 => array( '2026-12-18' ) ), $out['limits'] );
		$this->assertSame( array( 12 ), $out['only_ids'] );
		$this->assertSame( 0, $out['dropped'] );
	}

	public function test_notice_text() {
		$name = function ( $id ) {
			return 'Box ' . $id;
		};
		$this->assertSame( "Box 4 has no dates left in this event, so it can't be bought for it.", Fe_Event_Editor::notice_text( array( 'code' => 'limit_emptied', 'products' => array( 4 ) ), $name ) );
		$this->assertSame( '', Fe_Event_Editor::notice_text( array( 'code' => 'nope' ), $name ) );
	}

	public function test_parse_post_collects_unique_positive_ids() {
		$out = Fe_Event_Editor::parse_post( $this->base( array( 'fe_category_ids' => array( '4', '4', '0', 'x' ), 'fe_product_ids' => array( '9', '12' ) ) ) );
		$this->assertSame( array( 4 ), $out['category_ids'] );
		$this->assertSame( array( 9, 12 ), $out['product_ids'] );
	}

	public function test_parse_post_collects_excluded_products_and_drops_them_from_links() {
		$out = Fe_Event_Editor::parse_post( $this->base( array( 'fe_product_ids' => array( '9', '12' ), 'fe_excluded_ids' => array( '12', '15', '15', '0' ) ) ) );
		$this->assertSame( array( 12, 15 ), $out['excluded_ids'] );
		$this->assertSame( array( 9 ), $out['product_ids'] );
	}

	public function test_parse_post_without_exclusions_gives_empty_list() {
		$out = Fe_Event_Editor::parse_post( $this->base() );
		$this->assertSame( array(), $out['excluded_ids'] );
	}
}
