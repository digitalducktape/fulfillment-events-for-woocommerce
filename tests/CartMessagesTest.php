<?php
use PHPUnit\Framework\TestCase;

final class CartMessagesTest extends TestCase {

	public function test_zero_rates_message_names_event_and_postcode() {
		$this->assertSame(
			"Winter market items aren't available for 12345. Try a different address or remove them from your cart.",
			Fe_Cart::zero_rates_message( 'Winter market', '12345' )
		);
	}

	public function test_zero_rates_message_without_postcode() {
		$this->assertSame(
			"Winter market items aren't available for this address. Try a different address or remove them from your cart.",
			Fe_Cart::zero_rates_message( 'Winter market', '' )
		);
	}

	public function test_package_name_uses_event_name() {
		$this->assertSame( 'Winter market', Fe_Cart::package_name( 'Shipping', 0, array( 'fe_event_name' => 'Winter market' ) ) );
		$this->assertSame( 'Shipping 2', Fe_Cart::package_name( 'Shipping 2', 1, array( 'fe_group' => 'regular' ) ) );
	}

	public function test_inert_when_no_group() {
		$rates = array( 'flat:1' => new stdClass(), 'fe_delivery:2' => new stdClass() );
		$this->assertSame( $rates, Fe_Cart::filter_rates( $rates, array( 'contents' => array() ) ) );
		$packages = array( 3 => array( 'contents' => array() ) );
		$this->assertSame( $packages, Fe_Cart::packages( $packages ) );
	}
}
