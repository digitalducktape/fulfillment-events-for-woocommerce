<?php
use PHPUnit\Framework\TestCase;

final class LocationDefaultTest extends TestCase {

	public function test_first_location_becomes_default() {
		$this->assertTrue( Fe_Location_Repo::resolve_default_flag( false, null, 0 ) );
	}

	public function test_unsetting_sole_default_is_refused() {
		$this->assertTrue( Fe_Location_Repo::resolve_default_flag( false, 4, 4 ) );
	}

	public function test_non_default_save_stays_non_default_when_another_is_default() {
		$this->assertFalse( Fe_Location_Repo::resolve_default_flag( false, 4, 7 ) );
		$this->assertFalse( Fe_Location_Repo::resolve_default_flag( false, 4, 0 ) );
		$this->assertTrue( Fe_Location_Repo::resolve_default_flag( true, 4, 7 ) );
	}

	public function test_deleting_default_promotes_lowest_remaining_id() {
		$this->assertSame( 3, Fe_Location_Repo::next_default_after_delete( array( 9, 3, 5 ), 2, 2 ) );
		$this->assertNull( Fe_Location_Repo::next_default_after_delete( array(), 2, 2 ) );
	}

	public function test_deleting_non_default_leaves_default_unchanged() {
		$this->assertSame( 2, Fe_Location_Repo::next_default_after_delete( array( 2, 5 ), 2, 9 ) );
	}
}
