<?php
/**
 * Order meta contract that downstream apps read.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Meta key constants plus conversion between a fulfillment array and meta.
 */
class Fe_Meta {

	const EVENT_ID   = '_fe_event_id';
	const EVENT_NAME = '_fe_event_name';
	const DATE       = '_fe_date';
	const TYPE       = '_fe_type';
	const WINDOW     = '_fe_window';
	const LOCATION   = '_fe_location';
	const ADDON_OF   = '_fe_addon_of';
	const PARENT_ID  = '_fe_parent_id';
	const CONTRACT   = '_fe_contract';

	const CONTRACT_VERSION = 1;

	// Internal keys, not part of the public contract.
	const WILL_SPLIT = '_fe_will_split';
	const SPLIT_DONE = '_fe_split_done';
	const CHILDREN   = '_fe_children';

	// Order item meta.
	const GROUP = '_fe_group';

	const TYPES = array( 'delivery', 'pickup', 'courier' );

	/**
	 * Contract keys read from an order, in the order they are stored.
	 *
	 * @return string[]
	 */
	public static function contract_keys() {
		return array(
			self::CONTRACT,
			self::EVENT_ID,
			self::EVENT_NAME,
			self::DATE,
			self::TYPE,
			self::WINDOW,
			self::LOCATION,
			self::ADDON_OF,
			self::PARENT_ID,
		);
	}

	/**
	 * Converts a fulfillment array to order meta (all values strings).
	 * Null addon_of/parent_id are omitted.
	 *
	 * @param array $f Fulfillment.
	 * @return array<string,string>
	 */
	public static function to_meta( array $f ) {
		$meta = array(
			self::CONTRACT   => (string) self::CONTRACT_VERSION,
			self::EVENT_ID   => (string) (int) $f['event_id'],
			self::EVENT_NAME => (string) $f['event_name'],
			self::DATE       => (string) $f['date'],
			self::TYPE       => (string) $f['type'],
			self::WINDOW     => (string) $f['window'],
			self::LOCATION   => (string) $f['location'],
		);
		if ( isset( $f['addon_of'] ) ) {
			$meta[ self::ADDON_OF ] = (string) (int) $f['addon_of'];
		}
		if ( isset( $f['parent_id'] ) ) {
			$meta[ self::PARENT_ID ] = (string) (int) $f['parent_id'];
		}
		return $meta;
	}

	/**
	 * Converts order meta back to a fulfillment array.
	 *
	 * @param array $meta Meta values keyed by meta key.
	 * @return array|null Null without a contract marker or with an unknown type.
	 */
	public static function from_meta( array $meta ) {
		if ( empty( $meta[ self::CONTRACT ] ) ) {
			return null;
		}
		$type = isset( $meta[ self::TYPE ] ) ? (string) $meta[ self::TYPE ] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}
		$get = function ( $key ) use ( $meta ) {
			return isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
		};
		$opt = function ( $key ) use ( $meta ) {
			return isset( $meta[ $key ] ) && '' !== $meta[ $key ] ? (int) $meta[ $key ] : null;
		};
		return array(
			'event_id'   => (int) $get( self::EVENT_ID ),
			'event_name' => $get( self::EVENT_NAME ),
			'date'       => $get( self::DATE ),
			'type'       => $type,
			'window'     => $get( self::WINDOW ),
			'location'   => $get( self::LOCATION ),
			'addon_of'   => $opt( self::ADDON_OF ),
			'parent_id'  => $opt( self::PARENT_ID ),
		);
	}
}
