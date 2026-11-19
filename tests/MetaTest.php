<?php
use PHPUnit\Framework\TestCase;

class MetaTest extends TestCase {

	private function fulfillment() {
		return array(
			'event_id'   => 12,
			'event_name' => 'Winter market',
			'date'       => '2026-12-05',
			'type'       => 'delivery',
			'window'     => '10:00-14:00',
			'location'   => 'Zone A',
			'addon_of'   => null,
			'parent_id'  => 345,
		);
	}

	public function test_round_trip_to_and_from_meta() {
		$f    = $this->fulfillment();
		$meta = Fe_Meta::to_meta( $f );

		$this->assertSame( '1', $meta['_fe_contract'] );
		$this->assertSame( '12', $meta['_fe_event_id'] );
		$this->assertArrayNotHasKey( '_fe_addon_of', $meta );
		$this->assertSame( $f, Fe_Meta::from_meta( $meta ) );
	}

	public function test_from_meta_returns_null_without_contract() {
		$meta = Fe_Meta::to_meta( $this->fulfillment() );
		unset( $meta['_fe_contract'] );
		$this->assertNull( Fe_Meta::from_meta( $meta ) );
		$this->assertNull( Fe_Meta::from_meta( array() ) );
	}

	public function test_from_meta_rejects_unknown_type() {
		$f         = $this->fulfillment();
		$f['type'] = 'teleport';
		$this->assertNull( Fe_Meta::from_meta( Fe_Meta::to_meta( $f ) ) );
	}
}
