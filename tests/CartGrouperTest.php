<?php
use PHPUnit\Framework\TestCase;

final class CartGrouperTest extends TestCase {

	private function item( $key, $event, $ship = true ) {
		return array(
			'key'            => $key,
			'needs_shipping' => $ship,
			'event_id'       => $event,
		);
	}

	public function test_items_split_into_event_groups_and_regular() {
		$groups = Fe_Cart_Grouper::group(
			array(
				$this->item( 'a', null ),
				$this->item( 'b', 5 ),
				$this->item( 'c', 9 ),
				$this->item( 'd', 5 ),
			)
		);
		$this->assertSame(
			array(
				array( 'group' => 'event:5', 'event_id' => 5, 'item_keys' => array( 'b', 'd' ) ),
				array( 'group' => 'event:9', 'event_id' => 9, 'item_keys' => array( 'c' ) ),
				array( 'group' => 'regular', 'event_id' => null, 'item_keys' => array( 'a' ) ),
			),
			$groups
		);
	}

	public function test_virtual_items_are_not_grouped() {
		$groups = Fe_Cart_Grouper::group( array( $this->item( 'a', 5, false ), $this->item( 'b', null, false ) ) );
		$this->assertSame( array(), $groups );
	}

	public function test_cart_without_event_items_yields_single_regular_group() {
		$groups = Fe_Cart_Grouper::group( array( $this->item( 'a', null ), $this->item( 'b', null ) ) );
		$this->assertCount( 1, $groups );
		$this->assertSame( 'regular', $groups[0]['group'] );
		$this->assertSame( array( 'a', 'b' ), $groups[0]['item_keys'] );
	}
}
