<?php
/**
 * Email handling for split orders: suppression and the split confirmation.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Keeps a split order to one customer email and honours the staff setting.
 */
class Fe_Emails {

	/**
	 * Customer emails that would fire for the parent and for each child.
	 *
	 * @var string[]
	 */
	const CUSTOMER_IDS = array( 'customer_processing_order', 'customer_on_hold_order' );

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		foreach ( self::CUSTOMER_IDS as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, array( __CLASS__, 'filter_customer_email' ), 10, 2 );
		}
		add_filter( 'woocommerce_email_enabled_new_order', array( __CLASS__, 'filter_new_order_email' ), 10, 2 );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_class' ) );
	}

	/**
	 * Pure suppression rule.
	 *
	 * @param string $email_id        WooCommerce email id.
	 * @param array  $meta            Order meta with `_fe_will_split` and `_fe_parent_id`.
	 * @param string $staff_per_child 'yes' or 'no': whether staff get a new-order email per child.
	 * @return bool True when the email must not be sent.
	 */
	public static function suppress( $email_id, array $meta, $staff_per_child ) {
		$is_parent = isset( $meta['_fe_will_split'] ) && 'yes' === $meta['_fe_will_split'];
		$is_child  = ! empty( $meta['_fe_parent_id'] );

		if ( in_array( $email_id, self::CUSTOMER_IDS, true ) ) {
			return $is_parent || $is_child;
		}
		if ( 'new_order' === $email_id ) {
			return $is_parent || ( $is_child && 'no' === $staff_per_child );
		}
		return false;
	}

	/**
	 * Reads the suppression inputs from an order.
	 *
	 * @param mixed $order Order, or null.
	 * @return array|null Null when there is no order.
	 */
	private static function order_meta( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}
		return array(
			'_fe_will_split' => $order->get_meta( Fe_Meta::WILL_SPLIT ),
			'_fe_parent_id'  => $order->get_meta( Fe_Meta::PARENT_ID ),
		);
	}

	/**
	 * Filter for the customer processing and on-hold emails.
	 *
	 * @param bool          $enabled Whether the email is enabled.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function filter_customer_email( $enabled, $order = null ) {
		$meta = self::order_meta( $order );
		if ( $enabled && $meta && self::suppress( 'customer_processing_order', $meta, 'yes' ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Filter for the staff new-order email.
	 *
	 * @param bool          $enabled Whether the email is enabled.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function filter_new_order_email( $enabled, $order = null ) {
		$meta = self::order_meta( $order );
		if ( $enabled && $meta && self::suppress( 'new_order', $meta, (string) get_option( 'fe_staff_email_per_child', 'yes' ) ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Adds the split confirmation email. The class file loads here because
	 * WC_Email only exists once WooCommerce is loading its emails.
	 *
	 * @param array $emails Email id => instance.
	 * @return array
	 */
	public static function register_email_class( $emails ) {
		if ( class_exists( 'WC_Email' ) ) {
			require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-email-split-confirmation.php';
			$emails['fe_split_confirmation'] = new Fe_Email_Split_Confirmation();
		}
		return $emails;
	}

	/**
	 * Label for a fulfillment type.
	 *
	 * @param string $type delivery|pickup|courier.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'delivery' => __( 'Delivery', 'fulfillment-events-for-woocommerce' ),
			'pickup'   => __( 'Pickup', 'fulfillment-events-for-woocommerce' ),
			'courier'  => __( 'Courier pickup', 'fulfillment-events-for-woocommerce' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
	}
}
