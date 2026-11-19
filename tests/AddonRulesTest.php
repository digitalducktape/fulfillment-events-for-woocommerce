<?php
use PHPUnit\Framework\TestCase;

class AddonRulesTest extends TestCase {

	private function target( array $over = array() ) {
		return array_merge(
			array(
				'event_id'      => 12,
				'status'        => 'processing',
				'is_addon'      => false,
				'customer_id'   => 7,
				'billing_email' => 'Pat@Example.com',
			),
			$over
		);
	}

	public function test_eligible_for_owner() {
		$this->assertTrue( Fe_Addon_Rules::eligible( $this->target(), 12, '', 7 ) );
		$this->assertTrue( Fe_Addon_Rules::eligible( $this->target( array( 'status' => 'on-hold' ) ), 12, '', 7 ) );
		$this->assertFalse( Fe_Addon_Rules::eligible( $this->target(), 12, '', 8 ) );
	}

	public function test_eligible_for_guest_email_match_case_insensitive() {
		$this->assertTrue( Fe_Addon_Rules::eligible( $this->target( array( 'customer_id' => 0 ) ), 12, ' pat@example.COM ', 0 ) );
		$this->assertFalse( Fe_Addon_Rules::eligible( $this->target(), 12, 'someone@example.com', 0 ) );
	}

	public function test_empty_emails_never_match() {
		$this->assertFalse( Fe_Addon_Rules::eligible( $this->target( array( 'customer_id' => 0, 'billing_email' => '' ) ), 12, '', 0 ) );
	}

	public function test_not_eligible_other_event() {
		$this->assertFalse( Fe_Addon_Rules::eligible( $this->target(), 13, '', 7 ) );
		$this->assertFalse( Fe_Addon_Rules::eligible( $this->target( array( 'event_id' => 0 ) ), 0, 'pat@example.com', 0 ) );
	}

	public function test_not_eligible_completed() {
		foreach ( array( 'completed', 'cancelled', 'refunded', 'pending', 'fe-split', 'wc-processing' ) as $status ) {
			$this->assertFalse( Fe_Addon_Rules::eligible( $this->target( array( 'status' => $status ) ), 12, '', 7 ), $status );
		}
	}

	public function test_not_eligible_when_target_is_addon() {
		$this->assertFalse( Fe_Addon_Rules::eligible( $this->target( array( 'is_addon' => true ) ), 12, '', 7 ) );
	}

	public function test_order_id_from_input() {
		$this->assertSame( 345, Fe_Addon_Rules::order_id_from_input( ' #345 ' ) );
		$this->assertSame( 345, Fe_Addon_Rules::order_id_from_input( '345' ) );
		$this->assertSame( 0, Fe_Addon_Rules::order_id_from_input( '' ) );
		$this->assertSame( 0, Fe_Addon_Rules::order_id_from_input( '#' ) );
		$this->assertSame( 0, Fe_Addon_Rules::order_id_from_input( '34a' ) );
		$this->assertSame( 0, Fe_Addon_Rules::order_id_from_input( '-3' ) );
	}

	public function test_resolve_guest_returns_target_id_only_when_eligible() {
		$targets = array( 345 => $this->target( array( 'customer_id' => 0 ) ) );
		$lookup  = function ( $id ) use ( $targets ) {
			return isset( $targets[ $id ] ) ? $targets[ $id ] : null;
		};
		$this->assertSame( 345, Fe_Addon_Rules::resolve_guest( '#345', 12, 'pat@example.com', $lookup ) );
		$this->assertSame( 0, Fe_Addon_Rules::resolve_guest( '#345', 12, 'other@example.com', $lookup ) );
		$this->assertSame( 0, Fe_Addon_Rules::resolve_guest( '#345', 13, 'pat@example.com', $lookup ) );
		$this->assertSame( 0, Fe_Addon_Rules::resolve_guest( '#999', 12, 'pat@example.com', $lookup ) );
		$this->assertSame( 0, Fe_Addon_Rules::resolve_guest( '', 12, 'pat@example.com', $lookup ) );
	}

	public function test_rate_meta_carries_target_fulfillment_and_addon_of() {
		$meta = Fe_Addon_Rules::rate_meta(
			array(
				'event_id'   => 12,
				'event_name' => 'Winter market',
				'date'       => '2026-12-05',
				'type'       => 'pickup',
				'window'     => '10:00-14:00',
				'location'   => 'Zone A',
				'addon_of'   => null,
				'parent_id'  => 300,
			),
			345
		);
		$this->assertSame(
			array(
				'fe_event_id'   => 12,
				'fe_event_name' => 'Winter market',
				'fe_date'       => '2026-12-05',
				'fe_type'       => 'pickup',
				'fe_window'     => '10:00-14:00',
				'fe_location'   => 'Zone A',
				'fe_addon_of'   => 345,
			),
			$meta
		);

		$f = Fe_Order_Stamper::fulfillment_from_rate_meta( $meta );
		$this->assertSame( 345, $f['addon_of'] );
		$this->assertSame( '345', Fe_Meta::to_meta( $f )['_fe_addon_of'] );
	}

