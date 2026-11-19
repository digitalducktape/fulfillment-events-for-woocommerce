<?php
use PHPUnit\Framework\TestCase;

final class FeeDedupeTest extends TestCase {

	private function line( $group, $type, $date, $cost ) {
		return array(
			'group'      => $group,
			'type'       => $type,
			'date'       => $date,
			'cost_cents' => $cost,
		);
	}

	public function test_dedupe_credits_duplicate_same_date_delivery() {
		$credit = Fe_Fee_Dedupe::credit(
			array(
				$this->line( 'event:1', 'delivery', '2026-12-10', 1500 ),
				$this->line( 'event:2', 'delivery', '2026-12-10', 2000 ),
				$this->line( 'event:3', 'delivery', '2026-12-10', 500 ),
			)
		);
		$this->assertSame( 2000, $credit );
	}

	public function test_dedupe_ignores_pickup_and_different_dates() {
		$this->assertSame(
			0,
			Fe_Fee_Dedupe::credit(
				array(
					$this->line( 'event:1', 'delivery', '2026-12-10', 1500 ),
					$this->line( 'event:2', 'delivery', '2026-12-11', 1500 ),
					$this->line( 'event:3', 'pickup', '2026-12-10', 0 ),
					$this->line( 'event:4', 'courier', '2026-12-10', 900 ),
				)
			)
		);
		$this->assertSame( 0, Fe_Fee_Dedupe::credit( array() ) );
	}

	public function test_chosen_rate_lines_reads_only_event_packages() {
		$rate = new FeTestShippingRate(
			15.0,
			array(
				'fe_type' => 'delivery',
				'fe_date' => '2026-12-10',
			)
		);
		$packages = array(
			0 => array(
				'fe_group' => 'event:1',
				'rates'    => array( 'fe_delivery:3:a' => $rate ),
			),
			1 => array(
				'fe_group' => 'regular',
				'rates'    => array( 'flat_rate:1' => new FeTestShippingRate( 5.0, array() ) ),
			),
			2 => array(
				'rates' => array( 'flat_rate:2' => new FeTestShippingRate( 5.0, array() ) ),
			),
		);
		$lines = Fe_Cart::chosen_rate_lines(
			$packages,
			array(
				0 => 'fe_delivery:3:a',
				1 => 'flat_rate:1',
				2 => 'flat_rate:2',
			)
		);
		$this->assertSame(
			array(
				array(
					'group'      => 'event:1',
					'type'       => 'delivery',
					'date'       => '2026-12-10',
					'cost_cents' => 1500,
				),
			),
			$lines
		);
		$this->assertSame( array(), Fe_Cart::chosen_rate_lines( $packages, array() ) );
	}
}

final class FeTestShippingRate {
	private $cost;
	private $meta;
	public function __construct( $cost, $meta ) {
		$this->cost = $cost;
		$this->meta = $meta;
	}
	public function get_cost() {
		return $this->cost;
	}
	public function get_meta_data() {
		return $this->meta;
	}
}
