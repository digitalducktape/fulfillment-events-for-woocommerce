<?php
/**
 * Event addon shipping method.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event add-on method.
 */
class Fe_Method_Addon extends Fe_Shipping_Method {

	/**
	 * Method id.
	 *
	 * @var string
	 */
	public $id = 'fe_addon';

	/**
	 * Fulfillment type.
	 *
	 * @var string
	 */
	protected $fe_type = 'addon';

	/**
	 * Default title.
	 *
	 * @return string
	 */
	protected function default_title() {
		return __( 'Event add-on', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Default description.
	 *
	 * @return string
	 */
	protected function default_description() {
		return __( 'Lets customers add an event order on to an open order for the same event, at no extra shipping cost.', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Logged-in customers get one rate per eligible order of theirs; guests
	 * get one generic rate that requires the order number field.
	 *
	 * @param array $package WooCommerce package.
	 */
	public function calculate_shipping( $package = array() ) {
		if ( empty( $package['fe_event_id'] ) ) {
			return;
		}
		$event_id    = (int) $package['fe_event_id'];
		$event_name  = isset( $package['fe_event_name'] ) ? (string) $package['fe_event_name'] : '';
		$customer_id = isset( $package['fe_customer_id'] ) ? (int) $package['fe_customer_id'] : 0;
		$event       = Fe_Event_Repo::get( $event_id );

		if ( $customer_id <= 0 ) {
			$this->add_rate(
				array(
					'id'        => $this->id . ':' . $this->instance_id . ':guest',
					'label'     => apply_filters( 'fe_rate_label', __( 'Add to an existing order', 'fulfillment-events-for-woocommerce' ), array(), $event, $this->id ),
					'cost'      => 0,
					'package'   => $package,
					'meta_data' => array(
						'fe_event_id'    => $event_id,
						'fe_event_name'  => $event_name,
						'fe_addon_guest' => 'yes',
					),
				)
			);
			return;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'status'      => Fe_Addon_Rules::OPEN_STATUSES,
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
			)
		);
		foreach ( (array) $orders as $order ) {
			$data = Fe_Addon_Field::target_data( $order );
			if ( ! $data || ! Fe_Addon_Rules::eligible( $data['target'], $event_id, '', $customer_id ) ) {
				continue;
			}
			$meta = Fe_Addon_Rules::rate_meta( $data['fulfillment'], $order->get_id() );
			$spec = array(
				'id'    => (string) $order->get_id(),
				'cost'  => 0,
				'when'  => self::format_date( $meta['fe_date'] ),
				'where' => $meta['fe_location'],
				'meta'  => $meta,
			);
			$label = sprintf(
				/* translators: 1: order number, 2: fulfillment type, 3: date, e.g. Thu, Dec 10 */
				__( 'Add to my order #%1$s — %2$s · %3$s · no extra fee', 'fulfillment-events-for-woocommerce' ),
				$order->get_order_number(),
				Fe_Emails::type_label( $meta['fe_type'] ),
				$spec['when']
			);
			$this->add_rate(
				array(
					'id'        => $this->id . ':' . $this->instance_id . ':' . $order->get_id(),
					'label'     => apply_filters( 'fe_rate_label', $label, $spec, $event, $this->id ),
					'cost'      => 0,
					'package'   => $package,
					'meta_data' => $meta,
				)
			);
		}
	}
}
