<?php
use PHPUnit\Framework\TestCase;

final class OrderReceivedTest extends TestCase {

	private function child( $id, array $meta, array $lines, $shipping = null ) {
		$order       = new WC_Order();
		$order->id   = $id;
		$order->meta = $meta;
		foreach ( $lines as $name => $qty ) {
			$item = new WC_Order_Item_Product();
			$item->set_name( $name );
			$item->set_quantity( $qty );
			$order->add_item( $item );
		}
		if ( $shipping ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_name( $shipping[0] );
			$item->set_total( $shipping[1] );
			$order->add_item( $item );
		}
		return $order;
	}

	public function test_each_split_order_lists_its_items_and_how_it_arrives() {
		$pickup  = $this->child(
			201,
			array(
				Fe_Meta::CONTRACT   => '1',
				Fe_Meta::TYPE       => 'pickup',
				Fe_Meta::EVENT_NAME => 'Hanukkah 2026',
				Fe_Meta::DATE       => '2026-12-03',
				Fe_Meta::LOCATION   => 'City Gal Bakes, 1 Main St',
			),
			array( 'Rugelach' => 2 ),
			array( 'Pickup at City Gal Bakes · Thu, Dec 3 · 10am - 7pm', '0' )
		);
		$delivery = $this->child(
			202,
			array(
				Fe_Meta::CONTRACT   => '1',
				Fe_Meta::TYPE       => 'delivery',
				Fe_Meta::EVENT_NAME => 'Holiday Pre-order 2026',
				Fe_Meta::DATE       => '2026-12-18',
			),
			array( '2026 Holiday Cookie Box' => 1 ),
			array( 'Delivery · Fri, Dec 18 · 10am - 7pm', '9.00' )
		);
		$ticket   = $this->child( 203, array(), array( 'Cookie class' => 1 ) );

		$rows = Fe_Order_Received::rows( array( $pickup, $delivery, $ticket ) );

		$this->assertSame( '201', $rows[0]['number'] );
		$this->assertSame( 'Hanukkah 2026', $rows[0]['event'] );
		$this->assertSame( 'Pickup at City Gal Bakes · Thu, Dec 3 · 10am - 7pm', $rows[0]['shipping'] );
		$this->assertSame( 0.0, $rows[0]['shipping_cost'] );
		$this->assertSame( 'City Gal Bakes, 1 Main St', $rows[0]['location'] );
		$this->assertSame( array( array( 'name' => 'Rugelach', 'qty' => 2 ) ), $rows[0]['items'] );

		$this->assertSame( 9.0, $rows[1]['shipping_cost'] );
		$this->assertSame( '', $rows[1]['location'] );

		$this->assertSame( '', $rows[2]['event'] );
		$this->assertSame( '', $rows[2]['shipping'] );
		$this->assertSame( 'Cookie class', $rows[2]['items'][0]['name'] );
	}

	public function test_children_come_from_the_parent_meta_in_order() {
		$a = $this->child( 301, array(), array() );
		$b = $this->child( 302, array(), array() );
		$GLOBALS['fe_test_orders'][301] = $a;
		$GLOBALS['fe_test_orders'][302] = $b;
		$parent       = new WC_Order();
		$parent->meta = array( Fe_Meta::CHILDREN => '302,301,999' );
		$this->assertSame( array( $b, $a ), Fe_Order_Received::children( $parent ) );
	}
}
