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

	/**
	 * Term ids whose links include an event.
	 *
	 * @param int $event_id Event id.
	 * @return int[]
	 */
	public static function term_ids_for_event( $event_id ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1000,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_KEY,
						'value'   => self::needle( $event_id ),
						'compare' => 'LIKE',
					),
				),
			)
		);
		$out = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
			if ( in_array( (int) $event_id, self::clean( get_term_meta( (int) $term_id, self::META_KEY, true ) ), true ) ) {
				$out[] = (int) $term_id;
			}
		}
		return $out;
	}

	/**
	 * Product ids whose own links include an event.
	 *
	 * @param int $event_id Event id.
	 * @return int[]
	 */
	public static function product_ids_for_event( $event_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1000,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_KEY,
						'value'   => self::needle( $event_id ),
						'compare' => 'LIKE',
					),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( in_array( (int) $event_id, self::clean( get_post_meta( (int) $id, self::META_KEY, true ) ), true ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Serialized form of an int inside a stored array, for a LIKE prefilter.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private static function needle( $event_id ) {
		return 'i:' . (int) $event_id . ';';
	}

	/**
	 * Makes an event's links exactly the given categories and products,
	 * adding it where missing and removing it everywhere else.
	 *
	 * @param int   $event_id     Event id.
	 * @param int[] $term_ids     Categories that should link the event.
	 * @param int[] $product_ids  Products that should link the event.
	 */
	public static function sync_event( $event_id, array $term_ids, array $product_ids ) {
		$event_id    = (int) $event_id;
		$term_ids    = self::clean( $term_ids );
		$product_ids = self::clean( $product_ids );

		foreach ( array_unique( array_merge( $term_ids, self::term_ids_for_event( $event_id ) ) ) as $term_id ) {
			$current = self::clean( get_term_meta( (int) $term_id, self::META_KEY, true ) );
			self::set_for_term( $term_id, self::with_event( $current, $event_id, in_array( (int) $term_id, $term_ids, true ) ) );
		}
		foreach ( array_unique( array_merge( $product_ids, self::product_ids_for_event( $event_id ) ) ) as $product_id ) {
			$current = self::clean( get_post_meta( (int) $product_id, self::META_KEY, true ) );
			self::set_for_product( $product_id, self::with_event( $current, $event_id, in_array( (int) $product_id, $product_ids, true ) ) );
		}
	}

	/**
	 * Adds or removes an event id in a list.
	 *
	 * @param int[] $ids      Current ids.
	 * @param int   $event_id Event id.
	 * @param bool  $present  Whether it should be in the list.
	 * @return int[]
	 */
	private static function with_event( array $ids, $event_id, $present ) {
		$ids = array_values( array_diff( $ids, array( $event_id ) ) );
		if ( $present ) {
			$ids[] = $event_id;
		}
		return $ids;
	}
}
