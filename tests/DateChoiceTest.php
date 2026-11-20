<?php
use PHPUnit\Framework\TestCase;

final class FeDatedRate {
	public $id;
	public $meta;
	private $method;
	private $instance;
	public function __construct( $id, $method, $instance, $date ) {
		$this->id       = $id;
		$this->method   = $method;
		$this->instance = $instance;
		$this->meta     = array( 'fe_date' => $date );
	}
	public function get_id() {
		return $this->id;
	}
	public function set_id( $id ) {
		$this->id = $id;
	}
	public function get_method_id() {
		return $this->method;
	}
	public function get_instance_id() {
		return $this->instance;
	}
	public function get_meta_data() {
		return $this->meta;
	}
	public function add_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
}

final class DateChoiceTest extends TestCase {

	private function rows() {
		return array(
			array( 'id' => 'fe_delivery:3:2026-12-10', 'method_id' => 'fe_delivery', 'instance_id' => 3, 'date' => '2026-12-10' ),
			array( 'id' => 'fe_delivery:3:2026-12-18', 'method_id' => 'fe_delivery', 'instance_id' => 3, 'date' => '2026-12-18' ),
			array( 'id' => 'fe_pickup:4:2026-12-18', 'method_id' => 'fe_pickup', 'instance_id' => 4, 'date' => '2026-12-18' ),
			array( 'id' => 'fe_courier:5:2026-12-10', 'method_id' => 'fe_courier', 'instance_id' => 5, 'date' => '2026-12-10' ),
			array( 'id' => 'fe_courier:5:2026-12-18', 'method_id' => 'fe_courier', 'instance_id' => 5, 'date' => '2026-12-18' ),
			array( 'id' => 'fe_addon:6:812', 'method_id' => 'fe_addon', 'instance_id' => 6, 'date' => '2026-12-10' ),
		);
	}

	public function test_earliest_date_by_default_one_option_per_service() {
		$out = Fe_Cart::choose_date( $this->rows(), '' );
		$this->assertSame( '2026-12-10', $out['date'] );
		$this->assertSame( array( '2026-12-10', '2026-12-18' ), $out['dates'] );
		$this->assertSame(
			array(
				'fe_delivery:3:2026-12-10' => 'fe_delivery:3',
				'fe_courier:5:2026-12-10'  => 'fe_courier:5',
				'fe_addon:6:812'           => 'fe_addon:6:812',
			),
			$out['keep']
		);
	}

	public function test_chosen_date_shows_only_the_services_that_run_that_day() {
		$out = Fe_Cart::choose_date( $this->rows(), '2026-12-18' );
		$this->assertSame( '2026-12-18', $out['date'] );
		$this->assertSame( array( 'fe_delivery:3', 'fe_pickup:4', 'fe_courier:5', 'fe_addon:6:812' ), array_values( $out['keep'] ) );
	}

	public function test_a_chosen_date_no_longer_offered_falls_back_to_the_earliest() {
		$out = Fe_Cart::choose_date( $this->rows(), '2026-12-03' );
		$this->assertSame( '2026-12-10', $out['date'] );
	}

	public function test_two_events_on_one_date_keep_the_first_rate_of_a_service() {
		$rows = array(
			array( 'id' => 'fe_delivery:3:5-2026-12-10', 'method_id' => 'fe_delivery', 'instance_id' => 3, 'date' => '2026-12-10' ),
			array( 'id' => 'fe_delivery:3:9-2026-12-10', 'method_id' => 'fe_delivery', 'instance_id' => 3, 'date' => '2026-12-10' ),
		);
		$out  = Fe_Cart::choose_date( $rows, '' );
		$this->assertSame( array( 'fe_delivery:3:5-2026-12-10' => 'fe_delivery:3' ), $out['keep'] );
	}

	public function test_rates_without_a_date_are_untouched() {
		$rows = array( array( 'id' => 'flat_rate:1', 'method_id' => 'flat_rate', 'instance_id' => 1, 'date' => '' ) );
		$out  = Fe_Cart::choose_date( $rows, '' );
		$this->assertNull( $out['date'] );
		$this->assertSame( array(), $out['dates'] );
		$this->assertSame( array( 'flat_rate:1' => 'flat_rate:1' ), $out['keep'] );
	}

	public function test_filter_rates_keeps_the_picked_date_and_lists_the_dates() {
		$rates   = array();
		foreach ( $this->rows() as $row ) {
			$rates[ $row['id'] ] = new FeDatedRate( $row['id'], $row['method_id'], $row['instance_id'], $row['date'] );
		}
		$package = array( 'fe_group' => 'event:7', 'fe_event_id' => 7, 'fe_chosen' => '2026-12-18' );
		$out     = Fe_Cart::filter_rates( $rates, $package );
		$this->assertSame( array( 'fe_delivery:3', 'fe_pickup:4', 'fe_courier:5', 'fe_addon:6:812' ), array_keys( $out ) );
		$this->assertSame( 'fe_pickup:4', $out['fe_pickup:4']->get_id() );
		$this->assertSame( '2026-12-18', $out['fe_pickup:4']->meta['fe_date'] );
		$this->assertSame( array( '2026-12-10', '2026-12-18' ), $out['fe_pickup:4']->meta['fe_dates'] );
		$this->assertArrayNotHasKey( 'fe_dates', $out['fe_addon:6:812']->meta );
		// Running the filter again (WooCommerce may) changes nothing.
		$this->assertSame( array_keys( $out ), array_keys( Fe_Cart::filter_rates( $out, $package ) ) );
	}
}
