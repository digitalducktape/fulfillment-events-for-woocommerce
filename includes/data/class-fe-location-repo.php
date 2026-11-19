<?php
/**
 * Pickup/delivery location storage.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Reads and writes rows of the fe_locations table. Exactly one location is
 * the default.
 */
class Fe_Location_Repo {

	/**
	 * Name of the locations table.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fe_locations';
	}

	/**
	 * Shapes a database row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function shape( array $row ) {
		return array(
			'id'           => (int) $row['id'],
			'name'         => (string) $row['name'],
			'address'      => (string) $row['address'],
			'instructions' => (string) $row['instructions'],
			'is_default'   => (bool) $row['is_default'],
		);
	}

	/**
	 * All locations, default first.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT id, name, address, instructions, is_default FROM {$table} ORDER BY is_default DESC, name ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * One location.
	 *
	 * @param int $id Location id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, address, instructions, is_default FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Id of the default location.
	 *
	 * @return int|null
	 */
	public static function default_id() {
		global $wpdb;
		$table = self::table();
		$id    = $wpdb->get_var( "SELECT id FROM {$table} WHERE is_default = 1 ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? (int) $id : null;
	}

	/**
	 * Inserts or updates a location. Saving with is_default clears the flag
	 * on every other location.
	 *
	 * @param array $data {id?, name, address, instructions, is_default}.
	 * @return int Location id.
	 */
	public static function save( array $data ) {
		global $wpdb;
		$table  = self::table();
		$id     = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$fields = array(
			'name'         => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			'address'      => isset( $data['address'] ) ? sanitize_textarea_field( (string) $data['address'] ) : '',
			'instructions' => isset( $data['instructions'] ) ? sanitize_textarea_field( (string) $data['instructions'] ) : '',
			'is_default'   => self::resolve_default_flag( ! empty( $data['is_default'] ), self::default_id(), $id ) ? 1 : 0,
		);
		$formats = array( '%s', '%s', '%s', '%d' );

		if ( $id > 0 ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $fields, $formats );
			$id = (int) $wpdb->insert_id;
		}

		if ( $fields['is_default'] && $id > 0 ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = 0 WHERE id <> %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $id;
	}

	/**
	 * Deletes a location and detaches it from event dates that used it.
	 *
	 * @param int $id Location id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id      = (int) $id;
		$table   = self::table();
		$current = self::default_id();
		$wpdb->update( $wpdb->prefix . 'fe_event_dates', array( 'location_id' => null ), array( 'location_id' => $id ), array( '%d' ), array( '%d' ) );
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		$remaining = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next      = self::next_default_after_delete( $remaining, $current, $id );
		if ( null !== $next && $next !== $current ) {
			$wpdb->update( $table, array( 'is_default' => 1 ), array( 'id' => $next ), array( '%d' ), array( '%d' ) );
		}
	}

	/**
	 * Pure: the is_default value a save should store. There is always exactly
	 * one default: the first location becomes it, and the only default cannot
	 * be unset (set another location as default instead).
	 *
	 * @param bool     $requested          Whether the caller asked for default.
	 * @param int|null $current_default_id Current default id, if any.
	 * @param int      $saving_id          Id being saved (0 for a new row).
	 * @return bool
	 */
	public static function resolve_default_flag( $requested, $current_default_id, $saving_id ) {
		if ( $requested || null === $current_default_id ) {
			return true;
		}
		return (int) $current_default_id === (int) $saving_id && (int) $saving_id > 0;
	}

	/**
	 * Pure: which location is the default after a delete.
	 *
	 * @param int[]    $remaining_ids      Ids left after the delete.
	 * @param int|null $current_default_id Default id before the delete.
	 * @param int      $deleted_id         Deleted id.
	 * @return int|null
	 */
	public static function next_default_after_delete( array $remaining_ids, $current_default_id, $deleted_id ) {
		if ( null !== $current_default_id && (int) $current_default_id !== (int) $deleted_id ) {
			return (int) $current_default_id;
		}
		$remaining_ids = array_map( 'intval', $remaining_ids );
		return $remaining_ids ? min( $remaining_ids ) : null;
	}
}
