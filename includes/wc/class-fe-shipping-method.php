<?php
/**
 * Base class for the event shipping methods.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits one shipping rate per event date for packages that carry an event.
 */
abstract class Fe_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Fulfillment type this method offers: delivery|pickup|courier|addon.
	 *
	 * @var string
	 */
	protected $fe_type = '';

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping zone instance id.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->instance_id        = absint( $instance_id );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->method_title       = $this->default_title();
		$this->method_description = $this->default_description();
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', $this->default_title() );
	}

	/**
	 * Default method title.
	 *
	 * @return string
	 */
	abstract protected function default_title();

	/**
	 * Default method description.
	 *
	 * @return string
	 */
	abstract protected function default_description();

	/**
	 * Instance settings: title, cost and default window.
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title'  => array(
				'title'       => __( 'Title', 'fulfillment-events-for-woocommerce' ),
				'type'        => 'text',
				'default'     => $this->default_title(),
				'desc_tip'    => true,
				'description' => __( 'Name shown in the shipping zone list.', 'fulfillment-events-for-woocommerce' ),
			),
			'cost'   => array(
				'title'       => __( 'Cost', 'fulfillment-events-for-woocommerce' ),
				'type'        => 'price',
				'default'     => '0',
				'placeholder' => '0',
				'desc_tip'    => true,
				'description' => __( 'Default cost for this zone. Events can override it per date.', 'fulfillment-events-for-woocommerce' ),
			),
			'window' => array(
				'title'       => __( 'Default window', 'fulfillment-events-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'desc_tip'    => true,
				'description' => __( 'Time window shown when an event date has none of its own.', 'fulfillment-events-for-woocommerce' ),
			),
		);
	}

	/**
	 * Adds one rate per date of each event the package offers.
	 *
	 * @param array $package WooCommerce package.
	 */
	public function calculate_shipping( $package = array() ) {
		if ( '' === $this->fe_type || 'addon' === $this->fe_type ) {
			return;
		}
		$event_ids = Fe_Cart::package_event_ids( $package );
		if ( ! $event_ids ) {
			return;
		}

		$zone    = WC_Shipping_Zones::get_zone_by( 'instance_id', $this->instance_id );
		$zone_id = $zone ? (int) $zone->get_id() : 0;

		$locations = array();
		foreach ( Fe_Location_Repo::all() as $loc ) {
			$locations[ (int) $loc['id'] ] = $loc;
		}

		foreach ( $event_ids as $event_id ) {
			$event = Fe_Event_Repo::get( (int) $event_id );
			if ( ! $event || 'publish' !== $event['status'] ) {
				continue;
			}
			$event = Fe_Event_Repo::without_closed( $event );

			$specs = Fe_Rate_Builder::build(
				$event,
				$this->fe_type,
				$zone_id,
				Fe_Zone_Rule_Repo::for_event( $event['id'] ),
				(float) $this->get_option( 'cost', 0 ),
				Fe_Event_Repo::clean_window( (string) $this->get_option( 'window', '' ) ),
				$locations,
				Fe_Location_Repo::default_id(),
				! empty( $package['fe_today'] ) ? (string) $package['fe_today'] : wp_date( 'Y-m-d' ),
				array( __CLASS__, 'format_date' ),
				__( "We'll coordinate the time with you", 'fulfillment-events-for-woocommerce' )
			);

			foreach ( $specs as $i => $spec ) {
				$specs[ $i ]['label'] = apply_filters( 'fe_rate_label', $this->translated_label( $spec ), $spec, $event, $this->id );
			}

			$allowed = Fe_Cart::package_allowed_dates( $package, (int) $event['id'] );
			if ( is_array( $allowed ) ) {
				$specs = array_values(
					array_filter(
						$specs,
						function ( $spec ) use ( $allowed ) {
							return in_array( (string) $spec['date'], $allowed, true );
						}
					)
				);
			}

			$specs = apply_filters( 'fe_package_rates_for_event', $specs, $event, $package, $this );

			$note = 'courier' === $this->fe_type ? (string) apply_filters( 'fe_courier_instructions', fe_courier_instructions(), $event, $package ) : '';

			foreach ( $specs as $spec ) {
				$rate_id = $this->id . ':' . $this->instance_id . ':' . Fe_Cart::rate_suffix( $event['id'], $spec['id'], count( $event_ids ) );
				$this->add_rate(
					array(
						'id'        => $rate_id,
						'label'     => $spec['label'],
						'cost'      => $spec['cost'],
						'package'   => $package,
						'meta_data' => $spec['meta'],
					)
				);
				// The block checkout shows a rate's "delivery time" under its name
				// (WooCommerce 9.2+); the classic one gets the note from
				// Fe_Cart::label_with_note().
				if ( '' !== $note && isset( $this->rates[ $rate_id ] ) && is_callable( array( $this->rates[ $rate_id ], 'set_delivery_time' ) ) ) {
					$this->rates[ $rate_id ]->set_delivery_time( $note );
				}
			}
		}
	}

	/**
	 * Locale-aware date label via the store timezone.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	public static function format_date( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, wp_timezone() );
		return $dt ? wp_date( 'D, M j', $dt->getTimestamp() ) : (string) $ymd;
	}

	/**
	 * Translated rate label for a spec.
	 *
	 * @param array $spec Rate spec.
	 * @return string
	 */
	private function translated_label( array $spec ) {
		return Fe_Order_Event::label( $this->fe_type, $spec['when'], $spec['where'], isset( $spec['window'] ) ? $spec['window'] : '' );
	}
}
