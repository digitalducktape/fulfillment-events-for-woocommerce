<?php
use PHPUnit\Framework\TestCase;

class EmailsTest extends TestCase {

	private $parent = array( '_fe_will_split' => 'yes' );
	private $child  = array( '_fe_parent_id' => '12' );

	public function test_parent_suppresses_processing_but_keeps_on_hold() {
		$this->assertTrue( Fe_Emails::suppress( 'customer_processing_order', $this->parent, 'yes' ) );
		// The on-hold email carries bank transfer / cheque instructions.
		$this->assertFalse( Fe_Emails::suppress( 'customer_on_hold_order', $this->parent, 'yes' ) );
		$this->assertFalse( Fe_Emails::suppress( 'customer_on_hold_order', $this->parent, 'no' ) );
	}

	public function test_child_suppresses_customer_emails() {
		$this->assertTrue( Fe_Emails::suppress( 'customer_processing_order', $this->child, 'yes' ) );
		$this->assertTrue( Fe_Emails::suppress( 'customer_on_hold_order', $this->child, 'yes' ) );
	}

	public function test_staff_per_child_on_sends_children_not_parent() {
		$this->assertTrue( Fe_Emails::suppress( 'new_order', $this->parent, 'yes' ) );
		$this->assertFalse( Fe_Emails::suppress( 'new_order', $this->child, 'yes' ) );
	}

	public function test_staff_per_child_off_sends_parent_once_not_children() {
		$this->assertFalse( Fe_Emails::suppress( 'new_order', $this->parent, 'no' ) );
		$this->assertTrue( Fe_Emails::suppress( 'new_order', $this->child, 'no' ) );
	}

	public function test_catch_up_after_rollback() {
		$this->assertSame( array( 'WC_Email_Customer_Processing_Order', 'WC_Email_New_Order' ), Fe_Emails::catch_up_emails( 'processing', 'yes', false ) );
		// On-hold was never suppressed for the parent; the new-order email was not either with per-child off.
		$this->assertSame( array(), Fe_Emails::catch_up_emails( 'on-hold', 'no', false ) );
		$this->assertSame( array( 'WC_Email_New_Order' ), Fe_Emails::catch_up_emails( 'on-hold', 'yes', false ) );
		$this->assertSame( array( 'WC_Email_Customer_Processing_Order' ), Fe_Emails::catch_up_emails( 'processing', 'no', false ) );
	}

	public function test_failed_parent_is_not_emailed_twice() {
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->update_meta_data( Fe_Meta::SPLIT_FAILED, 'yes' );
		$order->save();
		Fe_Emails::on_split_failed( $order, array(), new RuntimeException( 'x' ) );
		$this->assertSame( 'yes', $order->get_meta( Fe_Emails::FAILED_EMAILED ) );
		$this->assertSame( array(), Fe_Emails::catch_up_emails( 'processing', 'yes', true ) );
	}

	public function test_plain_order_suppresses_nothing() {
		$plain = array( '_fe_will_split' => '', '_fe_parent_id' => '' );
		foreach ( array( 'customer_processing_order', 'customer_on_hold_order', 'new_order' ) as $id ) {
			$this->assertFalse( Fe_Emails::suppress( $id, $plain, 'no' ) );
			$this->assertFalse( Fe_Emails::suppress( $id, array(), 'no' ) );
		}
	}

	public function test_unrelated_email_never_suppressed() {
		$this->assertFalse( Fe_Emails::suppress( 'customer_completed_order', $this->parent, 'no' ) );
		$this->assertFalse( Fe_Emails::suppress( 'customer_refunded_order', $this->child, 'no' ) );
		$this->assertFalse( Fe_Emails::suppress( 'fe_split_confirmation', $this->parent, 'no' ) );
	}

	public function test_split_failed_parent_suppresses_nothing() {
		$meta = array( '_fe_will_split' => 'yes', '_fe_split_failed' => 'yes' );
		foreach ( array( 'customer_processing_order', 'customer_on_hold_order', 'new_order' ) as $id ) {
			$this->assertFalse( Fe_Emails::suppress( $id, $meta, 'no' ) );
		}
	}
}
