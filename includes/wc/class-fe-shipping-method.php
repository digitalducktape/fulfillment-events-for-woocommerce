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
				'description' => __( 'Shown to customers when the cart has no event rates.', 'fulfillment-events-for-woocommerce' ),
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
	 * Adds one rate per event date.
	 *
	 * @param array $package WooCommerce package.
	 */
	public function calculate_shipping( $package = array() ) {
		if ( empty( $package['fe_event_id'] ) || '' === $this->fe_type || 'addon' === $this->fe_type ) {
			return;
		}
		$event = Fe_Event_Repo::get( (int) $package['fe_event_id'] );
		if ( ! $event || 'publish' !== $event['status'] ) {
			return;
		}

		$zone    = WC_Shipping_Zones::get_zone_by( 'instance_id', $this->instance_id );
		$zone_id = $zone ? (int) $zone->get_id() : 0;

		$locations = array();
		foreach ( Fe_Location_Repo::all() as $loc ) {
			$locations[ (int) $loc['id'] ] = $loc;
		}

		$specs = Fe_Rate_Builder::build(
			$event,
			$this->fe_type,
			$zone_id,
			Fe_Zone_Rule_Repo::for_event( $event['id'] ),
			(float) $this->get_option( 'cost', 0 ),
			Fe_Event_Repo::clean_window( (string) $this->get_option( 'window', '' ) ),
			$locations,
			Fe_Location_Repo::default_id(),
			wp_date( 'Y-m-d' ),
			array( __CLASS__, 'format_date' ),
			__( "We'll coordinate the time with you", 'fulfillment-events-for-woocommerce' )
		);

		foreach ( $specs as $i => $spec ) {
			$specs[ $i ]['label'] = apply_filters( 'fe_rate_label', $this->translated_label( $spec ), $spec, $event, $this->id );
		}

		$specs = apply_filters( 'fe_package_rates_for_event', $specs, $event, $package, $this );

		foreach ( $specs as $spec ) {
			$this->add_rate(
				array(
					'id'        => $this->id . ':' . $this->instance_id . ':' . $spec['id'],
					'label'     => $spec['label'],
					'cost'      => $spec['cost'],
					'package'   => $package,
					'meta_data' => $spec['meta'],
				)
			);
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
		switch ( $this->fe_type ) {
			case 'delivery':
				/* translators: %s: date, e.g. Thu, Dec 10 */
				return sprintf( __( 'Delivery · %s', 'fulfillment-events-for-woocommerce' ), $spec['when'] );
			case 'pickup':
				if ( '' === $spec['where'] ) {
					/* translators: %s: date, e.g. Thu, Dec 10 */
					return sprintf( __( 'Pickup · %s', 'fulfillment-events-for-woocommerce' ), $spec['when'] );
				}
				/* translators: 1: location name, 2: date, e.g. Thu, Dec 10 */
				return sprintf( __( 'Pickup at %1$s · %2$s', 'fulfillment-events-for-woocommerce' ), $spec['where'], $spec['when'] );
			default:
				/* translators: %s: date, e.g. Thu, Dec 10 */
				return sprintf( __( 'Courier pickup · %s', 'fulfillment-events-for-woocommerce' ), $spec['when'] );
		}
	}
}
