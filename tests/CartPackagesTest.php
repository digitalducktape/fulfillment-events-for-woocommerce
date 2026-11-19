<?php
use PHPUnit\Framework\TestCase;

final class FeTestProduct {
	private $ship;
	public function __construct( $ship = true ) {
		$this->ship = $ship;
	}
	public function needs_shipping() {
		return $this->ship;
	}
}

final class FeTestRate {
	private $m;
	public function __construct( $m ) {
		$this->m = $m;
	}
	public function get_method_id() {
		return $this->m;
	}
}

final class CartPackagesTest extends TestCase {

	private function line( $pid, $total, $sub, $tax ) {
		return array(
			'product_id'        => $pid,
			'data'              => new FeTestProduct(),
			'line_total'        => $total,
			'line_subtotal'     => $sub,
			'line_subtotal_tax' => $tax,
		);
	}

	private function package() {
		return array(
			'contents'        => array(
				'a' => $this->line( 1, 10, 12, 1 ),
				'b' => $this->line( 2, 20, 20, 2 ),
				'c' => $this->line( 3, 5, 5, 0 ),
				'd' => $this->line( 1, 7, 7, 0 ),
			),
			'contents_cost'   => 42,
			'applied_coupons' => array( 'x' ),
			'user'            => array( 'ID' => 4 ),
			'destination'     => array( 'postcode' => '12345' ),
			'cart_subtotal'   => 99,
			'custom'          => 'keep',
		);
	}

	private function build( array $pkgs ) {
		$map = array( 1 => 5, 2 => 9, 3 => null );
		return Fe_Cart::build_packages(
			$pkgs,
			function ( $pid ) use ( $map ) {
				return $map[ $pid ];
			},
			function ( $id ) {
				return 'Event ' . $id;
			}
		);
	}

	public function test_mixed_cart_becomes_event_packages_plus_regular() {
		$out = $this->build( array( $this->package() ) );
		$this->assertCount( 3, $out );
		$this->assertSame( 'event:5', $out[0]['fe_group'] );
		$this->assertSame( array( 'a', 'd' ), array_keys( $out[0]['contents'] ) );
		$this->assertEquals( 17, $out[0]['contents_cost'] );
		$this->assertEquals( 20, $out[0]['cart_subtotal'] );
		$this->assertSame( 5, $out[0]['fe_event_id'] );
		$this->assertSame( 'Event 5', $out[0]['fe_event_name'] );
		$this->assertEquals( 22, $out[1]['cart_subtotal'] );
		$this->assertSame( 'regular', $out[2]['fe_group'] );
		$this->assertNull( $out[2]['fe_event_id'] );
		$this->assertSame( '', $out[2]['fe_event_name'] );
		$this->assertSame( array( 'postcode' => '12345' ), $out[2]['destination'] );
		$this->assertSame( 'keep', $out[2]['custom'] );
		$this->assertSame( 4, $out[0]['fe_customer_id'] );
		// Today is part of each package, so the rate cache hash rolls daily.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $out[0]['fe_today'] );
		$this->assertSame( $out[0]['fe_today'], $out[2]['fe_today'] );
	}

	public function test_inert_input_returned_unchanged() {
		$pkg = $this->package();
		unset( $pkg['contents']['a'], $pkg['contents']['b'], $pkg['contents']['d'] );
		$in = array( 2 => $pkg );
		$this->assertSame( $in, $this->build( $in ) );
	}

	public function test_event_package_keeps_only_fe_rates() {
		$rates = array( 'flat:1' => new FeTestRate( 'flat_rate' ), 'fe_delivery:2' => new FeTestRate( 'fe_delivery' ) );
		$out   = Fe_Cart::filter_rates( $rates, array( 'fe_group' => 'event:5', 'fe_event_id' => 5 ) );
		$this->assertSame( array( 'fe_delivery:2' ), array_keys( $out ) );
	}

