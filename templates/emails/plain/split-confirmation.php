<?php
/**
 * Split order confirmation email (plain text). Override in your theme at
 * woocommerce/emails/plain/split-confirmation.php.
 *
 * @package Fulfillment_Events
 * @var WC_Order   $order
 * @var WC_Order[] $children
 * @var string     $email_heading
 * @var string     $additional_content
 * @var WC_Email   $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

/* translators: 1: order number, 2: total paid */
echo esc_html( sprintf( __( 'Your order #%1$s is confirmed. You paid %2$s in total, split into the orders below.', 'fulfillment-events-for-woocommerce' ), $order->get_order_number(), wp_strip_all_tags( $order->get_formatted_order_total() ) ) ) . "\n\n";

foreach ( $children as $child ) {
	$f = fe_get_order_fulfillment( $child );
	echo "----------------------------------------\n";
	/* translators: %s: order number */
	echo esc_html( sprintf( __( 'Order #%s', 'fulfillment-events-for-woocommerce' ), $child->get_order_number() ) ) . "\n";
	if ( $f ) {
		echo esc_html( $f['event_name'] ) . "\n";
		$line = Fe_Email_Split_Confirmation::type_label( $f['type'] ) . ' - ' . Fe_Email_Split_Confirmation::format_date( $f['date'] );
		if ( '' !== $f['window'] ) {
			$line .= ' - ' . $f['window'];
		}
		echo esc_html( $line ) . "\n";
		if ( 'pickup' === $f['type'] && '' !== $f['location'] ) {
			echo esc_html( $f['location'] ) . "\n";
		}
	} else {
		echo esc_html__( 'Shipped separately', 'fulfillment-events-for-woocommerce' ) . "\n";
	}
	foreach ( $child->get_items() as $item ) {
		echo '- ' . esc_html( $item->get_name() ) . ' x ' . esc_html( (string) $item->get_quantity() ) . "\n";
	}
	echo "\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
