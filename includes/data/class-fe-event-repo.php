<?php
/**
 * Event storage: the fe_event post type plus the fe_event_dates table.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Reads and writes events and their dates.
 */
class Fe_Event_Repo {

	const POST_TYPE   = 'fe_event';
	const META_OPEN   = '_fe_sales_open';
	const META_CLOSE  = '_fe_sales_close';
	const MAX_EVENTS  = 200;

	/**
	 * Registers the fe_event post type. Hooked to init.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Events', 'fulfillment-events-for-woocommerce' ),
					'singular_name' => __( 'Event', 'fulfillment-events-for-woocommerce' ),
					'add_new_item'  => __( 'Add event', 'fulfillment-events-for-woocommerce' ),
					'edit_item'     => __( 'Edit event', 'fulfillment-events-for-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'fe-fulfillment', // Parent menu is added by Fe_Admin.
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_posts'             => 'manage_woocommerce',
					'edit_others_posts'      => 'manage_woocommerce',
					'edit_private_posts'     => 'manage_woocommerce',
					'edit_published_posts'   => 'manage_woocommerce',
					'publish_posts'          => 'manage_woocommerce',
					'read_private_posts'     => 'manage_woocommerce',
					'delete_posts'           => 'manage_woocommerce',
					'delete_others_posts'    => 'manage_woocommerce',
					'delete_private_posts'   => 'manage_woocommerce',
					'delete_published_posts' => 'manage_woocommerce',
					'create_posts'           => 'manage_woocommerce',
				),
				'map_meta_cap'    => true,
				'rewrite'         => false,
			)
		);
	}

	/**
	 * Name of the dates table.
	 *
	 * @return string
	 */
	private static function dates_table() {
		global $wpdb;
		return $wpdb->prefix . 'fe_event_dates';
	}

	/**
	 * Returns one event with its dates.
	 *
	 * @param int $id Event post id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$dates = self::dates_for_events( array( (int) $post->ID ) );
		return self::shape( $post, isset( $dates[ $post->ID ] ) ? $dates[ $post->ID ] : array() );
	}

	/**
	 * Shapes a post and its date rows into the event array.
	 *
	 * @param WP_Post $post  Event post.
	 * @param array   $dates Date rows.
	 * @return array
	 */
	private static function shape( $post, array $dates ) {
		$open  = (string) get_post_meta( $post->ID, self::META_OPEN, true );
		$close = (string) get_post_meta( $post->ID, self::META_CLOSE, true );
		return array(
			'id'          => (int) $post->ID,
			'title'       => (string) $post->post_title,
			'status'      => (string) $post->post_status,
			'sales_open'  => '' === $open ? null : $open,
			'sales_close' => '' === $close ? null : $close,
			'dates'       => $dates,
		);
	}