	public function test_regular_package_drops_fe_rates() {
		$rates = array( 'flat:1' => new FeTestRate( 'flat_rate' ), 'fe_delivery:2' => new FeTestRate( 'fe_delivery' ) );
		$out   = Fe_Cart::filter_rates( $rates, array( 'fe_group' => 'regular', 'fe_event_id' => null ) );
		$this->assertSame( array( 'flat:1' ), array_keys( $out ) );
	}

	public function test_destination_is_complete() {
		$this->assertFalse( Fe_Cart::destination_is_complete( array() ) );
		$this->assertFalse( Fe_Cart::destination_is_complete( array( 'country' => 'US', 'postcode' => '' ) ) );
		$this->assertTrue( Fe_Cart::destination_is_complete( array( 'postcode' => '12345' ) ) );
		$this->assertTrue( Fe_Cart::destination_is_complete( array( 'country' => 'US', 'city' => 'Town' ) ) );
	}

	public function test_package_carries_given_today() {
		$map = array( 1 => 5, 2 => 9, 3 => null );
		$out = Fe_Cart::build_packages(
			array( $this->package() ),
			function ( $pid ) use ( $map ) {
				return $map[ $pid ];
			},
			function ( $id ) {
				return 'Event ' . $id;
			},
			'2026-12-01'
		);
		$this->assertSame( '2026-12-01', $out[1]['fe_today'] );
	}

	public function test_past_date_errors() {
		$metas = array(
			array( 'fe_date' => '2026-12-09', 'fe_event_name' => 'Winter market' ),
			array( 'fe_date' => '2026-12-10', 'fe_event_name' => 'Holiday' ),
			array( 'fe_date' => '', 'fe_event_name' => 'Guest add-on' ),
		);
		$this->assertSame(
			array( 'The date you chose for Winter market has passed. Choose another date.' ),
			Fe_Cart::past_date_errors( $metas, '2026-12-10' )
		);
		$this->assertSame( array(), Fe_Cart::past_date_errors( $metas, '2026-12-09' ) );
	}

	public function test_chosen_fe_metas_only_reads_plugin_rates() {
		$rate = new class() {
			public $meta;
			public function get_meta_data() {
				return $this->meta;
			}
		};
		$fe       = clone $rate;
		$fe->meta = array( 'fe_date' => '2026-12-09' );
		$flat     = clone $rate;
		$flat->meta = array( 'fe_date' => '2000-01-01' );
		$packages = array(
			0 => array( 'rates' => array( 'fe_delivery:3:5-2026-12-09' => $fe ) ),
			1 => array( 'rates' => array( 'flat_rate:1' => $flat ) ),
		);
		$this->assertSame(
			array( array( 'fe_date' => '2026-12-09' ) ),
			Fe_Cart::chosen_fe_metas( $packages, array( 0 => 'fe_delivery:3:5-2026-12-09', 1 => 'flat_rate:1' ) )
		);
	}

	public function test_product_in_two_events_gets_one_package_offering_both() {
		$out = Fe_Cart::build_packages(
			array( $this->package() ),
			function ( $pid ) {
				return 1 === $pid ? array( 5, 9 ) : array();
			},
			function ( $id ) {
				return 'Event ' . $id;
			}
		);
		$this->assertSame( 'event:5,9', $out[0]['fe_group'] );
		$this->assertSame( 5, $out[0]['fe_event_id'] );
		$this->assertSame( array( 5, 9 ), $out[0]['fe_event_ids'] );
		$this->assertSame( 'Event 5 / Event 9', $out[0]['fe_event_name'] );
		$this->assertSame( array( 5, 9 ), Fe_Cart::package_event_ids( $out[0] ) );
		$this->assertSame( array( 7 ), Fe_Cart::package_event_ids( array( 'fe_event_id' => 7 ) ) );
		$this->assertSame( '2026-12-10', Fe_Cart::rate_suffix( 5, '2026-12-10', 1 ) );
		$this->assertSame( '5-2026-12-10', Fe_Cart::rate_suffix( 5, '2026-12-10', 2 ) );
	}
}
