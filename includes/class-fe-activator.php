<?php
/**
 * Activation: creates the plugin's tables and tracks the schema version.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Creates and upgrades the database schema.
 */
class Fe_Activator {

	const DB_VERSION_OPTION = 'fe_db_version';

	/**
	 * CREATE TABLE statements for dbDelta(). Pure: no WordPress calls.
	 *
	 * @param string $prefix  Table prefix, e.g. "wp_".
	 * @param string $charset Charset, e.g. "utf8mb4" (a full COLLATE clause is also accepted).
	 * @return string[]
	 */
	public static function schema_sql( $prefix, $charset ) {
		$charset_sql = 'DEFAULT CHARACTER SET ' . $charset;
		if ( false !== strpos( $charset, ' ' ) ) {
			$charset_sql = $charset;
		}

		$event_dates = "CREATE TABLE {$prefix}fe_event_dates (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT(20) UNSIGNED NOT NULL,
  date DATE NOT NULL,
  delivery TINYINT(1) NOT NULL DEFAULT 0,
  delivery_window VARCHAR(100) NOT NULL DEFAULT '',
  pickup TINYINT(1) NOT NULL DEFAULT 0,
  pickup_window VARCHAR(100) NOT NULL DEFAULT '',
  courier TINYINT(1) NOT NULL DEFAULT 1,
  location_id BIGINT(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY event_id (event_id)
) {$charset_sql};";

		$locations = "CREATE TABLE {$prefix}fe_locations (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL DEFAULT '',
  address TEXT DEFAULT NULL,
  instructions TEXT DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) {$charset_sql};";

		$zone_rules = "CREATE TABLE {$prefix}fe_zone_rules (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT(20) UNSIGNED NOT NULL,
  zone_id BIGINT(20) UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL,
  date DATE DEFAULT NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'inherit',
  cost DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_zone_type_date (event_id,zone_id,type,date)
) {$charset_sql};";

		return array( $event_dates, $locations, $zone_rules );
	}

	/**
	 * Plugin activation hook: create tables and record the schema version.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::DB_VERSION_OPTION, FE_DB_VERSION );

		if ( class_exists( 'Fe_Split_Status' ) ) {
			Fe_Split_Status::add_to_excluded_report_option();
		}
	}

	/**
	 * Re-run the schema when the stored version is behind FE_DB_VERSION.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < (int) FE_DB_VERSION ) {
			self::activate();
		}
	}
}