	public function test_addon_only_cart_stamps_with_addon_of() {
		$meta = Fe_Addon_Rules::rate_meta(
			array(
				'event_id'   => 12,
				'event_name' => 'Winter market',
				'date'       => '2026-12-05',
				'type'       => 'delivery',
				'window'     => '',
				'location'   => '',
			),
			345
		);
		$plan = Fe_Order_Stamper::plan( array( array( 'group' => 'event:12', 'rate_meta' => $meta ) ) );
		$this->assertSame( 'stamp', $plan['action'] );
		$this->assertSame( 345, $plan['fulfillment']['addon_of'] );
	}

	public function test_unhydrated_guest_line_plans_as_non_event() {
		$plan = Fe_Order_Stamper::plan(
			array(
				array(
					'group'     => 'event:12',
					'rate_meta' => array( 'fe_event_id' => 12, 'fe_addon_guest' => 'yes' ),
				),
			)
		);
		$this->assertSame( 'none', $plan['action'] );
	}

	public function test_guest_error_only_for_guest_addon_lines() {
		$lookup = function ( $id ) {
			return 345 === $id ? array(
				'event_id'      => 12,
				'status'        => 'processing',
				'is_addon'      => false,
				'customer_id'   => 0,
				'billing_email' => 'pat@example.com',
			) : null;
		};
		$guest   = array( 'fe_addon_guest' => 'yes', 'fe_event_id' => 12, 'fe_event_name' => 'Winter market' );
		$regular = array( 'fe_event_id' => 12, 'fe_date' => '2026-12-05' );

		$this->assertNull( Fe_Addon_Rules::guest_error( array( $regular ), '', 'x@example.com', $lookup ) );
		$this->assertNull( Fe_Addon_Rules::guest_error( array( $regular, $guest ), '#345', 'pat@example.com', $lookup ) );
		$this->assertSame(
			array( 'number' => '345', 'event_name' => 'Winter market' ),
			Fe_Addon_Rules::guest_error( array( $guest ), ' #345', 'x@example.com', $lookup )
		);
		$this->assertSame( '', Fe_Addon_Rules::guest_error( array( $guest ), '', 'pat@example.com', $lookup )['number'] );
	}

	private function stamped_order( array $meta, array $props ) {
		$order        = new WC_Order();
		$order->props = array_merge( $order->props, $props );
		foreach ( $meta as $k => $v ) {
			$order->update_meta_data( $k, $v );
		}
		$order->save();
		return $order;
	}

	public function test_hydrate_copies_target_fulfillment_onto_guest_line() {
		$target = $this->stamped_order(
			Fe_Meta::to_meta(
				array(
					'event_id'   => 12,
					'event_name' => 'Winter market',
					'date'       => '2026-12-05',
					'type'       => 'pickup',
					'window'     => '10:00-14:00',
					'location'   => 'Zone A',
				)
			),
			array( 'status' => 'processing', 'customer_id' => 0, 'billing_email' => 'pat@example.com' )
		);

		$new = $this->stamped_order( array(), array( 'billing_email' => 'PAT@example.com' ) );
		$new->update_meta_data( Fe_Addon_Field::INPUT_META, '#' . $target->get_id() );
		$item = new WC_Order_Item_Shipping();
		$item->add_meta_data( Fe_Meta::GROUP, 'event:12' );
		$item->add_meta_data( 'fe_event_id', 12 );
		$item->add_meta_data( 'fe_event_name', 'Winter market' );
		$item->add_meta_data( 'fe_addon_guest', 'yes' );
		$new->add_item( $item );

		Fe_Addon_Field::hydrate( $new );

		$this->assertSame( '2026-12-05', $item->get_meta( 'fe_date' ) );
		$this->assertSame( 'pickup', $item->get_meta( 'fe_type' ) );
		$this->assertSame( 'Zone A', $item->get_meta( 'fe_location' ) );
		$this->assertSame( $target->get_id(), $item->get_meta( 'fe_addon_of' ) );

		Fe_Order_Stamper::finalize( $new );
		$this->assertSame( (string) $target->get_id(), $new->get_meta( Fe_Meta::ADDON_OF ) );
		$this->assertSame( '2026-12-05', $new->get_meta( Fe_Meta::DATE ) );
	}

	public function test_hydrate_leaves_line_when_email_does_not_match() {
		$target = $this->stamped_order(
			Fe_Meta::to_meta(
				array(
					'event_id'   => 12,
					'event_name' => 'Winter market',
					'date'       => '2026-12-05',
					'type'       => 'delivery',
					'window'     => '',
					'location'   => '',
				)
			),
			array( 'status' => 'processing', 'customer_id' => 0, 'billing_email' => 'pat@example.com' )
		);
		$new = $this->stamped_order( array( Fe_Addon_Field::INPUT_META => (string) $target->get_id() ), array( 'billing_email' => 'other@example.com' ) );
		$item = new WC_Order_Item_Shipping();
		$item->add_meta_data( 'fe_event_id', 12 );
		$item->add_meta_data( 'fe_addon_guest', 'yes' );
		$new->add_item( $item );

		Fe_Addon_Field::hydrate( $new );

		$this->assertSame( '', $item->get_meta( 'fe_date' ) );
		$this->assertSame( '', $item->get_meta( 'fe_addon_of' ) );
	}
}
