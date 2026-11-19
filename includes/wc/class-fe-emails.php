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
	 * Marks a parent whose split was rolled back; it is a normal order again.
	 * Set by the splitter.
	 *
	 * @var string
	 */
	const SPLIT_FAILED = Fe_Meta::SPLIT_FAILED;

	/**
	 * Marks a rolled-back parent whose catch-up emails were sent, so a later
	 * failed retry does not send them again.
	 *
	 * @var string
	 */
	const FAILED_EMAILED = '_fe_split_failed_emailed';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( __CLASS__, 'filter_processing_email' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', array( __CLASS__, 'filter_on_hold_email' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_new_order', array( __CLASS__, 'filter_new_order_email' ), 10, 2 );
		add_action( 'fe_order_split', array( __CLASS__, 'send_split_confirmation' ), 10, 2 );
		add_action( 'fe_order_split_failed', array( __CLASS__, 'on_split_failed' ), 10, 3 );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_class' ) );
	}

	/**
	 * Pure suppression rule.
	 *
	 * - Processing: suppressed for the parent and the children; the split
	 *   confirmation replaces it.
	 * - On-hold: suppressed for the children only. The parent's on-hold email
	 *   carries the payment instructions (bank transfer, cheque) and is sent
	 *   before the split.
	 * - New order (staff): with one email per child on, the parent's is
	 *   suppressed and each child sends its own; with it off, the parent's is
	 *   sent once for the whole order and the children's are suppressed.
	 *
	 * @param string $email_id        WooCommerce email id.
	 * @param array  $meta            Order meta with `_fe_will_split`, `_fe_parent_id`, `_fe_split_failed`.
	 * @param string $staff_per_child 'yes' or 'no': whether staff get a new-order email per child.
	 * @return bool True when the email must not be sent.
	 */
	public static function suppress( $email_id, array $meta, $staff_per_child ) {
		if ( isset( $meta[ Fe_Meta::SPLIT_FAILED ] ) && 'yes' === $meta[ Fe_Meta::SPLIT_FAILED ] ) {
			return false;
		}
		$is_parent = isset( $meta[ Fe_Meta::WILL_SPLIT ] ) && 'yes' === $meta[ Fe_Meta::WILL_SPLIT ];
		$is_child  = ! empty( $meta[ Fe_Meta::PARENT_ID ] );
		$per_child = 'no' !== $staff_per_child;

		if ( 'customer_processing_order' === $email_id ) {
			return $is_parent || $is_child;
		}
		if ( 'customer_on_hold_order' === $email_id ) {
			return $is_child;
		}
		if ( 'new_order' === $email_id ) {
			return $per_child ? $is_parent : $is_child;
		}
		return false;
	}

	/**
	 * The staff per-child setting.
	 *
	 * @return string 'yes' or 'no'.
	 */
	private static function staff_per_child() {
		return 'no' === (string) get_option( 'fe_staff_email_per_child', 'yes' ) ? 'no' : 'yes';
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
			Fe_Meta::WILL_SPLIT   => $order->get_meta( Fe_Meta::WILL_SPLIT ),
			Fe_Meta::PARENT_ID    => $order->get_meta( Fe_Meta::PARENT_ID ),
			Fe_Meta::SPLIT_FAILED => $order->get_meta( Fe_Meta::SPLIT_FAILED ),
		);
	}

	/**
	 * Applies the suppression rule to an email-enabled filter value.
	 *
	 * @param string $email_id Email id.
	 * @param bool   $enabled  Whether the email is enabled.
	 * @param mixed  $order    Order.
	 * @return bool
	 */
	private static function filter_email( $email_id, $enabled, $order ) {
		$meta = self::order_meta( $order );
		if ( $enabled && $meta && self::suppress( $email_id, $meta, self::staff_per_child() ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Filter for the customer processing email.
	 *
	 * @param bool          $enabled Whether the email is enabled.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function filter_processing_email( $enabled, $order = null ) {
		return self::filter_email( 'customer_processing_order', $enabled, $order );
	}

	/**
	 * Filter for the customer on-hold email.
	 *
	 * @param bool          $enabled Whether the email is enabled.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function filter_on_hold_email( $enabled, $order = null ) {
		return self::filter_email( 'customer_on_hold_order', $enabled, $order );
	}

	/**
	 * Filter for the staff new-order email.
	 *
	 * @param bool          $enabled Whether the email is enabled.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function filter_new_order_email( $enabled, $order = null ) {
		return self::filter_email( 'new_order', $enabled, $order );
	}

	/**
	 * Sends the split confirmation. Loads the mailer itself, so the email goes
	 * out even when nothing else instantiated it in this request (for example
	 * with deferred transactional emails).
	 *
	 * @param WC_Order   $parent   Parent order.
	 * @param WC_Order[] $children Children.
	 */
	public static function send_split_confirmation( $parent, $children = array() ) {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['fe_split_confirmation'] ) ) {
			$emails['fe_split_confirmation']->trigger( $parent, $children );
		}
	}

	/**
	 * Pure rule: which emails to send after a clean rollback. The processing
	 * email and (with one email per child on) the staff new-order email were
	 * suppressed for the parent; the on-hold email never is.
	 *
	 * @param string $status          Parent status.
	 * @param string $staff_per_child 'yes' or 'no'.
	 * @param bool   $already_emailed Whether a previous rollback sent them.
	 * @return string[] WooCommerce email class keys.
	 */
	public static function catch_up_emails( $status, $staff_per_child, $already_emailed ) {
		if ( $already_emailed ) {
			return array();
		}
		$keys = array();
		if ( 'processing' === $status ) {
			$keys[] = 'WC_Email_Customer_Processing_Order';
		}
		if ( 'no' !== $staff_per_child ) {
			$keys[] = 'WC_Email_New_Order';
		}
		return $keys;
	}

	/**
	 * After a rolled-back split the parent is a single order (the splitter
	 * set `_fe_split_failed`). Sends the emails that were suppressed for it,
	 * once. The partial failure path (children passed) sends nothing:
	 * children may already have emailed, so staff contact the customer from
	 * the order note.
	 *
	 * @param WC_Order   $parent   Parent order.
	 * @param WC_Order[] $children Children (empty on a clean rollback).
	 * @param Throwable  $e        Error.
	 */
	public static function on_split_failed( $parent, $children = array(), $e = null ) {
		if ( ! empty( $children ) || ! $parent instanceof WC_Order ) {
			return;
		}
		$keys = self::catch_up_emails( (string) $parent->get_status(), self::staff_per_child(), 'yes' === $parent->get_meta( self::FAILED_EMAILED ) );
		if ( empty( $keys ) ) {
			return;
		}
		$parent->update_meta_data( self::FAILED_EMAILED, 'yes' );
		$parent->save();
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$emails = WC()->mailer()->get_emails();
		foreach ( $keys as $key ) {
			if ( isset( $emails[ $key ] ) ) {
				$emails[ $key ]->trigger( $parent->get_id(), $parent );
			}
		}
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
