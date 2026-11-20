<?php
/**
 * Order-received page: the orders a split order became. Override in your
 * theme at woocommerce/order-received/split-orders.php.
 *
 * @package Fulfillment_Events
 * @var WC_Order $order The order the customer placed.
 * @var array[]  $rows  From Fe_Order_Received::rows().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="fe-split-orders" style="margin:0 0 2em">
	<h2 class="woocommerce-column__title">
		<?php
		/* translators: %d: number of orders */
		echo esc_html( sprintf( _n( 'Your order was split into %d order', 'Your order was split into %d orders', count( $rows ), 'fulfillment-events-for-woocommerce' ), count( $rows ) ) );
		?>
	</h2>
	<p><?php esc_html_e( 'Each one is prepared and fulfilled separately. We\'ve emailed you this list too.', 'fulfillment-events-for-woocommerce' ); ?></p>

	<?php foreach ( $rows as $row ) : ?>
		<div class="fe-split-order" style="margin:0 0 1.5em;padding:1em 1.25em;border:1px solid rgba(0,0,0,0.15);border-radius:6px">
			<h3 style="margin:0 0 0.5em">
				<?php
				/* translators: %s: order number */
				echo esc_html( sprintf( __( 'Order #%s', 'fulfillment-events-for-woocommerce' ), $row['number'] ) );
				?>
			</h3>
			<p style="margin:0 0 0.5em">
				<?php if ( '' !== $row['event'] ) : ?>
					<strong><?php echo esc_html( $row['event'] ); ?></strong><br>
				<?php endif; ?>
				<?php if ( '' !== $row['shipping'] ) : ?>
					<?php echo esc_html( $row['shipping'] ) . ( $row['shipping_cost'] > 0 ? ': ' . wp_kses_post( wc_price( $row['shipping_cost'] ) ) : '' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'No delivery needed', 'fulfillment-events-for-woocommerce' ); ?>
				<?php endif; ?>
				<?php if ( '' !== $row['location'] ) : ?>
					<br><?php echo esc_html( $row['location'] ); ?>
				<?php endif; ?>
			</p>
			<ul style="margin:0;padding-left:1.25em">
				<?php foreach ( $row['items'] as $item ) : ?>
					<li><?php echo esc_html( $item['name'] ); ?> &times; <?php echo esc_html( (string) $item['qty'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
</section>
