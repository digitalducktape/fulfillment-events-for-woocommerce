<?php
use PHPUnit\Framework\TestCase;

final class ActivatorTest extends TestCase {

	public function test_schema_creates_three_tables_with_prefix() {
		$sql = Fe_Activator::schema_sql( 'wp_', 'utf8mb4' );

		$this->assertCount( 3, $sql );
		$joined = implode( "\n", $sql );
		$this->assertStringContainsString( 'CREATE TABLE wp_fe_event_dates', $joined );
		$this->assertStringContainsString( 'CREATE TABLE wp_fe_locations', $joined );
		$this->assertStringContainsString( 'CREATE TABLE wp_fe_zone_rules', $joined );
		$this->assertStringContainsString( 'UNIQUE KEY event_zone_type_date (event_id,zone_id,type,date)', $joined );
		$this->assertStringContainsString( 'utf8mb4', $joined );
	}

	public function test_schema_respects_custom_prefix() {
		$joined = implode( "\n", Fe_Activator::schema_sql( 'site2_', 'utf8' ) );
		$this->assertStringContainsString( 'CREATE TABLE site2_fe_locations', $joined );
		$this->assertStringNotContainsString( 'wp_fe_', $joined );
	}
}
