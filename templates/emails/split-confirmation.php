<?php
/**
 * Split order confirmation email (HTML). Override in your theme at
 * woocommerce/emails/split-confirmation.php.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
/* translators: %s: customer first name */
printf( esc_html__( 'Hi %s,', 'fulfillment-events-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) );
?>
</p>
<p>
<?php
/* translators: 1: order number, 2: total paid */
echo wp_kses_post( sprintf( __( 'Your order #%1$s is confirmed. You paid %2$s in total, split into the orders below.', 'fulfillment-events-for-woocommerce' ), esc_html( $order->get_order_number() ), $order->get_formatted_order_total() ) );
?>
</p>

<?php foreach ( $children as $child ) : ?>
	<?php
	$f = fe_get_order_fulfillment( $child );
	?>
	<div style="margin-bottom: 24px;">
		<h2 style="margin-bottom: 8px;">
		<?php
		/* translators: %s: order number */
		printf( esc_html__( 'Order #%s', 'fulfillment-events-for-woocommerce' ), esc_html( $child->get_order_number() ) );
		?>
		</h2>
		<?php if ( $f ) : ?>
			<p style="margin: 0 0 8px;">
				<strong><?php echo esc_html( $f['event_name'] ); ?></strong><br>
				<?php echo esc_html( Fe_Email_Split_Confirmation::type_label( $f['type'] ) ); ?>
				&middot; <?php echo esc_html( Fe_Email_Split_Confirmation::format_date( $f['date'] ) ); ?>
				<?php if ( '' !== $f['window'] ) : ?>
					&middot; <?php echo esc_html( $f['window'] ); ?>
				<?php endif; ?>
				<?php if ( 'pickup' === $f['type'] && '' !== $f['location'] ) : ?>
					<br><?php echo esc_html( $f['location'] ); ?>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<p style="margin: 0 0 8px;"><?php esc_html_e( 'Shipped separately', 'fulfillment-events-for-woocommerce' ); ?></p>
		<?php endif; ?>
		<ul style="margin: 0; padding-left: 20px;">
			<?php foreach ( $child->get_items() as $item ) : ?>
				<li><?php echo esc_html( $item->get_name() ); ?> &times; <?php echo esc_html( (string) $item->get_quantity() ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endforeach; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
