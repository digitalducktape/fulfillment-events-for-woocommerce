<?php
use PHPUnit\Framework\TestCase;

final class SplitPlanTest extends TestCase {

	private function item( $id, $group, $subtotal ) {
		return array(
			'id'             => $id,
			'group'          => $group,
			'subtotal_cents' => $subtotal,
			'total_cents'    => $subtotal,
			'tax_cents'      => 0,
		);
	}

	private function ship( $id, $group ) {
		return array(
			'id'             => $id,
			'group'          => $group,
			'subtotal_cents' => 0,
			'total_cents'    => 1000,
			'tax_cents'      => 0,
		);
	}

	public function test_plan_groups_items_and_shipping_by_group() {
		$plan = Fe_Order_Splitter::plan(
			array( $this->item( 1, 'event:5', 1000 ), $this->item( 2, 'event:6', 3000 ), $this->item( 3, 'event:5', 1000 ) ),
			array( $this->ship( 10, 'event:5' ), $this->ship( 11, 'event:6' ) ),
			array(
				array(
					'id'          => 20,
					'group'       => '',
					'total_cents' => 101,
					'tax_cents'   => 0,
					'taxes'       => array( 4 => 11 ),
				),
			)
		);
		$this->assertCount( 2, $plan );
		$this->assertSame( 'event:5', $plan[0]['group'] );
		$this->assertSame( array( 1, 3 ), $plan[0]['item_ids'] );
		$this->assertSame( array( 10 ), $plan[0]['shipping_ids'] );
		$this->assertSame( 'event:6', $plan[1]['group'] );
		$this->assertSame( array( 2 ), $plan[1]['item_ids'] );
		$this->assertSame( array( 11 ), $plan[1]['shipping_ids'] );
		// 101 cents by 2000:3000 → 40 + 60, remainder 1 to the first child.
		$this->assertSame( array( 20 => 41 ), $plan[0]['fee_allocations'] );
		$this->assertSame( array( 20 => 60 ), $plan[1]['fee_allocations'] );
		$this->assertSame( 11, $plan[0]['fee_tax_allocations'][20][4] + $plan[1]['fee_tax_allocations'][20][4] );
	}

	public function test_virtual_items_ride_first_child() {
		$plan = Fe_Order_Splitter::plan(
			array( $this->item( 1, 'event:5', 1000 ), $this->item( 2, 'none', 500 ), $this->item( 3, 'event:6', 1000 ), $this->item( 4, '', 100 ) ),
			array( $this->ship( 10, 'event:5' ), $this->ship( 11, 'event:6' ) ),
			array()
		);
		$this->assertSame( array( 1, 2, 4 ), $plan[0]['item_ids'] );
		$this->assertSame( array( 3 ), $plan[1]['item_ids'] );
		$this->assertSame( array(), $plan[0]['fee_allocations'] );
	}

	public function test_regular_group_becomes_its_own_child() {
		$plan = Fe_Order_Splitter::plan(
			array( $this->item( 1, 'event:5', 1000 ), $this->item( 2, 'regular', 500 ) ),
			array( $this->ship( 10, 'event:5' ), $this->ship( 11, 'regular' ) ),
			array()
		);
		$this->assertCount( 2, $plan );
		$this->assertSame( 'regular', $plan[1]['group'] );
		$this->assertSame( array( 2 ), $plan[1]['item_ids'] );
		$this->assertSame( array( 11 ), $plan[1]['shipping_ids'] );
	}

	public function test_single_group_plans_nothing() {
		$plan = Fe_Order_Splitter::plan(
			array( $this->item( 1, 'event:5', 1000 ), $this->item( 2, 'none', 500 ) ),
			array( $this->ship( 10, 'event:5' ) ),
			array()
		);
		$this->assertSame( array(), $plan );
	}

	/* ---- Adapter tests against the fake WC_Order in tests/wc-stubs.php ---- */

	protected function setUp(): void {
		$GLOBALS['fe_test_orders']  = array();
		$GLOBALS['fe_test_actions'] = array();
		$GLOBALS['fe_test_filters'] = array();
	}

	private function product( $name, $group, $total, $tax, $reduced ) {
		$item = new WC_Order_Item_Product();
		$item->set_name( $name );
		$item->set_product_id( 7 );
		$item->set_variation_id( 0 );
		$item->set_quantity( 2 );
		$item->set_subtotal( $total );
		$item->set_total( $total );
		$item->set_subtotal_tax( $tax );
		$item->set_total_tax( $tax );
		$item->set_taxes( array( 'total' => array( 1 => $tax ), 'subtotal' => array( 1 => $tax ) ) );
		$item->add_meta_data( Fe_Meta::GROUP, $group );
		if ( $reduced ) {
			$item->add_meta_data( '_reduced_stock', $reduced );
		}
		return $item;
	}

	private function shipping( $event_id, $date, $cost ) {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_id( 'fe_delivery' );
		$item->set_instance_id( 3 );
		$item->set_method_title( 'Delivery' );
		$item->set_total( $cost );
		$item->set_taxes( array( 'total' => array() ) );
		$item->add_meta_data( Fe_Meta::GROUP, 'event:' . $event_id );
		foreach ( array(
			'fe_event_id'   => (string) $event_id,
			'fe_event_name' => 'Winter market',
			'fe_date'       => $date,
			'fe_type'       => 'delivery',
			'fe_window'     => '10:00-12:00',
			'fe_location'   => '',
		) as $k => $v ) {
			$item->add_meta_data( $k, $v );
		}
		return $item;
	}

