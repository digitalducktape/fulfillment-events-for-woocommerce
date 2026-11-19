<?php
/**
 * Order admin: fulfillment column in the orders list and cross-links
 * between split parents and children. Works with HPOS and legacy storage.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows split relationships in the order screens.
 */
class Fe_Order_Admin {

	const COLUMN = 'fe_fulfillment';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		// HPOS list table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		// Legacy posts list table.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
	}

	/**
	 * Adds the Fulfillment column after the status column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Fulfillment', 'fulfillment-events-for-woocommerce' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Fulfillment', 'fulfillment-events-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * Prints the column cell.
	 *
	 * @param string          $column Column key.
	 * @param WC_Order|int    $order  Order (HPOS) or post id (legacy).
	 */
	public static function render_column( $column, $order ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$text = self::column_html( $order );
		echo '' === $text ? '&ndash;' : wp_kses_post( $text );
	}

	/**
	 * Builds the cell HTML for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function column_html( WC_Order $order ) {
		$parent_id = (int) $order->get_meta( Fe_Meta::PARENT_ID );
		$child_ids = self::child_ids( $order );

		if ( $child_ids ) {
			$links = array();
			foreach ( $child_ids as $id ) {
				$links[] = self::order_link( $id );
			}
			/* translators: %s: list of linked order numbers */
			return sprintf( esc_html__( 'Split into %s', 'fulfillment-events-for-woocommerce' ), implode( ', ', $links ) );
		}

		$parts = array();
		$f     = fe_get_order_fulfillment( $order );
		if ( $f ) {
			$parts[] = esc_html( Fe_Emails::type_label( $f['type'] ) );
			if ( '' !== $f['date'] ) {
				$ts      = strtotime( $f['date'] . ' 12:00:00' );
				$parts[] = esc_html( $ts ? date_i18n( get_option( 'date_format' ), $ts ) : $f['date'] );
			}
		}
		if ( $parent_id ) {
			/* translators: %s: linked parent order number */
			$parts[] = sprintf( esc_html__( 'part of %s', 'fulfillment-events-for-woocommerce' ), self::order_link( $parent_id ) );
		}
		return implode( ' &middot; ', $parts );
	}

	/**
	 * Child order ids stored on a split parent.
	 *
	 * @param WC_Order $order Order.
	 * @return int[]
	 */
	private static function child_ids( WC_Order $order ) {
		return array_values( array_filter( array_map( 'intval', explode( ',', (string) $order->get_meta( Fe_Meta::CHILDREN ) ) ) ) );
	}

	/**
	 * Escaped link to an order's edit screen, or plain "#id" when it is gone.
	 *
	 * @param int $id Order id.
	 * @return string
	 */
	private static function order_link( $id ) {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) {
			return esc_html( '#' . $id );
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
	}

	/**
	 * Adds the split links box on the order edit screen.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'fe_split_links',
			__( 'Split order', 'fulfillment-events-for-woocommerce' ),
			array( __CLASS__, 'render_meta_box' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'high'
		);
	}

	/**
	 * Renders the split links box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (legacy) or order (HPOS).
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$parent_id = (int) $order->get_meta( Fe_Meta::PARENT_ID );
		$child_ids = self::child_ids( $order );

		if ( $parent_id ) {
			/* translators: %s: linked parent order number */
			echo '<p>' . wp_kses_post( sprintf( __( 'Refunds for this order are made on %s.', 'fulfillment-events-for-woocommerce' ), self::order_link( $parent_id ) ) ) . '</p>';
		}
		if ( $child_ids ) {
			echo '<p>' . esc_html__( 'This order was split into:', 'fulfillment-events-for-woocommerce' ) . '</p><ul>';
			foreach ( $child_ids as $id ) {
				echo '<li>' . wp_kses_post( self::order_link( $id ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! $parent_id && ! $child_ids ) {
			echo '<p>' . esc_html__( 'This order was not split.', 'fulfillment-events-for-woocommerce' ) . '</p>';
		}
	}
}
