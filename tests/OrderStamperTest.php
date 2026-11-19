<?php
use PHPUnit\Framework\TestCase;

final class OrderStamperTest extends TestCase {

	private function event_line( $id = 5 ) {
		return array(
			'group'     => 'event:' . $id,
			'rate_meta' => array(
				'fe_event_id'   => (string) $id,
				'fe_event_name' => 'Winter market',
				'fe_date'       => '2026-12-10',
				'fe_type'       => 'pickup',
				'fe_window'     => '10:00-12:00',
				'fe_location'   => 'Town hall',
			),
		);
	}

	private function regular_line() {
		return array(
			'group'     => 'regular',
			'rate_meta' => array(),
		);
	}

	public function test_single_event_line_is_stamped() {
		$plan = Fe_Order_Stamper::plan( array( $this->event_line() ) );
		$this->assertSame( 'stamp', $plan['action'] );
		$this->assertSame( 5, $plan['fulfillment']['event_id'] );
		$this->assertSame( '2026-12-10', $plan['fulfillment']['date'] );
		$this->assertSame( 'pickup', $plan['fulfillment']['type'] );
	}

	public function test_regular_only_is_none() {
		$plan = Fe_Order_Stamper::plan( array( $this->regular_line() ) );
		$this->assertSame( 'none', $plan['action'] );
		$this->assertNull( $plan['fulfillment'] );
		$this->assertSame( 'none', Fe_Order_Stamper::plan( array() )['action'] );
	}

	public function test_two_lines_one_event_is_split() {
		$plan = Fe_Order_Stamper::plan( array( $this->event_line(), $this->regular_line() ) );
		$this->assertSame( 'split', $plan['action'] );
		$this->assertNull( $plan['fulfillment'] );
	}

	public function test_two_event_lines_is_split() {
		$plan = Fe_Order_Stamper::plan( array( $this->event_line( 5 ), $this->event_line( 6 ) ) );
		$this->assertSame( 'split', $plan['action'] );
	}

	public function test_event_group_without_date_is_not_an_event_line() {
		$line                         = $this->event_line();
		$line['rate_meta']['fe_date'] = '';
		$this->assertSame( 'none', Fe_Order_Stamper::plan( array( $line ) )['action'] );
	}

	public function test_stamp_snapshots_names_not_ids() {
		$plan = Fe_Order_Stamper::plan( array( $this->event_line( 999 ) ) );
		$meta = Fe_Meta::to_meta( $plan['fulfillment'] );
		$this->assertSame( 'Winter market', $meta[ Fe_Meta::EVENT_NAME ] );
		$this->assertSame( 'Town hall', $meta[ Fe_Meta::LOCATION ] );
		$this->assertSame( '10:00-12:00', $meta[ Fe_Meta::WINDOW ] );
	}

	public function test_fulfillment_from_rate_meta_maps_and_defaults() {
		$f = Fe_Order_Stamper::fulfillment_from_rate_meta( array( 'fe_event_id' => '7', 'fe_date' => '2026-12-10', 'fe_addon_of' => '42' ) );
		$this->assertSame( 7, $f['event_id'] );
		$this->assertSame( '', $f['event_name'] );
		$this->assertSame( '', $f['location'] );
		$this->assertSame( 42, $f['addon_of'] );
		$g = Fe_Order_Stamper::fulfillment_from_rate_meta( array() );
		$this->assertSame( 0, $g['event_id'] );
		$this->assertNull( $g['addon_of'] );
		$this->assertNull( $g['parent_id'] );
	}

	public function test_line_group() {
		$this->assertSame( 'event:3', Fe_Order_Stamper::line_group( true, 3 ) );
		$this->assertSame( 'regular', Fe_Order_Stamper::line_group( true, null ) );
		$this->assertSame( 'none', Fe_Order_Stamper::line_group( false, 3 ) );
	}
}
