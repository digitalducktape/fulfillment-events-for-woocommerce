<?php
use PHPUnit\Framework\TestCase;

final class ZoneRulesTest extends TestCase {

	private function rule( $zone, $type, $date, $mode, $cost ) {
		return array(
			'zone_id' => $zone,
			'type'    => $type,
			'date'    => $date,
			'mode'    => $mode,
			'cost'    => $cost,
		);
	}

	public function test_inherit_uses_method_default() {
		$r = Fe_Zone_Rules::resolve( array(), 1, 'delivery', '2026-12-10', 9.0 );
		$this->assertTrue( $r['enabled'] );
		$this->assertSame( 9.0, $r['cost'] );
	}

	public function test_date_specific_override_beats_event_wide() {
		$rules = array(
			$this->rule( 1, 'delivery', null, 'on', 20.0 ),
			$this->rule( 1, 'delivery', '2026-12-18', 'on', 25.0 ),
		);
		$this->assertSame( 25.0, Fe_Zone_Rules::resolve( $rules, 1, 'delivery', '2026-12-18', 9.0 )['cost'] );
		$this->assertSame( 20.0, Fe_Zone_Rules::resolve( $rules, 1, 'delivery', '2026-12-10', 9.0 )['cost'] );
	}

	public function test_off_disables() {
		$rules = array( $this->rule( 1, 'delivery', null, 'off', null ) );
		$this->assertFalse( Fe_Zone_Rules::resolve( $rules, 1, 'delivery', '2026-12-10', 9.0 )['enabled'] );
	}

	public function test_on_with_null_cost_uses_default() {
		$rules = array( $this->rule( 1, 'pickup', null, 'on', null ) );
		$r     = Fe_Zone_Rules::resolve( $rules, 1, 'pickup', '2026-12-10', 4.5 );
		$this->assertTrue( $r['enabled'] );
		$this->assertSame( 4.5, $r['cost'] );
	}

	public function test_rule_for_other_zone_is_ignored() {
		$rules = array( $this->rule( 2, 'delivery', null, 'off', null ) );
		$r     = Fe_Zone_Rules::resolve( $rules, 1, 'delivery', '2026-12-10', 9.0 );
		$this->assertTrue( $r['enabled'] );
		$this->assertSame( 9.0, $r['cost'] );
	}

	public function test_rule_for_other_type_is_ignored() {
		$rules = array( $this->rule( 1, 'pickup', null, 'off', null ) );
		$this->assertTrue( Fe_Zone_Rules::resolve( $rules, 1, 'delivery', '2026-12-10', 9.0 )['enabled'] );
	}
}
