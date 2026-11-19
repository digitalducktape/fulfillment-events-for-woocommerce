<?php
use PHPUnit\Framework\TestCase;

final class SplitStatusTest extends TestCase {

	public function test_status_sits_after_processing() {
		$out = Fe_Split_Status::add_to_statuses(
			array(
				'wc-pending'    => 'Pending',
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
			)
		);
		$this->assertSame( array( 'wc-pending', 'wc-processing', 'wc-fe-split', 'wc-completed' ), array_keys( $out ) );
		$this->assertSame( 'Split', $out['wc-fe-split'] );
	}

	public function test_status_is_excluded_from_payment_and_added_to_analytics_exclusions() {
		$this->assertSame( array( 'pending', 'failed' ), Fe_Split_Status::without_split( array( 'pending', 'fe-split', 'failed' ) ) );
		$this->assertSame( array( 'auto-draft', 'fe-split' ), Fe_Split_Status::with_split( array( 'auto-draft' ) ) );
		$this->assertSame( array( 'fe-split' ), Fe_Split_Status::with_split( array( 'fe-split' ) ) );
	}

	public function test_activation_fallback_keeps_defaults() {
		$GLOBALS['fe_test_options'] = array();
		Fe_Split_Status::add_to_excluded_report_option();
		$this->assertSame( array( 'pending', 'cancelled', 'failed', 'fe-split' ), get_option( 'woocommerce_excluded_report_order_statuses' ) );

		update_option( 'woocommerce_excluded_report_order_statuses', array( 'failed' ) );
		Fe_Split_Status::add_to_excluded_report_option();
		Fe_Split_Status::add_to_excluded_report_option();
		$this->assertSame( array( 'failed', 'fe-split' ), get_option( 'woocommerce_excluded_report_order_statuses' ) );
	}
}
