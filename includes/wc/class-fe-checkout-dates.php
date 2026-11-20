<?php
/**
 * The date picker at checkout: one dropdown per event section.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each event section offers one option per service (delivery, pickup,
 * courier) for the date picked in its dropdown, instead of one option per
 * service and date. The pick is kept in the customer's session by section
 * and goes into the package, so a new pick recalculates the rates (see
 * Fe_Cart::filter_rates()).
 */
class Fe_Checkout_Dates {

	/**
	 * Session key: section group => Y-m-d.
	 */
	const SESSION = 'fe_chosen_dates';

	/**
	 * Store API namespace.
	 */
	const NS = 'fulfillment-events';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
		}
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_block_integration' ) );
		add_action( 'woocommerce_blocks_cart_block_registration', array( __CLASS__, 'register_block_integration' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_classic' ) );
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_classic' ), 20, 2 );
		add_action( 'wp_ajax_fe_set_date', array( __CLASS__, 'ajax_set' ) );
		add_action( 'wp_ajax_nopriv_fe_set_date', array( __CLASS__, 'ajax_set' ) );
	}

	/**
	 * The date picked for a section, or ''.
	 *
	 * @param string $group Section group key.
	 * @return string
	 */
	public static function chosen( $group ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		$all = WC()->session->get( self::SESSION, array() );
		return is_array( $all ) && isset( $all[ $group ] ) ? (string) $all[ $group ] : '';
	}

	/**
	 * Saves the date picked for a section.
	 *
	 * @param string $group Section group key.
	 * @param string $date  Y-m-d.
	 * @return bool Whether it was saved.
	 */
	public static function set( $group, $date ) {
		$group = (string) $group;
		$date  = (string) $date;
		if ( '' === $group || strlen( $group ) > 200 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}
		$all = WC()->session->get( self::SESSION, array() );
		$all = is_array( $all ) ? $all : array();
		unset( $all[ $group ] );
		$all[ $group ] = $date;
		// Keep the most recent picks only.
		WC()->session->set( self::SESSION, array_slice( $all, -20, null, true ) );
		return true;
	}

	/**
	 * One entry per event section that has more than one date, for the
	 * current cart's calculated packages.
	 *
	 * @return array[] List of {package_id, group, label, selected, options: [{value, label}]}.
	 */
	public static function sections() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return array();
		}
		$packages = (array) WC()->shipping()->get_packages();
		$events   = 0;
		foreach ( $packages as $package ) {
			if ( ! empty( $package['fe_event_id'] ) ) {
				++$events;
			}
		}
		$out = array();
		foreach ( $packages as $i => $package ) {
			if ( empty( $package['fe_event_id'] ) ) {
				continue;
			}
			$picked = self::package_dates( isset( $package['rates'] ) ? (array) $package['rates'] : array() );
			if ( count( $picked['dates'] ) < 2 ) {
				continue;
			}
			$heading = isset( $package['fe_event_name'] ) ? (string) $package['fe_event_name'] : '';
			$out[]   = array(
				'package_id' => (int) $i,
				'group'      => isset( $package['fe_group'] ) ? (string) $package['fe_group'] : '',
				/* translators: %s: event name, e.g. Holiday Pre-order 2026 */
				'label'      => $events > 1 && '' !== $heading ? sprintf( __( 'Date for %s', 'fulfillment-events-for-woocommerce' ), $heading ) : __( 'Date', 'fulfillment-events-for-woocommerce' ),
				'selected'   => $picked['selected'],
				'options'    => array_map(
					function ( $date ) {
						return array(
							'value' => $date,
							'label' => self::date_label( $date ),
						);
					},
					$picked['dates']
				),
			);
		}
		return $out;
	}

	/**
	 * A section's dates and its current date, read from its rates.
	 *
	 * @param WC_Shipping_Rate[] $rates Rates of the package.
	 * @return array{dates: string[], selected: string}
	 */
	private static function package_dates( array $rates ) {
		foreach ( $rates as $rate ) {
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta_data' ) ) {
				continue;
			}
			$meta = (array) $rate->get_meta_data();
			if ( ! empty( $meta['fe_dates'] ) && is_array( $meta['fe_dates'] ) ) {
				return array(
					'dates'    => array_values( array_map( 'strval', $meta['fe_dates'] ) ),
					'selected' => isset( $meta['fe_date'] ) ? (string) $meta['fe_date'] : '',
				);
			}
		}
		return array(
			'dates'    => array(),
			'selected' => '',
		);
	}

	/**
	 * Date label for the dropdown, e.g. "Thu, Dec 10".
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private static function date_label( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, wp_timezone() );
		return $dt ? wp_date( 'D, M j', $dt->getTimestamp() ) : (string) $ymd;
	}

	// -------------------------------------------------------------------
	// Block checkout and cart.
	// -------------------------------------------------------------------

	/**
	 * Adds `extensions.fulfillment-events.sections` to the Store API cart and
	 * the update callback the dropdown calls.
	 */
	public static function register_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => self::NS,
				'data_callback'   => function () {
					return array( 'sections' => self::sections() );
				},
				'schema_callback' => function () {
					return array(
						'sections' => array(
							'description' => __( 'Date pickers for event shipping sections.', 'fulfillment-events-for-woocommerce' ),
							'type'        => 'array',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NS,
					'callback'  => function ( $data ) {
						self::set(
							isset( $data['group'] ) ? sanitize_text_field( (string) $data['group'] ) : '',
							isset( $data['date'] ) ? sanitize_text_field( (string) $data['date'] ) : ''
						);
					},
				)
			);
		}
	}

	/**
	 * Registers the block script with the checkout and cart blocks.
	 *
	 * @param object $registry IntegrationRegistry.
	 */
	public static function register_block_integration( $registry ) {
		if ( ! interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) || ! is_object( $registry ) ) {
			return;
		}
		require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-blocks-integration.php';
		if ( ! $registry->is_registered( 'fulfillment-events-dates' ) ) {
			$registry->register( new Fe_Blocks_Integration() );
		}
	}

	// -------------------------------------------------------------------
	// Classic checkout and cart.
	// -------------------------------------------------------------------

	/**
	 * Classic script on the cart and checkout pages.
	 */
	public static function enqueue_classic() {
		if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) || is_order_received_page() ) {
			return;
		}
		wp_enqueue_script( 'fe-checkout-dates-classic', plugins_url( 'assets/checkout-dates-classic.js', FE_PLUGIN_FILE ), array( 'jquery' ), FE_VERSION, true );
		// Themes often lay a shipping option out as a flex row; the date
		// picker goes on a line of its own.
		wp_register_style( 'fe-checkout-dates-classic', false, array(), FE_VERSION );
		wp_enqueue_style( 'fe-checkout-dates-classic' );
		wp_add_inline_style(
			'fe-checkout-dates-classic',
			'.woocommerce-shipping-methods li:has(.fe-date-picker){flex-wrap:wrap}'
			// Options can run to several lines: radio and text side by side, the
			// radio on the first line. The id outranks themes' #shipping_method rules.
			. '#shipping_method li:has(> input.shipping_method[value^="fe_"]){display:flex;align-items:baseline;column-gap:0.5em}'
			. '#shipping_method li:has(> input.shipping_method[value^="fe_"]) > input.shipping_method{flex:none;margin:0}'
			// A zero basis keeps the text beside the radio even in themes that
			// let the row wrap (Kadence's checkout does).
			. '#shipping_method li:has(> input.shipping_method[value^="fe_"]) > label{flex:1 1 0;min-width:0}'
			. '.fe-rate-note{display:block;margin:0.25em 0 0.25em;font-size:0.875em;font-weight:normal;opacity:0.8}'
			. '.fe-date-picker{flex-basis:100%;margin:0 0 0.75em}'
			. '.fe-date-picker label{display:block;font-weight:600;margin-bottom:0.25em}'
			. '.fe-date-picker select{max-width:100%}'
		);
		wp_localize_script(
			'fe-checkout-dates-classic',
			'feCheckoutDates',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'fe_set_date' ),
			)
		);
	}

	/**
	 * Classic checkout: prints the section's date dropdown after its last
	 * shipping option; the classic script moves it above the options.
	 *
	 * @param WC_Shipping_Rate $rate  Rate.
	 * @param int              $index Package index.
	 */
	public static function render_classic( $rate, $index ) {
		if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_id' ) || ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return;
		}
		$packages = (array) WC()->shipping()->get_packages();
		if ( ! isset( $packages[ $index ] ) || empty( $packages[ $index ]['fe_event_id'] ) || empty( $packages[ $index ]['rates'] ) ) {
			return;
		}
		$rates = (array) $packages[ $index ]['rates'];
		$ids   = array_keys( $rates );
		if ( (string) end( $ids ) !== (string) $rate->get_id() ) {
			return;
		}
		foreach ( self::sections() as $section ) {
			if ( (int) $section['package_id'] !== (int) $index ) {
				continue;
			}
			$id = 'fe-date-' . (int) $index;
			// The section's name is right beside it, so the label is just "Date".
			echo '<p class="fe-date-picker"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Date', 'fulfillment-events-for-woocommerce' ) . '</label>';
			echo '<select id="' . esc_attr( $id ) . '" class="fe-date-select" data-group="' . esc_attr( $section['group'] ) . '">';
			foreach ( $section['options'] as $option ) {
				echo '<option value="' . esc_attr( $option['value'] ) . '"' . selected( $section['selected'], $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
			}
			echo '</select></p>';
		}
	}

	/**
	 * Classic dropdown change.
	 */
	public static function ajax_set() {
		check_ajax_referer( 'fe_set_date', 'nonce' );
		$ok = self::set(
			isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '',
			isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : ''
		);
		if ( $ok ) {
			wp_send_json_success();
		}
		wp_send_json_error( null, 400 );
	}
}
