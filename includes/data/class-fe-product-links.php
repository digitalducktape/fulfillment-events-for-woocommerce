<?php
/**
 * Links between events and products or product categories.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event ids are stored as an array of ints in `_fe_events` meta on products
 * and on product_cat terms.
 */
class Fe_Product_Links {

	const META_KEY = '_fe_events';

	/**
	 * Casts a stored value to a unique list of positive ints.
	 *
	 * @param mixed $value Stored value.
	 * @return int[]
	 */
	private static function clean( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );
	}

	/**
	 * Events a product belongs to: its own links plus those of every
	 * product_cat term. A variation uses its parent's links and categories.
	 *
	 * @param int $product_id Product or variation id.
	 * @return int[]
	 */
	public static function event_ids_for_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$parent = (int) wp_get_post_parent_id( $product_id );
			if ( $parent > 0 ) {
				$product_id = $parent;
			}
		}

		$ids = self::clean( get_post_meta( $product_id, self::META_KEY, true ) );

		$term_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_array( $term_ids ) ) {
			foreach ( $term_ids as $term_id ) {
				$ids = array_merge( $ids, self::clean( get_term_meta( (int) $term_id, self::META_KEY, true ) ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sets a product's own event links.
	 *
	 * @param int   $product_id Product id.
	 * @param int[] $event_ids  Event ids.
	 */
	public static function set_for_product( $product_id, array $event_ids ) {
		self::store( 'post', (int) $product_id, $event_ids );
	}

	/**
	 * Sets a product category's event links.
	 *
	 * @param int   $term_id   Term id.
	 * @param int[] $event_ids Event ids.
	 */
	public static function set_for_term( $term_id, array $event_ids ) {
		self::store( 'term', (int) $term_id, $event_ids );
	}

	/**
	 * Writes or removes the meta.
	 *
	 * @param string $kind      post|term.
	 * @param int    $object_id Object id.
	 * @param int[]  $event_ids Event ids.
	 */
	private static function store( $kind, $object_id, array $event_ids ) {
		$ids = self::clean( $event_ids );
		if ( 'term' === $kind ) {
			$ids ? update_term_meta( $object_id, self::META_KEY, $ids ) : delete_term_meta( $object_id, self::META_KEY );
		} else {
			$ids ? update_post_meta( $object_id, self::META_KEY, $ids ) : delete_post_meta( $object_id, self::META_KEY );
		}
	}
}
