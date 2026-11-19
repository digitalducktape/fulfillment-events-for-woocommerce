<?php
use PHPUnit\Framework\TestCase;

class EmailsTest extends TestCase {

	private $parent = array( '_fe_will_split' => 'yes' );
	private $child  = array( '_fe_parent_id' => '12' );

	public function test_parent_suppresses_customer_and_staff_emails() {
		$this->assertTrue( Fe_Emails::suppress( 'customer_processing_order', $this->parent, 'yes' ) );
		$this->assertTrue( Fe_Emails::suppress( 'customer_on_hold_order', $this->parent, 'yes' ) );
		$this->assertTrue( Fe_Emails::suppress( 'new_order', $this->parent, 'yes' ) );
	}

	public function test_child_suppresses_customer_emails() {
		$this->assertTrue( Fe_Emails::suppress( 'customer_processing_order', $this->child, 'yes' ) );
		$this->assertTrue( Fe_Emails::suppress( 'customer_on_hold_order', $this->child, 'yes' ) );
	}

	public function test_child_staff_email_follows_setting() {
		$this->assertFalse( Fe_Emails::suppress( 'new_order', $this->child, 'yes' ) );
		$this->assertTrue( Fe_Emails::suppress( 'new_order', $this->child, 'no' ) );
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
