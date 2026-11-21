<?php
/**
 * Guest "existing order number" field for the add-on rate: field
 * registration, place-order validation and hydration of the shipping line.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Checkout field and checks for adding on to an existing order as a guest.
 */
class Fe_Addon_Field {

	/**
	 * Additional checkout field id (blocks).
	 */
	const FIELD_ID = 'fulfillment-events/addon-order';

	/**
	 * Classic checkout field key.
	 */
	const CLASSIC_KEY = 'fe_addon_order';

	/**
	 * Order meta holding our copy of the typed order number.
	 */
	const INPUT_META = '_fe_addon_order_input';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'classic_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_blocks' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic' ), 10, 2 );
	}

	/**
	 * Field label.
	 *
	 * @return string
	 */
	private static function label() {
		return __( 'Adding to an existing order? Order number', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Selected guest add-on rate ids look like fe_addon:{instance}:guest.
	 */
	const GUEST_RATE_PATTERN = '^fe_addon:[0-9]+:guest$';

	/**
	 * Blocks field definition. On WooCommerce 9.9+ the field uses the
	 * JSON-schema conditions (evaluated against the checkout document, where
	 * cart.shipping_rates lists the selected rate ids) to stay hidden unless a
	 * guest add-on rate is selected, and to be required when it is. Older
	 * versions get an unconditional optional field.
	 *
	 * @param string $wc_version WooCommerce version.
	 * @return array
	 */
	public static function block_field_args( $wc_version ) {
		$args = array(
			'id'            => self::FIELD_ID,
			'label'         => self::label(),
			'optionalLabel' => self::label(),
			'location'      => 'order',
			'type'          => 'text',
			'required'      => false,
		);
		if ( '' === (string) $wc_version || version_compare( (string) $wc_version, '9.9', '<' ) ) {
			return $args;
		}
		$selected         = array(
			'type'       => 'object',
			'required'   => array( 'cart' ),
			'properties' => array(
				'cart' => array(
					'type'       => 'object',
					'required'   => array( 'shipping_rates' ),
					'properties' => array(
						'shipping_rates' => array(
							'type'     => 'array',
							'contains' => array(
								'type'    => 'string',
								'pattern' => self::GUEST_RATE_PATTERN,
							),
						),
					),
				),
			),
		);
		$not_selected     = array(
			'type'       => 'object',
			'properties' => array(
				'cart' => array(
					'properties' => array(
						'shipping_rates' => array(
							'not' => $selected['properties']['cart']['properties']['shipping_rates'],
						),
					),
				),
			),
		);
		$args['required'] = $selected;
		$args['hidden']   = $not_selected;
		return $args;
	}

	/**
	 * Pure: whether the guest add-on rate can be offered. On WooCommerce 9.9+
	 * the block checkout shows the order number field only when the rate is
	 * picked. Below 9.9 the field is not registered for blocks (it would show
	 * on every checkout), so the guest rate is only offered to the classic
	 * checkout: not on Store API requests and not when the checkout page uses
	 * the checkout block.
	 *
	 * @param string $wc_version        WooCommerce version.
	 * @param bool   $is_store_api      Whether this is a Store API request.
	 * @param bool   $checkout_is_block Whether the checkout page uses the checkout block.
	 * @return bool
	 */
	public static function guest_addon_supported( $wc_version, $is_store_api, $checkout_is_block ) {
		if ( self::blocks_field_supported( $wc_version ) ) {
			return true;
		}
		return ! $is_store_api && ! $checkout_is_block;
	}

	/**
	 * Pure: whether the conditional blocks field is available (WooCommerce 9.9+).
	 *
	 * @param string $wc_version WooCommerce version.
	 * @return bool
	 */
	public static function blocks_field_supported( $wc_version ) {
		return '' !== (string) $wc_version && version_compare( (string) $wc_version, '9.9', '>=' );
	}

	/**
	 * Whether the guest add-on rate can be offered in this request.
	 *
	 * @return bool
	 */
	public static function guest_addon_available() {
		$version = defined( 'WC_VERSION' ) ? WC_VERSION : '';
		if ( self::blocks_field_supported( $version ) ) {
			return true;
		}
		return self::guest_addon_supported( $version, self::is_store_api_request(), self::checkout_is_block() );
	}

	/**
	 * Whether the current request goes to the Store API (blocks cart/checkout).
	 *
	 * @return bool
	 */
	private static function is_store_api_request() {
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'is_store_api_request' ) ) {
			return (bool) WC()->is_store_api_request();
		}
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, 'wc/store' );
	}

	/**
	 * Whether the store's checkout page uses the checkout block.
	 *
	 * @return bool
	 */
	private static function checkout_is_block() {
		if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		$page_id = (int) wc_get_page_id( 'checkout' );
		return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
	}

	/**
	 * Registers the blocks checkout field (WooCommerce 9.9+ only; older
	 * versions can't hide it, and the guest add-on rate is classic-only there).
	 */
	public static function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! self::blocks_field_supported( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) ) {
			return;
		}
		try {
			woocommerce_register_additional_checkout_field( self::block_field_args( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) );
		} catch ( Exception $e ) {
			// Registration failed (field API changed); classic still works.
			return;
		}
	}

	/**
	 * Adds the classic checkout field for guests with an event product.
	 *
	 * @param array $fields Checkout fields by fieldset.
	 * @return array
	 */
	public static function classic_field( $fields ) {
		if ( ! is_array( $fields ) || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) || ! self::cart_has_event() ) {
			return $fields;
		}
		$fields['order'][ self::CLASSIC_KEY ] = array(
			'type'     => 'text',
			'label'    => self::label(),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 5,
		);
		return $fields;
	}

	/**
	 * Whether the cart has a shippable event product.
	 *
	 * @return bool
	 */
	private static function cart_has_event() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $line ) {
			$product = isset( $line['data'] ) ? $line['data'] : null;
			if ( $product && method_exists( $product, 'needs_shipping' ) && ! $product->needs_shipping() ) {
				continue;
			}
			if ( Fe_Cart::product_event_status( (int) $line['product_id'] )['event_id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Eligibility data and fulfillment of a possible target order.
	 *
	 * @param WC_Order|mixed $order Order.
	 * @return array|null {target, fulfillment}, null when it has no fulfillment.
	 */
	public static function target_data( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$meta = array();
		foreach ( Fe_Meta::contract_keys() as $key ) {
			$meta[ $key ] = $order->get_meta( $key );
		}
		$fulfillment = Fe_Meta::from_meta( $meta );
		if ( ! $fulfillment || '' === $fulfillment['date'] ) {
			return null;
		}
		return array(
			'target'      => array(
				'event_id'      => (int) $fulfillment['event_id'],
				'status'        => (string) $order->get_status(),
				'is_addon'      => null !== $fulfillment['addon_of'],
				'customer_id'   => (int) $order->get_customer_id(),
				'billing_email' => (string) $order->get_billing_email(),
				'date'          => (string) $fulfillment['date'],
				// No add-ons once the date stops taking orders.
				'closed'        => in_array( (string) $fulfillment['date'], Fe_Event_Repo::closed_dates( (int) $fulfillment['event_id'] ), true ),
			),
			'fulfillment' => $fulfillment,
		);
	}

	/**
	 * Lookup for Fe_Addon_Rules: order id => target array. A split parent
	 * comes back as {status, children} so the rules can pick its child.
	 *
	 * @param int $id Order id.
	 * @return array|null
	 */
	public static function lookup( $id ) {
		$order = wc_get_order( absint( $id ) );
		if ( $order instanceof WC_Order && Fe_Addon_Rules::SPLIT_STATUS === (string) $order->get_status() ) {
			return array(
				'status'   => Fe_Addon_Rules::SPLIT_STATUS,
				'children' => array_values( array_filter( array_map( 'intval', explode( ',', (string) $order->get_meta( Fe_Meta::CHILDREN ) ) ) ) ),
			);
		}
		$data = self::target_data( $order );
		return $data ? $data['target'] : null;
	}

	/**
	 * Store-local today, Y-m-d.
	 *
	 * @return string
	 */
	public static function today() {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : '';
	}

	/**
	 * Error message for a failed guest add-on check.
	 *
	 * @param array $error {number, event_name}.
	 * @return string
	 */
	public static function error_message( array $error ) {
		if ( '' === $error['number'] ) {
			return __( 'Enter the number of the order you are adding to.', 'fulfillment-events-for-woocommerce' );
		}
		/* translators: 1: order number, 2: event name */
		return sprintf( __( "We couldn't find an open order #%1\$s for %2\$s with this email.", 'fulfillment-events-for-woocommerce' ), $error['number'], $error['event_name'] );
	}

	/**
	 * Rate meta of each shipping item on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array List of fe_* meta arrays.
	 */
	private static function order_rate_metas( $order ) {
		$out = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$out[] = array(
				'fe_addon_guest' => (string) $item->get_meta( 'fe_addon_guest' ),
				'fe_event_id'    => (int) $item->get_meta( 'fe_event_id' ),
				'fe_event_ids'   => (string) $item->get_meta( 'fe_event_ids' ),
				'fe_offers'      => (string) $item->get_meta( 'fe_offers' ),
				'fe_event_name'  => (string) $item->get_meta( 'fe_event_name' ),
			);
		}
		return $out;
	}

	/**
	 * Whether any rate meta is a guest add-on.
	 *
	 * @param array $metas Rate metas.
	 * @return bool
	 */
	private static function has_guest_addon( array $metas ) {
		foreach ( $metas as $meta ) {
			if ( isset( $meta['fe_addon_guest'] ) && 'yes' === $meta['fe_addon_guest'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cleans a typed order number for storage and messages.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}

	/**
	 * Blocks: validates the field when a guest add-on rate is chosen and keeps
	 * our own copy of the value. The Store API saves the order after this
	 * (on place-order, as part of processing).
	 *
	 * @param WC_Order        $order   Draft order.
	 * @param WP_REST_Request $request Checkout request.
	 * @throws Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the number doesn't resolve.
	 */
	public static function validate_blocks( $order, $request ) {
		// The hook also fires on PUT/PATCH updates while the customer types; only check at place-order.
		if ( is_object( $request ) && method_exists( $request, 'get_method' ) && 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return;
		}
		$metas = self::order_rate_metas( $order );
		if ( ! self::has_guest_addon( $metas ) ) {
			$order->delete_meta_data( self::INPUT_META );
			return;
		}
		$fields = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'additional_fields' ) : null;
		$input  = is_array( $fields ) && isset( $fields[ self::FIELD_ID ] ) ? self::clean( $fields[ self::FIELD_ID ] ) : '';
		if ( '' === $input ) {
			$input = self::clean( self::saved_block_value( $order ) );
		}
		$error = Fe_Addon_Rules::guest_error( $metas, $input, (string) $order->get_billing_email(), array( __CLASS__, 'lookup' ), self::today() );
		if ( $error ) {
			// The Store API returns the message as JSON text; the blocks UI escapes it when rendering.
			throw new Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'fe_addon_invalid', self::error_message( $error ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$order->update_meta_data( self::INPUT_META, $input );
	}

	/**
	 * Value WooCommerce stored for the blocks field, if any.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function saved_block_value( $order ) {
		$package = 'Automattic\WooCommerce\Blocks\Package';
		$service = 'Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields';
		if ( ! class_exists( $package ) || ! class_exists( $service ) ) {
			return '';
		}
		try {
			$fields = $package::container()->get( $service );
			return (string) $fields->get_field_from_object( self::FIELD_ID, $order, 'other' );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Classic: validates the field against the chosen rates.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate_classic( $data, $errors ) {
		$metas = self::classic_rate_metas( $data );
		if ( ! self::has_guest_addon( $metas ) ) {
			return;
		}
		$input = isset( $data[ self::CLASSIC_KEY ] ) ? self::clean( $data[ self::CLASSIC_KEY ] ) : '';
		$email = isset( $data['billing_email'] ) ? (string) $data['billing_email'] : '';
		$error = Fe_Addon_Rules::guest_error( $metas, $input, $email, array( __CLASS__, 'lookup' ), self::today() );
		if ( $error ) {
			$errors->add( 'fe_addon_invalid', esc_html( self::error_message( $error ) ) );
		}
	}

	/**
	 * Classic: rate meta of the chosen rate in each package.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	private static function classic_rate_metas( $data ) {
		$chosen = isset( $data['shipping_method'] ) ? (array) $data['shipping_method'] : array();
		if ( ! function_exists( 'WC' ) || ! WC() || empty( $chosen ) ) {
			return array();
		}
		$out = array();
		foreach ( WC()->shipping()->get_packages() as $key => $package ) {
			if ( ! isset( $chosen[ $key ], $package['rates'][ $chosen[ $key ] ] ) ) {
				continue;
			}
			$out[] = (array) $package['rates'][ $chosen[ $key ] ]->get_meta_data();
		}
		return $out;
	}

	/**
	 * Classic: stores the typed number when a guest add-on rate was chosen.
	 * Shipping items exist by the time this runs.
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 */
	public static function save_classic( $order, $data ) {
		if ( ! self::has_guest_addon( self::order_rate_metas( $order ) ) ) {
			return;
		}
		$order->update_meta_data( self::INPUT_META, isset( $data[ self::CLASSIC_KEY ] ) ? self::clean( $data[ self::CLASSIC_KEY ] ) : '' );
	}

	/**
	 * Writes the target's fulfillment onto guest add-on shipping lines, so the
	 * stamper/splitter treat them like any event line. Lines that don't resolve
	 * are left alone and plan as non-event.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function hydrate( $order ) {
		$input = null;
		$noted = false;
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( 'yes' !== (string) $item->get_meta( 'fe_addon_guest' ) || '' !== (string) $item->get_meta( 'fe_date' ) ) {
				continue;
			}
			if ( null === $input ) {
				$input = (string) $order->get_meta( self::INPUT_META );
				if ( '' === $input ) {
					$input = self::clean( self::saved_block_value( $order ) );
				}
			}
			$offers    = Fe_Addon_Rules::meta_offers(
				array(
					'fe_event_id'  => $item->get_meta( 'fe_event_id' ),
					'fe_event_ids' => $item->get_meta( 'fe_event_ids' ),
					'fe_offers'    => $item->get_meta( 'fe_offers' ),
				)
			);
			$target_id = Fe_Addon_Rules::resolve_guest( $input, $offers, (string) $order->get_billing_email(), array( __CLASS__, 'lookup' ), self::today() );
			$data      = $target_id ? self::target_data( wc_get_order( $target_id ) ) : null;
			if ( ! $data ) {
				if ( ! $noted ) {
					$order->add_order_note( __( 'Add-on order number could not be matched; check this order.', 'fulfillment-events-for-woocommerce' ) );
					$noted = true;
				}
				continue;
			}
			foreach ( Fe_Addon_Rules::rate_meta( $data['fulfillment'], $target_id ) as $key => $value ) {
				$item->update_meta_data( $key, $value );
			}
			$item->save();
		}
	}
}
