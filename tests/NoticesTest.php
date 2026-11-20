<?php
use PHPUnit\Framework\TestCase;

class NoticesTest extends TestCase {

	public function test_looks_like_ticket_matches_class_names() {
		$this->assertTrue( Fe_Notices::looks_like_ticket( 'Sourdough Workshop' ) );
		$this->assertTrue( Fe_Notices::looks_like_ticket( 'beginner CLASS' ) );
		$this->assertTrue( Fe_Notices::looks_like_ticket( 'Concert tickets' ) );
		$this->assertTrue( Fe_Notices::looks_like_ticket( 'Piano lesson (1 hr)' ) );
	}

	public function test_looks_like_ticket_ignores_other_names() {
		$this->assertFalse( Fe_Notices::looks_like_ticket( 'Classic loaf' ) );
		$this->assertFalse( Fe_Notices::looks_like_ticket( 'Upper classman mug' ) );
		$this->assertFalse( Fe_Notices::looks_like_ticket( 'Cookie box' ) );
		$this->assertFalse( Fe_Notices::looks_like_ticket( '' ) );
	}
}
