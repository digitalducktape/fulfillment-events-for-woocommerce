<?php
use PHPUnit\Framework\TestCase;

final class ZoneGridTest extends TestCase {

	public function test_offer_text() {
		$this->assertSame( 'delivery and pickup', Fe_Event_Editor::offer_text( array( 'delivery' => true, 'pickup' => true ) ) );
		$this->assertSame( 'pickup', Fe_Event_Editor::offer_text( array( 'pickup' => true ) ) );
	}

	public function test_offer_text_includes_courier() {
		$this->assertSame( 'courier', Fe_Event_Editor::offer_text( array( 'courier' => true ) ) );
		$this->assertSame( 'delivery and courier', Fe_Event_Editor::offer_text( array( 'delivery' => true, 'courier' => true ) ) );
		$this->assertSame( 'delivery, pickup and courier', Fe_Event_Editor::offer_text( array( 'delivery' => true, 'pickup' => true, 'courier' => true ) ) );
	}
}