	/**
	 * Date rows for several events in one query.
	 *
	 * @param int[] $event_ids Event ids.
	 * @return array<int,array> Date rows grouped by event id, ascending by date.
	 */
	private static function dates_for_events( array $event_ids ) {
		global $wpdb;
		$out = array();
		if ( ! $event_ids ) {
			return $out;
		}
		$event_ids    = array_map( 'intval', $event_ids );
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		$table        = self::dates_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql  = $wpdb->prepare( "SELECT event_id, date, delivery, delivery_window, pickup, pickup_window, location_id FROM {$table} WHERE event_id IN ({$placeholders}) ORDER BY date ASC", $event_ids );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['event_id'] ][] = array(
				'date'            => (string) $row['date'],
				'delivery'        => (bool) $row['delivery'],
				'delivery_window' => (string) $row['delivery_window'],
				'pickup'          => (bool) $row['pickup'],
				'pickup_window'   => (string) $row['pickup_window'],
				'location_id'     => null === $row['location_id'] ? null : (int) $row['location_id'],
			);
		}
		return $out;
	}

	/**
	 * Pure: cleans submitted date rows. Drops rows with an invalid date or
	 * with neither delivery nor pickup, dedupes by date (last row wins) and
	 * sorts ascending.
	 *
	 * @param array $raw Raw rows.
	 * @return array[] Rows of {date, delivery, delivery_window, pickup, pickup_window, location_id}.
	 */
	public static function normalize_dates( array $raw ) {
		$by_date = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date = isset( $row['date'] ) ? trim( (string) $row['date'] ) : '';
			if ( ! self::is_valid_date( $date ) ) {
				continue;
			}
			$delivery = ! empty( $row['delivery'] );
			$pickup   = ! empty( $row['pickup'] );
			if ( ! $delivery && ! $pickup ) {
				continue;
			}
			$location = isset( $row['location_id'] ) ? (int) $row['location_id'] : 0;
			$by_date[ $date ] = array(
				'date'            => $date,
				'delivery'        => $delivery,
				'delivery_window' => $delivery && isset( $row['delivery_window'] ) ? trim( (string) $row['delivery_window'] ) : '',
				'pickup'          => $pickup,
				'pickup_window'   => $pickup && isset( $row['pickup_window'] ) ? trim( (string) $row['pickup_window'] ) : '',
				'location_id'     => $location > 0 ? $location : null,
			);
		}
		ksort( $by_date );
		return array_values( $by_date );
	}

	/**
	 * Whether a string is a real calendar date in Y-m-d.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	public static function is_valid_date( $date ) {
		$dt = DateTime::createFromFormat( '!Y-m-d', (string) $date );
		return false !== $dt && $dt->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Deletes an event's date rows (used when the event post is deleted).
	 *
	 * @param int $event_id Event post id.
	 */
	public static function delete_children( $event_id ) {
		global $wpdb;
		$wpdb->delete( self::dates_table(), array( 'event_id' => (int) $event_id ), array( '%d' ) );
	}

	/**
	 * Replaces all of an event's dates.
	 *
	 * @param int   $event_id Event post id.
	 * @param array $rows     Raw date rows (normalized here).
	 */
	public static function save_dates( $event_id, array $rows ) {
		global $wpdb;
		$event_id = (int) $event_id;
		$table    = self::dates_table();

		$wpdb->delete( $table, array( 'event_id' => $event_id ), array( '%d' ) );
		foreach ( self::normalize_dates( $rows ) as $row ) {
			$wpdb->insert(
				$table,
				array(
					'event_id'        => $event_id,
					'date'            => $row['date'],
					'delivery'        => $row['delivery'] ? 1 : 0,
					'delivery_window' => $row['delivery_window'],
					'pickup'          => $row['pickup'] ? 1 : 0,
					'pickup_window'   => $row['pickup_window'],
					'location_id'     => $row['location_id'],
				),
				array( '%d', '%s', '%d', '%s', '%d', '%s', '%d' )
			);
		}
	}

	/**
	 * Stores the sales window as store-local 'Y-m-d H:i:s' (or empty).
	 *
	 * @param int    $event_id Event post id.
	 * @param string $open     Window start or ''.
	 * @param string $close    Window end or ''.
	 */
	public static function save_sales_window( $event_id, $open, $close ) {
		update_post_meta( $event_id, self::META_OPEN, (string) $open );
		update_post_meta( $event_id, self::META_CLOSE, (string) $close );
	}

	/**
	 * Pure: whether now falls in [open, close). Empty bounds are unbounded.
	 *
	 * @param string $open  Start or ''.
	 * @param string $close End or ''.
	 * @param string $now   Store-local 'Y-m-d H:i:s'.
	 * @return bool
	 */
	public static function is_open( $open, $close, $now ) {
		if ( '' !== (string) $open && (string) $open > $now ) {
			return false;
		}
		if ( '' !== (string) $close && (string) $close <= $now ) {
			return false;
		}
		return true;
	}

	/**
	 * Published events whose sales window includes $now, each with its dates.
	 * One query for the events and one for all their dates.
	 *
	 * @param string $now Store-local 'Y-m-d H:i:s'.
	 * @return array[]
	 */
	public static function open_events( $now ) {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => self::MAX_EVENTS,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$open = array();
		foreach ( $posts as $post ) {
			$o = (string) get_post_meta( $post->ID, self::META_OPEN, true );
			$c = (string) get_post_meta( $post->ID, self::META_CLOSE, true );
			if ( self::is_open( $o, $c, $now ) ) {
				$open[] = $post;
			}
		}

		$dates = self::dates_for_events( wp_list_pluck( $open, 'ID' ) );
		$out   = array();
		foreach ( $open as $post ) {
			$out[] = self::shape( $post, isset( $dates[ $post->ID ] ) ? $dates[ $post->ID ] : array() );
		}
		return $out;
	}
}
