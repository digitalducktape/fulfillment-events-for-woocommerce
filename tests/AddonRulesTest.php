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

	public function test_not_eligible_past_date() {
		$t = $this->target( array( 'date' => '2026-12-05' ) );
		$this->assertTrue( Fe_Addon_Rules::eligible( $t, 12, '', 7, '2026-12-04' ) );
		$this->assertTrue( Fe_Addon_Rules::eligible( $t, 12, '', 7, '2026-12-05' ), 'today counts' );
		$this->assertFalse( Fe_Addon_Rules::eligible( $t, 12, '', 7, '2026-12-06' ) );
		$this->assertFalse( Fe_Addon_Rules::eligible( $this->target(), 12, '', 7, '2026-12-04' ), 'no date with today set' );
		$this->assertTrue( Fe_Addon_Rules::eligible( $t, 12, '', 7 ), 'no today: no date check' );
	}

	public function test_resolve_guest_maps_split_parent_to_child_for_event() {
		$guest   = array( 'customer_id' => 0, 'date' => '2026-12-05' );
		$targets = array(
			300 => array( 'status' => 'fe-split', 'children' => array( 301, 302 ) ),
			301 => $this->target( array_merge( $guest, array( 'event_id' => 13 ) ) ),
			302 => $this->target( $guest ),
			400 => array( 'status' => 'fe-split', 'children' => array( 301 ) ),
		);
		$lookup  = function ( $id ) use ( $targets ) {
			return isset( $targets[ $id ] ) ? $targets[ $id ] : null;
		};
		$this->assertSame( 302, Fe_Addon_Rules::resolve_guest( '#300', 12, 'pat@example.com', $lookup, '2026-11-19' ) );
		$this->assertSame( 301, Fe_Addon_Rules::resolve_guest( '300', 13, 'pat@example.com', $lookup, '2026-11-19' ) );
		$this->assertSame( 0, Fe_Addon_Rules::resolve_guest( '400', 12, 'pat@example.com', $lookup, '2026-11-19' ), 'no child for event' );
		$this->assertSame( 0, Fe_Addon_Rules::resolve_guest( '300', 12, 'x@example.com', $lookup, '2026-11-19' ), 'email' );
		$this->assertSame( 0, Fe_Addon_Rules::resolve_guest( '300', 12, 'pat@example.com', $lookup, '2026-12-06' ), 'past' );
	}

	public function test_hydrate_from_split_parent_number_targets_child() {
		$child  = $this->stamped_order(
			Fe_Meta::to_meta(
				array(
					'event_id'   => 12,
					'event_name' => 'Winter market',
					'date'       => '2026-12-05',
					'type'       => 'delivery',
					'window'     => '',
					'location'   => '',
					'parent_id'  => 1,
				)
			),
			array( 'status' => 'processing', 'customer_id' => 0, 'billing_email' => 'pat@example.com' )
		);
		$parent = $this->stamped_order( array( Fe_Meta::CHILDREN => '999,' . $child->get_id() ), array( 'status' => 'fe-split', 'billing_email' => 'pat@example.com' ) );
		$new    = $this->stamped_order( array( Fe_Addon_Field::INPUT_META => '#' . $parent->get_id() ), array( 'billing_email' => 'pat@example.com' ) );
		$item   = new WC_Order_Item_Shipping();
		$item->add_meta_data( 'fe_event_id', 12 );
		$item->add_meta_data( 'fe_addon_guest', 'yes' );
		$new->add_item( $item );

		Fe_Addon_Field::hydrate( $new );

		$this->assertSame( $child->get_id(), $item->get_meta( 'fe_addon_of' ) );
		$this->assertSame( '2026-12-05', $item->get_meta( 'fe_date' ) );
	}

	public function test_target_data_carries_date() {
		$order = $this->stamped_order(
			Fe_Meta::to_meta(
				array(
					'event_id'   => 12,
					'event_name' => 'Winter market',
					'date'       => '2026-12-05',
					'type'       => 'pickup',
					'window'     => '',
					'location'   => 'Zone A',
				)
			),
			array( 'status' => 'on-hold', 'customer_id' => 7, 'billing_email' => 'pat@example.com' )
		);
		$data  = Fe_Addon_Field::target_data( $order );
		$this->assertSame( '2026-12-05', $data['target']['date'] );
	}

	public function test_strip_fe_meta_removes_plugin_item_meta() {
		$formatted = array(
			1 => (object) array( 'key' => 'fe_date', 'value' => '2026-12-05' ),
			2 => (object) array( 'key' => 'Items', 'value' => 'Pie × 1' ),
			3 => (object) array( 'key' => 'fe_addon_guest', 'value' => 'yes' ),
			4 => (object) array( 'key' => 'coffee_size', 'value' => 'Large' ),
			5 => (object) array( 'key' => 'fe_gift_wrap', 'value' => 'Yes' ),
		);
		// Only this plugin's keys go; another plugin's fe_* key stays.
		$this->assertSame( array( 2, 4, 5 ), array_keys( Fe_Order_Stamper::strip_fe_meta( $formatted ) ) );

		$hidden = Fe_Order_Stamper::hidden_item_meta( array( '_qty' ) );
		foreach ( array( '_qty', 'fe_event_id', 'fe_event_name', 'fe_date', 'fe_type', 'fe_window', 'fe_location', 'fe_addon_of', 'fe_addon_guest' ) as $key ) {
			$this->assertContains( $key, $hidden );
		}
	}

	public function test_block_field_is_conditional_on_9_9_and_plain_before() {
		$old = Fe_Addon_Field::block_field_args( '9.8.5' );
		$this->assertFalse( $old['required'] );
		$this->assertArrayNotHasKey( 'hidden', $old );
		$this->assertSame( 'order', $old['location'] );
		$this->assertSame( 'fulfillment-events/addon-order', $old['id'] );

		$new     = Fe_Addon_Field::block_field_args( '9.9.0' );
		$rates   = $new['required']['properties']['cart']['properties']['shipping_rates'];
		$pattern = '/' . $rates['contains']['pattern'] . '/u';
		$this->assertSame( 1, preg_match( $pattern, 'fe_addon:3:guest' ) );
		$this->assertSame( 0, preg_match( $pattern, 'fe_addon:3:345' ) );
		$this->assertSame( 0, preg_match( $pattern, 'flat_rate:1' ) );
		$this->assertSame( $rates, $new['hidden']['properties']['cart']['properties']['shipping_rates']['not'] );
		$this->assertSame( array( 'shipping_rates' ), $new['required']['properties']['cart']['required'] );
	}

	public function test_guest_addon_supported_below_99_only_on_classic() {
		$this->assertTrue( Fe_Addon_Field::guest_addon_supported( '9.9.0', true, true ) );
		$this->assertTrue( Fe_Addon_Field::guest_addon_supported( '10.3.1', true, false ) );
		$this->assertTrue( Fe_Addon_Field::guest_addon_supported( '9.8.5', false, false ) );
		$this->assertFalse( Fe_Addon_Field::guest_addon_supported( '9.8.5', true, false ) );
		$this->assertFalse( Fe_Addon_Field::guest_addon_supported( '9.8.5', false, true ) );
		$this->assertFalse( Fe_Addon_Field::blocks_field_supported( '8.9.0' ) );
		$this->assertFalse( Fe_Addon_Field::blocks_field_supported( '' ) );
		$this->assertTrue( Fe_Addon_Field::blocks_field_supported( '9.9.0' ) );
	}
}
