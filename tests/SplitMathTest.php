<?php
use PHPUnit\Framework\TestCase;

final class SplitMathTest extends TestCase {

	public function test_prorate_sums_exactly() {
		$out = Fe_Split_Math::prorate( 1000, array( 'a' => 333, 'b' => 333, 'c' => 334 ) );
		$this->assertSame( 1000, array_sum( $out ) );
		$this->assertSame( array( 'a', 'b', 'c' ), array_keys( $out ) );
		$this->assertSame( 333, $out['b'] );
		$this->assertSame( 334, $out['c'] );
	}

	public function test_prorate_remainder_goes_to_first() {
		$out = Fe_Split_Math::prorate( 100, array( 'x' => 1, 'y' => 1, 'z' => 1 ) );
		$this->assertSame( array( 'x' => 34, 'y' => 33, 'z' => 33 ), $out );

		$neg = Fe_Split_Math::prorate( -100, array( 'x' => 1, 'y' => 1, 'z' => 1 ) );
		$this->assertSame( array( 'x' => -34, 'y' => -33, 'z' => -33 ), $neg );
	}

	public function test_prorate_zero_weights() {
		$this->assertSame( array( 'a' => 500, 'b' => 0 ), Fe_Split_Math::prorate( 500, array( 'a' => 0, 'b' => 0 ) ) );
		$this->assertSame( array(), Fe_Split_Math::prorate( 500, array() ) );
		$this->assertSame( array( 'a' => 0, 'b' => 0 ), Fe_Split_Math::prorate( 0, array( 'a' => 3, 'b' => 7 ) ) );
	}

	public function test_prorate_ignores_negative_weights() {
		$this->assertSame( array( 'a' => 0, 'b' => 250 ), Fe_Split_Math::prorate( 250, array( 'a' => -10, 'b' => 10 ) ) );
	}

	public function test_to_cents_rounds_float_noise() {
		$this->assertSame( 1999, Fe_Split_Math::to_cents( 19.99 ) );
		$this->assertSame( 30, Fe_Split_Math::to_cents( 0.1 + 0.2 ) );
		$this->assertSame( -1050, Fe_Split_Math::to_cents( '-10.50' ) );
	}
}
