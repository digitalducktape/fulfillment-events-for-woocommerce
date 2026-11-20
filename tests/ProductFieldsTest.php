<?php
use PHPUnit\Framework\TestCase;

final class ProductFieldsTest extends TestCase {

	public function test_limit_text() {
		$this->assertSame( 'Holiday Pre-order 2026: only Dec 18', Fe_Product_Fields::limit_text( 'Holiday Pre-order 2026', array( '2026-12-18' ) ) );
		$this->assertSame( 'Holiday Pre-order 2026: only Dec 10 and Dec 18', Fe_Product_Fields::limit_text( 'Holiday Pre-order 2026', array( '2026-12-18', '2026-12-10' ) ) );
		$this->assertSame( 'Holiday Pre-order 2026: no dates', Fe_Product_Fields::limit_text( 'Holiday Pre-order 2026', array() ) );
	}

	public function test_exception_count_text() {
		$this->assertSame( '1 product exception', Fe_Event_Editor::exception_count_text( 1 ) );
		$this->assertSame( '3 product exceptions', Fe_Event_Editor::exception_count_text( 3 ) );
		$this->assertSame( '', Fe_Event_Editor::exception_count_text( 0 ) );
	}
}