	private function parent_order() {
		$order = new WC_Order();
		$order->set_props(
			array(
				'status'         => 'processing',
				'customer_id'    => 3,
				'billing'        => array( 'first_name' => 'Sam' ),
				'shipping'       => array( 'first_name' => 'Sam' ),
				'payment_method' => 'cod',
				'coupon_codes'   => array( 'save10' ),
			)
		);
		$order->save();
		$order->add_item( $this->product( 'Pie', 'event:5', 10.00, 0.83, 2 ) );
		$order->add_item( $this->product( 'Cake', 'event:6', 20.00, 1.67, 2 ) );
		$order->add_item( $this->product( 'Gift card', 'none', 5.00, 0.00, 0 ) );
		$order->add_item( $this->shipping( 5, '2026-12-10', 7.00 ) );
		$order->add_item( $this->shipping( 6, '2026-12-11', 7.00 ) );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Service' );
		$fee->set_total( 1.01 );
		$fee->set_taxes( array( 'total' => array( 1 => 0.07 ) ) );
		$order->add_item( $fee );
		// 35 items + 2.50 tax + 14 shipping + 1.01 fee + 0.07 fee tax = 52.58; one cent of drift on top.
		$order->set_total( 52.59 );
		$order->update_meta_data( Fe_Meta::WILL_SPLIT, 'yes' );
		return $order;
	}

	private function children_of( $order ) {
		$out = array();
		foreach ( explode( ',', (string) $order->get_meta( Fe_Meta::CHILDREN ) ) as $id ) {
			if ( '' !== $id ) {
				$out[] = wc_get_order( (int) $id );
			}
		}
		return $out;
	}

	private function count_split_actions() {
		$n = 0;
		foreach ( $GLOBALS['fe_test_actions'] as $a ) {
			if ( 'fe_order_split' === $a[0] ) {
				$n++;
			}
		}
		return $n;
	}

	public function test_split_is_idempotent() {
		$order  = $this->parent_order();
		$before = count( $GLOBALS['fe_test_orders'] );
		Fe_Order_Splitter::maybe_split( $order->get_id() );
		Fe_Order_Splitter::maybe_split( $order->get_id() );
		Fe_Order_Splitter::on_status_changed( $order->get_id(), 'on-hold', 'processing', $order );

		$this->assertSame( $before + 2, count( $GLOBALS['fe_test_orders'] ) );
		$this->assertSame( 1, $this->count_split_actions() );
		$this->assertSame( 'yes', $order->get_meta( Fe_Meta::SPLIT_DONE ) );
		$this->assertSame( Fe_Split_Status::STATUS, $order->get_status() );
		$this->assertCount( 2, $this->children_of( $order ) );
	}

	public function test_split_skips_unmarked_or_unpaid_orders() {
		$order = $this->parent_order();
		$order->delete_meta_data( Fe_Meta::WILL_SPLIT );
		Fe_Order_Splitter::maybe_split( $order->get_id() );
		$this->assertSame( '', $order->get_meta( Fe_Meta::SPLIT_DONE ) );

		$order = $this->parent_order();
		$order->set_status( 'pending' );
		Fe_Order_Splitter::maybe_split( $order->get_id() );
		$this->assertSame( '', $order->get_meta( Fe_Meta::SPLIT_DONE ) );

		$order = $this->parent_order();
		$GLOBALS['fe_test_filters']['fe_should_split_order'] = function () {
			return false;
		};
		Fe_Order_Splitter::maybe_split( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 0, $this->count_split_actions() );
	}

	public function test_parent_items_lose_reduced_stock_meta_after_split() {
		$order = $this->parent_order();
		Fe_Order_Splitter::maybe_split( $order->get_id() );

		foreach ( $order->get_items() as $item ) {
			$this->assertSame( '', $item->get_meta( '_reduced_stock' ) );
		}
		$children = $this->children_of( $order );
		$reduced  = array();
		foreach ( $children as $child ) {
			$this->assertTrue( $child->stock_reduced );
			// The flag is set before the status change that would reduce stock.
			$this->assertSame( array( 'stock_reduced:yes', 'status:processing' ), $child->log );
			foreach ( $child->get_items() as $item ) {
				$reduced[ $item->get_name() ] = $item->get_meta( '_reduced_stock' );
			}
		}
		$this->assertSame( 2, $reduced['Pie'] );
		$this->assertSame( 2, $reduced['Cake'] );
	}

	public function test_children_total_equals_parent_total() {
		$order = $this->parent_order();
		Fe_Order_Splitter::maybe_split( $order->get_id() );
		$children = $this->children_of( $order );

		$sum = 0;
		foreach ( $children as $child ) {
			$sum += Fe_Split_Math::to_cents( $child->get_total() );
		}
		$this->assertSame( 5259, $sum );

		// Pie + gift card ride the first child; event meta is stamped per child.
		$first = $children[0];
		$names = array();
		foreach ( $first->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$this->assertSame( array( 'Pie', 'Gift card' ), $names );
		$this->assertSame( '5', $first->get_meta( Fe_Meta::EVENT_ID ) );
		$this->assertSame( '2026-12-10', $first->get_meta( Fe_Meta::DATE ) );
		$this->assertSame( (string) $order->get_id(), $first->get_meta( Fe_Meta::PARENT_ID ) );
		$this->assertSame( '6', $children[1]->get_meta( Fe_Meta::EVENT_ID ) );
		$this->assertSame( 'fe-split', $first->get_created_via() );
		$this->assertSame( 'Sam', $first->get_billing_first_name() );
		$this->assertSame( 'processing', $children[1]->get_status() );
		$this->assertSame( '', $first->get_parent_id() );
	}

	public function test_on_hold_parent_makes_on_hold_children() {
		$order = $this->parent_order();
		$order->set_status( 'on-hold' );
		Fe_Order_Splitter::maybe_split( $order->get_id() );
		foreach ( $this->children_of( $order ) as $child ) {
			$this->assertSame( 'on-hold', $child->get_status() );
		}
	}
}

